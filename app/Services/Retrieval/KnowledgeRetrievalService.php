<?php

namespace App\Services\Retrieval;

use App\Models\KnowledgeChunk;
use App\Models\Tenant;
use App\Services\AI\EmbeddingGenerationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class KnowledgeRetrievalService
{
    public function __construct(private readonly EmbeddingGenerationService $embeddingGenerationService)
    {
    }

    /**
     * @return Collection<int, KnowledgeChunk>
     */
    public function search(Tenant $tenant, string $queryText, int $limit = 4, ?array $queryEmbedding = null): Collection
    {
        $resolvedLimit = $this->resolvedLimit($limit);

        $embedding = $this->normalizeEmbedding($queryEmbedding);
        if ($embedding === []) {
            $embedding = $this->embeddingGenerationService->embedText(
                $queryText,
                config('services.openai.embedding_model', 'text-embedding-3-small'),
            );
        }

        if ($this->canUsePgvector($embedding)) {
            try {
                $semanticScores = $this->semanticScoresPgvector($tenant, $embedding, $resolvedLimit * 4);
            } catch (QueryException) {
                $semanticScores = $this->semanticScoresJson($tenant, $embedding, $resolvedLimit * 6);
            }
        } else {
            $semanticScores = $this->semanticScoresJson($tenant, $embedding, $resolvedLimit * 6);
        }

        if ($semanticScores !== []) {
            $minScore = $this->knowledgeMinScore();
            $chunks = KnowledgeChunk::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('id', array_keys($semanticScores))
                ->whereHas('document', function ($inner): void {
                    $inner->where('status', 'indexed')
                        ->where('ai_allowed', true)
                        ->where('internal_only', false)
                        ->where('visibility', '!=', 'disabled');
                })
                ->get()
                ->sortByDesc(function (KnowledgeChunk $chunk) use ($semanticScores): float {
                    return (float) ($semanticScores[$chunk->id] ?? 0.0);
                })
                ->filter(function (KnowledgeChunk $chunk) use ($semanticScores, $minScore): bool {
                    return (float) ($semanticScores[$chunk->id] ?? 0.0) >= $minScore;
                })
                ->values();

            $chunks = $this->applyContextBudget($chunks, $resolvedLimit, $this->knowledgeMaxContextChars());

            if ($chunks->isNotEmpty()) {
                return $chunks;
            }
        }

        // Lexical fallback when vectors are not available yet.
        $keywords = $this->extractKeywords($queryText);
        if ($keywords === []) {
            return collect();
        }

        $query = KnowledgeChunk::query()
            ->where('tenant_id', $tenant->id)
            ->whereHas('document', function ($inner): void {
                $inner->where('status', 'indexed')
                    ->where('ai_allowed', true)
                    ->where('internal_only', false)
                    ->where('visibility', '!=', 'disabled');
            });

        if ($keywords !== []) {
            $query->where(function ($inner) use ($keywords): void {
                foreach ($keywords as $keyword) {
                    $inner->orWhere('chunk_text', 'like', '%'.$keyword.'%');
                }
            });
        }

        $fallbackChunks = $query->limit($resolvedLimit * 3)->get();

        return $this->applyContextBudget($fallbackChunks, $resolvedLimit, $this->knowledgeMaxContextChars());
    }

    /**
     * @param array<int, float> $queryEmbedding
     * @return array<int, float>
     */
    private function semanticScoresPgvector(Tenant $tenant, array $queryEmbedding, int $limit): array
    {
        if ($queryEmbedding === []) {
            return [];
        }

        $vector = $this->vectorLiteral($queryEmbedding);

        $sql = <<<SQL
SELECT kc.id, 1 - (kc.embedding_vector_pg <=> ?::vector) AS semantic_score
FROM knowledge_chunks kc
JOIN knowledge_documents kd ON kd.id = kc.knowledge_document_id
WHERE kc.tenant_id = ?
  AND kd.status = 'indexed'
  AND kd.ai_allowed = true
  AND kd.internal_only = false
  AND kd.visibility <> 'disabled'
  AND kc.embedding_vector_pg IS NOT NULL
ORDER BY kc.embedding_vector_pg <=> ?::vector ASC
LIMIT ?
SQL;

        $rows = DB::select($sql, [$vector, $tenant->id, $vector, $limit]);

        $scores = [];
        foreach ($rows as $row) {
            $chunkId = (int) ($row->id ?? 0);
            if ($chunkId <= 0) {
                continue;
            }
            $scores[$chunkId] = max(0.0, min(1.0, (float) ($row->semantic_score ?? 0.0)));
        }

        return $scores;
    }

    /**
     * @param array<int, float> $queryEmbedding
     * @return array<int, float>
     */
    private function semanticScoresJson(Tenant $tenant, array $queryEmbedding, int $limit): array
    {
        if ($queryEmbedding === []) {
            return [];
        }

        $rows = KnowledgeChunk::query()
            ->where('tenant_id', $tenant->id)
            ->whereHas('document', function ($inner): void {
                $inner->where('status', 'indexed')
                    ->where('ai_allowed', true)
                    ->where('internal_only', false)
                    ->where('visibility', '!=', 'disabled');
            })
            ->limit($limit)
            ->get(['id', 'embedding_vector']);

        $scores = [];

        foreach ($rows as $row) {
            if (! is_array($row->embedding_vector)) {
                continue;
            }

            $scores[$row->id] = $this->cosineSimilarity($queryEmbedding, $row->embedding_vector);
        }

        return $scores;
    }

    /**
     * @param array<int, float> $a
     * @param array<int, float> $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $length = min(count($a), count($b));
        if ($length === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $length; $i++) {
            $va = (float) $a[$i];
            $vb = (float) $b[$i];
            $dot += $va * $vb;
            $normA += $va * $va;
            $normB += $vb * $vb;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * @param array<int, float> $vector
     */
    private function vectorLiteral(array $vector): string
    {
        return '['.implode(',', array_map(static fn ($v): string => (string) $v, $vector)).']';
    }

    private function canUsePgvector(array $queryEmbedding): bool
    {
        return DB::connection()->getDriverName() === 'pgsql'
            && Schema::hasColumn('knowledge_chunks', 'embedding_vector_pg')
            && count($queryEmbedding) === $this->embeddingDimensions();
    }

    private function embeddingDimensions(): int
    {
        return max(1, (int) config('services.openai.embedding_dimensions', 1536));
    }

    private function resolvedLimit(int $requestedLimit): int
    {
        $requested = max(1, $requestedLimit);
        $configured = max(1, (int) config('services.openai.rag.knowledge_max_chunks', 4));

        return min($requested, $configured);
    }

    private function knowledgeMinScore(): float
    {
        $score = (float) config('services.openai.rag.knowledge_min_score', 0.63);

        return max(0.0, min(1.0, $score));
    }

    private function knowledgeMaxContextChars(): int
    {
        return max(500, (int) config('services.openai.rag.knowledge_max_context_chars', 2600));
    }

    /**
     * @param Collection<int, KnowledgeChunk> $chunks
     * @return Collection<int, KnowledgeChunk>
     */
    private function applyContextBudget(Collection $chunks, int $limit, int $maxChars): Collection
    {
        $selected = collect();
        $usedChars = 0;

        foreach ($chunks as $chunk) {
            $chunkChars = mb_strlen(trim((string) $chunk->chunk_text));
            if ($chunkChars <= 0) {
                continue;
            }

            if ($selected->count() >= $limit) {
                break;
            }

            if ($selected->isNotEmpty() && ($usedChars + $chunkChars) > $maxChars) {
                continue;
            }

            $selected->push($chunk);
            $usedChars += $chunkChars;
        }

        return $selected->values();
    }

    /**
     * @param array<int, mixed>|null $embedding
     * @return array<int, float>
     */
    private function normalizeEmbedding(?array $embedding): array
    {
        if (! is_array($embedding) || $embedding === []) {
            return [];
        }

        $normalized = [];
        foreach ($embedding as $value) {
            if (! is_numeric($value)) {
                return [];
            }

            $normalized[] = (float) $value;
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function extractKeywords(string $text): array
    {
        $tokens = preg_split('/\s+/', strtolower($text)) ?: [];

        return array_values(array_unique(array_filter($tokens, static fn (string $token): bool => strlen(trim($token, ',.;:!?')) > 3)));
    }
}
