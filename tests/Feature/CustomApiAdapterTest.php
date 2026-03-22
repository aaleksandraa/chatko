<?php

namespace Tests\Feature;

use App\Jobs\RunIntegrationSyncJob;
use App\Models\ImportJob;
use App\Models\IntegrationConnection;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomApiAdapterTest extends TestCase
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
            'name' => 'Custom API Tenant',
            'slug' => 'custom-api-tenant',
            'plan_id' => $plan->id,
            'locale' => 'bs',
            'timezone' => 'Europe/Sarajevo',
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'custom-api-owner@test.local',
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

    public function test_custom_api_adapter_sync_and_transform_rules(): void
    {
        $oldOne = now()->subHours(2)->toIso8601String();
        $oldTwo = now()->subHour()->toIso8601String();
        $oldThree = now()->subMinutes(30)->toIso8601String();
        $freshDelta = now()->addMinute()->toIso8601String();

        Http::fake([
            'https://api.example.com/v1/products*' => Http::sequence()
                ->push([
                    'payload' => [
                        'items' => [
                            [
                                'id' => 'p-100',
                                'product_name' => 'Custom Serum',
                                'identifiers' => ['sku' => 'CUS-100'],
                                'pricing' => ['current' => '42.50', 'sale' => '38.00', 'currency' => 'BAM'],
                                'inventory' => ['qty' => 12, 'status' => 'in_stock'],
                                'links' => ['web' => 'https://shop.example.com/p/custom-serum'],
                                'media' => ['gallery' => [['url' => 'https://cdn.example.com/img1.jpg']]],
                                'taxonomy' => ['categories' => ['Skincare'], 'tag_string' => 'hydration|daily'],
                                'content' => ['html' => '<p>Sample.</p>'],
                                'state' => ['status' => 'ACTIVE'],
                                'updated_at' => $oldOne,
                            ],
                        ],
                    ],
                ], 200)
                ->push([
                    'payload' => [
                        'items' => [
                            [
                                'id' => 'p-100',
                                'product_name' => '  Custom Serum  ',
                                'identifiers' => ['sku' => 'CUS-100'],
                                'pricing' => ['current' => '42.50', 'sale' => '38.00', 'currency' => 'BAM'],
                                'inventory' => ['qty' => '12', 'status' => 'in_stock'],
                                'links' => ['web' => 'https://shop.example.com/p/custom-serum'],
                                'media' => [
                                    'gallery' => [
                                        ['url' => 'https://cdn.example.com/img1.jpg'],
                                        ['src' => 'https://cdn.example.com/img2.jpg', 'alt_text' => 'image 2'],
                                    ],
                                ],
                                'taxonomy' => [
                                    'categories' => ['Skincare', 'Serums'],
                                    'tag_string' => 'hydration|sensitive|daily',
                                ],
                                'content' => ['html' => '<p>Great <strong>serum</strong> for daily use.</p>'],
                                'state' => ['status' => 'ACTIVE'],
                                'updated_at' => $oldOne,
                            ],
                            [
                                'id' => 'p-101',
                                'product_name' => 'Custom Cream',
                                'identifiers' => ['sku' => 'CUS-101'],
                                'pricing' => ['current' => '29.90', 'currency' => 'BAM'],
                                'inventory' => ['qty' => 5, 'status' => 'in_stock'],
                                'links' => ['web' => 'https://shop.example.com/p/custom-cream'],
                                'media' => ['gallery' => []],
                                'taxonomy' => ['categories' => ['Skincare'], 'tag_string' => 'barrier|cream'],
                                'content' => ['html' => '<p>Barrier cream.</p>'],
                                'state' => ['status' => 'ACTIVE'],
                                'updated_at' => $oldTwo,
                            ],
                        ],
                    ],
                ], 200)
                ->push([
                    'payload' => [
                        'items' => [
                            [
                                'id' => 'p-102',
                                'product_name' => 'Custom Cleanser',
                                'identifiers' => ['sku' => 'CUS-102'],
                                'pricing' => ['current' => '24.00', 'currency' => 'BAM'],
                                'inventory' => ['qty' => 9, 'status' => 'in_stock'],
                                'links' => ['web' => 'https://shop.example.com/p/custom-cleanser'],
                                'media' => ['gallery' => []],
                                'taxonomy' => ['categories' => ['Skincare'], 'tag_string' => 'cleanser|daily'],
                                'content' => ['html' => '<p>Daily cleanser.</p>'],
                                'state' => ['status' => 'ACTIVE'],
                                'updated_at' => $oldThree,
                            ],
                        ],
                    ],
                ], 200)
                ->push([
                    'payload' => [
                        'items' => [
                            [
                                'id' => 'p-100',
                                'product_name' => 'Custom Serum',
                                'identifiers' => ['sku' => 'CUS-100'],
                                'pricing' => ['current' => '40.00', 'sale' => '35.00', 'currency' => 'BAM'],
                                'inventory' => ['qty' => 8, 'status' => 'in_stock'],
                                'links' => ['web' => 'https://shop.example.com/p/custom-serum'],
                                'media' => ['gallery' => [['url' => 'https://cdn.example.com/img1.jpg']]],
                                'taxonomy' => ['categories' => ['Skincare', 'Serums'], 'tag_string' => 'hydration|sensitive'],
                                'content' => ['html' => '<p>Updated serum description.</p>'],
                                'state' => ['status' => 'ACTIVE'],
                                'updated_at' => $freshDelta,
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $create = $this->withHeaders($this->adminHeaders())->postJson('/api/admin/integrations', [
            'type' => 'custom_api',
            'name' => 'Custom API Source',
            'base_url' => 'https://api.example.com',
            'auth_type' => 'bearer',
            'credentials' => [
                'token' => 'custom_api_token',
            ],
            'config_json' => [
                'method' => 'GET',
                'products_endpoint' => '/v1/products',
                'products_path' => 'payload.items',
                'modified_field' => 'updated_at',
                'pagination' => [
                    'type' => 'page',
                    'page_param' => 'page',
                    'per_page_param' => 'limit',
                    'per_page' => 2,
                    'max_pages' => 10,
                ],
                'query' => [
                    'active' => '1',
                ],
            ],
            'mapping_json' => [
                'name' => [
                    'path' => 'product_name',
                    'transform' => 'trim',
                ],
                'sku' => 'identifiers.sku',
                'price' => [
                    'path' => 'pricing.current',
                    'transform' => 'decimal',
                ],
                'sale_price' => [
                    'path' => 'pricing.sale',
                    'transform' => 'decimal',
                ],
                'currency' => [
                    'path' => 'pricing.currency',
                    'default' => 'BAM',
                ],
                'stock_quantity' => [
                    'path' => 'inventory.qty',
                    'transform' => 'integer',
                ],
                'in_stock' => [
                    'path' => 'inventory.status',
                    'transform' => 'bool_from_stock_status',
                ],
                'product_url' => 'links.web',
                'images' => [
                    'path' => 'media.gallery',
                    'transform' => 'extract_image_srcs',
                ],
                'category_text' => [
                    'path' => 'taxonomy.categories',
                    'transform' => [
                        'name' => 'join',
                        'delimiter' => ', ',
                    ],
                ],
                'tags' => [
                    'path' => 'taxonomy.tag_string',
                    'transform' => [
                        'name' => 'split',
                        'delimiter' => '|',
                    ],
                ],
                'description' => [
                    'path' => 'content.html',
                    'transform' => 'strip_html',
                ],
                'status' => [
                    'from' => ['state.status', 'status'],
                    'transforms' => ['lowercase'],
                ],
            ],
        ]);

        $create->assertCreated();
        $connectionId = (int) $create->json('data.id');

        $test = $this->withHeaders($this->adminHeaders())
            ->postJson('/api/admin/integrations/'.$connectionId.'/test');

        $test->assertOk()->assertJsonPath('data.ok', true);

        $connection = IntegrationConnection::query()->findOrFail($connectionId);

        $initialJob = ImportJob::query()->create([
            'tenant_id' => $this->tenant->id,
            'integration_connection_id' => $connection->id,
            'job_type' => 'initial_sync',
            'source_type' => 'custom_api',
            'status' => 'pending',
        ]);

        app()->call([new RunIntegrationSyncJob($initialJob->id), 'handle']);

        $product = Product::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('sku', 'CUS-100')
            ->first();

        $this->assertNotNull($product);
        $this->assertSame('Custom Serum', $product->name);
        $this->assertSame('42.50', (string) $product->price);
        $this->assertSame('38.00', (string) $product->sale_price);
        $this->assertSame(12, (int) $product->stock_qty);
        $this->assertTrue((bool) $product->in_stock);
        $this->assertStringContainsString('Skincare', (string) $product->category_text);
        $this->assertStringContainsString('Serums', (string) $product->category_text);
        $this->assertSame('Great serum for daily use.', $product->long_description);
        $this->assertIsArray($product->tags_json);
        $this->assertSame(['hydration', 'sensitive', 'daily'], $product->tags_json);
        $this->assertSame('active', $product->status);

        $connection->refresh();
        $this->assertNotNull($connection->last_sync_at);

        $deltaJob = ImportJob::query()->create([
            'tenant_id' => $this->tenant->id,
            'integration_connection_id' => $connection->id,
            'job_type' => 'delta_sync',
            'source_type' => 'custom_api',
            'status' => 'pending',
        ]);

        app()->call([new RunIntegrationSyncJob($deltaJob->id), 'handle']);

        $updated = Product::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('sku', 'CUS-100')
            ->first();

        $this->assertNotNull($updated);
        $this->assertSame('40.00', (string) $updated->price);
        $this->assertSame('35.00', (string) $updated->sale_price);
        $this->assertSame(8, (int) $updated->stock_qty);
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
