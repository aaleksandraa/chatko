<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbeddingGenerationService
{
    /**
     * @return array<int, float>
     */
    public function embedText(string $text, string $model = 'text-embedding-3-small'): array
    {
        $apiKey = config('services.openai.api_key');
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        if (! is_string($apiKey) || trim($apiKey) === '') {
            return [];
        }

        foreach ($this->embeddingModelCandidates($model) as $candidateModel) {
            $dimensions = $this->embeddingDimensions($candidateModel);
            $cacheKey = $this->embeddingCacheKey($candidateModel, $text, $dimensions);

            if ($cacheKey !== null) {
                $cached = Cache::get($cacheKey);

                if (is_array($cached) && count($cached) === $dimensions) {
                    return array_map(static fn ($value): float => (float) $value, $cached);
                }
            }

            try {
                $response = Http::withToken($apiKey)
                    ->acceptJson()
                    ->timeout(30)
                    ->retry(2, 250)
                    ->post('https://api.openai.com/v1/embeddings', [
                        'model' => $candidateModel,
                        'input' => $text,
                    ]);
            } catch (\Throwable $exception) {
                Log::warning('Embedding generation failed with transport/runtime error.', [
                    'model' => $candidateModel,
                    'error' => $exception->getMessage(),
                ]);
                continue;
            }

            if (! $response->successful()) {
                if (in_array($response->status(), [401, 403, 404], true)) {
                    Log::warning('Embedding generation returned non-success status.', [
                        'model' => $candidateModel,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
                continue;
            }

            $vector = data_get($response->json(), 'data.0.embedding');

            if (! is_array($vector)) {
                continue;
            }

            $normalized = array_map(static fn ($value): float => (float) $value, $vector);

            if (count($normalized) !== $dimensions) {
                continue;
            }

            if ($cacheKey !== null) {
                $ttl = max(60, (int) config('services.openai.query_embedding_cache_ttl_seconds', 86400));
                Cache::put($cacheKey, $normalized, now()->addSeconds($ttl));
            }

            return $normalized;
        }

        return [];
    }

    private function embeddingDimensions(string $model): int
    {
        $configuredModel = (string) config('services.openai.embedding_model', 'text-embedding-3-small');
        $configuredDimensions = max(1, (int) config('services.openai.embedding_dimensions', 1536));

        if ($model === $configuredModel) {
            return $configuredDimensions;
        }

        return match ($model) {
            'text-embedding-3-small' => 1536,
            'text-embedding-3-large' => 3072,
            'text-embedding-ada-002' => 1536,
            default => $configuredDimensions,
        };
    }

    private function embeddingCacheKey(string $model, string $text, int $dimensions): ?string
    {
        if (! (bool) config('services.openai.query_embedding_cache_enabled', true)) {
            return null;
        }

        if ($text === '') {
            return null;
        }

        $maxChars = max(40, (int) config('services.openai.query_embedding_cache_max_chars', 280));
        if (mb_strlen($text) > $maxChars) {
            return null;
        }

        return sprintf(
            'openai:embedding:%s:d%d:%s',
            $model,
            $dimensions,
            sha1(mb_strtolower($text)),
        );
    }

    /**
     * @return array<int, string>
     */
    private function embeddingModelCandidates(string $requestedModel): array
    {
        $requestedModel = trim($requestedModel);
        $configuredModel = trim((string) config('services.openai.embedding_model', 'text-embedding-3-small'));
        $configuredFallback = config('services.openai.embedding_fallback_models', []);
        $fallbackModels = is_array($configuredFallback) ? $configuredFallback : [];

        $candidates = array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            array_merge([$requestedModel, $configuredModel], $fallbackModels),
        )));

        if ($candidates === []) {
            return ['text-embedding-3-small'];
        }

        $baseDimensions = $this->embeddingDimensions($candidates[0]);
        $sameDimensions = array_values(array_filter($candidates, function (string $candidate) use ($baseDimensions): bool {
            return $this->embeddingDimensions($candidate) === $baseDimensions;
        }));

        if ($sameDimensions !== []) {
            return array_values(array_unique($sameDimensions));
        }

        return array_values(array_unique($candidates));
    }
}


