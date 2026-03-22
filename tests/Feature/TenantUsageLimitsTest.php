<?php

namespace Tests\Feature;

use App\Mail\TenantUsageLimitAlertMail;
use App\Models\AiConfig;
use App\Models\ConversationMessage;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Widget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TenantUsageLimitsTest extends TestCase
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
            'max_messages_monthly' => 5000,
        ]);

        $this->tenant = Tenant::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Limits Tenant',
            'slug' => 'limits-tenant',
            'plan_id' => $plan->id,
            'locale' => 'bs',
            'timezone' => 'Europe/Sarajevo',
            'support_email' => 'alerts@limits-tenant.test',
        ]);

        $this->widget = Widget::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Limits Widget',
            'public_key' => 'wpk_limits_test',
            'secret_key' => 'wsk_limits_secret',
            'default_locale' => 'bs',
            'is_active' => true,
        ]);
    }

    public function test_monthly_message_limit_blocks_and_sends_admin_alert(): void
    {
        Mail::fake();

        AiConfig::query()->create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'openai',
            'model_name' => 'gpt-5-mini',
            'embedding_model' => 'text-embedding-3-small',
            'temperature' => 0.3,
            'max_output_tokens' => 600,
            'max_messages_monthly' => 1,
            'block_on_limit' => true,
            'alert_on_limit' => true,
            'top_p' => 1.0,
        ]);

        $session = $this->startWidgetSession('limits-visitor-1');

        $first = $this->postJson('/api/widget/message', [
            'public_key' => $this->widget->public_key,
            'message' => 'Treba mi preporuka za serum',
            'conversation_id' => $session['conversation_id'],
            'session_id' => $session['session_id'],
            'visitor_uuid' => $session['visitor_uuid'],
            'widget_session_token' => $session['widget_session_token'],
        ]);

        $first->assertOk();
        $this->assertNotSame('usage_limit', (string) $first->json('data.detected_intent'));

        $conversationId = (int) $session['conversation_id'];

        $second = $this->postJson('/api/widget/message', [
            'public_key' => $this->widget->public_key,
            'message' => 'Jos jedna poruka',
            'conversation_id' => $conversationId,
            'session_id' => $session['session_id'],
            'visitor_uuid' => $session['visitor_uuid'],
            'widget_session_token' => $session['widget_session_token'],
        ]);

        $second->assertOk()
            ->assertJsonPath('data.detected_intent', 'usage_limit')
            ->assertJsonPath('data.usage_limit.blocked', true);

        Mail::assertQueued(TenantUsageLimitAlertMail::class, function (TenantUsageLimitAlertMail $mail): bool {
            return $mail->hasTo('alerts@limits-tenant.test');
        });

        Mail::assertQueued(TenantUsageLimitAlertMail::class, 1);
    }

    public function test_daily_token_limit_blocks_after_usage_is_reached(): void
    {
        config()->set('services.openai.api_key', 'test_openai_key');

        Http::fake([
            'https://api.openai.com/v1/embeddings' => Http::response([
                'data' => [
                    [
                        'embedding' => array_fill(0, 1536, 0.01),
                    ],
                ],
            ], 200),
            'https://api.openai.com/v1/responses' => Http::response([
                'output' => [[
                    'content' => [[
                        'text' => json_encode([
                            'answer_text' => 'Predlazem Hydra Serum.',
                            'recommended_product_ids' => [],
                            'cta_type' => null,
                            'cta_label' => null,
                            'needs_handoff' => false,
                            'lead_capture_suggested' => false,
                            'detected_intent' => 'product_recommendation',
                            'confidence' => 0.9,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]],
                ]],
                'usage' => [
                    'input_tokens' => 60,
                    'output_tokens' => 50,
                    'total_tokens' => 110,
                ],
            ], 200),
        ]);

        AiConfig::query()->create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'openai',
            'model_name' => 'gpt-5-mini',
            'embedding_model' => 'text-embedding-3-small',
            'temperature' => 0.3,
            'max_output_tokens' => 600,
            'max_tokens_daily' => 100,
            'block_on_limit' => true,
            'alert_on_limit' => false,
            'top_p' => 1.0,
        ]);

        $session = $this->startWidgetSession('limits-visitor-2');

        $first = $this->postJson('/api/widget/message', [
            'public_key' => $this->widget->public_key,
            'message' => 'Prva poruka',
            'conversation_id' => $session['conversation_id'],
            'session_id' => $session['session_id'],
            'visitor_uuid' => $session['visitor_uuid'],
            'widget_session_token' => $session['widget_session_token'],
        ]);

        $first->assertOk();
        $this->assertNotSame('usage_limit', (string) $first->json('data.detected_intent'));

        $conversationId = (int) $session['conversation_id'];

        $second = $this->postJson('/api/widget/message', [
            'public_key' => $this->widget->public_key,
            'message' => 'Druga poruka',
            'conversation_id' => $conversationId,
            'session_id' => $session['session_id'],
            'visitor_uuid' => $session['visitor_uuid'],
            'widget_session_token' => $session['widget_session_token'],
        ]);

        $second->assertOk()
            ->assertJsonPath('data.detected_intent', 'usage_limit')
            ->assertJsonPath('data.usage_limit.blocked', true);

        $assistant = ConversationMessage::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('role', 'assistant')
            ->whereNotNull('tokens_input')
            ->first();

        $this->assertNotNull($assistant);
        $this->assertSame(60, (int) $assistant->tokens_input);
        $this->assertSame(50, (int) $assistant->tokens_output);
    }

    /**
     * @return array{conversation_id:int, session_id:string, visitor_uuid:string, widget_session_token:string}
     */
    private function startWidgetSession(string $visitorUuid): array
    {
        $session = $this->postJson('/api/widget/session/start', [
            'public_key' => $this->widget->public_key,
            'visitor_uuid' => $visitorUuid,
            'source_url' => 'https://tests.local/limits',
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
