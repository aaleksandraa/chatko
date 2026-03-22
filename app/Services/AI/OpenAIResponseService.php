<?php

namespace App\Services\AI;

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
            return $this->fallbackResponse($promptPackage);
        }

        $input = [
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

        $attempt = $this->executeOpenAIRequest($safeModel, $payload, $apiKey);
        if (! is_array($attempt)) {
            return $this->fallbackResponse($promptPackage);
        }

        $json = is_array($attempt['json'] ?? null) ? $attempt['json'] : [];
        $outputText = $this->extractOutputText($json);

        if ((! is_string($outputText) || $outputText === '') && $this->shouldRetryWithHigherTokens($json)) {
            $retryTokens = $this->retryMaxOutputTokens($safeMaxOutputTokens);
            if ($retryTokens > $safeMaxOutputTokens) {
                $payload['max_output_tokens'] = $retryTokens;
                $retryAttempt = $this->executeOpenAIRequest($safeModel, $payload, $apiKey);
                if (is_array($retryAttempt)) {
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

            return $this->fallbackResponse($promptPackage);
        }

        $decoded = json_decode($outputText, true);

        if (! is_array($decoded)) {
            Log::warning('OpenAI response returned non-JSON output; falling back.', [
                'model' => $safeModel,
                'output_preview' => mb_substr($outputText, 0, 200),
            ]);

            return $this->fallbackResponse($promptPackage);
        }

        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        $decoded['_usage'] = [
            'input_tokens' => $this->intOrNull($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null),
            'output_tokens' => $this->intOrNull($usage['output_tokens'] ?? $usage['completion_tokens'] ?? null),
            'total_tokens' => $this->intOrNull($usage['total_tokens'] ?? null),
            'source' => 'openai',
        ];

        return $decoded;
    }

    /**
     * @param array<string, mixed> $promptPackage
     * @return array<string, mixed>
     */
    private function fallbackResponse(array $promptPackage): array
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
                'source' => 'fallback',
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
     * @return array<string, mixed>|null
     */
    private function executeOpenAIRequest(string $model, array $payload, string $apiKey): ?array
    {
        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(45)
                ->retry(2, 250)
                ->post('https://api.openai.com/v1/responses', $payload);
        } catch (\Throwable $exception) {
            Log::warning('OpenAI response generation failed with transport/runtime error.', [
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            if (in_array($response->status(), [401, 403, 404], true)) {
                Log::warning('OpenAI response generation returned non-success status.', [
                    'model' => $model,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            return null;
        }

        return [
            'json' => $response->json(),
        ];
    }

    /**
     * @param array<string, mixed> $json
     */
    private function extractOutputText(array $json): ?string
    {
        $text = data_get($json, 'output.0.content.0.text')
            ?? data_get($json, 'output_text')
            ?? null;

        return is_string($text) ? $text : null;
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
}
