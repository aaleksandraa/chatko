<?php

namespace App\Services\Integrations;

use App\Models\IntegrationConnection;
use App\Services\Integrations\Adapters\CustomApiProductSourceAdapter;
use App\Services\Integrations\Adapters\ProductSourceAdapterInterface;
use App\Services\Integrations\Adapters\ShopifyProductSourceAdapter;
use App\Services\Integrations\Adapters\WooCommerceProductSourceAdapter;
use App\Services\Integrations\Adapters\WordPressRestProductSourceAdapter;
use App\Services\Integrations\Exceptions\IntegrationAdapterException;

class ProductSourceAdapterRegistry
{
    public function __construct(
        private readonly WooCommerceProductSourceAdapter $wooCommerceAdapter,
        private readonly WordPressRestProductSourceAdapter $wordPressRestAdapter,
        private readonly ShopifyProductSourceAdapter $shopifyAdapter,
        private readonly CustomApiProductSourceAdapter $customApiAdapter,
    ) {
    }

    public function resolve(IntegrationConnection $connection): ProductSourceAdapterInterface
    {
        return match ($connection->type) {
            'woocommerce' => $this->wooCommerceAdapter,
            'wordpress_rest' => $this->wordPressRestAdapter,
            'shopify' => $this->shopifyAdapter,
            'custom_api' => $this->customApiAdapter,
            default => throw new IntegrationAdapterException(
                sprintf('Integration type "%s" does not have a product source adapter yet.', $connection->type),
            ),
        };
    }
}
