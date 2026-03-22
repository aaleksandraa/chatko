<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\ImportJob;
use App\Services\Audit\AuditLogService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportJobController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $jobs = ImportJob::query()
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->paginate(20);

        return response()->json($jobs);
    }

    public function show(Request $request, TenantContext $tenantContext, int $id): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $job = ImportJob::query()
            ->where('tenant_id', $tenant->id)
            ->with('rows')
            ->findOrFail($id);

        return response()->json(['data' => $job]);
    }

    public function update(Request $request, TenantContext $tenantContext, AuditLogService $auditLogService, int $id): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $job = ImportJob::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        $payload = $request->validate([
            'status' => ['sometimes', 'string', 'max:32'],
            'log_summary' => ['sometimes', 'nullable', 'string'],
        ]);

        $before = $job->toArray();
        $job->fill($payload);
        $job->save();
        $auditLogService->logMutation(
            $request,
            'updated',
            $job,
            $before,
            $job->fresh()?->toArray(),
            ['changed_fields' => array_keys($payload)],
        );

        return response()->json(['data' => $job]);
    }

    public function destroy(Request $request, TenantContext $tenantContext, AuditLogService $auditLogService, int $id): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $job = ImportJob::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        $before = $job->toArray();
        $job->delete();
        $auditLogService->logMutation(
            $request,
            'deleted',
            $job,
            $before,
            null,
        );

        return response()->json([
            'message' => 'Import job deleted.',
        ]);
    }
}
