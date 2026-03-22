<?php

namespace App\Services\Integrations\Adapters;

use App\Models\IntegrationConnection;
use Carbon\CarbonImmutable;

interface ProductSourceAdapterInterface
{
    /**
     * @return array{ok: bool, message: string, meta?: array<string, mixed>}
     */
    public function testConnection(IntegrationConnection $connection): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchProducts(
        IntegrationConnection $connection,
        string $mode = 'delta',
        ?CarbonImmutable $since = null,
    ): array;
}

