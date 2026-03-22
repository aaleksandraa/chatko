<?php

namespace App\Services\Integrations;

use App\Models\IntegrationConnection;
use App\Services\Integrations\Adapters\CustomApiProductSourceAdapter;
use App\Services\Integrations\Adapters\OrderPlacementAdapterInterface;
use App\Services\Integrations\Adapters\ShopifyProductSourceAdapter;
use App\Services\Integrations\Adapters\WooCommerceProductSourceAdapter;
use App\Services\Integrations\Exceptions\IntegrationAdapterException;

class OrderPlacementAdapterRegistry
{
    public function __construct(
        private readonly WooCommerceProductSourceAdapter $wooCommerceAdapter,
        private readonly ShopifyProductSourceAdapter $shopifyAdapter,
        private readonly CustomApiProductSourceAdapter $customApiAdapter,
    ) {
    }

    public function resolve(IntegrationConnection $connection): OrderPlacementAdapterInterface
    {
        return match ($connection->type) {
            'woocommerce' => $this->wooCommerceAdapter,
            'shopify' => $this->shopifyAdapter,
            'custom_api' => $this->customApiAdapter,
            default => throw new IntegrationAdapterException(
                sprintf('Integration type "%s" does not support order placement.', $connection->type),
            ),
        };
    }
}
