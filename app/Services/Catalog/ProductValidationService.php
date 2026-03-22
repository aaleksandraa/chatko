<?php

namespace App\Services\Catalog;

use Illuminate\Support\Arr;

class ProductValidationService
{
    /**
     * @return array{ok: bool, errors: array<int, string>}
     */
    public function validate(array $normalized): array
    {
        $errors = [];

        if (trim((string) Arr::get($normalized, 'name', '')) === '') {
            $errors[] = 'Product name is required.';
        }

        if (! is_numeric(Arr::get($normalized, 'price'))) {
            $errors[] = 'Price must be numeric.';
        }

        $url = Arr::get($normalized, 'product_url');

        if ($url !== null && ! filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = 'product_url is invalid.';
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
        ];
    }
}
