<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class EmbeddingGenerationService
{
    /**
     * @return array<int, float>
     */
    public function embedText(string $text, string $model = 'text-embedding-3-small'): array
    {
        $apiKey = config('services.openai.api_key');
        $text = trim($text);
        $dimensions = $this->embeddingDimensions($model);
        $cacheKey = $this->embeddingCacheKey($model, $text, $dimensions);

        if ($cacheKey !== null) {
            $cached = Cache::get($cacheKey);

            if (is_array($cached) && count($cached) === $dimensions) {
                return array_map(static fn ($value): float => (float) $value, $cached);
            }
        }

        if ($text === '') {
            return [];
        }

        if (! is_string($apiKey) || trim($apiKey) === '') {
            return [];
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 250)
            ->post('https://api.openai.com/v1/embeddings', [
                'model' => $model,
                'input' => $text,
            ]);

        if (! $response->successful()) {
            return [];
        }

        $vector = data_get($response->json(), 'data.0.embedding');

        if (! is_array($vector)) {
            return [];
        }

        $normalized = array_map(static fn ($value): float => (float) $value, $vector);

        if (count($normalized) !== $dimensions) {
            return [];
        }

        if ($cacheKey !== null) {
            $ttl = max(60, (int) config('services.openai.query_embedding_cache_ttl_seconds', 86400));
            Cache::put($cacheKey, $normalized, now()->addSeconds($ttl));
        }

        return $normalized;
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
}


