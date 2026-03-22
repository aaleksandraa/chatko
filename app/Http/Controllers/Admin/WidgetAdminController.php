<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Widget;
use App\Services\Audit\AuditLogService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WidgetAdminController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $widgets = Widget::query()
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->get();

        return response()->json(['data' => $widgets]);
    }

    public function store(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'allowed_domains_json' => ['nullable', 'array'],
            'theme_json' => ['nullable', 'array'],
            'default_locale' => ['nullable', 'string', 'max:16'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $widget = Widget::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $payload['name'],
            'public_key' => 'wpk_'.Str::random(24),
            'secret_key' => 'wsk_'.Str::random(48),
            'allowed_domains_json' => $payload['allowed_domains_json'] ?? null,
            'theme_json' => $payload['theme_json'] ?? null,
            'default_locale' => $payload['default_locale'] ?? $tenant->locale,
            'is_active' => (bool) ($payload['is_active'] ?? true),
        ]);

        return response()->json(['data' => $widget], 201);
    }

    public function update(Request $request, TenantContext $tenantContext, AuditLogService $auditLogService, int $id): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $widget = Widget::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        $payload = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'allowed_domains_json' => ['sometimes', 'nullable', 'array'],
            'theme_json' => ['sometimes', 'nullable', 'array'],
            'default_locale' => ['sometimes', 'string', 'max:16'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $before = $widget->toArray();
        $widget->fill($payload);
        $widget->save();
        $auditLogService->logMutation(
            $request,
            'updated',
            $widget,
            $before,
            $widget->fresh()?->toArray(),
            ['changed_fields' => array_keys($payload)],
        );

        return response()->json(['data' => $widget]);
    }

    public function destroy(Request $request, TenantContext $tenantContext, AuditLogService $auditLogService, int $id): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $widget = Widget::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        $before = $widget->toArray();
        $widget->delete();
        $auditLogService->logMutation(
            $request,
            'deleted',
            $widget,
            $before,
            null,
        );

        return response()->json([
            'message' => 'Widget deleted.',
        ]);
    }
}
