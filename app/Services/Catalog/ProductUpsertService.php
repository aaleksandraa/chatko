<?php

namespace App\Services\Catalog;

use App\Models\IntegrationConnection;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

class ProductUpsertService
{
    public function __construct(
        private readonly ProductNormalizerService $normalizer,
        private readonly ProductValidationService $validator,
        private readonly ProductEmbeddingService $embeddingService,
    ) {
    }

    /**
     * @return array{ok: bool, product: ?Product, errors: array<int, string>}
     */
    public function upsert(Tenant $tenant, array $payload, string $sourceType = 'manual', ?IntegrationConnection $connection = null): array
    {
        $normalized = $this->normalizer->normalize($payload);
        $validation = $this->validator->validate($normalized);

        if (! $validation['ok']) {
            return [
                'ok' => false,
                'product' => null,
                'errors' => $validation['errors'],
            ];
        }

        $query = Product::query()->where('tenant_id', $tenant->id);

        if (! empty($normalized['external_id'])) {
            $query->where('source_type', $sourceType)
                ->where('external_id', $normalized['external_id']);
        } elseif (! empty($normalized['sku'])) {
            $query->where('sku', $normalized['sku']);
        } else {
            $query->where('name', $normalized['name']);
        }

        $product = $query->first();

        if ($product === null) {
            $product = new Product();
            $product->tenant_id = $tenant->id;
            $product->source_type = $sourceType;
            $product->source_connection_id = $connection?->id;
        }

        $product->fill($normalized);
        $product->last_synced_at = Carbon::now();
        $product->save();
        $this->embeddingService->embed($product);

        return [
            'ok' => true,
            'product' => $product,
            'errors' => [],
        ];
    }
}
