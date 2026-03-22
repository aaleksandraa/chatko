<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\IntegrationConnection;
use App\Models\OrderAttributed;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Widget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderStatusWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Conversation $conversation;

    private IntegrationConnection $connection;

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
            'name' => 'Webhook Tenant',
            'slug' => 'webhook-tenant',
            'plan_id' => $plan->id,
            'locale' => 'bs',
            'timezone' => 'Europe/Sarajevo',
        ]);

        $widget = Widget::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Webhook Widget',
            'public_key' => 'wpk_webhook_test',
            'secret_key' => 'wsk_webhook_secret',
            'default_locale' => 'bs',
            'is_active' => true,
        ]);

        $this->conversation = Conversation::query()->create([
            'tenant_id' => $this->tenant->id,
            'widget_id' => $widget->id,
            'visitor_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'session_id' => 'sess-webhook-1',
            'channel' => 'web_widget',
            'locale' => 'bs',
            'started_at' => now(),
            'status' => 'active',
            'converted' => true,
        ]);

        $this->connection = IntegrationConnection::query()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'custom_api',
            'name' => 'Custom API Orders',
            'status' => 'connected',
            'base_url' => 'https://api.example.local',
            'auth_type' => 'bearer',
            'config_json' => [
                'order' => [
                    'webhook_token' => 'webhook_token_123',
                    'webhook_order_id_path' => 'order.id',
                    'webhook_status_path' => 'order.status',
                    'webhook_tracking_url_path' => 'order.tracking_url',
                    'webhook_occurred_at_path' => 'order.updated_at',
                ],
            ],
        ]);

        OrderAttributed::query()->create([
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'external_order_id' => 'ORD-501',
            'order_value' => 79.80,
            'currency' => 'BAM',
            'attributed_model' => 'chat_checkout',
        ]);
    }

    public function test_webhook_status_sync_writes_timeline_and_updates_latest_status(): void
    {
        $paid = $this->postJson('/api/webhooks/integrations/'.$this->connection->id.'/orders/status?token=webhook_token_123', [
            'order' => [
                'id' => 'ORD-501',
                'status' => 'paid',
                'tracking_url' => 'https://tracking.example.local/paid',
                'updated_at' => now()->toIso8601String(),
            ],
        ]);

        $paid->assertOk()
            ->assertJsonPath('data.external_order_id', 'ORD-501')
            ->assertJsonPath('data.normalized_status', 'paid')
            ->assertJsonPath('data.conversation_id', $this->conversation->id);

        $this->assertDatabaseHas('order_status_events', [
            'tenant_id' => $this->tenant->id,
            'external_order_id' => 'ORD-501',
            'normalized_status' => 'paid',
        ]);

        $this->assertDatabaseHas('conversation_messages', [
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'role' => 'system',
            'intent' => 'order_status_update',
        ]);

        $this->assertDatabaseHas('orders_attributed', [
            'tenant_id' => $this->tenant->id,
            'external_order_id' => 'ORD-501',
            'last_status' => 'paid',
        ]);

        $shipped = $this->postJson('/api/webhooks/integrations/'.$this->connection->id.'/orders/status?token=webhook_token_123', [
            'order' => [
                'id' => 'ORD-501',
                'status' => 'shipped',
                'tracking_url' => 'https://tracking.example.local/shipped',
                'updated_at' => now()->addMinute()->toIso8601String(),
            ],
        ]);

        $shipped->assertOk()
            ->assertJsonPath('data.normalized_status', 'shipped');

        $cancelled = $this->postJson('/api/webhooks/integrations/'.$this->connection->id.'/orders/status?token=webhook_token_123', [
            'order' => [
                'id' => 'ORD-501',
                'status' => 'cancelled',
                'updated_at' => now()->addMinutes(2)->toIso8601String(),
            ],
        ]);

        $cancelled->assertOk()
            ->assertJsonPath('data.normalized_status', 'cancelled');

        $conversation = $this->conversation->fresh();
        $this->assertNotNull($conversation);
        $this->assertSame('cancelled', $conversation->status);
        $this->assertFalse((bool) $conversation->converted);

        $this->assertDatabaseHas('orders_attributed', [
            'tenant_id' => $this->tenant->id,
            'external_order_id' => 'ORD-501',
            'last_status' => 'cancelled',
        ]);
    }

    public function test_webhook_rejects_invalid_token(): void
    {
        $response = $this->postJson('/api/webhooks/integrations/'.$this->connection->id.'/orders/status?token=wrong-token', [
            'order' => [
                'id' => 'ORD-501',
                'status' => 'paid',
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Webhook token is invalid.');
    }

    public function test_woocommerce_status_mapping_processing_and_completed(): void
    {
        $wooConnection = IntegrationConnection::query()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'woocommerce',
            'name' => 'Woo Webhook',
            'status' => 'connected',
            'base_url' => 'https://woo.example.local',
            'config_json' => [
                'order' => [
                    'webhook_token' => 'woo_token_123',
                ],
            ],
        ]);

        OrderAttributed::query()->create([
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'external_order_id' => '9001',
            'order_value' => 80,
            'currency' => 'BAM',
            'attributed_model' => 'chat_checkout',
        ]);

        $processing = $this->postJson('/api/webhooks/integrations/'.$wooConnection->id.'/orders/status?token=woo_token_123', [
            'id' => 9001,
            'status' => 'processing',
        ]);

        $processing->assertOk()
            ->assertJsonPath('data.normalized_status', 'paid');

        $completed = $this->postJson('/api/webhooks/integrations/'.$wooConnection->id.'/orders/status?token=woo_token_123', [
            'id' => 9001,
            'status' => 'completed',
        ]);

        $completed->assertOk()
            ->assertJsonPath('data.normalized_status', 'shipped');
    }

    public function test_shopify_status_mapping_paid_shipped_cancelled(): void
    {
        $shopifyConnection = IntegrationConnection::query()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'shopify',
            'name' => 'Shopify Webhook',
            'status' => 'connected',
            'base_url' => 'https://shopify.example.local',
            'config_json' => [
                'order' => [
                    'webhook_token' => 'shopify_token_123',
                ],
            ],
        ]);

        OrderAttributed::query()->create([
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'external_order_id' => '8801',
            'order_value' => 45,
            'currency' => 'USD',
            'attributed_model' => 'chat_checkout',
        ]);

        $paid = $this->postJson('/api/webhooks/integrations/'.$shopifyConnection->id.'/orders/status?token=shopify_token_123', [
            'id' => 8801,
            'financial_status' => 'paid',
            'fulfillment_status' => null,
        ]);

        $paid->assertOk()
            ->assertJsonPath('data.normalized_status', 'paid');

        $shipped = $this->postJson('/api/webhooks/integrations/'.$shopifyConnection->id.'/orders/status?token=shopify_token_123', [
            'id' => 8801,
            'financial_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
        ]);

        $shipped->assertOk()
            ->assertJsonPath('data.normalized_status', 'shipped');

        $cancelled = $this->postJson('/api/webhooks/integrations/'.$shopifyConnection->id.'/orders/status?token=shopify_token_123', [
            'id' => 8801,
            'financial_status' => 'voided',
            'cancelled_at' => now()->toIso8601String(),
        ]);

        $cancelled->assertOk()
            ->assertJsonPath('data.normalized_status', 'cancelled');
    }
}
