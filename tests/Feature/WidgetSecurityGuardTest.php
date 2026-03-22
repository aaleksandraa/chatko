<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Widget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WidgetSecurityGuardTest extends TestCase
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
            'name' => 'Security Tenant',
            'slug' => 'security-tenant',
            'plan_id' => $plan->id,
            'locale' => 'bs',
            'timezone' => 'Europe/Sarajevo',
        ]);

        $this->widget = Widget::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Security Widget',
            'public_key' => 'wpk_security_test',
            'secret_key' => 'wsk_security_secret',
            'default_locale' => 'bs',
            'is_active' => true,
        ]);
    }

    public function test_widget_message_requires_valid_session_token(): void
    {
        $response = $this->postJson('/api/widget/message', [
            'public_key' => $this->widget->public_key,
            'message' => 'Treba mi preporuka',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Invalid widget session token.');

        $this->assertDatabaseHas('widget_abuse_logs', [
            'widget_id' => $this->widget->id,
            'reason' => 'missing_widget_session_token',
        ]);
    }

    public function test_widget_message_accepts_valid_session_token(): void
    {
        $session = $this->postJson('/api/widget/session/start', [
            'public_key' => $this->widget->public_key,
            'visitor_uuid' => 'security-visitor',
            'source_url' => 'https://shop.example.com/product/1',
        ]);

        $session->assertCreated();

        $response = $this->postJson('/api/widget/message', [
            'public_key' => $this->widget->public_key,
            'conversation_id' => (int) $session->json('data.conversation_id'),
            'session_id' => (string) $session->json('data.session_id'),
            'visitor_uuid' => (string) $session->json('data.visitor_uuid'),
            'widget_session_token' => (string) $session->json('data.widget_session_token'),
            'message' => 'Trebam nesto za suhu kozu',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'conversation_id',
                    'session_id',
                    'answer_text',
                    'widget_session_token',
                ],
            ]);
    }

    public function test_widget_origin_allowlist_blocks_disallowed_domain(): void
    {
        $this->widget->update([
            'allowed_domains_json' => [
                'https://shop.example.com',
            ],
        ]);

        $blocked = $this->withHeaders([
            'Origin' => 'https://evil.example',
        ])->postJson('/api/widget/session/start', [
            'public_key' => $this->widget->public_key,
            'visitor_uuid' => 'security-origin-1',
            'source_url' => 'https://evil.example',
        ]);

        $blocked->assertStatus(403)
            ->assertJsonPath('message', 'Widget origin is not allowed.');

        $allowed = $this->withHeaders([
            'Origin' => 'https://shop.example.com',
        ])->postJson('/api/widget/session/start', [
            'public_key' => $this->widget->public_key,
            'visitor_uuid' => 'security-origin-2',
            'source_url' => 'https://shop.example.com/product/2',
        ]);

        $allowed->assertCreated();

        $this->assertDatabaseHas('widget_abuse_logs', [
            'widget_id' => $this->widget->id,
            'reason' => 'origin_not_allowed',
        ]);
    }
}

