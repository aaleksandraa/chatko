<?php

namespace App\Services\Integrations\Adapters;

use App\Models\IntegrationConnection;
use App\Services\Integrations\ConnectionCredentialService;
use App\Services\Integrations\Exceptions\IntegrationAdapterException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ShopifyProductSourceAdapter implements ProductSourceAdapterInterface, OrderPlacementAdapterInterface
{
    public function __construct(private readonly ConnectionCredentialService $credentialService)
    {
    }

    public function testConnection(IntegrationConnection $connection): array
    {
        $response = $this->client($connection)->post($this->endpoint($connection), [
            'query' => <<<'GQL'
query {
  shop {
    name
    myshopifyDomain
  }
}
GQL,
        ]);

        if ($response->failed()) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'Shopify test failed (%s): %s',
                    $response->status(),
                    Str::limit((string) $response->body(), 500),
                ),
            ];
        }

        $errors = data_get($response->json(), 'errors', []);
        if (is_array($errors) && $errors !== []) {
            return [
                'ok' => false,
                'message' => 'Shopify GraphQL returned errors: '.json_encode($errors),
            ];
        }

        return [
            'ok' => true,
            'message' => 'Shopify connection is valid.',
            'meta' => [
                'shop_name' => data_get($response->json(), 'data.shop.name'),
                'shop_domain' => data_get($response->json(), 'data.shop.myshopifyDomain'),
            ],
        ];
    }

    public function fetchProducts(
        IntegrationConnection $connection,
        string $mode = 'delta',
        ?CarbonImmutable $since = null,
    ): array {
        $results = [];
        $cursor = null;
        $page = 0;
        $maxPages = 100;

        while ($page < $maxPages) {
            $page++;
            $response = $this->client($connection)->post($this->endpoint($connection), [
                'query' => $this->productsQuery(),
                'variables' => [
                    'cursor' => $cursor,
                    'query' => $this->queryFilter($mode, $since),
                ],
            ]);

            if ($response->failed()) {
                throw new IntegrationAdapterException(sprintf(
                    'Shopify sync failed on page %d (%s): %s',
                    $page,
                    $response->status(),
                    Str::limit((string) $response->body(), 500),
                ));
            }

            $errors = data_get($response->json(), 'errors', []);
            if (is_array($errors) && $errors !== []) {
                throw new IntegrationAdapterException(
                    'Shopify GraphQL errors: '.json_encode($errors),
                );
            }

            $edges = data_get($response->json(), 'data.products.edges', []);
            if (! is_array($edges) || $edges === []) {
                break;
            }

            foreach ($edges as $edge) {
                if (! is_array($edge)) {
                    continue;
                }

                $node = $edge['node'] ?? null;
                if (! is_array($node)) {
                    continue;
                }

                $results[] = $this->mapProduct($node);
                $cursor = isset($edge['cursor']) ? (string) $edge['cursor'] : $cursor;
            }

            $hasNextPage = (bool) data_get($response->json(), 'data.products.pageInfo.hasNextPage', false);
            if (! $hasNextPage) {
                break;
            }
        }

        return $results;
    }

    public function createOrder(IntegrationConnection $connection, array $payload): array
    {
        $paymentMethod = strtolower((string) ($payload['payment_method'] ?? 'cod'));
        $useDraftOrderForOnline = (bool) data_get($connection->config_json, 'order.use_draft_order_for_online', true);

        if ($paymentMethod !== 'cod' && $useDraftOrderForOnline) {
            return $this->createDraftOrder($connection, $payload);
        }

        return $this->createOrderDirect($connection, $payload);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function mapProduct(array $node): array
    {
        $variants = data_get($node, 'variants.nodes', []);
        $variantList = is_array($variants) ? $variants : [];
        $attributes = $this->extractAttributes($node);

        $firstVariant = $variantList[0] ?? [];
        $price = (float) ($firstVariant['price'] ?? 0);
        $compareAtPrice = isset($firstVariant['compareAtPrice']) ? (float) $firstVariant['compareAtPrice'] : null;

        $inventorySum = 0;
        $inventoryKnown = false;

        foreach ($variantList as $variant) {
            if (! is_array($variant)) {
                continue;
            }
            if (isset($variant['inventoryQuantity']) && is_numeric($variant['inventoryQuantity'])) {
                $inventoryKnown = true;
                $inventorySum += (int) $variant['inventoryQuantity'];
            }
        }

        $images = [];
        foreach ((array) data_get($node, 'images.nodes', []) as $image) {
            if (is_array($image) && isset($image['url'])) {
                $images[] = ['src' => $image['url'], 'alt' => $image['altText'] ?? null];
            }
        }

        $salePrice = null;
        $regularPrice = $price;
        if ($compareAtPrice !== null && $compareAtPrice > $price) {
            $regularPrice = $compareAtPrice;
            $salePrice = $price;
        }

        return [
            'id' => $this->gidToNumericId((string) ($node['id'] ?? '')),
            'external_id' => (string) $this->gidToNumericId((string) ($node['id'] ?? '')),
            'sku' => $firstVariant['sku'] ?? null,
            'name' => $node['title'] ?? null,
            'slug' => $node['handle'] ?? null,
            'short_description' => null,
            'description' => isset($node['descriptionHtml']) ? strip_tags((string) $node['descriptionHtml']) : null,
            'regular_price' => $regularPrice,
            'sale_price' => $salePrice,
            'stock_quantity' => $inventoryKnown ? $inventorySum : null,
            'stock_status' => $inventoryKnown ? ($inventorySum > 0 ? 'instock' : 'outofstock') : 'instock',
            'product_url' => $node['onlineStoreUrl'] ?? null,
            'images' => $images,
            'category' => $node['productType'] ?? null,
            'category_text' => $node['productType'] ?? null,
            'brand' => $node['vendor'] ?? null,
            'attributes' => $attributes,
            'tags' => $node['tags'] ?? [],
            'status' => strtolower((string) ($node['status'] ?? 'active')),
        ];
    }

    private function productsQuery(): string
    {
        return <<<'GQL'
query Products($cursor: String, $query: String) {
  products(first: 100, after: $cursor, query: $query, sortKey: UPDATED_AT) {
    edges {
      cursor
      node {
        id
        title
        handle
        descriptionHtml
        productType
        vendor
        options {
          name
          values
        }
        onlineStoreUrl
        status
        tags
        updatedAt
        images(first: 5) {
          nodes {
            url
            altText
          }
        }
        variants(first: 25) {
          nodes {
            id
            sku
            price
            compareAtPrice
            inventoryQuantity
          }
        }
      }
    }
    pageInfo {
      hasNextPage
    }
  }
}
GQL;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, array<int, string>>
     */
    private function extractAttributes(array $node): array
    {
        $attributes = [];
        foreach ((array) data_get($node, 'options', []) as $option) {
            if (! is_array($option)) {
                continue;
            }

            $name = trim((string) ($option['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $values = $option['values'] ?? [];
            if (! is_array($values)) {
                $values = [$values];
            }

            $normalizedValues = array_values(array_filter(array_map(
                static fn ($value): string => trim((string) $value),
                $values,
            ), static fn (string $value): bool => $value !== ''));

            if ($normalizedValues !== []) {
                $attributes[$name] = $normalizedValues;
            }
        }

        return $attributes;
    }

    private function queryFilter(string $mode, ?CarbonImmutable $since): ?string
    {
        if ($mode !== 'delta' || $since === null) {
            return 'status:active';
        }

        return sprintf(
            'status:active updated_at:>%s',
            $since->setTimezone('UTC')->format('Y-m-d\\TH:i:s\\Z'),
        );
    }

    private function client(IntegrationConnection $connection): PendingRequest
    {
        $credentials = $this->credentialService->decryptCredentials($connection);
        $accessToken = (string) ($credentials['access_token'] ?? '');

        if ($accessToken === '') {
            throw new IntegrationAdapterException('Shopify credentials missing access_token.');
        }

        return Http::acceptJson()
            ->withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
            ])
            ->timeout(30)
            ->retry(2, 250);
    }

    private function endpoint(IntegrationConnection $connection): string
    {
        $baseUrl = trim((string) $connection->base_url);
        if ($baseUrl === '') {
            throw new IntegrationAdapterException('Shopify base_url is required.');
        }

        $apiVersion = (string) data_get($connection->config_json, 'api_version', '2025-01');

        return rtrim($baseUrl, '/').'/admin/api/'.$apiVersion.'/graphql.json';
    }

    private function restEndpoint(IntegrationConnection $connection, string $path): string
    {
        $baseUrl = trim((string) $connection->base_url);
        if ($baseUrl === '') {
            throw new IntegrationAdapterException('Shopify base_url is required.');
        }

        $apiVersion = (string) data_get($connection->config_json, 'api_version', '2025-01');

        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return rtrim($baseUrl, '/').'/admin/api/'.$apiVersion.$path;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function createDraftOrder(IntegrationConnection $connection, array $payload): array
    {
        $body = [
            'draft_order' => [
                'line_items' => $this->shopifyLineItems((array) ($payload['items'] ?? [])),
                'billing_address' => $this->shopifyAddress((array) ($payload['customer'] ?? [])),
                'shipping_address' => $this->shopifyAddress((array) ($payload['customer'] ?? [])),
                'email' => data_get($payload, 'customer.email'),
                'phone' => data_get($payload, 'customer.phone'),
                'note' => $payload['note'] ?? null,
                'tags' => $this->chatkoTags($payload),
            ],
        ];

        $response = $this->restRequest(
            $connection,
            'POST',
            '/draft_orders.json',
            ['json' => $body],
        );

        if ($response->failed()) {
            throw new IntegrationAdapterException(sprintf(
                'Shopify draft order create failed (%s): %s',
                $response->status(),
                Str::limit((string) $response->body(), 700),
            ));
        }

        $json = $response->json();
        $draftOrder = is_array($json['draft_order'] ?? null) ? $json['draft_order'] : null;
        if (! is_array($draftOrder)) {
            throw new IntegrationAdapterException('Shopify draft order response missing draft_order payload.');
        }

        $externalOrderId = isset($draftOrder['id']) ? (string) $draftOrder['id'] : '';
        if ($externalOrderId === '') {
            throw new IntegrationAdapterException('Shopify draft order response missing id.');
        }

        return [
            'external_order_id' => $externalOrderId,
            'status' => isset($draftOrder['status']) ? (string) $draftOrder['status'] : 'open',
            'total' => $draftOrder['total_price'] ?? null,
            'currency' => isset($draftOrder['currency']) ? (string) $draftOrder['currency'] : null,
            'checkout_url' => $draftOrder['invoice_url'] ?? null,
            'payment_required' => true,
            'raw' => is_array($json) ? $json : [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function createOrderDirect(IntegrationConnection $connection, array $payload): array
    {
        $paymentMethod = strtolower((string) ($payload['payment_method'] ?? 'cod'));

        $body = [
            'order' => [
                'line_items' => $this->shopifyLineItems((array) ($payload['items'] ?? [])),
                'billing_address' => $this->shopifyAddress((array) ($payload['customer'] ?? [])),
                'shipping_address' => $this->shopifyAddress((array) ($payload['customer'] ?? [])),
                'email' => data_get($payload, 'customer.email'),
                'phone' => data_get($payload, 'customer.phone'),
                'note' => $payload['note'] ?? null,
                'tags' => $this->chatkoTags($payload),
                'financial_status' => 'pending',
                'send_receipt' => false,
            ],
        ];

        $response = $this->restRequest(
            $connection,
            'POST',
            '/orders.json',
            ['json' => $body],
        );

        if ($response->failed()) {
            throw new IntegrationAdapterException(sprintf(
                'Shopify order create failed (%s): %s',
                $response->status(),
                Str::limit((string) $response->body(), 700),
            ));
        }

        $json = $response->json();
        $order = is_array($json['order'] ?? null) ? $json['order'] : null;
        if (! is_array($order)) {
            throw new IntegrationAdapterException('Shopify order response missing order payload.');
        }

        $externalOrderId = isset($order['id']) ? (string) $order['id'] : '';
        if ($externalOrderId === '') {
            throw new IntegrationAdapterException('Shopify order response missing id.');
        }

        return [
            'external_order_id' => $externalOrderId,
            'status' => isset($order['financial_status']) ? (string) $order['financial_status'] : null,
            'total' => $order['total_price'] ?? null,
            'currency' => isset($order['currency']) ? (string) $order['currency'] : null,
            'checkout_url' => $order['order_status_url'] ?? null,
            'payment_required' => $paymentMethod !== 'cod',
            'raw' => is_array($json) ? $json : [],
        ];
    }

    /**
     * @param array<string, mixed> $customer
     * @return array<string, mixed>
     */
    private function shopifyAddress(array $customer): array
    {
        $firstName = trim((string) ($customer['first_name'] ?? ''));
        $lastName = trim((string) ($customer['last_name'] ?? ''));

        if ($firstName === '' && $lastName === '') {
            [$firstName, $lastName] = $this->splitName((string) ($customer['name'] ?? ''));
        }

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'address1' => (string) ($customer['address_line_1'] ?? ''),
            'city' => (string) ($customer['city'] ?? ''),
            'zip' => (string) ($customer['postal_code'] ?? ''),
            'country_code' => strtoupper((string) ($customer['country'] ?? 'BA')),
            'phone' => (string) ($customer['phone'] ?? ''),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function shopifyLineItems(array $items): array
    {
        $lineItems = [];

        foreach ($items as $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $title = (string) ($item['name'] ?? 'Product');
            $price = number_format((float) ($item['unit_price'] ?? 0), 2, '.', '');

            $lineItems[] = [
                'title' => $title,
                'quantity' => $quantity,
                'price' => $price,
                'sku' => isset($item['sku']) ? (string) $item['sku'] : null,
            ];
        }

        if ($lineItems === []) {
            throw new IntegrationAdapterException('Shopify narudzba nema line items.');
        }

        return $lineItems;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function chatkoTags(array $payload): string
    {
        $conversationId = (string) ($payload['conversation_id'] ?? '');
        $payment = (string) ($payload['payment_method'] ?? 'cod');

        return trim(sprintf('chatko,conversation:%s,payment:%s', $conversationId, $payment), ',');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function restRequest(IntegrationConnection $connection, string $method, string $path, array $options = []): Response
    {
        $endpoint = $this->restEndpoint($connection, $path);
        $client = $this->client($connection);

        return $client->send($method, $endpoint, $options);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        $parts = array_values(array_filter(explode(' ', trim($name))));
        if ($parts === []) {
            return ['', ''];
        }

        $first = (string) array_shift($parts);
        $last = implode(' ', $parts);

        return [$first, $last];
    }

    private function gidToNumericId(string $gid): string
    {
        if ($gid === '') {
            return '';
        }

        $parts = explode('/', $gid);

        return (string) end($parts);
    }
}
