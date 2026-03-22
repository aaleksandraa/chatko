<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\OrderStatusEvent;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderStatusEventController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $query = OrderStatusEvent::query()
            ->where('tenant_id', $tenant->id)
            ->with([
                'integrationConnection:id,type,name',
                'conversation:id,status,session_id',
            ]);

        if ($request->filled('status')) {
            $query->where('normalized_status', (string) $request->query('status'));
        }

        if ($request->filled('provider')) {
            $provider = (string) $request->query('provider');
            $query->whereHas('integrationConnection', function ($inner) use ($provider): void {
                $inner->where('type', $provider);
            });
        }

        if ($request->filled('order_id')) {
            $orderId = (string) $request->query('order_id');
            $query->where('external_order_id', 'like', '%'.$orderId.'%');
        }

        $items = $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(50);

        return response()->json($items);
    }
}
