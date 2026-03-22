<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\AiConfig;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Widget;
use App\Services\AI\OpenAIModelCatalogService;
use App\Services\Auth\ApiTokenService;
use App\Services\Integrations\IntegrationConnectionService;
use App\Support\IntegrationSyncFrequency;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OnboardingController extends Controller
{
    public function bootstrap(
        Request $request,
        ApiTokenService $tokenService,
        IntegrationConnectionService $integrationConnectionService,
        OpenAIModelCatalogService $openAIModelCatalogService,
    ): JsonResponse {
        $actor = $request->user();
        $actorSystemAdminUserId = $actor instanceof User && (bool) $actor->is_system_admin
            ? (int) $actor->id
            : null;

        $payload = $request->validate([
            'tenant_name' => ['required', 'string', 'max:255'],
            'tenant_slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/'],
            'brand_name' => ['nullable', 'string', 'max:255'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'locale' => ['nullable', 'string', 'max:16'],
            'timezone' => ['nullable', 'string', 'max:64'],

            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'owner_password' => ['required', 'string', 'min:8', 'confirmed'],

            'plan_code' => ['nullable', 'string', 'max:64'],
            'token_ttl_minutes' => ['nullable', 'integer', 'min:30', 'max:10080'],

            'widget' => ['nullable', 'array'],
            'widget.name' => ['nullable', 'string', 'max:255'],
            'widget.default_locale' => ['nullable', 'string', 'max:16'],
            'widget.allowed_domains_json' => ['nullable', 'array'],
            'widget.theme_json' => ['nullable', 'array'],
            'widget.is_active' => ['nullable', 'boolean'],

            'integration' => ['nullable', 'array'],
            'integration.enabled' => ['nullable', 'boolean'],
            'integration.type' => ['nullable', 'string', 'max:64'],
            'integration.name' => ['nullable', 'string', 'max:255'],
            'integration.base_url' => ['nullable', 'string', 'max:2048'],
            'integration.auth_type' => ['nullable', 'string', 'max:64'],
            'integration.credentials' => ['nullable', 'array'],
            'integration.config_json' => ['nullable', 'array'],
            'integration.mapping_json' => ['nullable', 'array'],
            'integration.sync_frequency' => ['nullable', \Illuminate\Validation\Rule::in(IntegrationSyncFrequency::allowedValues())],

            'ai_config' => ['nullable', 'array'],
            'ai_config.provider' => ['nullable', 'string', 'max:64'],
            'ai_config.model_name' => ['nullable', 'string', 'max:64'],
            'ai_config.embedding_model' => ['nullable', 'string', 'max:64'],
            'ai_config.temperature' => ['nullable', 'numeric', 'between:0,2'],
            'ai_config.max_output_tokens' => ['nullable', 'integer', 'min:128', 'max:4000'],
            'ai_config.max_messages_monthly' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'ai_config.max_tokens_daily' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'ai_config.max_tokens_monthly' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'ai_config.block_on_limit' => ['nullable', 'boolean'],
            'ai_config.alert_on_limit' => ['nullable', 'boolean'],
            'ai_config.top_p' => ['nullable', 'numeric', 'between:0,1'],
            'ai_config.system_prompt_template' => ['nullable', 'string'],
            'ai_config.sales_rules_json' => ['nullable', 'array'],
        ]);

        $tenantSlug = Str::slug((string) ($payload['tenant_slug'] ?? $payload['tenant_name']));
        if ($tenantSlug === '') {
            throw ValidationException::withMessages([
                'tenant_slug' => ['Tenant slug is invalid.'],
            ]);
        }

        if (Tenant::query()->where('slug', $tenantSlug)->exists()) {
            throw ValidationException::withMessages([
                'tenant_slug' => ['Tenant slug is already taken.'],
            ]);
        }

        $issued = DB::transaction(function () use ($payload, $tenantSlug, $tokenService, $integrationConnectionService, $openAIModelCatalogService, $actorSystemAdminUserId): array {
            $planCode = (string) ($payload['plan_code'] ?? 'starter');
            $plan = Plan::query()->firstOrCreate(
                ['code' => $planCode],
                [
                    'name' => Str::title(str_replace(['-', '_'], ' ', $planCode)),
                    'max_products' => 500,
                    'max_messages_monthly' => 5000,
                    'max_widgets' => 1,
                    'features_json' => [
                        'knowledge_upload' => true,
                        'csv_import' => true,
                        'integrations' => true,
                    ],
                ],
            );

            $tenant = Tenant::query()->create([
                'uuid' => (string) Str::uuid(),
                'name' => $payload['tenant_name'],
                'slug' => $tenantSlug,
                'status' => 'active',
                'plan_id' => $plan->id,
                'locale' => (string) ($payload['locale'] ?? 'bs'),
                'timezone' => (string) ($payload['timezone'] ?? 'Europe/Sarajevo'),
                'brand_name' => $payload['brand_name'] ?? $payload['tenant_name'],
                'support_email' => $payload['support_email'] ?? null,
            ]);

            $owner = User::query()->create([
                'name' => $payload['owner_name'],
                'email' => $payload['owner_email'],
                'password' => Hash::make((string) $payload['owner_password']),
                'is_system_admin' => false,
            ]);

            $tenant->users()->attach($owner->id, ['role' => 'owner']);
            if ($actorSystemAdminUserId !== null && $actorSystemAdminUserId !== (int) $owner->id) {
                $tenant->users()->syncWithoutDetaching([
                    $actorSystemAdminUserId => ['role' => 'admin'],
                ]);
            }

            $aiPayload = is_array($payload['ai_config'] ?? null) ? $payload['ai_config'] : [];
            $aiConfig = AiConfig::query()->create([
                'tenant_id' => $tenant->id,
                'provider' => $aiPayload['provider'] ?? 'openai',
                'model_name' => $openAIModelCatalogService->normalizeChatModel($aiPayload['model_name'] ?? null),
                'embedding_model' => $openAIModelCatalogService->normalizeEmbeddingModel($aiPayload['embedding_model'] ?? null),
                'temperature' => $aiPayload['temperature'] ?? 0.30,
                'max_output_tokens' => $aiPayload['max_output_tokens'] ?? max(64, (int) config('services.openai.default_max_output_tokens', 350)),
                'max_messages_monthly' => $aiPayload['max_messages_monthly'] ?? null,
                'max_tokens_daily' => $aiPayload['max_tokens_daily'] ?? null,
                'max_tokens_monthly' => $aiPayload['max_tokens_monthly'] ?? null,
                'block_on_limit' => $aiPayload['block_on_limit'] ?? true,
                'alert_on_limit' => $aiPayload['alert_on_limit'] ?? true,
                'top_p' => $aiPayload['top_p'] ?? 1.00,
                'system_prompt_template' => $aiPayload['system_prompt_template'] ?? null,
                'sales_rules_json' => $aiPayload['sales_rules_json'] ?? null,
            ]);

            $widgetPayload = is_array($payload['widget'] ?? null) ? $payload['widget'] : [];
            $widget = Widget::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $widgetPayload['name'] ?? 'Main Widget',
                'public_key' => 'wpk_'.Str::random(24),
                'secret_key' => 'wsk_'.Str::random(48),
                'allowed_domains_json' => $widgetPayload['allowed_domains_json'] ?? ['http://localhost', 'https://localhost'],
                'theme_json' => $widgetPayload['theme_json'] ?? null,
                'default_locale' => $widgetPayload['default_locale'] ?? $tenant->locale,
                'is_active' => (bool) ($widgetPayload['is_active'] ?? true),
            ]);

            $integration = null;
            $integrationPayload = is_array($payload['integration'] ?? null) ? $payload['integration'] : null;
            if ($this->shouldCreateIntegration($integrationPayload)) {
                $integration = $integrationConnectionService->create($tenant, [
                    'type' => $integrationPayload['type'] ?? 'manual',
                    'name' => $integrationPayload['name'] ?? 'Primary Source',
                    'base_url' => $integrationPayload['base_url'] ?? null,
                    'auth_type' => $integrationPayload['auth_type'] ?? null,
                    'credentials' => $integrationPayload['credentials'] ?? null,
                    'config_json' => $integrationPayload['config_json'] ?? null,
                    'mapping_json' => $integrationPayload['mapping_json'] ?? null,
                    'sync_frequency' => $integrationPayload['sync_frequency'] ?? null,
                ]);
            }

            $expiresAt = null;
            if (! empty($payload['token_ttl_minutes'])) {
                $expiresAt = CarbonImmutable::now()->addMinutes((int) $payload['token_ttl_minutes']);
            }

            $token = $tokenService->issueToken(
                $owner,
                $tenant,
                'owner',
                $tokenService->abilitiesForRole('owner'),
                'onboarding_owner_token',
                $expiresAt,
            );

            return [
                'plain_token' => $token['plain_text_token'],
                'token' => $token['token'],
                'tenant' => $tenant,
                'owner' => $owner,
                'widget' => $widget,
                'integration' => $integration,
                'ai_config' => $aiConfig,
            ];
        });

        return response()->json([
            'message' => 'Tenant onboarding completed.',
            'data' => [
                'token' => $issued['plain_token'],
                'token_type' => 'Bearer',
                'expires_at' => $issued['token']->expires_at,
                'role' => 'owner',
                'tenant' => [
                    'id' => $issued['tenant']->id,
                    'slug' => $issued['tenant']->slug,
                    'name' => $issued['tenant']->name,
                ],
                'user' => [
                    'id' => $issued['owner']->id,
                    'name' => $issued['owner']->name,
                    'email' => $issued['owner']->email,
                    'is_system_admin' => (bool) $issued['owner']->is_system_admin,
                ],
                'widget' => $issued['widget'],
                'integration' => $issued['integration'],
                'ai_config' => $issued['ai_config'],
            ],
        ], 201);
    }

    /**
     * @param array<string, mixed>|null $integrationPayload
     */
    private function shouldCreateIntegration(?array $integrationPayload): bool
    {
        if (! is_array($integrationPayload)) {
            return false;
        }

        if (($integrationPayload['enabled'] ?? false) === true) {
            return true;
        }

        $baseUrl = trim((string) ($integrationPayload['base_url'] ?? ''));
        if ($baseUrl !== '') {
            return true;
        }

        $credentials = $integrationPayload['credentials'] ?? null;
        if (is_array($credentials) && $credentials !== []) {
            return true;
        }

        $config = $integrationPayload['config_json'] ?? null;
        if (is_array($config) && $config !== []) {
            return true;
        }

        $mapping = $integrationPayload['mapping_json'] ?? null;
        if (is_array($mapping) && $mapping !== []) {
            return true;
        }

        return false;
    }
}
