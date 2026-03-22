<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Widget;
use App\Services\Auth\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminWidgetLabApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Widget $widget;

    private string $token;

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
            'name' => 'Widget Lab Tenant',
            'slug' => 'widget-lab-tenant',
            'plan_id' => $plan->id,
            'locale' => 'bs',
            'timezone' => 'Europe/Sarajevo',
        ]);

        $this->widget = Widget::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Beauty Widget',
            'public_key' => 'wpk_widget_lab_test',
            'secret_key' => 'wsk_widget_lab_secret',
            'default_locale' => 'bs',
            'is_active' => true,
            'allowed_domains_json' => ['https://beautyshop.ba'],
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@widget-lab.local',
            'password' => Hash::make('password123'),
        ]);

        $this->tenant->users()->attach($owner->id, ['role' => 'owner']);

        $issued = app(ApiTokenService::class)->issueToken(
            $owner,
            $this->tenant,
            'owner',
            ['*'],
            'widget_lab_test_token',
            null,
        );

        $this->token = $issued['plain_text_token'];
    }

    public function test_admin_widget_lab_start_session_bypasses_origin_allowlist(): void
    {
        $blockedPublic = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
        ])->postJson('/api/widget/session/start', [
            'public_key' => $this->widget->public_key,
            'visitor_uuid' => 'lab-visitor-public',
            'source_url' => 'http://localhost:5173/demo',
        ]);

        $blockedPublic->assertStatus(403)
            ->assertJsonPath('message', 'Widget origin is not allowed.');

        $allowedInLab = $this->withHeaders(array_merge($this->adminHeaders(), [
            'Origin' => 'http://localhost:5173',
        ]))->postJson('/api/admin/widget-lab/session/start', [
            'public_key' => $this->widget->public_key,
            'visitor_uuid' => 'lab-visitor-admin',
            'source_url' => 'http://localhost:5173/demo',
        ]);

        $allowedInLab->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'conversation_id',
                    'session_id',
                    'visitor_uuid',
                    'widget_session_token',
                ],
            ]);
    }

    public function test_admin_widget_lab_message_flow_works_with_valid_session_token(): void
    {
        $session = $this->withHeaders($this->adminHeaders())->postJson('/api/admin/widget-lab/session/start', [
            'public_key' => $this->widget->public_key,
            'visitor_uuid' => 'lab-visitor-chat',
            'source_url' => 'http://localhost:5173/demo',
        ]);

        $session->assertCreated();

        $response = $this->withHeaders($this->adminHeaders())->postJson('/api/admin/widget-lab/message', [
            'public_key' => $this->widget->public_key,
            'conversation_id' => (int) $session->json('data.conversation_id'),
            'session_id' => (string) $session->json('data.session_id'),
            'visitor_uuid' => (string) $session->json('data.visitor_uuid'),
            'widget_session_token' => (string) $session->json('data.widget_session_token'),
            'message' => 'Treba mi nesto za suhu kozu',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'conversation_id',
                    'session_id',
                    'answer_text',
                    'recommended_products',
                    'widget_session_token',
                ],
            ]);
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->token,
            'X-Tenant-Slug' => $this->tenant->slug,
        ];
    }
}
