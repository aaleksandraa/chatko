<?php

namespace App\Services\Integrations\Adapters;

use App\Models\IntegrationConnection;

interface OrderPlacementAdapterInterface
{
    /**
     * @param array<string, mixed> $payload
     * @return array{
     *   external_order_id: string,
     *   status?: string|null,
     *   total?: float|int|string|null,
     *   currency?: string|null,
     *   checkout_url?: string|null,
     *   payment_required?: bool,
     *   raw?: array<string, mixed>
     * }
     */
    public function createOrder(IntegrationConnection $connection, array $payload): array;
}
