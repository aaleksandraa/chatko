<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Widget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WidgetChallengeMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private Widget $widget;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::query()->create([
            'name' => 'Starter',
            'code' => 'starter',
        ]);

        $tenant = Tenant::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Challenge Tenant',
            'slug' => 'challenge-tenant',
            'plan_id' => $plan->id,
            'locale' => 'bs',
            'timezone' => 'Europe/Sarajevo',
        ]);

        $this->widget = Widget::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Challenge Widget',
            'public_key' => 'wpk_challenge_test',
            'secret_key' => 'wsk_challenge_secret',
            'default_locale' => 'bs',
            'is_active' => true,
        ]);
    }

    public function test_session_start_is_blocked_without_challenge_token_when_challenge_is_enabled(): void
    {
        $this->enableTurnstileChallenge();

        $response = $this->postJson('/api/widget/session/start', [
            'public_key' => $this->widget->public_key,
            'visitor_uuid' => 'visitor-challenge-1',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Challenge verification failed. Please try again.');

        $this->assertDatabaseHas('widget_abuse_logs', [
            'widget_id' => $this->widget->id,
            'reason' => 'missing_challenge_token',
        ]);
    }

    public function test_session_start_is_created_when_turnstile_verification_succeeds(): void
    {
        $this->enableTurnstileChallenge();

        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => true,
                'action' => 'widget_session_start',
                'hostname' => 'shop.example.com',
            ], 200),
        ]);

        $response = $this->postJson('/api/widget/session/start', [
            'public_key' => $this->widget->public_key,
            'visitor_uuid' => 'visitor-challenge-2',
            'challenge_token' => 'turnstile_token_ok',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.visitor_uuid', 'visitor-challenge-2')
            ->assertJsonStructure([
                'data' => [
                    'conversation_id',
                    'session_id',
                    'visitor_uuid',
                    'widget_session_token',
                ],
            ]);
    }

    public function test_widget_config_exposes_public_challenge_configuration(): void
    {
        $this->enableTurnstileChallenge();

        $response = $this->getJson('/api/widget/config/'.$this->widget->public_key);

        $response->assertOk()
            ->assertJsonPath('data.challenge.enabled', true)
            ->assertJsonPath('data.challenge.provider', 'turnstile')
            ->assertJsonPath('data.challenge.site_key', 'turnstile_site_public')
            ->assertJsonPath('data.challenge.action', 'widget_session_start');
    }

    private function enableTurnstileChallenge(): void
    {
        config()->set('services.widget.challenge.enabled', true);
        config()->set('services.widget.challenge.provider', 'turnstile');
        config()->set('services.widget.challenge.action', 'widget_session_start');
        config()->set('services.widget.challenge.turnstile.site_key', 'turnstile_site_public');
        config()->set('services.widget.challenge.turnstile.secret_key', 'turnstile_secret_private');
        config()->set('services.widget.challenge.turnstile.verify_url', 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
    }
}

