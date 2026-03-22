<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    use ResolvesTenant;

    public function overview(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $conversations = Conversation::query()->where('tenant_id', $tenant->id)->count();
        $leadCount = Lead::query()->where('tenant_id', $tenant->id)->count();
        $events = AnalyticsEvent::query()->where('tenant_id', $tenant->id)->count();

        $topEvents = AnalyticsEvent::query()
            ->selectRaw('event_name, COUNT(*) as total')
            ->where('tenant_id', $tenant->id)
            ->groupBy('event_name')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => [
                'conversations' => $conversations,
                'leads' => $leadCount,
                'events' => $events,
                'lead_capture_rate' => $conversations > 0 ? round(($leadCount / $conversations) * 100, 2) : 0,
                'top_events' => $topEvents,
            ],
        ]);
    }
}
