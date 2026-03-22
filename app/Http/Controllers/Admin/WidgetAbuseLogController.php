<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\WidgetAbuseLog;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WidgetAbuseLogController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $query = WidgetAbuseLog::query()
            ->where('tenant_id', $tenant->id)
            ->with('widget:id,name,public_key');

        if ($request->filled('reason')) {
            $query->where('reason', (string) $request->query('reason'));
        }

        if ($request->filled('ip')) {
            $ip = trim((string) $request->query('ip'));
            if ($ip !== '') {
                $query->where('ip_address', 'like', '%'.$ip.'%');
            }
        }

        if ($request->filled('public_key')) {
            $publicKey = trim((string) $request->query('public_key'));
            if ($publicKey !== '') {
                $query->where('public_key', 'like', '%'.$publicKey.'%');
            }
        }

        $items = $query
            ->latest('id')
            ->paginate(50);

        return response()->json($items);
    }
}

