<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AiConfigModelAllowlistTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openai.allowed_chat_models', [
            'gpt-5-mini-2025-08-07',
            'gpt-5.4-mini-2026-03-17',
        ]);
        config()->set('services.openai.default_model', 'gpt-5-mini');
        config()->set('services.openai.allowed_embedding_models', [
            'text-embedding-3-small',
            'text-embedding-ada-002',
        ]);
        config()->set('services.openai.embedding_model', 'text-embedding-3-small');

        $plan = Plan::query()->create([
            'name' => 'Starter',
            'code' => 'starter',
        ]);

        $this->tenant = Tenant::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'AI Allowlist Tenant',
            'slug' => 'ai-allowlist-tenant',
            'plan_id' => $plan->id,
            'locale' => 'bs',
            'timezone' => 'Europe/Sarajevo',
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@allowlist.local',
            'password' => Hash::make('password123'),
        ]);

        $this->tenant->users()->attach($owner->id, ['role' => 'owner']);

        $issued = app(ApiTokenService::class)->issueToken(
            $owner,
            $this->tenant,
            'owner',
            ['*'],
            'allowlist_test_token',
            null,
        );

        $this->adminToken = $issued['plain_text_token'];
    }

    public function test_ai_config_show_returns_allowed_model_lists(): void
    {
        $response = $this->withHeaders($this->adminHeaders())->getJson('/api/admin/ai-config');

        $response->assertOk()
            ->assertJsonPath('meta.allowed_models.chat.0', 'gpt-5-mini-2025-08-07')
            ->assertJsonPath('meta.allowed_models.embedding.0', 'text-embedding-3-small');
    }

    public function test_ai_config_update_normalizes_requested_models_to_allowlist(): void
    {
        $response = $this->withHeaders($this->adminHeaders())->putJson('/api/admin/ai-config', [
            'provider' => 'openai',
            // Alias should be normalized to dated model from allowlist.
            'model_name' => 'gpt-5-mini',
            // Disallowed embedding should fallback to configured default allowlisted model.
            'embedding_model' => 'text-embedding-3-large',
            'temperature' => 0.3,
            'max_output_tokens' => 350,
            'top_p' => 1.0,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.model_name', 'gpt-5-mini-2025-08-07')
            ->assertJsonPath('data.embedding_model', 'text-embedding-3-small');
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->adminToken,
            'X-Tenant-Slug' => $this->tenant->slug,
        ];
    }
}

