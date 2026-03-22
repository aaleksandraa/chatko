<?php

namespace App\Services\Retrieval;

use App\Models\Product;
use App\Models\ProductEmbedding;
use App\Models\Tenant;
use App\Services\AI\EmbeddingGenerationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductRetrievalService
{
    public function __construct(private readonly EmbeddingGenerationService $embeddingGenerationService)
    {
    }

    /**
     * @return Collection<int, Product>
     */
    public function search(Tenant $tenant, string $queryText, array $entities = [], int $limit = 5, ?array $queryEmbedding = null): Collection
    {
        $query = Product::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->where('in_stock', true);

        $budgetMax = $entities['budget_max'] ?? null;
        if (is_numeric($budgetMax)) {
            $query->where('price', '<=', (float) $budgetMax);
        }

        $category = $entities['category'] ?? null;
        if (is_string($category) && $category !== '') {
            $categoryTerms = $this->categorySearchTerms($category);
            $query->where(function ($inner) use ($categoryTerms): void {
                foreach ($categoryTerms as $term) {
                    $inner->orWhere('category_text', 'like', '%'.$term.'%')
                        ->orWhere('name', 'like', '%'.$term.'%')
                        ->orWhere('short_description', 'like', '%'.$term.'%')
                        ->orWhere('long_description', 'like', '%'.$term.'%');
                }
            });
        }

        $keywords = $this->extractKeywords($queryText);

        if ($keywords !== []) {
            $query->where(function ($inner) use ($keywords): void {
                foreach ($keywords as $keyword) {
                    $inner->orWhere('name', 'like', '%'.$keyword.'%')
                        ->orWhere('short_description', 'like', '%'.$keyword.'%')
                        ->orWhere('long_description', 'like', '%'.$keyword.'%')
                        ->orWhere('category_text', 'like', '%'.$keyword.'%');
                }
            });
        }

        $products = $query->limit(max($limit * 5, 20))->get();
        if ($products->isEmpty()) {
            return collect();
        }

        $embedding = $this->normalizeEmbedding($queryEmbedding);
        if ($embedding === []) {
            $embedding = $this->embeddingGenerationService->embedText(
                $queryText,
                config('services.openai.embedding_model', 'text-embedding-3-small'),
            );
        }

        $semanticScores = $this->semanticScores($tenant, $products->pluck('id')->all(), $embedding);

        $scored = $products->map(function (Product $product) use ($entities, $semanticScores, $keywords): array {
            $semanticScore = $semanticScores[$product->id] ?? 0.0;
            $keywordMatches = $this->keywordMatchCount($product, $keywords);

            return [
                'product' => $product,
                'score' => $this->scoreProduct($product, $entities, $semanticScore, $keywordMatches),
                'semantic_score' => $semanticScore,
                'keyword_matches' => $keywordMatches,
            ];
        });

        $relevant = $scored
            ->filter(fn (array $row): bool => $this->isRelevantScoredRow($row))
            ->values();

        $pool = $relevant->isNotEmpty() ? $relevant : $scored->values();

        return $pool
            ->sortByDesc('score')
            ->take($limit)
            ->map(fn (array $row): Product => $row['product'])
            ->values();
    }

    /**
     * @param array<int, int> $productIds
     * @param array<int, float> $queryEmbedding
     * @return array<int, float>
     */
    private function semanticScores(Tenant $tenant, array $productIds, array $queryEmbedding): array
    {
        if ($productIds === [] || $queryEmbedding === []) {
            return [];
        }

        if ($this->canUsePgvector($queryEmbedding)) {
            try {
                return $this->semanticScoresPgvector($tenant, $productIds, $queryEmbedding);
            } catch (QueryException) {
                return $this->semanticScoresJson($tenant, $productIds, $queryEmbedding);
            }
        }

        return $this->semanticScoresJson($tenant, $productIds, $queryEmbedding);
    }

    /**
     * @param array<int, int> $productIds
     * @param array<int, float> $queryEmbedding
     * @return array<int, float>
     */
    private function semanticScoresPgvector(Tenant $tenant, array $productIds, array $queryEmbedding): array
    {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $vector = $this->vectorLiteral($queryEmbedding);

        $sql = <<<SQL
SELECT pe.product_id, 1 - (pe.embedding_vector_pg <=> ?::vector) AS semantic_score
FROM product_embeddings pe
WHERE pe.tenant_id = ?
  AND pe.product_id IN ($placeholders)
  AND pe.embedding_vector_pg IS NOT NULL
ORDER BY pe.embedding_vector_pg <=> ?::vector ASC
SQL;

        $bindings = array_merge([$vector, $tenant->id], $productIds, [$vector]);
        $rows = DB::select($sql, $bindings);

        $scores = [];
        foreach ($rows as $row) {
            $productId = (int) ($row->product_id ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $scores[$productId] = max(0.0, min(1.0, (float) ($row->semantic_score ?? 0.0)));
        }

        return $scores;
    }

    /**
     * @param array<int, int> $productIds
     * @param array<int, float> $queryEmbedding
     * @return array<int, float>
     */
    private function semanticScoresJson(Tenant $tenant, array $productIds, array $queryEmbedding): array
    {
        $scores = [];

        $embeddings = ProductEmbedding::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'embedding_vector']);

        foreach ($embeddings as $embedding) {
            $vector = $embedding->embedding_vector;
            if (! is_array($vector)) {
                continue;
            }

            $scores[(int) $embedding->product_id] = $this->cosineSimilarity($queryEmbedding, $vector);
        }

        return $scores;
    }

    private function scoreProduct(Product $product, array $entities, float $semanticScore, int $keywordMatches): float
    {
        $score = 0.0;

        $score += ($keywordMatches * 1.25);

        if (($entities['budget_max'] ?? null) !== null && (float) $product->price <= (float) $entities['budget_max']) {
            $score += 1.0;
        }

        if ($product->in_stock) {
            $score += 0.75;
        }

        if ($product->sale_price !== null && (float) $product->sale_price > 0) {
            $score += 0.35;
        }

        // Hybrid weighting: lexical/business score + semantic relevance.
        $score += ($semanticScore * 2.25);

        return $score;
    }

    /**
     * @param array<int, string> $keywords
     */
    private function keywordMatchCount(Product $product, array $keywords): int
    {
        if ($keywords === []) {
            return 0;
        }

        $combined = mb_strtolower((string) ($product->name.' '.$product->short_description.' '.$product->category_text));
        $matches = 0;

        foreach ($keywords as $keyword) {
            if ($keyword !== '' && str_contains($combined, $keyword)) {
                $matches++;
            }
        }

        return $matches;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isRelevantScoredRow(array $row): bool
    {
        $keywordMatches = (int) ($row['keyword_matches'] ?? 0);
        if ($keywordMatches > 0) {
            return true;
        }

        $semanticScore = (float) ($row['semantic_score'] ?? 0.0);

        return $semanticScore >= $this->minSemanticScore();
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
            && Schema::hasColumn('product_embeddings', 'embedding_vector_pg')
            && count($queryEmbedding) === $this->embeddingDimensions();
    }

    private function embeddingDimensions(): int
    {
        return max(1, (int) config('services.openai.embedding_dimensions', 1536));
    }

    private function minSemanticScore(): float
    {
        $score = (float) config('services.openai.rag.products_min_semantic_score', 0.48);

        return max(0.0, min(1.0, $score));
    }

    /**
     * @return array<int, string>
     */
    private function categorySearchTerms(string $category): array
    {
        $normalized = trim(mb_strtolower($category));
        if ($normalized === '') {
            return [];
        }

        $terms = [$normalized];
        foreach ($this->extractKeywords($normalized) as $token) {
            $terms[] = $token;

            $stem = $this->stemCategoryToken($token);
            if ($stem !== $token && mb_strlen($stem) >= 3) {
                $terms[] = $stem;
            }
        }

        return array_values(array_unique(array_filter($terms, static fn (string $term): bool => $term !== '')));
    }

    private function stemCategoryToken(string $token): string
    {
        $normalized = trim(mb_strtolower($token));
        if (mb_strlen($normalized) <= 3) {
            return $normalized;
        }

        $suffix = mb_substr($normalized, -1);
        if (in_array($suffix, ['a', 'e', 'i', 'o', 'u'], true)) {
            return mb_substr($normalized, 0, mb_strlen($normalized) - 1);
        }

        return $normalized;
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
        $tokens = preg_split('/\s+/', mb_strtolower($text)) ?: [];
        $stopWords = ['i', 'ili', 'za', 'od', 'do', 'mi', 'je', 'na', 'u', 'sa', 'treba', 'nesto', 'sto', 'neko', 'neka', 'neki', 'neku', 'nekog'];

        return array_values(array_unique(array_filter($tokens, function (string $token) use ($stopWords): bool {
            $clean = trim($token, " \t\n\r\0\x0B,.;:!?()[]{}\"'");
            return strlen($clean) > 2 && ! in_array($clean, $stopWords, true);
        })));
    }
}
