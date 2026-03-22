<?php

namespace App\Services\Catalog;

class ProductNormalizerService
{
    public function normalize(array $input): array
    {
        $price = $this->toDecimal($input['price'] ?? $input['regular_price'] ?? null);
        $salePrice = $this->toDecimal($input['sale_price'] ?? $input['salePrice'] ?? null);
        $stockQty = isset($input['stock_qty']) ? (int) $input['stock_qty'] : (isset($input['stock_quantity']) ? (int) $input['stock_quantity'] : null);

        $inStock = $input['in_stock']
            ?? $input['inStock']
            ?? (isset($input['stock_status']) ? in_array((string) $input['stock_status'], ['instock', 'in_stock', 'available'], true) : null)
            ?? ($stockQty === null ? true : $stockQty > 0);

        $externalId = $input['external_id'] ?? $input['id'] ?? $input['product_id'] ?? null;
        $name = trim((string) ($input['name'] ?? $input['title'] ?? ''));

        return [
            'external_id' => $externalId ? (string) $externalId : null,
            'sku' => isset($input['sku']) ? (string) $input['sku'] : null,
            'slug' => isset($input['slug']) ? (string) $input['slug'] : null,
            'name' => $name,
            'short_description' => $this->stringOrNull($input['short_description'] ?? $input['excerpt'] ?? null),
            'long_description' => $this->stringOrNull($input['long_description'] ?? $input['description'] ?? null),
            'price' => $price,
            'sale_price' => $salePrice,
            'currency' => (string) ($input['currency'] ?? 'BAM'),
            'stock_qty' => $stockQty,
            'in_stock' => (bool) $inStock,
            'availability_label' => $this->stringOrNull($input['availability_label'] ?? $input['stock_status'] ?? null),
            'product_url' => $this->stringOrNull($input['product_url'] ?? $input['url'] ?? $input['permalink'] ?? null),
            'primary_image_url' => $this->resolvePrimaryImage($input),
            'category_text' => $this->stringOrNull($input['category_text'] ?? $input['category'] ?? null),
            'brand_text' => $this->stringOrNull($input['brand_text'] ?? $input['brand'] ?? null),
            'attributes_json' => $this->arrayOrNull($input['attributes_json'] ?? $input['attributes'] ?? null),
            'specs_json' => $this->arrayOrNull($input['specs_json'] ?? $input['specs'] ?? null),
            'tags_json' => $this->arrayOrNull($input['tags_json'] ?? $input['tags'] ?? null),
            'status' => $this->normalizeStatus((string) ($input['status'] ?? 'active')),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function toDecimal(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return (float) str_replace(',', '.', (string) $value);
    }

    private function resolvePrimaryImage(array $input): ?string
    {
        if (isset($input['primary_image_url'])) {
            return $this->stringOrNull($input['primary_image_url']);
        }

        if (isset($input['image_url'])) {
            return $this->stringOrNull($input['image_url']);
        }

        $images = $input['images'] ?? null;

        if (is_array($images) && isset($images[0])) {
            $first = $images[0];

            if (is_array($first) && isset($first['src'])) {
                return $this->stringOrNull($first['src']);
            }

            return $this->stringOrNull($first);
        }

        return null;
    }

    private function arrayOrNull(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return $decoded;
            }

            $parts = array_values(array_filter(array_map('trim', explode(',', $value))));

            return $parts === [] ? null : $parts;
        }

        return null;
    }

    private function normalizeStatus(string $status): string
    {
        $normalized = strtolower(trim($status));

        return match ($normalized) {
            'publish', 'published', 'active' => 'active',
            'archive', 'archived' => 'archived',
            'draft' => 'draft',
            default => 'inactive',
        };
    }
}
