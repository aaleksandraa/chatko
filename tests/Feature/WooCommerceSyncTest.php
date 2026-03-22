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

class WooCommerceSyncTest extends TestCase
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
            'name' => 'Woo Tenant',
            'slug' => 'woo-tenant',
            'plan_id' => $plan->id,
            'locale' => 'bs',
            'timezone' => 'Europe/Sarajevo',
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'woo-owner@test.local',
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

    public function test_woocommerce_connection_test_endpoint_succeeds(): void
    {
        Http::fake([
            'https://shop.example.com/wp-json/wc/v3/products*' => Http::response([
                ['id' => 101, 'name' => 'Hydra Serum'],
            ], 200),
        ]);

        $create = $this->withHeaders($this->adminHeaders())->postJson('/api/admin/integrations', [
            'type' => 'woocommerce',
            'name' => 'Main Woo',
            'base_url' => 'https://shop.example.com',
            'auth_type' => 'woocommerce_key_secret',
            'credentials' => [
                'consumer_key' => 'ck_test',
                'consumer_secret' => 'cs_test',
            ],
        ]);

        $create->assertCreated();
        $connectionId = $create->json('data.id');

        $test = $this->withHeaders($this->adminHeaders())
            ->postJson('/api/admin/integrations/'.$connectionId.'/test');

        $test->assertOk()
            ->assertJsonPath('data.ok', true);
    }

    public function test_woocommerce_sync_job_handles_initial_and_delta_sync(): void
    {
        $credentials = app('encrypter')->encrypt(json_encode([
            'consumer_key' => 'ck_test',
            'consumer_secret' => 'cs_test',
        ]));

        $connection = IntegrationConnection::query()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'woocommerce',
            'name' => 'Main Woo',
            'status' => 'connected',
            'base_url' => 'https://shop.example.com',
            'credentials_encrypted' => $credentials,
        ]);

        Http::fake([
            'https://shop.example.com/wp-json/wc/v3/products*' => Http::sequence()
                ->push([
                    [
                        'id' => 501,
                        'sku' => 'SERUM-501',
                        'name' => 'Hydra Serum Sensitive',
                        'slug' => 'hydra-serum-sensitive',
                        'short_description' => 'Serum for dry skin.',
                        'description' => 'Hydration support.',
                        'regular_price' => '39.90',
                        'sale_price' => '34.90',
                        'stock_quantity' => 10,
                        'stock_status' => 'instock',
                        'categories' => [['name' => 'Skincare']],
                        'images' => [['src' => 'https://img.example.com/serum.jpg']],
                        'permalink' => 'https://shop.example.com/p/hydra-serum-sensitive',
                        'status' => 'publish',
                    ],
                ], 200)
                ->push([
                    [
                        'id' => 501,
                        'sku' => 'SERUM-501',
                        'name' => 'Hydra Serum Sensitive',
                        'slug' => 'hydra-serum-sensitive',
                        'short_description' => 'Serum for dry skin.',
                        'description' => 'Hydration support.',
                        'regular_price' => '37.50',
                        'sale_price' => '32.50',
                        'stock_quantity' => 8,
                        'stock_status' => 'instock',
                        'categories' => [['name' => 'Skincare']],
                        'images' => [['src' => 'https://img.example.com/serum.jpg']],
                        'permalink' => 'https://shop.example.com/p/hydra-serum-sensitive',
                        'status' => 'publish',
                    ],
                ], 200),
        ]);

        $initialJob = ImportJob::query()->create([
            'tenant_id' => $this->tenant->id,
            'integration_connection_id' => $connection->id,
            'job_type' => 'initial_sync',
            'source_type' => 'woocommerce',
            'status' => 'pending',
        ]);

        app()->call([new RunIntegrationSyncJob($initialJob->id), 'handle']);

        $this->assertDatabaseHas('products', [
            'tenant_id' => $this->tenant->id,
            'sku' => 'SERUM-501',
            'price' => 39.90,
        ]);

        $connection->refresh();
        $this->assertNotNull($connection->last_sync_at);

        $deltaJob = ImportJob::query()->create([
            'tenant_id' => $this->tenant->id,
            'integration_connection_id' => $connection->id,
            'job_type' => 'delta_sync',
            'source_type' => 'woocommerce',
            'status' => 'pending',
        ]);

        app()->call([new RunIntegrationSyncJob($deltaJob->id), 'handle']);

        $product = Product::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('sku', 'SERUM-501')
            ->first();

        $this->assertNotNull($product);
        $this->assertSame('37.50', (string) $product->price);
        $this->assertSame('32.50', (string) $product->sale_price);
    }

    public function test_woocommerce_sync_uses_total_pages_header_when_page_size_is_capped(): void
    {
        $credentials = app('encrypter')->encrypt(json_encode([
            'consumer_key' => 'ck_test',
            'consumer_secret' => 'cs_test',
        ]));

        $connection = IntegrationConnection::query()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'woocommerce',
            'name' => 'Main Woo',
            'status' => 'connected',
            'base_url' => 'https://shop.example.com',
            'credentials_encrypted' => $credentials,
        ]);

        Http::fake([
            'https://shop.example.com/wp-json/wc/v3/products*' => Http::sequence()
                ->push([
                    [
                        'id' => 601,
                        'sku' => 'SERUM-601',
                        'name' => 'Page 1 Product',
                        'regular_price' => '19.90',
                        'stock_quantity' => 5,
                        'stock_status' => 'instock',
                        'categories' => [['name' => 'Skincare']],
                        'images' => [],
                        'status' => 'publish',
                    ],
                ], 200, ['X-WP-TotalPages' => '2'])
                ->push([
                    [
                        'id' => 602,
                        'sku' => 'SERUM-602',
                        'name' => 'Page 2 Product',
                        'regular_price' => '29.90',
                        'stock_quantity' => 8,
                        'stock_status' => 'instock',
                        'categories' => [['name' => 'Skincare']],
                        'images' => [],
                        'status' => 'publish',
                    ],
                ], 200, ['X-WP-TotalPages' => '2']),
        ]);

        $job = ImportJob::query()->create([
            'tenant_id' => $this->tenant->id,
            'integration_connection_id' => $connection->id,
            'job_type' => 'initial_sync',
            'source_type' => 'woocommerce',
            'status' => 'pending',
        ]);

        app()->call([new RunIntegrationSyncJob($job->id), 'handle']);

        $this->assertDatabaseHas('products', [
            'tenant_id' => $this->tenant->id,
            'sku' => 'SERUM-601',
        ]);
        $this->assertDatabaseHas('products', [
            'tenant_id' => $this->tenant->id,
            'sku' => 'SERUM-602',
        ]);
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
