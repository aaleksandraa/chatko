<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Models\SourceMappingPreset;
use App\Services\Audit\AuditLogService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SourceMappingPresetController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request, TenantContext $tenantContext, int $integrationId): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);
        $connection = IntegrationConnection::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($integrationId);

        $presets = SourceMappingPreset::query()
            ->where('tenant_id', $tenant->id)
            ->where('integration_connection_id', $connection->id)
            ->latest('id')
            ->get();

        return response()->json(['data' => $presets]);
    }

    public function store(Request $request, TenantContext $tenantContext, int $integrationId): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);
        $connection = IntegrationConnection::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($integrationId);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mapping_json' => ['required', 'array'],
            'apply_to_connection' => ['nullable', 'boolean'],
        ]);

        $preset = SourceMappingPreset::query()->create([
            'tenant_id' => $tenant->id,
            'integration_connection_id' => $connection->id,
            'name' => $payload['name'],
            'mapping_json' => $payload['mapping_json'],
        ]);

        if ((bool) ($payload['apply_to_connection'] ?? false)) {
            $connection->update(['mapping_json' => $payload['mapping_json']]);
        }

        return response()->json(['data' => $preset], 201);
    }

    public function update(Request $request, TenantContext $tenantContext, AuditLogService $auditLogService, int $id): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $preset = SourceMappingPreset::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        $payload = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'mapping_json' => ['sometimes', 'array'],
            'apply_to_connection' => ['nullable', 'boolean'],
        ]);

        $before = $preset->toArray();
        $preset->fill($payload);
        $preset->save();

        if ((bool) ($payload['apply_to_connection'] ?? false)) {
            $preset->integrationConnection?->update(['mapping_json' => $preset->mapping_json]);
        }

        $auditLogService->logMutation(
            $request,
            'updated',
            $preset,
            $before,
            $preset->fresh()?->toArray(),
            ['changed_fields' => array_keys($payload)],
        );

        return response()->json(['data' => $preset]);
    }

    public function apply(Request $request, TenantContext $tenantContext, int $id): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $preset = SourceMappingPreset::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        $connection = $preset->integrationConnection;
        if ($connection === null || $connection->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Preset connection not found.'], 404);
        }

        $connection->update(['mapping_json' => $preset->mapping_json]);

        return response()->json([
            'message' => 'Mapping preset applied to integration connection.',
            'data' => $connection,
        ]);
    }

    public function destroy(Request $request, TenantContext $tenantContext, AuditLogService $auditLogService, int $id): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $preset = SourceMappingPreset::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        $before = $preset->toArray();
        $preset->delete();
        $auditLogService->logMutation(
            $request,
            'deleted',
            $preset,
            $before,
            null,
        );

        return response()->json([
            'message' => 'Mapping preset deleted.',
        ]);
    }
}
