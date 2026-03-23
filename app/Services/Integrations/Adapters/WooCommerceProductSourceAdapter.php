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

class WooCommerceProductSourceAdapter implements ProductSourceAdapterInterface, OrderPlacementAdapterInterface
{
    public function __construct(private readonly ConnectionCredentialService $credentialService)
    {
    }

    public function testConnection(IntegrationConnection $connection): array
    {
        $params = ['per_page' => 1];
        $statusParam = $this->productStatusParam($connection);
        if ($statusParam !== null) {
            $params['status'] = $statusParam;
        }

        $response = $this->client($connection)->get(
            $this->endpoint($connection, '/wp-json/wc/v3/products'),
            $params,
        );

        if ($response->failed()) {
            $message = sprintf(
                'WooCommerce API test failed (%s): %s',
                $response->status(),
                Str::limit((string) $response->body(), 500),
            );

            return [
                'ok' => false,
                'message' => $message,
            ];
        }

        $payload = $response->json();
        $count = is_array($payload) ? count($payload) : 0;

        return [
            'ok' => true,
            'message' => 'WooCommerce API connection is valid.',
            'meta' => [
                'sample_items' => $count,
            ],
        ];
    }

    public function fetchProducts(
        IntegrationConnection $connection,
        string $mode = 'delta',
        ?CarbonImmutable $since = null,
    ): array {
        $results = [];
        $page = 1;
        $perPage = 100;
        $maxPages = 100;

        while ($page <= $maxPages) {
            $params = [
                'per_page' => $perPage,
                'page' => $page,
                'orderby' => 'modified',
                'order' => 'asc',
            ];
            $statusParam = $this->productStatusParam($connection);
            if ($statusParam !== null) {
                $params['status'] = $statusParam;
            }

            if ($mode === 'delta' && $since !== null) {
                $params['modified_after'] = $since->toIso8601String();
            }

            $response = $this->client($connection)->get(
                $this->endpoint($connection, '/wp-json/wc/v3/products'),
                $params,
            );

            if ($response->failed()) {
                if ($this->isInvalidPageResponse($response)) {
                    break;
                }

                throw new IntegrationAdapterException(sprintf(
                    'WooCommerce sync failed on page %d (%s): %s',
                    $page,
                    $response->status(),
                    Str::limit((string) $response->body(), 500),
                ));
            }

            $items = $response->json();
            if (! is_array($items)) {
                break;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $results[] = $this->mapProduct($item);
            }

            $totalPages = $this->totalPagesFromResponse($response);
            if ($totalPages !== null) {
                if ($page >= $totalPages) {
                    break;
                }
            } elseif (count($items) < $perPage) {
                break;
            }

            $page++;
        }

        return $results;
    }

