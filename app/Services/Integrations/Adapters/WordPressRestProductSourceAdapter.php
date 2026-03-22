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

class WordPressRestProductSourceAdapter implements ProductSourceAdapterInterface
{
    public function __construct(
        private readonly ConnectionCredentialService $credentialService,
        private readonly FieldMappingResolverService $mappingResolver,
    ) {
    }

    public function testConnection(IntegrationConnection $connection): array
    {
        $resourcePath = $this->resourcePath($connection);
        $statusParam = $this->productStatusParam($connection);

        $response = $this->client($connection)->get(
            $this->endpoint($connection, $resourcePath),
            [
                'per_page' => 1,
                '_embed' => 1,
                'status' => $statusParam,
            ],
        );

        if ($response->failed()) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'WordPress REST test failed (%s): %s',
                    $response->status(),
                    Str::limit((string) $response->body(), 500),
                ),
            ];
        }

        $payload = $response->json();
        $sampleItems = is_array($payload) ? count($payload) : 0;

        return [
            'ok' => true,
            'message' => 'WordPress REST connection is valid.',
            'meta' => [
                'resource_path' => $resourcePath,
                'sample_items' => $sampleItems,
            ],
        ];
    }

    public function fetchProducts(
        IntegrationConnection $connection,
        string $mode = 'delta',
        ?CarbonImmutable $since = null,
    ): array {
        $resourcePath = $this->resourcePath($connection);
        $mapping = $this->mapping($connection);
        $results = [];
        $page = 1;
        $perPage = 100;
        $maxPages = 100;

        while ($page <= $maxPages) {
            $params = [
                'per_page' => $perPage,
                'page' => $page,
                '_embed' => 1,
                'orderby' => 'modified',
                'order' => 'asc',
                'status' => $this->productStatusParam($connection),
            ];

            $response = $this->client($connection)->get(
                $this->endpoint($connection, $resourcePath),
                $params,
            );

            if ($response->failed()) {
                if ($this->isInvalidPageResponse($response)) {
                    break;
                }

                throw new IntegrationAdapterException(sprintf(
                    'WordPress REST sync failed on page %d (%s): %s',
                    $page,
                    $response->status(),
                    Str::limit((string) $response->body(), 500),
                ));
            }

            $items = $response->json();
            if (! is_array($items) || $items === []) {
                break;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                if ($mode === 'delta' && $since !== null && ! $this->isModifiedAfter($item, $since)) {
                    continue;
                }

                if (! $this->isActiveStatus((string) ($item['status'] ?? 'publish'))) {
                    continue;
                }

                $results[] = $this->mapProduct($item, $mapping);
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

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    private function mapProduct(array $item, array $mapping): array
    {
        $base = [
            'id' => $item['id'] ?? null,
            'external_id' => isset($item['id']) ? (string) $item['id'] : null,
            'sku' => data_get($item, 'meta.sku'),
            'name' => data_get($item, 'title.rendered') ?? $item['title'] ?? null,
            'slug' => $item['slug'] ?? null,
            'short_description' => data_get($item, 'excerpt.rendered'),
            'description' => data_get($item, 'content.rendered'),
            'regular_price' => data_get($item, 'meta.price', 0),
            'sale_price' => data_get($item, 'meta.sale_price'),
            'stock_quantity' => data_get($item, 'meta.stock_qty'),
            'stock_status' => data_get($item, 'meta.stock_status'),
            'product_url' => $item['link'] ?? null,
            'images' => $this->extractImages($item),
            'category' => $this->extractCategory($item),
            'category_text' => $this->extractCategory($item),
            'attributes' => data_get($item, 'meta.attributes', []),
            'status' => $item['status'] ?? 'publish',
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
     * @param array<string, mixed> $item
     */
    private function extractImages(array $item): array
    {
        $featured = data_get($item, '_embedded.wp:featuredmedia.0.source_url');
        if (is_string($featured) && $featured !== '') {
            return [['src' => $featured]];
        }

        $custom = data_get($item, 'meta.images');
        if (is_array($custom)) {
            return $custom;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function extractCategory(array $item): ?string
    {
        $terms = data_get($item, '_embedded.wp:term', []);
        if (! is_array($terms)) {
            return null;
        }

        $categories = [];
        foreach ($terms as $taxonomyGroup) {
            if (! is_array($taxonomyGroup)) {
                continue;
            }
            foreach ($taxonomyGroup as $term) {
                if (is_array($term) && isset($term['name'])) {
                    $categories[] = (string) $term['name'];
                }
            }
        }

        $categories = array_values(array_unique(array_filter($categories)));

        return $categories === [] ? null : implode(', ', $categories);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isModifiedAfter(array $item, CarbonImmutable $since): bool
    {
        $modified = data_get($item, 'modified_gmt') ?? data_get($item, 'modified');
        if (! is_string($modified) || trim($modified) === '') {
            return true;
        }

        try {
            $modifiedAt = CarbonImmutable::parse($modified);
        } catch (\Throwable) {
            return true;
        }

        return $modifiedAt->greaterThan($since);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapping(IntegrationConnection $connection): array
    {
        $mapping = $connection->mapping_json;

        return is_array($mapping) ? $mapping : [];
    }

    private function resourcePath(IntegrationConnection $connection): string
    {
        $resource = (string) data_get($connection->config_json, 'resource_path', '/wp-json/wp/v2/posts');
        if ($resource === '') {
            throw new IntegrationAdapterException('WordPress resource_path is required.');
        }

        return str_starts_with($resource, '/') ? $resource : '/'.$resource;
    }

    private function client(IntegrationConnection $connection): PendingRequest
    {
        $credentials = $this->credentialService->decryptCredentials($connection);
        $authType = strtolower((string) ($connection->auth_type ?? 'none'));
        $client = Http::acceptJson()->timeout(30)->retry(2, 250);

        if ($authType === 'basic') {
            $username = (string) ($credentials['username'] ?? '');
            $password = (string) ($credentials['password'] ?? '');
            if ($username !== '' && $password !== '') {
                return $client->withBasicAuth($username, $password);
            }
        }

        if ($authType === 'bearer') {
            $token = (string) ($credentials['token'] ?? '');
            if ($token !== '') {
                return $client->withToken($token);
            }
        }

        return $client;
    }

    private function endpoint(IntegrationConnection $connection, string $path): string
    {
        $baseUrl = trim((string) $connection->base_url);
        if ($baseUrl === '') {
            throw new IntegrationAdapterException('WordPress base_url is required.');
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

    private function productStatusParam(IntegrationConnection $connection): string
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

        return in_array('publish', $statuses, true) ? 'publish' : 'publish';
    }

    private function isActiveStatus(string $status): bool
    {
        $normalized = strtolower(trim($status));

        return in_array($normalized, ['publish', 'published', 'active'], true);
    }
}
