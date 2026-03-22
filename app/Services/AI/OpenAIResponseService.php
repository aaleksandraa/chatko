<?php

namespace App\Services\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIResponseService
{
    /**
     * @param array<string, mixed> $promptPackage
     * @return array<string, mixed>
     */
    public function respond(array $promptPackage, string $model = 'gpt-5-mini', float $temperature = 0.3, int $maxOutputTokens = 350): array
    {
        $apiKey = config('services.openai.api_key');
        $safeModel = trim($model) !== '' ? $model : (string) config('services.openai.default_model', 'gpt-5-mini');
        $safeMaxOutputTokens = $this->sanitizeMaxOutputTokens($maxOutputTokens);

        if (! is_string($apiKey) || trim($apiKey) === '') {
            return $this->fallbackResponse($promptPackage, 'missing_api_key');
        }

        $input = $this->buildStructuredInput($promptPackage);
        $payload = [
            'model' => $safeModel,
            'input' => $input,
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'assistant_response',
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'answer_text' => ['type' => 'string'],
                            'recommended_product_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            'cta_type' => ['type' => ['string', 'null']],
                            'cta_label' => ['type' => ['string', 'null']],
                            'needs_handoff' => ['type' => 'boolean'],
                            'lead_capture_suggested' => ['type' => 'boolean'],
                            'detected_intent' => ['type' => 'string'],
                            'confidence' => ['type' => 'number'],
                        ],
                        'required' => [
                            'answer_text',
                            'recommended_product_ids',
                            'cta_type',
                            'cta_label',
                            'needs_handoff',
                            'lead_capture_suggested',
                            'detected_intent',
                            'confidence',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'temperature' => $temperature,
            'max_output_tokens' => $safeMaxOutputTokens,
        ];

        $attempt = $this->executeOpenAIRequest($safeModel, $payload, $apiKey, 'structured');
        $usedCompatibilityMode = false;

        if (! (bool) ($attempt['successful'] ?? false) && $this->shouldRetryWithCompatibilityPayload($attempt)) {
            $compatPayload = $this->buildCompatibilityPayload($safeModel, $promptPackage, $safeMaxOutputTokens);
            $attempt = $this->executeOpenAIRequest($safeModel, $compatPayload, $apiKey, 'compatibility');
            $usedCompatibilityMode = true;
            $payload = $compatPayload;
        }

        if (! (bool) ($attempt['successful'] ?? false)) {
            return $this->fallbackResponse(
                $promptPackage,
                'http_error',
                [
                    'status' => $attempt['status'] ?? null,
                    'stage' => $attempt['stage'] ?? null,
                    'body' => $this->bodyPreview((string) ($attempt['body'] ?? '')),
                ],
            );
        }

        $json = is_array($attempt['json'] ?? null) ? $attempt['json'] : [];
        $outputText = $this->extractOutputText($json);

        if ((! is_string($outputText) || $outputText === '') && $this->shouldRetryWithHigherTokens($json)) {
            $retryTokens = $this->retryMaxOutputTokens($safeMaxOutputTokens);
            if ($retryTokens > $safeMaxOutputTokens) {
                $payload['max_output_tokens'] = $retryTokens;
                $retryAttempt = $this->executeOpenAIRequest($safeModel, $payload, $apiKey, 'retry_higher_tokens');
                if ((bool) ($retryAttempt['successful'] ?? false)) {
                    $attempt = $retryAttempt;
                    $json = is_array($attempt['json'] ?? null) ? $attempt['json'] : [];
                    $outputText = $this->extractOutputText($json);
                }
            }
        }

        if (! is_string($outputText) || $outputText === '') {
            Log::warning('OpenAI response missing output text; falling back.', [
                'model' => $safeModel,
                'status' => (string) ($json['status'] ?? 'unknown'),
                'incomplete_reason' => data_get($json, 'incomplete_details.reason'),
            ]);

            return $this->fallbackResponse(
                $promptPackage,
                'missing_output_text',
                [
                    'status' => (string) ($json['status'] ?? 'unknown'),
                    'incomplete_reason' => data_get($json, 'incomplete_details.reason'),
                ],
            );
        }

        $decoded = $this->decodeJsonPayload($outputText);

        if (! is_array($decoded)) {
            Log::warning('OpenAI response returned non-JSON output; falling back.', [
                'model' => $safeModel,
                'output_preview' => mb_substr($outputText, 0, 200),
            ]);

            return $this->fallbackResponse(
                $promptPackage,
                'non_json_output',
                ['output_preview' => mb_substr($outputText, 0, 200)],
            );
        }

        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        $decoded['_usage'] = [
            'input_tokens' => $this->intOrNull($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null),
            'output_tokens' => $this->intOrNull($usage['output_tokens'] ?? $usage['completion_tokens'] ?? null),
            'total_tokens' => $this->intOrNull($usage['total_tokens'] ?? null),
            'source' => $usedCompatibilityMode ? 'openai_compat' : 'openai',
            'mode' => $usedCompatibilityMode ? 'compatibility' : 'structured',
        ];

        return $decoded;
    }

