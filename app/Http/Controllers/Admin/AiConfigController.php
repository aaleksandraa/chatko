<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\AiConfig;
use App\Services\Audit\AuditLogService;
use App\Services\Conversation\TenantUsageLimitService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiConfigController extends Controller
{
    use ResolvesTenant;

    public function show(Request $request, TenantContext $tenantContext, TenantUsageLimitService $usageLimitService): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $config = AiConfig::query()->firstOrNew(['tenant_id' => $tenant->id], [
            'provider' => 'openai',
            'model_name' => (string) config('services.openai.default_model', 'gpt-5-mini'),
            'embedding_model' => 'text-embedding-3-small',
            'temperature' => 0.30,
            'max_output_tokens' => max(64, (int) config('services.openai.default_max_output_tokens', 350)),
            'max_messages_monthly' => null,
            'max_tokens_daily' => null,
            'max_tokens_monthly' => null,
            'block_on_limit' => true,
            'alert_on_limit' => true,
            'top_p' => 1.00,
        ]);

        return response()->json([
            'data' => $config,
            'meta' => $usageLimitService->snapshot($tenant, $config),
        ]);
    }

    public function update(Request $request, TenantContext $tenantContext, AuditLogService $auditLogService): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $payload = $request->validate([
            'provider' => ['sometimes', 'string', 'max:64'],
            'model_name' => ['sometimes', 'string', 'max:64'],
            'embedding_model' => ['sometimes', 'string', 'max:64'],
            'temperature' => ['sometimes', 'numeric', 'between:0,2'],
            'max_output_tokens' => ['sometimes', 'integer', 'min:128', 'max:4000'],
            'max_messages_monthly' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000000'],
            'max_tokens_daily' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'max_tokens_monthly' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'block_on_limit' => ['sometimes', 'boolean'],
            'alert_on_limit' => ['sometimes', 'boolean'],
            'top_p' => ['sometimes', 'numeric', 'between:0,1'],
            'safety_rules_json' => ['sometimes', 'nullable', 'array'],
            'system_prompt_template' => ['sometimes', 'nullable', 'string'],
            'sales_rules_json' => ['sometimes', 'nullable', 'array'],
        ]);

        $config = AiConfig::query()->firstOrNew(['tenant_id' => $tenant->id]);
        $before = $config->exists ? $config->toArray() : null;
        $config->fill($payload);
        $config->tenant_id = $tenant->id;
        $config->save();
        $auditLogService->logMutation(
            $request,
            'updated',
            $config,
            $before,
            $config->fresh()?->toArray(),
            ['changed_fields' => array_keys($payload)],
        );

        return response()->json(['data' => $config]);
    }
}