    public function createOrder(IntegrationConnection $connection, array $payload): array
    {
        $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
        $lineItems = $this->lineItemsForOrder((array) ($payload['items'] ?? []));
        $paymentMethod = $this->paymentMethodForOrder($connection, (string) ($payload['payment_method'] ?? 'cod'));

        $requestBody = [
            'status' => (string) data_get($connection->config_json, 'order.default_status', 'pending'),
            'payment_method' => $paymentMethod,
            'payment_method_title' => $this->paymentMethodTitleForOrder($connection, $paymentMethod),
            'set_paid' => false,
            'billing' => $this->customerAddressForWoo($customer),
            'shipping' => $this->customerAddressForWoo($customer),
            'line_items' => $lineItems,
            'customer_note' => $payload['note'] ?? null,
            'meta_data' => [
                [
                    'key' => 'chatko_conversation_id',
                    'value' => (string) ($payload['conversation_id'] ?? ''),
                ],
                [
                    'key' => 'chatko_payment_method',
                    'value' => (string) ($payload['payment_method'] ?? 'cod'),
                ],
            ],
        ];

        $response = $this->client($connection)->post(
            $this->endpoint($connection, '/wp-json/wc/v3/orders'),
            $requestBody,
        );

        if ($response->failed()) {
            throw new IntegrationAdapterException(sprintf(
                'WooCommerce order create failed (%s): %s',
                $response->status(),
                Str::limit((string) $response->body(), 700),
            ));
        }

        $json = $response->json();
        $externalOrderId = (string) ($json['id'] ?? '');
        if ($externalOrderId === '') {
            throw new IntegrationAdapterException('WooCommerce order response missing order id.');
        }

        return [
            'external_order_id' => $externalOrderId,
            'status' => isset($json['status']) ? (string) $json['status'] : null,
            'total' => $json['total'] ?? null,
            'currency' => isset($json['currency']) ? (string) $json['currency'] : null,
            'checkout_url' => $json['checkout_payment_url'] ?? $json['payment_url'] ?? $json['permalink'] ?? null,
            'payment_required' => strtolower((string) ($payload['payment_method'] ?? 'cod')) !== 'cod',
            'raw' => is_array($json) ? $json : [],
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function mapProduct(array $item): array
    {
        $categories = [];
        foreach (($item['categories'] ?? []) as $category) {
            if (is_array($category) && isset($category['name'])) {
                $categories[] = (string) $category['name'];
            }
        }

        $tags = [];
        foreach (($item['tags'] ?? []) as $tag) {
            if (! is_array($tag)) {
                continue;
            }

            $name = trim((string) ($tag['name'] ?? ''));
            if ($name !== '') {
                $tags[] = $name;
            }

            $slug = trim((string) ($tag['slug'] ?? ''));
            if ($slug !== '') {
                $tags[] = $slug;
            }
        }
        $tags = array_values(array_unique($tags));

        $attributes = [];
        foreach (($item['attributes'] ?? []) as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $name = (string) ($attribute['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $options = $attribute['options'] ?? [];
            if (! is_array($options)) {
                $options = [$options];
            }

            $attributes[$name] = array_values(array_filter(array_map(
                static fn ($value): string => trim((string) $value),
                $options,
            ), static fn (string $value): bool => $value !== ''));
        }

        return [
            'id' => $item['id'] ?? null,
            'external_id' => isset($item['id']) ? (string) $item['id'] : null,
            'sku' => $item['sku'] ?? null,
            'name' => $item['name'] ?? null,
            'slug' => $item['slug'] ?? null,
            'short_description' => $item['short_description'] ?? null,
            'description' => $item['description'] ?? null,
            'regular_price' => $item['regular_price'] ?? $item['price'] ?? 0,
            'sale_price' => $item['sale_price'] ?? null,
            'stock_quantity' => $item['stock_quantity'] ?? null,
            'stock_status' => $item['stock_status'] ?? null,
            'product_url' => $item['permalink'] ?? null,
            'images' => $item['images'] ?? [],
            'category' => implode(', ', $categories),
            'category_text' => implode(', ', $categories),
            'attributes' => $attributes,
            'tags' => $tags,
            'status' => $item['status'] ?? 'inactive',
        ];
    }

    private function client(IntegrationConnection $connection): PendingRequest
    {
        $credentials = $this->credentialService->decryptCredentials($connection);
        $authType = strtolower((string) ($connection->auth_type ?? 'woocommerce_key_secret'));

        $client = Http::acceptJson()->timeout(30)->retry(2, 250);

        if ($authType === 'basic') {
            $username = (string) ($credentials['username'] ?? '');
            $password = (string) ($credentials['password'] ?? '');

            if ($username !== '' && $password !== '') {
                return $client->withBasicAuth($username, $password);
            }
        }

        $consumerKey = (string) ($credentials['consumer_key'] ?? '');
        $consumerSecret = (string) ($credentials['consumer_secret'] ?? '');

        if ($consumerKey === '' || $consumerSecret === '') {
            throw new IntegrationAdapterException(
                'WooCommerce credentials are missing. Expected consumer_key and consumer_secret.',
            );
        }

        return $client->withQueryParameters([
            'consumer_key' => $consumerKey,
            'consumer_secret' => $consumerSecret,
        ]);
    }

    private function endpoint(IntegrationConnection $connection, string $path): string
    {
        $baseUrl = trim((string) $connection->base_url);
        if ($baseUrl === '') {
            throw new IntegrationAdapterException('WooCommerce base_url is required.');
        }

        return rtrim($baseUrl, '/').$path;
    }

    private function totalPagesFromResponse(Response $response): ?int
    {
        $header = $response->header('X-WP-TotalPages');
        if (! is_string($header) || trim($header) === '') {
            return null;
        }

        $pages = (int) $header;

        return $pages > 0 ? $pages : null;
    }

    private function isInvalidPageResponse(Response $response): bool
    {
        if ($response->status() !== 400) {
            return false;
        }

        $code = strtolower((string) data_get($response->json(), 'code', ''));
        $message = strtolower((string) data_get($response->json(), 'message', ''));

        return str_contains($code, 'invalid_page')
            || str_contains($message, 'invalid page');
    }

    private function productStatusParam(IntegrationConnection $connection): ?string
    {
        $raw = data_get($connection->config_json, 'products_status', 'publish');

        $statuses = [];
        if (is_string($raw)) {
            $statuses = array_map('trim', explode(',', strtolower($raw)));
        } elseif (is_array($raw)) {
            $statuses = array_map(
                static fn ($value): string => strtolower(trim((string) $value)),
                $raw,
            );
        }

        $statuses = array_values(array_unique(array_filter($statuses)));
        if ($statuses === [] || in_array('any', $statuses, true)) {
            return 'publish';
        }

        $allowed = ['publish'];
        $statuses = array_values(array_filter(
            $statuses,
            static fn (string $status): bool => in_array($status, $allowed, true),
        ));

        return $statuses === [] ? 'publish' : implode(',', $statuses);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, int>>
     */
    private function lineItemsForOrder(array $items): array
    {
        $lineItems = [];

        foreach ($items as $item) {
            $externalId = (string) ($item['external_id'] ?? '');
            $quantity = max(1, (int) ($item['quantity'] ?? 1));

            if ($externalId === '' || ! ctype_digit($externalId)) {
                throw new IntegrationAdapterException(
                    'WooCommerce narudzba zahtijeva numeric external_id za svaki proizvod.',
                );
            }

            $lineItems[] = [
                'product_id' => (int) $externalId,
                'quantity' => $quantity,
            ];
        }

        if ($lineItems === []) {
            throw new IntegrationAdapterException('WooCommerce narudzba nema line items.');
        }

        return $lineItems;
    }

    private function paymentMethodForOrder(IntegrationConnection $connection, string $requestedMethod): string
    {
        $normalized = strtolower(trim($requestedMethod));

        if ($normalized === 'cod') {
            return (string) data_get($connection->config_json, 'order.cod_gateway', 'cod');
        }

        return (string) data_get($connection->config_json, 'order.online_gateway', 'bacs');
    }

    private function paymentMethodTitleForOrder(IntegrationConnection $connection, string $paymentMethod): string
    {
        $codGateway = (string) data_get($connection->config_json, 'order.cod_gateway', 'cod');
        if ($paymentMethod === $codGateway) {
            return (string) data_get($connection->config_json, 'order.cod_title', 'Placanje pouzecem');
        }

        return (string) data_get($connection->config_json, 'order.online_title', 'Online placanje');
    }

    /**
     * @param array<string, mixed> $customer
     * @return array<string, mixed>
     */
    private function customerAddressForWoo(array $customer): array
    {
        $firstName = trim((string) ($customer['first_name'] ?? ''));
        $lastName = trim((string) ($customer['last_name'] ?? ''));

        if ($firstName === '' && $lastName === '') {
            [$firstName, $lastName] = $this->splitName((string) ($customer['name'] ?? ''));
        }

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'address_1' => (string) ($customer['address_line_1'] ?? ''),
            'city' => (string) ($customer['city'] ?? ''),
            'postcode' => (string) ($customer['postal_code'] ?? ''),
            'country' => strtoupper((string) ($customer['country'] ?? 'BA')),
            'email' => (string) ($customer['email'] ?? ''),
            'phone' => (string) ($customer['phone'] ?? ''),
        ];
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
}