    /**
     * @param array<string, mixed> $promptPackage
     * @return array<string, mixed>
     */
    private function fallbackResponse(array $promptPackage, string $reason = 'generic', array $meta = []): array
    {
        $contextProducts = data_get($promptPackage, 'context.products', []);
        $recommended = [];

        foreach ($contextProducts as $product) {
            if (isset($product['id'])) {
                $recommended[] = (int) $product['id'];
            }
            if (count($recommended) >= 3) {
                break;
            }
        }

        $answer = 'Mogu predloziti nekoliko opcija na osnovu dostupnog kataloga.';
        $ctaType = 'product_link';
        $ctaLabel = 'Pogledaj najbolje opcije';
        $leadCaptureSuggested = false;
        $detectedIntent = 'product_recommendation';
        $confidence = 0.62;

        if ($recommended !== []) {
            $answer = 'Na osnovu onoga sto trazis, izdvojio sam najrelevantnije opcije iz dostupnog kataloga.';
        } else {
            $answer = 'Trenutno ne vidim proizvod tog tipa u katalogu. Mogu ponuditi najblize alternative ako zelis.';
            $ctaType = 'clarify_need';
            $ctaLabel = 'Pokazi alternative';
            $leadCaptureSuggested = true;
            $detectedIntent = 'no_match';
            $confidence = 0.38;
        }

        return [
            'answer_text' => $answer,
            'recommended_product_ids' => $recommended,
            'cta_type' => $ctaType,
            'cta_label' => $ctaLabel,
            'needs_handoff' => false,
            'lead_capture_suggested' => $leadCaptureSuggested,
            'detected_intent' => $detectedIntent,
            'confidence' => $confidence,
            '_usage' => [
                'input_tokens' => null,
                'output_tokens' => null,
                'total_tokens' => null,
                'source' => sprintf('fallback:%s', $reason),
                'fallback_reason' => $reason,
                'fallback_meta' => $meta,
            ],
        ];
    }

    private function intOrNull(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function sanitizeMaxOutputTokens(int $requested): int
    {
        $minimum = 64;
        $default = max($minimum, (int) config('services.openai.default_max_output_tokens', 350));
        $ceiling = max($minimum, (int) config('services.openai.max_output_tokens_ceiling', 1200));

        if ($requested <= 0) {
            return min($default, $ceiling);
        }

        return min(max($requested, $minimum), $ceiling);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function executeOpenAIRequest(string $model, array $payload, string $apiKey, string $stage): array
    {
        $response = null;
        $lastConnectionError = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = Http::withToken($apiKey)
                    ->acceptJson()
                    ->timeout(45)
                    ->post('https://api.openai.com/v1/responses', $payload);

                $lastConnectionError = null;
                break;
            } catch (ConnectionException $exception) {
                $lastConnectionError = $exception;

                if ($attempt < 3) {
                    usleep(250000);
                }
            } catch (\Throwable $exception) {
                Log::warning('OpenAI response generation failed with transport/runtime error.', [
                    'model' => $model,
                    'stage' => $stage,
                    'error' => $exception->getMessage(),
                ]);

                return [
                    'successful' => false,
                    'status' => 0,
                    'body' => $exception->getMessage(),
                    'json' => null,
                    'stage' => $stage,
                ];
            }
        }

        if ($response === null) {
            $message = $lastConnectionError?->getMessage() ?? 'Connection failure.';
            Log::warning('OpenAI response generation failed with connection error after retries.', [
                'model' => $model,
                'stage' => $stage,
                'error' => $message,
            ]);

            return [
                'successful' => false,
                'status' => 0,
                'body' => $message,
                'json' => null,
                'stage' => $stage,
            ];
        }

        try {
            $body = (string) $response->body();
            $json = $response->json();
        } catch (\Throwable $exception) {
            $body = '';
            $json = null;
            Log::warning('OpenAI response parsing failed.', [
                'model' => $model,
                'stage' => $stage,
                'error' => $exception->getMessage(),
            ]);
        }

        if (! $response->successful()) {
            Log::warning('OpenAI response generation returned non-success status.', [
                'model' => $model,
                'stage' => $stage,
                'status' => $response->status(),
                'body' => $this->bodyPreview($body),
            ]);

            return [
                'successful' => false,
                'status' => $response->status(),
                'body' => $body,
                'json' => is_array($json) ? $json : null,
                'stage' => $stage,
            ];
        }

        return [
            'successful' => true,
            'status' => $response->status(),
            'body' => $body,
            'json' => is_array($json) ? $json : null,
            'stage' => $stage,
        ];
    }

