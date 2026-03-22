<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\OrderAttributed;
use App\Services\Audit\AuditLogService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationAdminController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $query = Conversation::query()->where('tenant_id', $tenant->id);

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        $items = $query->latest('id')->paginate(25);

        $items->getCollection()->transform(function (Conversation $conversation): array {
            $latestOrder = OrderAttributed::query()
                ->where('tenant_id', $conversation->tenant_id)
                ->where('conversation_id', $conversation->id)
                ->orderByDesc('last_status_at')
                ->orderByDesc('id')
                ->first();

            $row = $conversation->toArray();
            $row['latest_order_id'] = $latestOrder?->external_order_id;
            $row['latest_order_status'] = $latestOrder?->last_status;
            $row['latest_order_status_at'] = $latestOrder?->last_status_at?->toIso8601String();

            return $row;
        });

        return response()->json($items);
    }

    public function showMessages(Request $request, TenantContext $tenantContext, int $id): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        Conversation::query()->where('tenant_id', $tenant->id)->findOrFail($id);

        $messages = ConversationMessage::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $id)
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $messages]);
    }

    public function update(Request $request, TenantContext $tenantContext, AuditLogService $auditLogService, int $id): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $conversation = Conversation::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        $payload = $request->validate([
            'status' => ['sometimes', 'string', 'max:32'],
            'lead_captured' => ['sometimes', 'boolean'],
            'converted' => ['sometimes', 'boolean'],
            'ended_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $before = $conversation->toArray();
        $conversation->fill($payload);
        $conversation->save();
        $auditLogService->logMutation(
            $request,
            'updated',
            $conversation,
            $before,
            $conversation->fresh()?->toArray(),
            ['changed_fields' => array_keys($payload)],
        );

        return response()->json(['data' => $conversation]);
    }

    public function destroy(Request $request, TenantContext $tenantContext, AuditLogService $auditLogService, int $id): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $conversation = Conversation::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        $before = $conversation->toArray();
        $conversation->delete();
        $auditLogService->logMutation(
            $request,
            'deleted',
            $conversation,
            $before,
            null,
        );

        return response()->json([
            'message' => 'Conversation deleted.',
        ]);
    }
}
