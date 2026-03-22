<?php

namespace Tests\Feature;

use App\Mail\CheckoutOrderCustomerMail;
use App\Mail\CheckoutOrderMerchantMail;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Widget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WidgetCheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Widget $widget;

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
            'name' => 'Checkout Tenant',
            'slug' => 'checkout-tenant',
            'plan_id' => $plan->id,
            'locale' => 'bs',
            'timezone' => 'Europe/Sarajevo',
            'support_email' => 'sales@checkout-tenant.test',
        ]);

        $this->widget = Widget::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Checkout Widget',
            'public_key' => 'wpk_checkout_test',
            'secret_key' => 'wsk_checkout_secret',
            'default_locale' => 'bs',
            'is_active' => true,
        ]);
    }

    public function test_widget_checkout_cod_places_real_woocommerce_order(): void
    {
        Mail::fake();

        Http::fake([
            'https://shop.example.com/wp-json/wc/v3/orders*' => Http::response([
                'id' => 9001,
                'status' => 'pending',
                'total' => '79.80',
                'currency' => 'BAM',
                'checkout_payment_url' => null,
            ], 201),
        ]);

        $credentials = app('encrypter')->encrypt(json_encode([
            'consumer_key' => 'ck_test',
            'consumer_secret' => 'cs_test',
        ]));

        $connection = \App\Models\IntegrationConnection::query()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'woocommerce',
            'name' => 'Woo Checkout',
            'status' => 'connected',
            'base_url' => 'https://shop.example.com',
            'credentials_encrypted' => $credentials,
            'auth_type' => 'woocommerce_key_secret',
        ]);

        $product = Product::query()->create([
            'tenant_id' => $this->tenant->id,
            'source_type' => 'woocommerce',
            'source_connection_id' => $connection->id,
            'external_id' => '501',
            'sku' => 'SERUM-501',
            'name' => 'Hydra Serum',
            'price' => 39.90,
            'currency' => 'BAM',
            'in_stock' => true,
            'status' => 'active',
        ]);

        $session = $this->postJson('/api/widget/session/start', [
            'public_key' => $this->widget->public_key,
            'visitor_uuid' => 'visitor-checkout-1',
            'source_url' => 'https://example.com',
        ]);

        $session->assertCreated();
        $conversationId = (int) $session->json('data.conversation_id');
        $sessionToken = (string) $session->json('data.widget_session_token');

        $upsert = $this->postJson('/api/widget/checkout', [
            'public_key' => $this->widget->public_key,
            'conversation_id' => $conversationId,
            'widget_session_token' => $sessionToken,
            'customer_first_name' => 'Test',
            'customer_last_name' => 'Kupac',
            'customer_email' => 'kupac@example.com',
            'customer_phone' => '+38761111222',
            'delivery_address' => 'Glavna 1',
            'delivery_city' => 'Sarajevo',
            'delivery_postal_code' => '71000',
            'delivery_country' => 'BA',
            'payment_method' => 'cod',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $upsert->assertOk()
            ->assertJsonPath('data.checkout.status', 'awaiting_confirmation');

        $confirm = $this->postJson('/api/widget/checkout/confirm', [
            'public_key' => $this->widget->public_key,
            'conversation_id' => $conversationId,
            'widget_session_token' => $sessionToken,
        ]);

        $confirm->assertOk()
            ->assertJsonPath('data.order.external_order_id', '9001')
            ->assertJsonPath('data.order.payment_required', false)
            ->assertJsonPath('data.checkout.status', 'placed');

        $this->assertDatabaseHas('orders_attributed', [
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $conversationId,
            'external_order_id' => '9001',
            'currency' => 'BAM',
        ]);

        Mail::assertQueued(CheckoutOrderCustomerMail::class, function (CheckoutOrderCustomerMail $mail): bool {
            return $mail->hasTo('kupac@example.com')
                && (string) data_get($mail->data, 'order_id') === '9001';
        });

        Mail::assertQueued(CheckoutOrderMerchantMail::class, function (CheckoutOrderMerchantMail $mail): bool {
            return $mail->hasTo('sales@checkout-tenant.test')
                && (string) data_get($mail->data, 'order_id') === '9001';
        });
    }

    public function test_widget_checkout_requires_first_last_name_and_postal_code(): void
    {
        $product = Product::query()->create([
            'tenant_id' => $this->tenant->id,
            'source_type' => 'manual',
            'name' => 'Basic Cream',
            'price' => 12.50,
            'currency' => 'BAM',
            'in_stock' => true,
            'status' => 'active',
        ]);

        $session = $this->postJson('/api/widget/session/start', [
            'public_key' => $this->widget->public_key,
            'visitor_uuid' => 'visitor-checkout-required-fields',
            'source_url' => 'https://example.com',
        ]);

        $session->assertCreated();
        $conversationId = (int) $session->json('data.conversation_id');
        $sessionToken = (string) $session->json('data.widget_session_token');

        $upsert = $this->postJson('/api/widget/checkout', [
            'public_key' => $this->widget->public_key,
            'conversation_id' => $conversationId,
            'widget_session_token' => $sessionToken,
            'customer_first_name' => 'Test',
            'customer_email' => 'kupac@example.com',
            'customer_phone' => '+38761111222',
            'delivery_address' => 'Glavna 1',
            'delivery_city' => 'Sarajevo',
            'payment_method' => 'cod',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $upsert->assertOk()
            ->assertJsonPath('data.checkout.status', 'collecting_customer')
            ->assertJsonPath('data.checkout.missing_fields.0', 'customer_last_name');

        $missing = (array) $upsert->json('data.checkout.missing_fields');
        $this->assertContains('delivery_postal_code', $missing);
    }

    public function test_widget_checkout_online_returns_shopify_payment_link(): void
    {
        Mail::fake();

        Http::fake([
            'https://demo.myshopify.com/admin/api/2025-01/draft_orders.json' => Http::response([
                'draft_order' => [
                    'id' => 8801,
                    'status' => 'open',
                    'total_price' => '45.00',
                    'currency' => 'USD',
                    'invoice_url' => 'https://demo.myshopify.com/draft_orders/8801/invoice',
                ],
            ], 201),
        ]);

        $credentials = app('encrypter')->encrypt(json_encode([
            'access_token' => 'shpat_test_token',
        ]));

        $connection = \App\Models\IntegrationConnection::query()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'shopify',
            'name' => 'Shopify Checkout',
            'status' => 'connected',
            'base_url' => 'https://demo.myshopify.com',
            'credentials_encrypted' => $credentials,
            'auth_type' => 'shopify_token',
            'config_json' => [
                'api_version' => '2025-01',
                'order' => [
                    'use_draft_order_for_online' => true,
                ],
            ],
        ]);

        $product = Product::query()->create([
            'tenant_id' => $this->tenant->id,
            'source_type' => 'shopify',
            'source_connection_id' => $connection->id,
            'external_id' => '7001',
            'sku' => 'SHOP-7001',
            'name' => 'Shopify Serum',
            'price' => 45.00,
            'currency' => 'USD',
            'in_stock' => true,
            'status' => 'active',
        ]);

        $session = $this->postJson('/api/widget/session/start', [
            'public_key' => $this->widget->public_key,
            'visitor_uuid' => 'visitor-checkout-2',
            'source_url' => 'https://example.com',
        ]);

        $session->assertCreated();
        $conversationId = (int) $session->json('data.conversation_id');
        $sessionToken = (string) $session->json('data.widget_session_token');

        $upsert = $this->postJson('/api/widget/checkout', [
            'public_key' => $this->widget->public_key,
            'conversation_id' => $conversationId,
            'widget_session_token' => $sessionToken,
            'customer_first_name' => 'Online',
            'customer_last_name' => 'Kupac',
            'customer_phone' => '+38762222333',
            'customer_email' => 'kupac@example.com',
            'delivery_address' => 'Test 5',
            'delivery_city' => 'Tuzla',
            'delivery_postal_code' => '75000',
            'delivery_country' => 'BA',
            'payment_method' => 'online',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $upsert->assertOk()
            ->assertJsonPath('data.checkout.status', 'awaiting_confirmation');

        $confirm = $this->postJson('/api/widget/checkout/confirm', [
            'public_key' => $this->widget->public_key,
            'conversation_id' => $conversationId,
            'widget_session_token' => $sessionToken,
        ]);

        $confirm->assertOk()
            ->assertJsonPath('data.order.external_order_id', '8801')
            ->assertJsonPath('data.order.payment_required', true)
            ->assertJsonPath('data.order.checkout_url', 'https://demo.myshopify.com/draft_orders/8801/invoice')
            ->assertJsonPath('data.checkout.status', 'placed');

        Mail::assertQueued(CheckoutOrderCustomerMail::class, function (CheckoutOrderCustomerMail $mail): bool {
            return $mail->hasTo('kupac@example.com')
                && (string) data_get($mail->data, 'order_id') === '8801';
        });

        Mail::assertQueued(CheckoutOrderMerchantMail::class, function (CheckoutOrderMerchantMail $mail): bool {
            return $mail->hasTo('sales@checkout-tenant.test')
                && (string) data_get($mail->data, 'order_id') === '8801';
        });
    }

    public function test_widget_checkout_custom_api_submits_real_order(): void
    {
        Mail::fake();

        Http::fake([
            'https://api.custom.local/orders' => Http::response([
                'data' => [
                    'id' => 'CUST-1001',
                    'status' => 'accepted',
                    'total' => 24,
                    'currency' => 'BAM',
                    'payment_url' => 'https://pay.custom.local/orders/CUST-1001',
                ],
            ], 201),
        ]);

        $credentials = app('encrypter')->encrypt(json_encode([
            'token' => 'custom_token_123',
        ]));

        $connection = \App\Models\IntegrationConnection::query()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'custom_api',
            'name' => 'Custom Checkout',
            'status' => 'connected',
            'base_url' => 'https://api.custom.local',
            'credentials_encrypted' => $credentials,
            'auth_type' => 'bearer',
            'config_json' => [
                'orders' => [
                    'endpoint' => '/orders',
                    'response_path' => 'data',
                    'external_order_id_path' => 'id',
                    'status_path' => 'status',
                    'total_path' => 'total',
                    'currency_path' => 'currency',
                    'payment_url_path' => 'payment_url',
                ],
            ],
        ]);

        $product = Product::query()->create([
            'tenant_id' => $this->tenant->id,
            'source_type' => 'custom_api',
            'source_connection_id' => $connection->id,
            'external_id' => 'api-100',
            'sku' => 'API-100',
            'name' => 'Custom Gel',
            'price' => 24,
            'currency' => 'BAM',
            'in_stock' => true,
            'status' => 'active',
        ]);

        $session = $this->postJson('/api/widget/session/start', [
            'public_key' => $this->widget->public_key,
            'visitor_uuid' => 'visitor-checkout-3',
            'source_url' => 'https://example.com',
        ]);

        $session->assertCreated();
        $conversationId = (int) $session->json('data.conversation_id');
        $sessionToken = (string) $session->json('data.widget_session_token');

        $upsert = $this->postJson('/api/widget/checkout', [
            'public_key' => $this->widget->public_key,
            'conversation_id' => $conversationId,
            'widget_session_token' => $sessionToken,
            'customer_first_name' => 'Custom',
            'customer_last_name' => 'Kupac',
            'customer_phone' => '+38763333444',
            'customer_email' => 'custom@example.com',
            'delivery_address' => 'Adresa 8',
            'delivery_city' => 'Mostar',
            'delivery_postal_code' => '88000',
            'delivery_country' => 'BA',
            'payment_method' => 'online',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $upsert->assertOk()
            ->assertJsonPath('data.checkout.status', 'awaiting_confirmation');

        $confirm = $this->postJson('/api/widget/checkout/confirm', [
            'public_key' => $this->widget->public_key,
            'conversation_id' => $conversationId,
            'widget_session_token' => $sessionToken,
        ]);

        $confirm->assertOk()
            ->assertJsonPath('data.order.external_order_id', 'CUST-1001')
            ->assertJsonPath('data.order.payment_required', true)
            ->assertJsonPath('data.order.checkout_url', 'https://pay.custom.local/orders/CUST-1001')
            ->assertJsonPath('data.checkout.status', 'placed');

        Mail::assertQueued(CheckoutOrderCustomerMail::class, function (CheckoutOrderCustomerMail $mail): bool {
            return $mail->hasTo('custom@example.com')
                && (string) data_get($mail->data, 'order_id') === 'CUST-1001';
        });

        Mail::assertQueued(CheckoutOrderMerchantMail::class, function (CheckoutOrderMerchantMail $mail): bool {
            return $mail->hasTo('sales@checkout-tenant.test')
                && (string) data_get($mail->data, 'order_id') === 'CUST-1001';
        });
    }
}
