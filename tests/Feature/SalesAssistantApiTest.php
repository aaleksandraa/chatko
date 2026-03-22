<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Widget;
use App\Services\Auth\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SalesAssistantApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Widget $widget;

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
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'plan_id' => $plan->id,
            'locale' => 'bs',
            'timezone' => 'Europe/Sarajevo',
        ]);

        AiConfig::query()->create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'openai',
            'model_name' => 'gpt-5-mini',
            'embedding_model' => 'text-embedding-3-small',
            'temperature' => 0.3,
            'max_output_tokens' => 600,
            'top_p' => 1.0,
        ]);

        $this->widget = Widget::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Widget',
            'public_key' => 'wpk_test_123',
            'secret_key' => 'wsk_test_123456',
            'default_locale' => 'bs',
            'is_active' => true,
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@test.local',
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

    public function test_admin_product_create_and_list_flow(): void
    {
        $create = $this->withHeaders($this->adminHeaders())->postJson('/api/admin/products', [
            'name' => 'Hydra Serum',
            'price' => 39.90,
            'currency' => 'BAM',
            'sku' => 'HYDRA-1',
            'in_stock' => true,
            'status' => 'active',
            'category_text' => 'njega-koze',
        ]);

        $create->assertCreated()->assertJsonPath('data.name', 'Hydra Serum');

        $list = $this->withHeaders($this->adminHeaders())->getJson('/api/admin/products');

        $list->assertOk()
            ->assertJsonPath('data.0.name', 'Hydra Serum');
    }

    public function test_knowledge_text_ingest_creates_indexed_document(): void
    {
        $response = $this->withHeaders($this->adminHeaders())->postJson('/api/admin/knowledge-documents/text', [
            'title' => 'Dostava',
            'type' => 'shipping_policy',
            'content_raw' => 'Dostava traje 1 do 3 radna dana. Povrat je moguc u roku 14 dana.',
            'visibility' => 'public_for_ai',
            'ai_allowed' => true,
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'indexed');

        $this->assertDatabaseHas('knowledge_documents', [
            'tenant_id' => $this->tenant->id,
            'title' => 'Dostava',
            'status' => 'indexed',
        ]);

        $this->assertDatabaseCount('knowledge_chunks', 1);
    }

    public function test_widget_message_returns_structured_recommendation_payload(): void
    {
        Product::query()->create([
            'tenant_id' => $this->tenant->id,
            'source_type' => 'manual',
            'name' => 'Hydra Serum Sensitive',
            'price' => 34.90,
            'currency' => 'BAM',
            'in_stock' => true,
            'stock_qty' => 12,
            'status' => 'active',
            'category_text' => 'suhu kozu',
            'short_description' => 'Serum za suhu kozu do 40 KM.',
        ]);

        $session = $this->startWidgetSession('visitor-1');

        $response = $this->postJson('/api/widget/message', [
            'public_key' => $this->widget->public_key,
            'message' => 'Treba mi nesto za suhu kozu do 40 KM',
            'conversation_id' => $session['conversation_id'],
            'session_id' => $session['session_id'],
            'visitor_uuid' => $session['visitor_uuid'],
            'widget_session_token' => $session['widget_session_token'],
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'conversation_id',
                    'session_id',
                    'answer_text',
                    'detected_intent',
                    'recommended_products',
                    'cta' => ['type', 'label'],
                    'lead_capture_suggested',
                    'needs_handoff',
                ],
            ]);

        $this->assertNotEmpty($response->json('data.answer_text'));
    }

    public function test_widget_message_without_matching_category_returns_no_match_in_fallback_mode(): void
    {
        Product::query()->create([
            'tenant_id' => $this->tenant->id,
            'source_type' => 'manual',
            'name' => 'Gisou Ulje za kosu 20ml',
            'price' => 74.00,
            'currency' => 'BAM',
            'in_stock' => true,
            'stock_qty' => 6,
            'status' => 'active',
            'category_text' => 'kosa',
            'short_description' => 'Ulje za suhu i ostecenu kosu.',
        ]);

        $session = $this->startWidgetSession('visitor-no-match');

        $response = $this->postJson('/api/widget/message', [
            'public_key' => $this->widget->public_key,
            'message' => 'Treba mi neko ulje za bradu',
            'conversation_id' => $session['conversation_id'],
            'session_id' => $session['session_id'],
            'visitor_uuid' => $session['visitor_uuid'],
            'widget_session_token' => $session['widget_session_token'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.cta.type', 'clarify_need')
            ->assertJsonCount(0, 'data.recommended_products');

        $answer = mb_strtolower((string) $response->json('data.answer_text'));
        $this->assertStringContainsString('ne vidim proizvod tog tipa', $answer);
    }

    public function test_widget_session_start_normalizes_non_uuid_visitor_identifier(): void
    {
        $session = $this->postJson('/api/widget/session/start', [
            'public_key' => $this->widget->public_key,
            'visitor_uuid' => 'v_zvgsgswx4n',
            'source_url' => 'https://tests.local/product-page',
        ]);

        $session->assertCreated();

        $normalizedVisitorUuid = (string) $session->json('data.visitor_uuid');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $normalizedVisitorUuid,
        );

        $response = $this->postJson('/api/widget/message', [
            'public_key' => $this->widget->public_key,
            'message' => 'Treba mi nesto za suhu kozu do 40 KM',
            'conversation_id' => (int) $session->json('data.conversation_id'),
            'session_id' => (string) $session->json('data.session_id'),
            'visitor_uuid' => $normalizedVisitorUuid,
            'widget_session_token' => (string) $session->json('data.widget_session_token'),
        ]);

        $response->assertOk();
    }

    public function test_widget_message_accepts_legacy_non_uuid_visitor_identifier_with_valid_session_token(): void
    {
        $legacyVisitorId = 'v_legacy_client_123';

        $session = $this->postJson('/api/widget/session/start', [
            'public_key' => $this->widget->public_key,
            'visitor_uuid' => $legacyVisitorId,
            'source_url' => 'https://tests.local/product-page',
        ]);

        $session->assertCreated();

        $response = $this->postJson('/api/widget/message', [
            'public_key' => $this->widget->public_key,
            'message' => 'Trebam nesto za suhu kozu',
            'conversation_id' => (int) $session->json('data.conversation_id'),
            'session_id' => (string) $session->json('data.session_id'),
            // Legacy widget clients can still send old non-UUID visitor IDs.
            'visitor_uuid' => $legacyVisitorId,
            'widget_session_token' => (string) $session->json('data.widget_session_token'),
        ]);

        $response->assertOk();
    }

    public function test_widget_message_does_not_crash_when_embedding_endpoint_is_forbidden(): void
    {
        config()->set('services.openai.api_key', 'test_openai_key');
        config()->set('services.openai.embedding_fallback_models', ['text-embedding-3-small']);

        Http::fake([
            'https://api.openai.com/v1/embeddings' => Http::response([
                'error' => [
                    'message' => 'Project does not have access to model text-embedding-3-small.',
                    'type' => 'invalid_request_error',
                ],
            ], 403),
            'https://api.openai.com/v1/responses' => Http::response([
                'output' => [[
                    'content' => [[
                        'text' => json_encode([
                            'answer_text' => 'Predlazem opcije iz kataloga.',
                            'recommended_product_ids' => [],
                            'cta_type' => null,
                            'cta_label' => null,
                            'needs_handoff' => false,
                            'lead_capture_suggested' => false,
                            'detected_intent' => 'product_recommendation',
                            'confidence' => 0.8,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]],
                ]],
                'usage' => [
                    'input_tokens' => 30,
                    'output_tokens' => 40,
                    'total_tokens' => 70,
                ],
            ], 200),
        ]);

        $session = $this->startWidgetSession('visitor-embedding-403');

        $response = $this->postJson('/api/widget/message', [
            'public_key' => $this->widget->public_key,
            'message' => 'Treba mi preporuka',
            'conversation_id' => $session['conversation_id'],
            'session_id' => $session['session_id'],
            'visitor_uuid' => $session['visitor_uuid'],
            'widget_session_token' => $session['widget_session_token'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.answer_text', 'Predlazem opcije iz kataloga.');
    }

    public function test_widget_message_hair_loss_prefers_treatment_products_over_accessories_in_fallback_mode(): void
    {
        Product::query()->create([
            'tenant_id' => $this->tenant->id,
            'source_type' => 'manual',
            'name' => 'DENMAN Cetka za rascesljavanje',
            'price' => 39.00,
            'currency' => 'BAM',
            'in_stock' => true,
            'stock_qty' => 10,
            'status' => 'active',
            'category_text' => 'cetke za kosu',
            'short_description' => 'Cetka za rascesljavanje i stilizovanje kose.',
        ]);

        Product::query()->create([
            'tenant_id' => $this->tenant->id,
            'source_type' => 'manual',
            'name' => 'Anti Hair Loss Sampon',
            'price' => 28.00,
            'currency' => 'BAM',
            'in_stock' => true,
            'stock_qty' => 12,
            'status' => 'active',
            'category_text' => 'sampon protiv opadanja',
            'short_description' => 'Sampon protiv opadanja i za jacanje korijena kose.',
        ]);

        $session = $this->startWidgetSession('visitor-hair-loss-1');

        $response = $this->postJson('/api/widget/message', [
            'public_key' => $this->widget->public_key,
            'message' => 'Treba mi nesto protiv opadanja kose',
            'conversation_id' => $session['conversation_id'],
            'session_id' => $session['session_id'],
            'visitor_uuid' => $session['visitor_uuid'],
            'widget_session_token' => $session['widget_session_token'],
        ]);

        $response->assertOk();

        $recommendedNames = collect((array) $response->json('data.recommended_products'))
            ->map(fn (array $row): string => mb_strtolower((string) ($row['name'] ?? '')))
            ->values()
            ->all();

        $this->assertContains('anti hair loss sampon', $recommendedNames);
        $this->assertFalse(
            collect($recommendedNames)->contains(fn (string $name): bool => str_contains($name, 'cetka') || str_contains($name, 'četka')),
            'Accessory brush should not be recommended for hair loss treatment query.',
        );
    }

    public function test_widget_message_hair_loss_returns_no_match_when_only_accessories_exist(): void
    {
        Product::query()->create([
            'tenant_id' => $this->tenant->id,
            'source_type' => 'manual',
            'name' => 'Fromm Keramicka Cetka',
            'price' => 50.00,
            'currency' => 'BAM',
            'in_stock' => true,
            'stock_qty' => 4,
            'status' => 'active',
            'category_text' => 'cetke',
            'short_description' => 'Profesionalna cetka za stilizovanje.',
        ]);

        Product::query()->create([
            'tenant_id' => $this->tenant->id,
            'source_type' => 'manual',
            'name' => 'Barber Cesalj',
            'price' => 12.00,
            'currency' => 'BAM',
            'in_stock' => true,
            'stock_qty' => 7,
            'status' => 'active',
            'category_text' => 'cesljevi',
            'short_description' => 'Kvalitetan cesalj za svakodnevnu upotrebu.',
        ]);

        $session = $this->startWidgetSession('visitor-hair-loss-2');

        $response = $this->postJson('/api/widget/message', [
            'public_key' => $this->widget->public_key,
            'message' => 'Treba mi nesto protiv opadanja kose',
            'conversation_id' => $session['conversation_id'],
            'session_id' => $session['session_id'],
            'visitor_uuid' => $session['visitor_uuid'],
            'widget_session_token' => $session['widget_session_token'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.cta.type', 'clarify_need')
            ->assertJsonCount(0, 'data.recommended_products');
    }

    public function test_widget_message_generic_order_question_enters_checkout_without_random_recommendations(): void
    {
        Product::query()->create([
            'tenant_id' => $this->tenant->id,
            'source_type' => 'manual',
            'name' => 'Denman Cetka',
            'price' => 39.00,
            'currency' => 'BAM',
            'in_stock' => true,
            'stock_qty' => 8,
            'status' => 'active',
            'category_text' => 'cetke',
            'short_description' => 'Cetka za stilizovanje.',
        ]);

        $session = $this->startWidgetSession('visitor-order-question');

        $response = $this->postJson('/api/widget/message', [
            'public_key' => $this->widget->public_key,
            'message' => 'mogu li naruciti',
            'conversation_id' => $session['conversation_id'],
            'session_id' => $session['session_id'],
            'visitor_uuid' => $session['visitor_uuid'],
            'widget_session_token' => $session['widget_session_token'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.cta.type', 'checkout')
            ->assertJsonCount(0, 'data.recommended_products')
            ->assertJsonPath('data.checkout.status', 'collecting_customer');

        $answer = mb_strtolower((string) $response->json('data.answer_text'));
        $this->assertStringContainsString('potvrdi koji proizvod', $answer);
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

    /**
     * @return array{conversation_id:int, session_id:string, visitor_uuid:string, widget_session_token:string}
     */
    private function startWidgetSession(string $visitorUuid): array
    {
        $session = $this->postJson('/api/widget/session/start', [
            'public_key' => $this->widget->public_key,
            'visitor_uuid' => $visitorUuid,
            'source_url' => 'https://tests.local/product-page',
        ]);

        $session->assertCreated();

        return [
            'conversation_id' => (int) $session->json('data.conversation_id'),
            'session_id' => (string) $session->json('data.session_id'),
            'visitor_uuid' => (string) $session->json('data.visitor_uuid'),
            'widget_session_token' => (string) $session->json('data.widget_session_token'),
        ];
    }
}

