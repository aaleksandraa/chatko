<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductEmbedding;
use App\Services\AI\EmbeddingGenerationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductEmbeddingService
{
    public function __construct(private readonly EmbeddingGenerationService $embeddingGenerationService)
    {
    }

    public function embed(Product $product, string $model = 'text-embedding-3-small'): ProductEmbedding
    {
        $attributesText = $this->flattenStructured($product->attributes_json);
        $specsText = $this->flattenStructured($product->specs_json);
        $tagsText = $this->flattenStructured($product->tags_json);

        $text = implode("\n", array_filter([
            $product->name,
            $product->short_description,
            $product->long_description,
            $product->category_text,
            $product->brand_text,
            $attributesText !== '' ? 'Attributes: '.$attributesText : null,
            $specsText !== '' ? 'Specs: '.$specsText : null,
            $tagsText !== '' ? 'Tags: '.$tagsText : null,
        ]));

        $embeddingModel = config('services.openai.embedding_model', $model);
        $vector = $this->embeddingGenerationService->embedText($text, $embeddingModel);

        $embedding = ProductEmbedding::query()->updateOrCreate(
            [
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
            ],
            [
                'embedding_model' => $embeddingModel,
                'embedded_text' => $text,
                'embedding_vector' => $vector,
                'embedded_at' => now(),
            ],
        );

        if ($this->canUsePgvector('product_embeddings', 'embedding_vector_pg')) {
            $this->syncPgvectorColumn($embedding->id, $vector);
        }

        return $embedding;
    }

    /**
     * @param array<int, float> $vector
     */
    private function vectorLiteral(array $vector): string
    {
        return '['.implode(',', array_map(static fn (float $v): string => (string) $v, $vector)).']';
    }

    /**
     * @param array<int, float> $vector
     */
    private function syncPgvectorColumn(int $embeddingId, array $vector): void
    {
        if (count($vector) !== $this->embeddingDimensions()) {
            DB::statement('UPDATE product_embeddings SET embedding_vector_pg = NULL WHERE id = ?', [$embeddingId]);

            return;
        }

        try {
            DB::statement(
                'UPDATE product_embeddings SET embedding_vector_pg = ?::vector WHERE id = ?',
                [$this->vectorLiteral($vector), $embeddingId],
            );
        } catch (QueryException) {
            DB::statement('UPDATE product_embeddings SET embedding_vector_pg = NULL WHERE id = ?', [$embeddingId]);
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

    private function flattenStructured(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        if (! is_array($value)) {
            return '';
        }

        $chunks = [];
        foreach ($value as $key => $item) {
            $itemText = $this->flattenStructured($item);
            if ($itemText === '') {
                continue;
            }

            if (is_string($key)) {
                $chunks[] = trim($key).': '.$itemText;
            } else {
                $chunks[] = $itemText;
            }
        }

        return trim(implode('; ', array_filter($chunks, static fn (string $chunk): bool => $chunk !== '')));
    }
}

