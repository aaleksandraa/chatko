<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $query = AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->with('actor:id,name,email');

        if ($request->filled('action')) {
            $query->where('action', (string) $request->query('action'));
        }
        if ($request->filled('entity_type')) {
            $query->where('entity_type', (string) $request->query('entity_type'));
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', (string) $request->query('entity_id'));
        }
        if ($request->filled('actor_user_id')) {
            $query->where('actor_user_id', (int) $request->query('actor_user_id'));
        }

        $items = $query->latest('id')->paginate(50);

        return response()->json($items);
    }
}