    /**
     * @param array<string, mixed> $json
     */
    private function extractOutputText(array $json): ?string
    {
        $topLevel = data_get($json, 'output_text');
        if (is_string($topLevel) && trim($topLevel) !== '') {
            return trim($topLevel);
        }

        $output = $json['output'] ?? [];
        if (! is_array($output)) {
            return null;
        }

        foreach ($output as $item) {
            $content = is_array($item['content'] ?? null) ? $item['content'] : [];
            foreach ($content as $part) {
                if (! is_array($part)) {
                    continue;
                }

                foreach (['text', 'output_text', 'value'] as $key) {
                    $candidate = $part[$key] ?? null;
                    if (is_string($candidate) && trim($candidate) !== '') {
                        return trim($candidate);
                    }
                }

                if (is_array($part['text'] ?? null)) {
                    $candidate = $part['text']['value'] ?? null;
                    if (is_string($candidate) && trim($candidate) !== '') {
                        return trim($candidate);
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $json
     */
    private function shouldRetryWithHigherTokens(array $json): bool
    {
        $status = (string) ($json['status'] ?? '');
        $incompleteReason = (string) data_get($json, 'incomplete_details.reason', '');

        if ($status !== 'incomplete') {
            return false;
        }

        return in_array($incompleteReason, ['max_output_tokens', 'max_tokens'], true);
    }

    private function retryMaxOutputTokens(int $current): int
    {
        $minimum = 64;
        $ceiling = max($minimum, (int) config('services.openai.max_output_tokens_ceiling', 1200));
        $candidate = max($current + 200, $current * 2);

        return min($candidate, $ceiling);
    }

    /**
     * @param array<string, mixed> $promptPackage
     * @return array<int, array<string, mixed>>
     */
    private function buildStructuredInput(array $promptPackage): array
    {
        return [
            [
                'role' => 'system',
                'content' => [
                    ['type' => 'input_text', 'text' => (string) ($promptPackage['system'] ?? '')],
                ],
            ],
            [
                'role' => 'developer',
                'content' => [
                    ['type' => 'input_text', 'text' => (string) ($promptPackage['developer'] ?? '')],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => json_encode([
                        'message' => $promptPackage['user'] ?? '',
                        'context' => $promptPackage['context'] ?? [],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $promptPackage
     * @return array<string, mixed>
     */
    private function buildCompatibilityPayload(string $model, array $promptPackage, int $maxOutputTokens): array
    {
        $system = trim((string) ($promptPackage['system'] ?? ''));
        $developer = trim((string) ($promptPackage['developer'] ?? ''));
        $compatSystem = trim($system."\n\n".$developer."\n\n".
            'Vrati iskljucivo JSON objekat bez markdown-a i bez dodatnog teksta. Polja: answer_text, recommended_product_ids, cta_type, cta_label, needs_handoff, lead_capture_suggested, detected_intent, confidence.');

        return [
            'model' => $model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        ['type' => 'input_text', 'text' => $compatSystem],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => json_encode([
                            'message' => $promptPackage['user'] ?? '',
                            'context' => $promptPackage['context'] ?? [],
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                    ],
                ],
            ],
            'max_output_tokens' => $maxOutputTokens,
        ];
    }

    /**
     * @param array<string, mixed> $attempt
     */
    private function shouldRetryWithCompatibilityPayload(array $attempt): bool
    {
        $status = (int) ($attempt['status'] ?? 0);
        if (! in_array($status, [400, 422], true)) {
            return false;
        }

        $body = mb_strtolower((string) ($attempt['body'] ?? ''));
        if ($body === '') {
            return true;
        }

        foreach (['unsupported', 'temperature', 'json_schema', 'text.format', 'developer', 'invalid_parameter'] as $needle) {
            if (str_contains($body, $needle)) {
                return true;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonPayload(string $outputText): ?array
    {
        $decoded = json_decode(trim($outputText), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/isu', $outputText, $match) === 1) {
            $candidate = (string) ($match[1] ?? '');
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $start = mb_strpos($outputText, '{');
        $end = mb_strrpos($outputText, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidate = mb_substr($outputText, $start, $end - $start + 1);
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function bodyPreview(string $body): string
    {
        return mb_substr(trim($body), 0, 700);
    }
}
