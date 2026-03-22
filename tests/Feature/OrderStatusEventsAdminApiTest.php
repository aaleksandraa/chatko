<?php

namespace Tests\Feature;

use App\Models\IntegrationConnection;
use App\Models\OrderStatusEvent;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderStatusEventsAdminApiTest extends TestCase
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
            'name' => 'Order Events Tenant',
            'slug' => 'order-events-tenant',
            'plan_id' => $plan->id,
            'locale' => 'bs',
            'timezone' => 'Europe/Sarajevo',
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'events-owner@test.local',
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

    public function test_admin_can_filter_order_status_events_by_status_provider_and_order_id(): void
    {
        $woo = IntegrationConnection::query()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'woocommerce',
            'name' => 'Woo Main',
            'status' => 'connected',
            'base_url' => 'https://woo.example.local',
        ]);

        $shopify = IntegrationConnection::query()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'shopify',
            'name' => 'Shopify Main',
            'status' => 'connected',
            'base_url' => 'https://shopify.example.local',
        ]);

        OrderStatusEvent::query()->create([
            'tenant_id' => $this->tenant->id,
            'integration_connection_id' => $woo->id,
            'external_order_id' => 'ORD-1001',
            'provider_status' => 'processing',
            'normalized_status' => 'paid',
            'message_text' => 'Order paid',
            'occurred_at' => now(),
        ]);

        OrderStatusEvent::query()->create([
            'tenant_id' => $this->tenant->id,
            'integration_connection_id' => $shopify->id,
            'external_order_id' => 'ORD-2002',
            'provider_status' => 'fulfilled',
            'normalized_status' => 'shipped',
            'message_text' => 'Order shipped',
            'occurred_at' => now()->addMinute(),
        ]);

        $filtered = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/order-status-events?status=paid&provider=woocommerce&order_id=1001');

        $filtered->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_order_id', 'ORD-1001')
            ->assertJsonPath('data.0.normalized_status', 'paid')
            ->assertJsonPath('data.0.integration_connection.type', 'woocommerce');

        $all = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/order-status-events');

        $all->assertOk()
            ->assertJsonCount(2, 'data');
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
