<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeChunk;
use App\Services\AI\EmbeddingGenerationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class KnowledgeEmbeddingService
{
    public function __construct(private readonly EmbeddingGenerationService $embeddingGenerationService)
    {
    }

    public function embed(KnowledgeChunk $chunk, string $model = 'text-embedding-3-small'): KnowledgeChunk
    {
        $embeddingModel = config('services.openai.embedding_model', $model);
        $chunk->embedding_model = $embeddingModel;
        $chunk->embedding_vector = $this->embeddingGenerationService->embedText($chunk->chunk_text, $embeddingModel);
        $chunk->save();

        if ($this->canUsePgvector('knowledge_chunks', 'embedding_vector_pg')) {
            $this->syncPgvectorColumn($chunk->id, $chunk->embedding_vector ?? []);
        }

        return $chunk;
    }

    /**
     * @return array<int, float>
     */
    private function vectorLiteral(array $vector): string
    {
        return '['.implode(',', array_map(static fn ($v): string => (string) $v, $vector)).']';
    }

    /**
     * @param array<int, float|int> $vector
     */
    private function syncPgvectorColumn(int $chunkId, array $vector): void
    {
        if (count($vector) !== $this->embeddingDimensions()) {
            DB::statement('UPDATE knowledge_chunks SET embedding_vector_pg = NULL WHERE id = ?', [$chunkId]);

            return;
        }

        try {
            DB::statement(
                'UPDATE knowledge_chunks SET embedding_vector_pg = ?::vector WHERE id = ?',
                [$this->vectorLiteral(array_map(static fn ($v): float => (float) $v, $vector)), $chunkId],
            );
        } catch (QueryException) {
            DB::statement('UPDATE knowledge_chunks SET embedding_vector_pg = NULL WHERE id = ?', [$chunkId]);
        }
    }

    private function embeddingDimensions(): int
    {
        return max(1, (int) config('services.openai.embedding_dimensions', 1536));
    }

    private function canUsePgvector(string $table, string $column): bool
    {
        return DB::connection()->getDriverName() === 'pgsql' && Schema::hasColumn($table, $column);
    }
}

