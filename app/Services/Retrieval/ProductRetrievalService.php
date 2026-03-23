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
        $queryProfile = $this->buildQueryProfile($queryText);

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
                    $needle = '%'.mb_strtolower($term).'%';
                    $inner->orWhere('category_text', 'like', '%'.$term.'%')
                        ->orWhere('name', 'like', '%'.$term.'%')
                        ->orWhere('short_description', 'like', '%'.$term.'%')
                        ->orWhere('long_description', 'like', '%'.$term.'%')
                        ->orWhereRaw('LOWER(CAST(tags_json AS TEXT)) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(CAST(attributes_json AS TEXT)) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(CAST(specs_json AS TEXT)) LIKE ?', [$needle]);
                }
            });
        }

        $keywords = $this->extractKeywords($queryText);
        $keywords = array_values(array_unique(array_merge($keywords, $this->expandedKeywords($queryProfile))));

        if ($keywords !== []) {
            $query->where(function ($inner) use ($keywords): void {
                foreach ($keywords as $keyword) {
                    $needle = '%'.mb_strtolower($keyword).'%';
                    $inner->orWhere('name', 'like', '%'.$keyword.'%')
                        ->orWhere('short_description', 'like', '%'.$keyword.'%')
                        ->orWhere('long_description', 'like', '%'.$keyword.'%')
                        ->orWhere('category_text', 'like', '%'.$keyword.'%')
                        ->orWhereRaw('LOWER(CAST(tags_json AS TEXT)) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(CAST(attributes_json AS TEXT)) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(CAST(specs_json AS TEXT)) LIKE ?', [$needle]);
                }
            });
        }

        $products = $query->limit(max($limit * 5, 20))->get();
        $products = $this->applyQueryProfileFilter($products, $queryProfile);

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

        $scored = $products->map(function (Product $product) use ($entities, $semanticScores, $keywords, $queryProfile): array {
            $semanticScore = $semanticScores[$product->id] ?? 0.0;
            $keywordMatches = $this->keywordMatchCount($product, $keywords);

            return [
                'product' => $product,
                'score' => $this->scoreProduct($product, $entities, $semanticScore, $keywordMatches, $queryProfile),
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

    private function scoreProduct(Product $product, array $entities, float $semanticScore, int $keywordMatches, array $queryProfile = []): float
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

        $score += ($semanticScore * 2.25);

        if (($queryProfile['hair_loss'] ?? false) === true) {
            if ($this->isAccessoryProduct($product)) {
                $score -= 4.0;
            }

            if ($this->isHairLossTreatmentProduct($product)) {
                $score += 2.5;
            }
        }

        if (($queryProfile['oily_hair'] ?? false) === true) {
            if ($this->isAccessoryProduct($product)) {
                $score -= 3.5;
            }

            if ($this->isOilyHairProduct($product)) {
                $score += 2.4;
            }

            if ($this->isDryHairOnlyProduct($product)) {
                $score -= 1.2;
            }
        }

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

        $combined = $this->normalizedProductText($product);
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

        foreach (['ovima', 'evima', 'anje', 'enje', 'osti', 'skom', 'skoj', 'skih', 'ama', 'ima', 'om', 'oj', 'og', 'eg', 'im', 'ih', 'na', 'nu', 'ne', 'ni', 'no', 'a', 'e', 'i', 'o', 'u'] as $suffix) {
            if (str_ends_with($normalized, $suffix)) {
                $candidate = mb_substr($normalized, 0, mb_strlen($normalized) - mb_strlen($suffix));
                if (mb_strlen($candidate) >= 3) {
                    return $candidate;
                }
            }
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
        $stopWords = [
            'i', 'ili', 'za', 'od', 'do', 'mi', 'je', 'na', 'u', 'sa',
            'treba', 'nesto', 'sto', 'neko', 'neka', 'neki', 'neku', 'nekog',
            'mogu', 'li', 'moze', 'mozemo', 'da',
            'kupi', 'kupim', 'kupiti',
            'naruci', 'naruciti', 'narudzba', 'narudzbu',
            'poruciti', 'porudzbina',
            'checkout', 'korpa',
        ];

        $keywords = [];
        foreach ($tokens as $token) {
            $clean = trim($token, " \t\n\r\0\x0B,.;:!?()[]{}\"'");
            if ($clean === '') {
                continue;
            }

            foreach ($this->keywordVariants($clean) as $variant) {
                if (mb_strlen($variant) <= 2 || in_array($variant, $stopWords, true)) {
                    continue;
                }

                $keywords[] = $variant;
            }
        }

        return array_values(array_unique($keywords));
    }

    /**
     * @param array{hair_loss: bool, oily_hair: bool} $queryProfile
     * @return array<int, string>
     */
    private function expandedKeywords(array $queryProfile): array
    {
        $keywords = [];

        if (($queryProfile['hair_loss'] ?? false) === true) {
            $keywords = array_merge($keywords, [
                'opadanje', 'anti hair loss', 'hair loss', 'serum', 'ampula', 'sampon', 'vlasiste',
            ]);
        }

        if (($queryProfile['oily_hair'] ?? false) === true) {
            $keywords = array_merge($keywords, [
                'masna', 'masnu', 'masno', 'oily', 'sebum', 'seboreja',
                'clarifying', 'deep clean', 'dubinsko ciscenje', 'vlasiste', 'scalp', 'sampon',
            ]);
        }

        $normalized = [];
        foreach ($keywords as $keyword) {
            foreach ($this->keywordVariants($keyword) as $variant) {
                if (mb_strlen($variant) >= 3) {
                    $normalized[] = $variant;
                }
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array<int, string>
     */
    private function keywordVariants(string $token): array
    {
        $normalized = trim(mb_strtolower($token));
        if ($normalized === '') {
            return [];
        }

        $variants = [$normalized];

        $ascii = $this->asciiFold($normalized);
        if ($ascii !== $normalized) {
            $variants[] = $ascii;
        }

        foreach ([$normalized, $ascii] as $candidate) {
            $stem = $this->stemCategoryToken($candidate);
            if ($stem !== '' && mb_strlen($stem) >= 3) {
                $variants[] = $stem;
            }
        }

        return array_values(array_unique(array_filter($variants, static fn (string $item): bool => $item !== '')));
    }

    private function asciiFold(string $value): string
    {
        return strtr($value, [
            'č' => 'c',
            'ć' => 'c',
            'š' => 's',
            'ž' => 'z',
            'đ' => 'dj',
        ]);
    }

    /**
     * @return array{hair_loss: bool, oily_hair: bool}
     */
    private function buildQueryProfile(string $queryText): array
    {
        $text = mb_strtolower($queryText);

        $hasHair = preg_match('/\b(kosa|kose|kosu|kosi|vlasi|vlasiste|vlasište|skalp|skalpa)\b/u', $text) === 1;
        $hasHairLossSignal = preg_match('/\b(opadanje|opadanja|opada|ispadanje|ispadanja|alopecij)\b/u', $text) === 1;
        $hasOilyHairSignal = preg_match('/\b(masna|masnu|masne|masno|masnoc|masnoca|oily|sebum|seborej|seborr|masti)\b/u', $text) === 1;

        return [
            'hair_loss' => $hasHair && $hasHairLossSignal,
            'oily_hair' => $hasHair && $hasOilyHairSignal,
        ];
    }

    /**
     * @param Collection<int, Product> $products
     * @param array{hair_loss: bool, oily_hair: bool} $queryProfile
     * @return Collection<int, Product>
     */
    private function applyQueryProfileFilter(Collection $products, array $queryProfile): Collection
    {
        if (($queryProfile['hair_loss'] ?? false) === true) {
            $nonAccessory = $products->reject(fn (Product $product): bool => $this->isAccessoryProduct($product))->values();
            $treatments = $nonAccessory->filter(fn (Product $product): bool => $this->isHairLossTreatmentProduct($product))->values();

            if ($treatments->isNotEmpty()) {
                return $treatments;
            }

            if ($nonAccessory->isNotEmpty()) {
                return $nonAccessory;
            }

            return collect();
        }

        if (($queryProfile['oily_hair'] ?? false) === true) {
            $nonAccessory = $products->reject(fn (Product $product): bool => $this->isAccessoryProduct($product))->values();
            $oilyProducts = $nonAccessory->filter(fn (Product $product): bool => $this->isOilyHairProduct($product))->values();

            if ($oilyProducts->isNotEmpty()) {
                return $oilyProducts;
            }

            if ($nonAccessory->isNotEmpty()) {
                return $nonAccessory;
            }

            return collect();
        }

        return $products;
    }

    private function isAccessoryProduct(Product $product): bool
    {
        $text = $this->normalizedProductText($product);
        $signals = [
            'cetka', 'četka', 'brush',
            'cesalj', 'češalj', 'comb',
            'figaro', 'presa', 'fen',
            'traka', 'gumica', 'rajf',
            'ukosnica', 'ukosnice', 'spanga', 'španga',
            'dodatak za kosu', 'pribor za kosu',
        ];

        foreach ($signals as $signal) {
            if (str_contains($text, $signal)) {
                return true;
            }
        }

        return false;
    }

    private function isHairLossTreatmentProduct(Product $product): bool
    {
        $text = $this->normalizedProductText($product);
        $signals = [
            'opadanje', 'anti hair loss', 'hair loss', 'protiv opadanja',
            'rast kose', 'growth', 'serum', 'ampula', 'ampule', 'ampul',
            'sampon', 'šampon', 'losion', 'tonik', 'tretman',
            'vlasiste', 'vlasište', 'scalp',
            'kofein', 'caffeine', 'biotin', 'anaphase', 'genesis',
        ];

        foreach ($signals as $signal) {
            if (str_contains($text, $signal)) {
                return true;
            }
        }

        return false;
    }

    private function isOilyHairProduct(Product $product): bool
    {
        $text = $this->normalizedProductText($product);
        $signals = [
            'masna kosa', 'za masnu kosu', 'masno vlasiste', 'masno vlasište',
            'anti sebum', 'sebum', 'seboreja', 'seborr', 'oily scalp',
            'clarifying', 'deep clean', 'dubinsko ciscenje', 'dubinsko čišćenje',
            'balans vlaznosti', 'balans masnoce', 'sampon za masnu',
        ];

        foreach ($signals as $signal) {
            if (str_contains($text, $signal)) {
                return true;
            }
        }

        return false;
    }

    private function isDryHairOnlyProduct(Product $product): bool
    {
        $text = $this->normalizedProductText($product);
        $signals = [
            'suha kosa', 'za suhu kosu', 'dry hair', 'hydrating', 'repair', 'ultra hydration',
        ];

        foreach ($signals as $signal) {
            if (str_contains($text, $signal)) {
                return true;
            }
        }

        return false;
    }

    private function normalizedProductText(Product $product): string
    {
        $attributesText = $this->flattenStructured($product->attributes_json);
        $specsText = $this->flattenStructured($product->specs_json);
        $tagsText = $this->flattenStructured($product->tags_json);

        return mb_strtolower(trim(implode(' ', array_filter([
            (string) $product->name,
            (string) ($product->short_description ?? ''),
            (string) ($product->long_description ?? ''),
            (string) ($product->category_text ?? ''),
            (string) ($product->brand_text ?? ''),
            $attributesText,
            $specsText,
            $tagsText,
        ]))));
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
                $chunks[] = trim($key).' '.$itemText;
            } else {
                $chunks[] = $itemText;
            }
        }

        return trim(implode(' ', array_filter($chunks, static fn (string $chunk): bool => $chunk !== '')));
    }
}

