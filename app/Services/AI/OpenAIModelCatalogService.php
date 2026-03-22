<?php

namespace App\Services\AI;

class OpenAIModelCatalogService
{
    /**
     * @return array<int, string>
     */
    public function allowedChatModels(): array
    {
        $configured = config('services.openai.allowed_chat_models', []);
        if (! is_array($configured)) {
            return [];
        }

        return $this->sanitizeModelList($configured);
    }

    /**
     * @return array<int, string>
     */
    public function allowedEmbeddingModels(): array
    {
        $configured = config('services.openai.allowed_embedding_models', []);
        if (! is_array($configured)) {
            return [];
        }

        return $this->sanitizeModelList($configured);
    }

    public function defaultChatModel(): string
    {
        $configuredDefault = trim((string) config('services.openai.default_model', 'gpt-5-mini'));
        $allowed = $this->allowedChatModels();

        if ($allowed === []) {
            return $configuredDefault !== '' ? $configuredDefault : 'gpt-5-mini';
        }

        if ($configuredDefault !== '' && in_array($configuredDefault, $allowed, true)) {
            return $configuredDefault;
        }

        $resolvedAlias = $this->resolveAliasCandidate($configuredDefault, $allowed);
        if ($resolvedAlias !== null) {
            return $resolvedAlias;
        }

        return $allowed[0];
    }

    public function defaultEmbeddingModel(): string
    {
        $configuredDefault = trim((string) config('services.openai.embedding_model', 'text-embedding-3-small'));
        $allowed = $this->allowedEmbeddingModels();

        if ($allowed === []) {
            return $configuredDefault !== '' ? $configuredDefault : 'text-embedding-3-small';
        }

        if ($configuredDefault !== '' && in_array($configuredDefault, $allowed, true)) {
            return $configuredDefault;
        }

        $resolvedAlias = $this->resolveAliasCandidate($configuredDefault, $allowed);
        if ($resolvedAlias !== null) {
            return $resolvedAlias;
        }

        return $allowed[0];
    }

    public function normalizeChatModel(?string $requested): string
    {
        return $this->normalizeRequestedModel($requested, $this->allowedChatModels(), $this->defaultChatModel());
    }

    public function normalizeEmbeddingModel(?string $requested): string
    {
        return $this->normalizeRequestedModel($requested, $this->allowedEmbeddingModels(), $this->defaultEmbeddingModel());
    }

    /**
     * @param array<int, mixed> $models
     * @return array<int, string>
     */
    private function sanitizeModelList(array $models): array
    {
        return array_values(array_unique(array_values(array_filter(array_map(
            static fn (mixed $model): string => trim((string) $model),
            $models,
        )))));
    }

    /**
     * @param array<int, string> $allowed
     */
    private function normalizeRequestedModel(?string $requested, array $allowed, string $fallback): string
    {
        $candidate = trim((string) ($requested ?? ''));
        if ($candidate === '') {
            return $fallback;
        }

        if ($allowed === []) {
            return $candidate;
        }

        if (in_array($candidate, $allowed, true)) {
            return $candidate;
        }

        $resolvedAlias = $this->resolveAliasCandidate($candidate, $allowed);
        if ($resolvedAlias !== null) {
            return $resolvedAlias;
        }

        return $fallback;
    }

    /**
     * @param array<int, string> $allowed
     */
    private function resolveAliasCandidate(string $candidate, array $allowed): ?string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return null;
        }

        $prefixMatches = array_values(array_filter($allowed, static function (string $item) use ($candidate): bool {
            return str_starts_with($item, $candidate.'-');
        }));

        if ($prefixMatches === []) {
            return null;
        }

        usort($prefixMatches, static fn (string $a, string $b): int => strcmp($b, $a));

        return $prefixMatches[0] ?? null;
    }
}

