<?php

namespace App\Services\Integrations\Adapters;

use App\Models\IntegrationConnection;
use App\Services\Integrations\ConnectionCredentialService;
use App\Services\Integrations\Exceptions\IntegrationAdapterException;
use App\Services\Integrations\Mapping\FieldMappingResolverService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CustomApiProductSourceAdapter implements ProductSourceAdapterInterface, OrderPlacementAdapterInterface
{
    public function __construct(
        private readonly ConnectionCredentialService $credentialService,
        private readonly FieldMappingResolverService $mappingResolver,
    ) {
    }

    public function testConnection(IntegrationConnection $connection): array
    {
        $response = $this->request($connection, 1, null);
        if ($response->failed()) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'Custom API test failed (%s): %s',
                    $response->status(),
                    Str::limit((string) $response->body(), 600),
                ),
            ];
        }

        $items = $this->extractItems($response, $connection);

        return [
            'ok' => true,
            'message' => 'Custom API connection is valid.',
            'meta' => [
                'sample_items' => count($items),
                'endpoint' => $this->endpoint($connection),
            ],
        ];
    }

    public function fetchProducts(
        IntegrationConnection $connection,
        string $mode = 'delta',
        ?CarbonImmutable $since = null,
    ): array {
        $mapping = $this->mapping($connection);
        $results = [];
        $paginationType = $this->paginationType($connection);
        $page = 1;
        $cursor = null;
        $maxPages = $this->maxPages($connection);

        while ($page <= $maxPages) {
            $response = $this->request($connection, $page, $cursor);

            if ($response->failed()) {
                throw new IntegrationAdapterException(sprintf(
                    'Custom API sync failed on page %d (%s): %s',
                    $page,
                    $response->status(),
                    Str::limit((string) $response->body(), 600),
                ));
            }

            $items = $this->extractItems($response, $connection);
            if ($items === []) {
                break;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                if ($mode === 'delta' && $since !== null && ! $this->isModifiedAfter($item, $connection, $since)) {
                    continue;
                }

                $results[] = $this->mapProduct($item, $mapping);
            }

            if ($paginationType === 'none') {
                break;
            }

            if ($paginationType === 'page') {
                if (count($items) < $this->perPage($connection)) {
                    break;
                }
                $page++;
                continue;
            }

            if ($paginationType === 'cursor') {
                $cursor = $this->extractCursor($response, $connection);
                if (! is_string($cursor) || trim($cursor) === '') {
                    break;
                }
                $page++;
                continue;
            }

            break;
        }

        return $results;
    }

    public function createOrder(IntegrationConnection $connection, array $payload): array
    {
        $method = strtoupper((string) data_get($connection->config_json, 'orders.method', 'POST'));
        $endpoint = $this->orderEndpoint($connection);
        $requestPayload = $this->orderRequestPayload($connection, $payload);

        $response = $this->client($connection)->send($method, $endpoint, ['json' => $requestPayload]);

        if ($response->failed()) {
            throw new IntegrationAdapterException(sprintf(
                'Custom API order create failed (%s): %s',
                $response->status(),
                Str::limit((string) $response->body(), 700),
            ));
        }

        $decoded = $response->json();
        $responseRoot = $this->orderResponseRoot($connection, $decoded);

        $externalOrderId = (string) data_get(
            $responseRoot,
            (string) data_get($connection->config_json, 'orders.external_order_id_path', 'id'),
            '',
        );
        if ($externalOrderId === '') {
            throw new IntegrationAdapterException('Custom API order response missing external order id.');
        }

        $checkoutUrl = data_get(
            $responseRoot,
            (string) data_get($connection->config_json, 'orders.checkout_url_path', 'checkout_url'),
        );
        if (! is_string($checkoutUrl) || trim($checkoutUrl) === '') {
            $paymentPath = (string) data_get($connection->config_json, 'orders.payment_url_path', 'payment_url');
            $paymentUrl = data_get($responseRoot, $paymentPath);
            $checkoutUrl = is_string($paymentUrl) ? $paymentUrl : null;
        }

        $statusPath = (string) data_get($connection->config_json, 'orders.status_path', 'status');
        $currencyPath = (string) data_get($connection->config_json, 'orders.currency_path', 'currency');
        $totalPath = (string) data_get($connection->config_json, 'orders.total_path', 'total');

        return [
            'external_order_id' => $externalOrderId,
            'status' => data_get($responseRoot, $statusPath),
            'total' => data_get($responseRoot, $totalPath),
            'currency' => data_get($responseRoot, $currencyPath),
            'checkout_url' => $checkoutUrl,
            'payment_required' => strtolower((string) ($payload['payment_method'] ?? 'cod')) !== 'cod',
            'raw' => is_array($decoded) ? $decoded : [],
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    private function mapProduct(array $item, array $mapping): array
    {
        $base = [
            'id' => $item['id'] ?? $item['product_id'] ?? null,
            'external_id' => isset($item['id']) ? (string) $item['id'] : (isset($item['external_id']) ? (string) $item['external_id'] : null),
            'sku' => $item['sku'] ?? $item['code'] ?? null,
            'name' => $item['name'] ?? $item['title'] ?? null,
            'slug' => $item['slug'] ?? null,
            'short_description' => $item['short_description'] ?? $item['summary'] ?? null,
            'description' => $item['description'] ?? $item['long_description'] ?? null,
            'regular_price' => $item['price'] ?? $item['regular_price'] ?? 0,
            'sale_price' => $item['sale_price'] ?? null,
            'stock_quantity' => $item['stock_qty'] ?? $item['stock_quantity'] ?? null,
            'stock_status' => $item['stock_status'] ?? null,
            'in_stock' => $item['in_stock'] ?? null,
            'product_url' => $item['product_url'] ?? $item['url'] ?? null,
            'images' => $item['images'] ?? [],
            'category' => $item['category'] ?? $item['category_text'] ?? null,
            'category_text' => $item['category_text'] ?? $item['category'] ?? null,
            'brand' => $item['brand'] ?? null,
            'attributes' => $item['attributes'] ?? $item['attributes_json'] ?? [],
            'tags' => $item['tags'] ?? $item['tags_json'] ?? [],
            'status' => $item['status'] ?? 'active',
        ];

        $mapped = $mapping !== [] ? $this->mappingResolver->resolve($item, $mapping) : [];
        $merged = array_merge($base, array_filter($mapped, static fn ($value) => $value !== null));

        if (is_string($merged['short_description'] ?? null)) {
            $merged['short_description'] = trim(strip_tags((string) $merged['short_description']));
        }

        if (is_string($merged['description'] ?? null)) {
            $merged['description'] = trim(strip_tags((string) $merged['description']));
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapping(IntegrationConnection $connection): array
    {
        $mapping = $connection->mapping_json;

        return is_array($mapping) ? $mapping : [];
    }

    private function request(IntegrationConnection $connection, int $page, ?string $cursor): Response
    {
        $method = strtoupper((string) data_get($connection->config_json, 'method', 'GET'));
        $query = $this->baseQuery($connection);
        $body = $this->baseBody($connection);

        $paginationType = $this->paginationType($connection);
        if ($paginationType === 'page') {
            $query[$this->pageParam($connection)] = $page;
            $query[$this->perPageParam($connection)] = $this->perPage($connection);
        } elseif ($paginationType === 'cursor' && $cursor !== null) {
            $query[$this->cursorParam($connection)] = $cursor;
        }

        $endpoint = $this->endpoint($connection);
        $client = $this->client($connection);

        if ($method === 'GET') {
            return $client->get($endpoint, $query);
        }

        return $client
            ->withQueryParameters($query)
            ->send($method, $endpoint, ['json' => $body]);
    }

    private function client(IntegrationConnection $connection): PendingRequest
    {
        $credentials = $this->credentialService->decryptCredentials($connection);
        $authType = strtolower((string) ($connection->auth_type ?? 'none'));
        $client = Http::acceptJson()->timeout(30)->retry(2, 250);

        $headers = [];
        $queryAuth = [];

        if ($authType === 'basic') {
            $username = (string) ($credentials['username'] ?? '');
            $password = (string) ($credentials['password'] ?? '');
            if ($username !== '' && $password !== '') {
                $client = $client->withBasicAuth($username, $password);
            }
        } elseif ($authType === 'bearer') {
            $token = (string) ($credentials['token'] ?? $credentials['access_token'] ?? '');
            if ($token !== '') {
                $client = $client->withToken($token);
            }
        } elseif ($authType === 'api_key_header') {
            $headerName = (string) ($credentials['header_name'] ?? 'X-API-Key');
            $apiKey = (string) ($credentials['api_key'] ?? '');
            if ($apiKey !== '') {
                $headers[$headerName] = $apiKey;
            }
        } elseif ($authType === 'api_key_query') {
            $queryName = (string) ($credentials['query_param'] ?? 'api_key');
            $apiKey = (string) ($credentials['api_key'] ?? '');
            if ($apiKey !== '') {
                $queryAuth[$queryName] = $apiKey;
            }
        }

        $extraHeaders = data_get($connection->config_json, 'headers', []);
        if (is_array($extraHeaders)) {
            foreach ($extraHeaders as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $headers[$key] = (string) $value;
                }
            }
        }

        $credentialHeaders = $credentials['headers'] ?? [];
        if (is_array($credentialHeaders)) {
            foreach ($credentialHeaders as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $headers[$key] = (string) $value;
                }
            }
        }

        if ($headers !== []) {
            $client = $client->withHeaders($headers);
        }

        if ($queryAuth !== []) {
            $client = $client->withQueryParameters($queryAuth);
        }

        return $client;
    }

    private function endpoint(IntegrationConnection $connection): string
    {
        $baseUrl = trim((string) $connection->base_url);
        if ($baseUrl === '') {
            throw new IntegrationAdapterException('Custom API base_url is required.');
        }

        $path = (string) data_get($connection->config_json, 'products_endpoint', '/products');
        if ($path === '') {
            $path = '/products';
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return rtrim($baseUrl, '/').$path;
    }

    private function orderEndpoint(IntegrationConnection $connection): string
    {
        $baseUrl = trim((string) $connection->base_url);
        if ($baseUrl === '') {
            throw new IntegrationAdapterException('Custom API base_url is required.');
        }

        $path = (string) data_get($connection->config_json, 'orders.endpoint', '/orders');
        if ($path === '') {
            $path = '/orders';
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return rtrim($baseUrl, '/').$path;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function orderRequestPayload(IntegrationConnection $connection, array $payload): array
    {
        $requestRoot = (string) data_get($connection->config_json, 'orders.request_root', 'order');
        $envelope = [
            'order' => $payload,
        ];

        $mapping = data_get($connection->mapping_json, 'order');
        if (is_array($mapping) && $mapping !== []) {
            $mapped = $this->mappingResolver->resolve($payload, $mapping);
            if ($mapped !== []) {
                $envelope['order'] = $mapped;
            }
        }

        if ($requestRoot === '' || $requestRoot === 'order') {
            return $envelope;
        }

        return [
            $requestRoot => $envelope['order'],
        ];
    }

    /**
     * @param mixed $decoded
     * @return array<string, mixed>
     */
    private function orderResponseRoot(IntegrationConnection $connection, mixed $decoded): array
    {
        if (! is_array($decoded)) {
            return [];
        }

        $responsePath = (string) data_get($connection->config_json, 'orders.response_path', '');
        if ($responsePath === '') {
            return $decoded;
        }

        $root = data_get($decoded, $responsePath);

        return is_array($root) ? $root : [];
    }

    private function paginationType(IntegrationConnection $connection): string
    {
        $type = (string) (
            data_get($connection->config_json, 'pagination.type')
            ?? data_get($connection->config_json, 'pagination_type')
            ?? 'none'
        );

        $type = strtolower(trim($type));

        return in_array($type, ['none', 'page', 'cursor'], true) ? $type : 'none';
    }

    private function maxPages(IntegrationConnection $connection): int
    {
        $maxPages = (int) (
            data_get($connection->config_json, 'pagination.max_pages')
            ?? data_get($connection->config_json, 'max_pages')
            ?? 100
        );

        return max(1, min($maxPages, 1000));
    }

    private function perPage(IntegrationConnection $connection): int
    {
        $perPage = (int) (
            data_get($connection->config_json, 'pagination.per_page')
            ?? data_get($connection->config_json, 'per_page')
            ?? 100
        );

        return max(1, min($perPage, 500));
    }

    private function pageParam(IntegrationConnection $connection): string
    {
        $value = (string) (
            data_get($connection->config_json, 'pagination.page_param')
            ?? data_get($connection->config_json, 'page_param')
            ?? 'page'
        );

        return $value !== '' ? $value : 'page';
    }

    private function perPageParam(IntegrationConnection $connection): string
    {
        $value = (string) (
            data_get($connection->config_json, 'pagination.per_page_param')
            ?? data_get($connection->config_json, 'per_page_param')
            ?? 'per_page'
        );

        return $value !== '' ? $value : 'per_page';
    }

    private function cursorParam(IntegrationConnection $connection): string
    {
        $value = (string) (
            data_get($connection->config_json, 'pagination.cursor_param')
            ?? data_get($connection->config_json, 'cursor_param')
            ?? 'cursor'
        );

        return $value !== '' ? $value : 'cursor';
    }

    private function cursorPath(IntegrationConnection $connection): string
    {
        $value = (string) (
            data_get($connection->config_json, 'pagination.next_cursor_path')
            ?? data_get($connection->config_json, 'next_cursor_path')
            ?? 'data.next_cursor'
        );

        return $value !== '' ? $value : 'data.next_cursor';
    }

    /**
     * @return array<string, mixed>
     */
    private function baseQuery(IntegrationConnection $connection): array
    {
        $query = data_get($connection->config_json, 'query', []);

        return is_array($query) ? $query : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function baseBody(IntegrationConnection $connection): array
    {
        $body = data_get($connection->config_json, 'body', []);

        return is_array($body) ? $body : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractItems(Response $response, IntegrationConnection $connection): array
    {
        $payload = $response->json();
        if (! is_array($payload)) {
            return [];
        }

        $itemsPath = (string) data_get($connection->config_json, 'products_path', 'data');
        $items = $itemsPath !== '' ? data_get($payload, $itemsPath) : null;

        if ($items === null) {
            // If response is direct array of products.
            if (array_is_list($payload)) {
                return array_values(array_filter($payload, 'is_array'));
            }

            return [];
        }

        if (! is_array($items)) {
            return [];
        }

        if (! array_is_list($items)) {
            return [];
        }

        return array_values(array_filter($items, 'is_array'));
    }

    private function extractCursor(Response $response, IntegrationConnection $connection): ?string
    {
        $payload = $response->json();
        if (! is_array($payload)) {
            return null;
        }

        $cursor = data_get($payload, $this->cursorPath($connection));

        return is_scalar($cursor) ? (string) $cursor : null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isModifiedAfter(array $item, IntegrationConnection $connection, CarbonImmutable $since): bool
    {
        $modifiedField = (string) data_get($connection->config_json, 'modified_field', 'updated_at');
        if ($modifiedField === '') {
            $modifiedField = 'updated_at';
        }

        $modified = data_get($item, $modifiedField);
        if ($modified === null || $modified === '') {
            return true;
        }

        try {
            if (is_numeric($modified)) {
                $modifiedAt = CarbonImmutable::createFromTimestamp((int) $modified);
            } else {
                $modifiedAt = CarbonImmutable::parse((string) $modified);
            }
        } catch (\Throwable) {
            return true;
        }

        return $modifiedAt->greaterThan($since);
    }
}
