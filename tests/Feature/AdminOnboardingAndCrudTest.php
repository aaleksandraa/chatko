<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Models\Conversation;
use App\Models\ImportJob;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Widget;
use App\Services\Auth\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminOnboardingAndCrudTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $adminToken;

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
            'name' => 'Admin CRUD Tenant',
            'slug' => 'admin-crud-tenant',
            'plan_id' => $plan->id,
            'locale' => 'bs',
            'timezone' => 'Europe/Sarajevo',
        ]);

        AiConfig::query()->create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'openai',
            'model_name' => 'gpt-5-mini',
            'embedding_model' => 'text-embedding-3-small',
            'temperature' => 0.30,
            'max_output_tokens' => 600,
            'top_p' => 1.00,
        ]);

        $this->widget = Widget::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Primary Widget',
            'public_key' => 'wpk_admin_crud_1',
            'secret_key' => 'wsk_admin_crud_1',
            'default_locale' => 'bs',
            'is_active' => true,
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'admin-crud-owner@test.local',
            'password' => Hash::make('password123'),
            'is_system_admin' => true,
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

    public function test_onboarding_bootstrap_creates_tenant_owner_widget_and_token(): void
    {
        $response = $this->withHeaders($this->adminHeaders())->postJson('/api/onboarding/bootstrap', [
            'tenant_name' => 'Onboarding Tenant',
            'tenant_slug' => 'onboarding-tenant',
            'owner_name' => 'Onboarding Owner',
            'owner_email' => 'onboarding-owner@test.local',
            'owner_password' => 'password123',
            'owner_password_confirmation' => 'password123',
            'widget' => [
                'name' => 'Sales Widget',
                'default_locale' => 'bs',
            ],
            'integration' => [
                'enabled' => true,
                'type' => 'custom_api',
                'name' => 'Primary Source',
                'base_url' => 'https://api.example.com',
            ],
            'ai_config' => [
                'provider' => 'openai',
                'model_name' => 'gpt-5-mini',
                'embedding_model' => 'text-embedding-3-small',
                'temperature' => 0.3,
                'max_output_tokens' => 700,
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.tenant.slug', 'onboarding-tenant')
            ->assertJsonPath('data.user.email', 'onboarding-owner@test.local')
            ->assertJsonPath('data.widget.name', 'Sales Widget');

        $tenantId = (int) $response->json('data.tenant.id');
        $token = (string) $response->json('data.token');

        $this->assertDatabaseHas('tenants', [
            'id' => $tenantId,
            'slug' => 'onboarding-tenant',
            'name' => 'Onboarding Tenant',
        ]);
        $this->assertDatabaseHas('widgets', [
            'tenant_id' => $tenantId,
            'name' => 'Sales Widget',
        ]);
        $this->assertDatabaseHas('integration_connections', [
            'tenant_id' => $tenantId,
            'name' => 'Primary Source',
            'type' => 'custom_api',
        ]);
        $this->assertDatabaseHas('ai_configs', [
            'tenant_id' => $tenantId,
            'provider' => 'openai',
            'model_name' => 'gpt-5-mini',
        ]);

        $me = $this->withHeaders([
            'X-Tenant-Slug' => 'onboarding-tenant',
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/admin/auth/me');

        $me->assertOk()
            ->assertJsonPath('data.role', 'owner');
    }

    public function test_onboarding_creates_integration_when_connection_data_is_present_without_enabled_flag(): void
    {
        $response = $this->withHeaders($this->adminHeaders())->postJson('/api/onboarding/bootstrap', [
            'tenant_name' => 'Onboarding Auto Integration',
            'tenant_slug' => 'onboarding-auto-integration',
            'owner_name' => 'Onboarding Auto Owner',
            'owner_email' => 'onboarding-auto-owner@test.local',
            'owner_password' => 'password123',
            'owner_password_confirmation' => 'password123',
            'integration' => [
                'enabled' => false,
                'type' => 'woocommerce',
                'name' => 'Auto Woo',
                'base_url' => 'https://shop.example.com',
                'auth_type' => 'woocommerce_key_secret',
                'credentials' => [
                    'consumer_key' => 'ck_auto',
                    'consumer_secret' => 'cs_auto',
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.tenant.slug', 'onboarding-auto-integration');

        $tenantId = (int) $response->json('data.tenant.id');
        $this->assertDatabaseHas('integration_connections', [
            'tenant_id' => $tenantId,
            'name' => 'Auto Woo',
            'type' => 'woocommerce',
            'base_url' => 'https://shop.example.com',
        ]);
    }

    public function test_onboarding_bootstrap_is_forbidden_for_non_system_admin_user(): void
    {
        $owner = User::query()->create([
            'name' => 'Regular Owner',
            'email' => 'regular-owner@test.local',
            'password' => Hash::make('password123'),
            'is_system_admin' => false,
        ]);
        $this->tenant->users()->attach($owner->id, ['role' => 'owner']);

        $token = app(ApiTokenService::class)->issueToken(
            $owner,
            $this->tenant,
            'owner',
            ['*'],
            'regular_owner_token',
            null,
        )['plain_text_token'];

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Slug' => $this->tenant->slug,
        ])->postJson('/api/onboarding/bootstrap', [
            'tenant_name' => 'Should Fail Tenant',
            'tenant_slug' => 'should-fail-tenant',
            'owner_name' => 'Should Fail Owner',
            'owner_email' => 'should-fail-owner@test.local',
            'owner_password' => 'password123',
            'owner_password_confirmation' => 'password123',
        ])->assertForbidden()->assertJsonPath('message', 'Only system admin can perform this action.');
    }

    public function test_admin_update_and_delete_endpoints_cover_new_crud_surface(): void
    {
        $integrationCreate = $this->withHeaders($this->adminHeaders())->postJson('/api/admin/integrations', [
            'type' => 'custom_api',
            'name' => 'Main Source',
            'base_url' => 'https://api.example.com',
            'config_json' => ['products_endpoint' => '/products'],
        ]);
        $integrationCreate->assertCreated();
        $integrationId = (int) $integrationCreate->json('data.id');

        $presetCreate = $this->withHeaders($this->adminHeaders())->postJson("/api/admin/integrations/{$integrationId}/mapping-presets", [
            'name' => 'Default mapping',
            'mapping_json' => ['name' => 'title', 'price' => 'price'],
        ]);
        $presetCreate->assertCreated();
        $presetId = (int) $presetCreate->json('data.id');

        $productCreate = $this->withHeaders($this->adminHeaders())->postJson('/api/admin/products', [
            'name' => 'Hydra Gel',
            'price' => 19.90,
            'currency' => 'BAM',
            'status' => 'active',
            'in_stock' => true,
        ]);
        $productCreate->assertCreated();
        $productId = (int) $productCreate->json('data.id');

        $knowledgeCreate = $this->withHeaders($this->adminHeaders())->postJson('/api/admin/knowledge-documents/text', [
            'title' => 'Delivery FAQ',
            'type' => 'faq',
            'visibility' => 'public',
            'ai_allowed' => true,
            'content_raw' => 'Delivery takes up to 3 business days.',
        ]);
        $knowledgeCreate->assertCreated();
        $knowledgeId = (int) $knowledgeCreate->json('data.id');

        $conversation = Conversation::query()->create([
            'tenant_id' => $this->tenant->id,
            'widget_id' => $this->widget->id,
            'visitor_uuid' => 'visitor-admin-crud',
            'session_id' => 'session-admin-crud',
            'channel' => 'widget',
            'locale' => 'bs',
            'started_at' => now(),
            'status' => 'active',
            'lead_captured' => false,
            'converted' => false,
        ]);

        $importJob = ImportJob::query()->create([
            'tenant_id' => $this->tenant->id,
            'integration_connection_id' => $integrationId,
            'job_type' => 'initial_sync',
            'source_type' => 'custom_api',
            'status' => 'pending',
            'log_summary' => 'Queued',
        ]);

        $widgetCreate = $this->withHeaders($this->adminHeaders())->postJson('/api/admin/widgets', [
            'name' => 'Secondary Widget',
            'default_locale' => 'bs',
            'is_active' => true,
        ]);
        $widgetCreate->assertCreated();
        $widgetId = (int) $widgetCreate->json('data.id');

        $this->withHeaders($this->adminHeaders())->getJson('/api/admin/ai-config')->assertOk();

        $this->withHeaders($this->adminHeaders())->putJson("/api/admin/integrations/{$integrationId}", [
            'name' => 'Main Source Updated',
            'sync_frequency' => 'hourly',
        ])->assertOk()->assertJsonPath('data.name', 'Main Source Updated');

        $this->withHeaders($this->adminHeaders())->putJson("/api/admin/mapping-presets/{$presetId}", [
            'name' => 'Default mapping updated',
            'mapping_json' => ['name' => 'product_name', 'price' => 'amount'],
        ])->assertOk()->assertJsonPath('data.name', 'Default mapping updated');

        $this->withHeaders($this->adminHeaders())->putJson("/api/admin/products/{$productId}", [
            'name' => 'Hydra Gel Updated',
            'price' => 17.50,
            'in_stock' => false,
        ])->assertOk()->assertJsonPath('data.name', 'Hydra Gel Updated');

        $this->withHeaders($this->adminHeaders())->putJson("/api/admin/knowledge-documents/{$knowledgeId}", [
            'title' => 'Delivery FAQ Updated',
            'type' => 'faq',
            'visibility' => 'public',
        ])->assertOk()->assertJsonPath('data.title', 'Delivery FAQ Updated');

        $this->withHeaders($this->adminHeaders())->putJson("/api/admin/conversations/{$conversation->id}", [
            'status' => 'closed',
            'lead_captured' => true,
            'converted' => true,
        ])->assertOk()->assertJsonPath('data.status', 'closed');

        $this->withHeaders($this->adminHeaders())->putJson("/api/admin/import-jobs/{$importJob->id}", [
            'status' => 'completed',
            'log_summary' => 'Finished',
        ])->assertOk()->assertJsonPath('data.status', 'completed');

        $this->withHeaders($this->adminHeaders())->putJson("/api/admin/widgets/{$widgetId}", [
            'name' => 'Secondary Widget Updated',
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.name', 'Secondary Widget Updated');

        $this->withHeaders($this->adminHeaders())->putJson('/api/admin/ai-config', [
            'temperature' => 0.4,
            'max_output_tokens' => 850,
        ])->assertOk()->assertJsonPath('data.temperature', '0.40');

        $this->withHeaders($this->adminHeaders())->deleteJson("/api/admin/conversations/{$conversation->id}")->assertOk();
        $this->withHeaders($this->adminHeaders())->deleteJson("/api/admin/import-jobs/{$importJob->id}")->assertOk();
        $this->withHeaders($this->adminHeaders())->deleteJson("/api/admin/knowledge-documents/{$knowledgeId}")->assertOk();
        $this->withHeaders($this->adminHeaders())->deleteJson("/api/admin/products/{$productId}")->assertOk();
        $this->withHeaders($this->adminHeaders())->deleteJson("/api/admin/mapping-presets/{$presetId}")->assertOk();
        $this->withHeaders($this->adminHeaders())->deleteJson("/api/admin/integrations/{$integrationId}")->assertOk();
        $this->withHeaders($this->adminHeaders())->deleteJson("/api/admin/widgets/{$widgetId}")->assertOk();

        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('import_jobs', ['id' => $importJob->id]);
        $this->assertDatabaseMissing('knowledge_documents', ['id' => $knowledgeId]);
        $this->assertDatabaseMissing('products', ['id' => $productId]);
        $this->assertDatabaseMissing('source_mapping_presets', ['id' => $presetId]);
        $this->assertDatabaseMissing('integration_connections', ['id' => $integrationId]);
        $this->assertDatabaseMissing('widgets', ['id' => $widgetId]);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'updated',
            'entity_type' => 'integration_connections',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'deleted',
            'entity_type' => 'widgets',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'updated',
            'entity_type' => 'ai_configs',
        ]);

        $auditLogs = $this->withHeaders($this->adminHeaders())->getJson('/api/admin/audit-logs');
        $auditLogs->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'action',
                        'entity_type',
                        'entity_id',
                        'actor_role',
                        'request_method',
                        'request_path',
                        'before_json',
                        'after_json',
                        'metadata_json',
                    ],
                ],
            ]);

        $this->assertGreaterThanOrEqual(15, (int) $auditLogs->json('total'));
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
