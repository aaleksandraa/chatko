<?php

namespace Tests\Feature;

use App\Jobs\RunIntegrationSyncJob;
use App\Models\ImportJob;
use App\Models\IntegrationConnection;
use App\Models\Plan;
use App\Models\Product;
use App\Models\SourceMappingPreset;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WordPressAndShopifyAdapterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.openai.api_key', null);

        $plan = Plan::query()->create([
            'name' => 'Starter',
            'code' => 'starter',
        ]);

        $this->tenant = Tenant::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Adapters Tenant',
            'slug' => 'adapters-tenant',
            'plan_id' => $plan->id,
            'locale' => 'bs',
            'timezone' => 'Europe/Sarajevo',
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'adapters-owner@test.local',
            'password' => Hash::make('password123'),
        ]);
        $this->tenant->users()->attach($owner->id, ['role' => 'owner']);

        $issued = app(ApiTokenService::class)->issueToken(
            $owner,
            $this->tenant,
            'owner',
            ['*'],
            'test_token',
            null,
        );
        $this->adminToken = $issued['plain_text_token'];
    }

    public function test_wordpress_rest_sync_and_mapping_presets(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts*' => Http::response([
                [
                    'id' => 901,
                    'title' => ['rendered' => 'WP Product One'],
                    'slug' => 'wp-product-one',
                    'excerpt' => ['rendered' => '<p>Short text</p>'],
                    'content' => ['rendered' => '<p>Long text</p>'],
                    'link' => 'https://wp.example.com/wp-product-one',
                    'status' => 'publish',
                    'modified_gmt' => '2026-03-21T00:00:00',
                    'meta' => [
                        'sku' => 'WP-901',
                        'price' => '55.50',
                        'stock_qty' => 7,
                        'stock_status' => 'instock',
                    ],
                    '_embedded' => [
                        'wp:featuredmedia' => [
                            ['source_url' => 'https://wp.example.com/image.jpg'],
                        ],
                        'wp:term' => [
                            [
                                ['name' => 'Category A'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $create = $this->withHeaders($this->adminHeaders())->postJson('/api/admin/integrations', [
            'type' => 'wordpress_rest',
            'name' => 'WP Source',
            'base_url' => 'https://wp.example.com',
            'auth_type' => 'none',
            'config_json' => [
                'resource_path' => '/wp-json/wp/v2/posts',
            ],
            'mapping_json' => [
                'name' => 'title.rendered',
                'price' => [
                    'path' => 'meta.price',
                    'transform' => 'decimal',
                ],
            ],
        ]);

        $create->assertCreated();
        $integrationId = (int) $create->json('data.id');

        $test = $this->withHeaders($this->adminHeaders())
            ->postJson('/api/admin/integrations/'.$integrationId.'/test');

        $test->assertOk()->assertJsonPath('data.ok', true);

        $presetCreate = $this->withHeaders($this->adminHeaders())
            ->postJson('/api/admin/integrations/'.$integrationId.'/mapping-presets', [
                'name' => 'WP Default Mapping',
                'mapping_json' => [
                    'name' => 'title.rendered',
                    'price' => [
                        'path' => 'meta.price',
                        'transform' => 'decimal',
                    ],
                    'sku' => 'meta.sku',
                ],
                'apply_to_connection' => true,
            ]);

        $presetCreate->assertCreated();
        $presetId = (int) $presetCreate->json('data.id');
        $this->assertDatabaseHas('source_mapping_presets', ['id' => $presetId, 'tenant_id' => $this->tenant->id]);

        $preset = SourceMappingPreset::query()->find($presetId);
        $this->assertNotNull($preset);

        $connection = IntegrationConnection::query()->findOrFail($integrationId);
        $job = ImportJob::query()->create([
            'tenant_id' => $this->tenant->id,
            'integration_connection_id' => $connection->id,
            'job_type' => 'initial_sync',
            'source_type' => 'wordpress_rest',
            'status' => 'pending',
        ]);

        app()->call([new RunIntegrationSyncJob($job->id), 'handle']);

        $this->assertDatabaseHas('products', [
            'tenant_id' => $this->tenant->id,
            'sku' => 'WP-901',
            'name' => 'WP Product One',
        ]);
    }

    public function test_shopify_adapter_connection_test_and_sync(): void
    {
        Http::fake(function ($request) {
            $body = json_decode((string) $request->body(), true);
            $query = (string) ($body['query'] ?? '');

            if (str_contains($query, 'shop {')) {
                return Http::response([
                    'data' => [
                        'shop' => [
                            'name' => 'Demo Shopify Store',
                            'myshopifyDomain' => 'demo.myshopify.com',
                        ],
                    ],
                ], 200);
            }

            return Http::response([
                'data' => [
                    'products' => [
                        'edges' => [
                            [
                                'cursor' => 'cursor-1',
                                'node' => [
                                    'id' => 'gid://shopify/Product/7001',
                                    'title' => 'Shopify Serum',
                                    'handle' => 'shopify-serum',
                                    'descriptionHtml' => '<p>Shopify product description</p>',
                                    'productType' => 'Skincare',
                                    'vendor' => 'Shopify Brand',
                                    'onlineStoreUrl' => 'https://demo.myshopify.com/products/shopify-serum',
                                    'status' => 'ACTIVE',
                                    'tags' => ['serum', 'skin'],
                                    'updatedAt' => '2026-03-21T00:00:00Z',
                                    'images' => [
                                        'nodes' => [
                                            ['url' => 'https://cdn.shopify.com/image1.jpg', 'altText' => 'Image'],
                                        ],
                                    ],
                                    'variants' => [
                                        'nodes' => [
                                            [
                                                'id' => 'gid://shopify/ProductVariant/1',
                                                'sku' => 'SHOP-7001',
                                                'price' => '45.00',
                                                'compareAtPrice' => '49.00',
                                                'inventoryQuantity' => 13,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'pageInfo' => ['hasNextPage' => false],
                    ],
                ],
            ], 200);
        });

        $create = $this->withHeaders($this->adminHeaders())->postJson('/api/admin/integrations', [
            'type' => 'shopify',
            'name' => 'Shopify Source',
            'base_url' => 'https://demo.myshopify.com',
            'auth_type' => 'shopify_token',
            'credentials' => [
                'access_token' => 'shpat_test_token',
            ],
            'config_json' => [
                'api_version' => '2025-01',
            ],
        ]);

        $create->assertCreated();
        $connectionId = (int) $create->json('data.id');

        $test = $this->withHeaders($this->adminHeaders())
            ->postJson('/api/admin/integrations/'.$connectionId.'/test');

        $test->assertOk()->assertJsonPath('data.ok', true);

        $connection = IntegrationConnection::query()->findOrFail($connectionId);
        $job = ImportJob::query()->create([
            'tenant_id' => $this->tenant->id,
            'integration_connection_id' => $connection->id,
            'job_type' => 'initial_sync',
            'source_type' => 'shopify',
            'status' => 'pending',
        ]);

        app()->call([new RunIntegrationSyncJob($job->id), 'handle']);

        $product = Product::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('sku', 'SHOP-7001')
            ->first();

        $this->assertNotNull($product);
        $this->assertSame('Shopify Serum', $product->name);
        $this->assertSame('Skincare', $product->category_text);
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        return [
            'X-Tenant-Slug' => $this->tenant->slug,
            'Authorization' => 'Bearer '.$this->adminToken,
        ];
    }
}

