<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $ownerToken;

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
            'name' => 'Bulk Delete Tenant',
            'slug' => 'bulk-delete-tenant',
            'plan_id' => $plan->id,
            'locale' => 'bs',
            'timezone' => 'Europe/Sarajevo',
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'bulk-owner@test.local',
            'password' => Hash::make('password123'),
        ]);
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'bulk-admin@test.local',
            'password' => Hash::make('password123'),
        ]);

        $this->tenant->users()->attach($owner->id, ['role' => 'owner']);
        $this->tenant->users()->attach($admin->id, ['role' => 'admin']);

        $this->ownerToken = app(ApiTokenService::class)->issueToken(
            $owner,
            $this->tenant,
            'owner',
            ['*'],
            'owner_token',
            null,
        )['plain_text_token'];

        $this->adminToken = app(ApiTokenService::class)->issueToken(
            $admin,
            $this->tenant,
            'admin',
            ['*'],
            'admin_token',
            null,
        )['plain_text_token'];
    }

    public function test_owner_can_delete_all_products(): void
    {
        Product::query()->create([
            'tenant_id' => $this->tenant->id,
            'source_type' => 'manual',
            'name' => 'Product A',
            'price' => 10,
            'currency' => 'BAM',
            'in_stock' => true,
            'status' => 'active',
        ]);
        Product::query()->create([
            'tenant_id' => $this->tenant->id,
            'source_type' => 'manual',
            'name' => 'Product B',
            'price' => 20,
            'currency' => 'BAM',
            'in_stock' => true,
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->ownerHeaders())->deleteJson('/api/admin/products');

        $response->assertOk()
            ->assertJsonPath('data.deleted_count', 2);

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'bulk_deleted',
            'entity_type' => 'products',
        ]);
    }

    public function test_admin_is_forbidden_from_bulk_delete_products(): void
    {
        Product::query()->create([
            'tenant_id' => $this->tenant->id,
            'source_type' => 'manual',
            'name' => 'Product A',
            'price' => 10,
            'currency' => 'BAM',
            'in_stock' => true,
            'status' => 'active',
        ]);

        $this->withHeaders($this->adminHeaders())
            ->deleteJson('/api/admin/products')
            ->assertForbidden();

        $this->assertDatabaseCount('products', 1);
    }

    /**
     * @return array<string, string>
     */
    private function ownerHeaders(): array
    {
        return [
            'X-Tenant-Slug' => $this->tenant->slug,
            'Authorization' => 'Bearer '.$this->ownerToken,
        ];
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
