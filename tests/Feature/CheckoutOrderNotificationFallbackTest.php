<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationCheckout;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\Widget;
use App\Services\Conversation\CheckoutOrderNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CheckoutOrderNotificationFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_failure_falls_back_to_sync_send(): void
    {
        $tenant = Tenant::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Fallback Tenant',
            'slug' => 'fallback-tenant',
            'locale' => 'bs',
            'timezone' => 'Europe/Sarajevo',
        ]);

        $widget = Widget::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Fallback Widget',
            'public_key' => 'wpk_fallback_test',
            'secret_key' => 'wsk_fallback_test',
            'default_locale' => 'bs',
            'is_active' => true,
        ]);

        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'widget_id' => $widget->id,
            'visitor_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'session_id' => 'session-fallback-test',
            'channel' => 'web_widget',
            'locale' => 'bs',
            'status' => 'active',
            'started_at' => now(),
        ]);

        $checkout = ConversationCheckout::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'status' => 'placed',
            'items_json' => [
                [
                    'name' => 'Fallback Produkt',
                    'quantity' => 1,
                    'unit_price' => 29.90,
                    'currency' => 'BAM',
                ],
            ],
            'customer_first_name' => 'Ana',
            'customer_last_name' => 'Kupac',
            'customer_name' => 'Ana Kupac',
            'customer_email' => 'buyer@example.com',
            'customer_phone' => '+38761111222',
            'delivery_address' => 'Glavna 1',
            'delivery_city' => 'Sarajevo',
            'delivery_postal_code' => '71000',
            'delivery_country' => 'BA',
            'payment_method' => 'cod',
            'estimated_total' => 29.90,
            'currency' => 'BAM',
            'submitted_at' => now(),
        ]);

        $connection = IntegrationConnection::query()->create([
            'tenant_id' => $tenant->id,
            'type' => 'woocommerce',
            'name' => 'Fallback Connection',
            'status' => 'connected',
        ]);

        Mail::shouldReceive('to')->once()->with('buyer@example.com')->andReturnSelf();
        Mail::shouldReceive('queue')->once()->andThrow(new \RuntimeException('Queue unavailable'));
        Mail::shouldReceive('to')->once()->with('buyer@example.com')->andReturnSelf();
        Mail::shouldReceive('send')->once();

        app(CheckoutOrderNotificationService::class)->sendOrderPlacedNotifications(
            $checkout,
            $connection,
            [
                'external_order_id' => 'FB-1001',
                'status' => 'pending',
                'checkout_url' => null,
                'payment_required' => false,
                'currency' => 'BAM',
                'total' => 29.90,
                'integration_type' => 'woocommerce',
            ],
        );
    }
}

