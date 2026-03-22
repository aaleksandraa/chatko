<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Widget;
use App\Services\Conversation\AnalyticsService;
use App\Services\Conversation\ConversationOrchestratorService;
use App\Services\Widget\WidgetSecurityService;
use App\Services\Widget\WidgetService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WidgetLabController extends Controller
{
    use ResolvesTenant;

    public function start(
        Request $request,
        TenantContext $tenantContext,
        WidgetService $widgetService,
        WidgetSecurityService $widgetSecurityService,
        AnalyticsService $analyticsService,
    ): JsonResponse {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $payload = $request->validate([
            'public_key' => ['required', 'string'],
            'visitor_uuid' => ['nullable', 'string', 'max:64'],
            'session_id' => ['nullable', 'string', 'max:64'],
            'source_url' => ['nullable', 'string', 'max:2048'],
            'utm_json' => ['nullable', 'array'],
            'locale' => ['nullable', 'string', 'max:16'],
        ]);

        $widget = $widgetService->resolveByPublicKey($payload['public_key']);
        if (! $widget instanceof Widget) {
            return response()->json(['message' => 'Invalid widget key.'], 422);
        }

        if ((int) $widget->tenant_id !== (int) $tenant->id) {
            return response()->json([
                'message' => 'Widget does not belong to current tenant context.',
            ], 403);
        }

        $conversation = Conversation::query()->create([
            'tenant_id' => $widget->tenant_id,
            'widget_id' => $widget->id,
            'visitor_uuid' => $this->normalizedVisitorUuid($payload['visitor_uuid'] ?? null),
            'session_id' => $payload['session_id'] ?? (string) Str::uuid(),
            'channel' => 'web_widget',
            'locale' => $payload['locale'] ?? $widget->default_locale,
            'started_at' => now(),
            'source_url' => $payload['source_url'] ?? null,
            'utm_json' => $payload['utm_json'] ?? null,
            'status' => 'active',
        ]);

        $analyticsService->track($conversation, 'widget_opened', null, [
            'source_url' => $conversation->source_url,
            'mode' => 'widget_lab_admin',
        ]);

        $sessionToken = $widgetSecurityService->issueSessionToken($widget, $conversation);

        return response()->json([
            'data' => [
                'conversation_id' => $conversation->id,
                'session_id' => $conversation->session_id,
                'visitor_uuid' => $conversation->visitor_uuid,
                'widget_session_token' => $sessionToken,
            ],
        ], 201);
    }

    public function message(
        Request $request,
        TenantContext $tenantContext,
        WidgetService $widgetService,
        WidgetSecurityService $widgetSecurityService,
        ConversationOrchestratorService $orchestratorService,
    ): JsonResponse {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $payload = $request->validate([
            'public_key' => ['required', 'string'],
            'message' => ['required', 'string', 'min:1'],
            'conversation_id' => ['nullable', 'integer'],
            'session_id' => ['nullable', 'string', 'max:64'],
            'visitor_uuid' => ['nullable', 'string', 'max:64'],
            'source_url' => ['nullable', 'string', 'max:2048'],
            'utm_json' => ['nullable', 'array'],
            'locale' => ['nullable', 'string', 'max:16'],
            'channel' => ['nullable', 'string', 'max:32'],
            'widget_session_token' => ['required', 'string', 'max:2048'],
        ]);

        $widget = $widgetService->resolveByPublicKey($payload['public_key']);
        if (! $widget instanceof Widget) {
            return response()->json(['message' => 'Invalid widget key.'], 422);
        }

        if ((int) $widget->tenant_id !== (int) $tenant->id) {
            return response()->json([
                'message' => 'Widget does not belong to current tenant context.',
            ], 403);
        }

        $tokenCheck = $widgetSecurityService->validateSessionToken($request, $widget, $payload);
        if (! (bool) ($tokenCheck['ok'] ?? false)) {
            return response()->json(['message' => 'Invalid widget session token.'], 401);
        }

        $claims = is_array($tokenCheck['claims'] ?? null) ? $tokenCheck['claims'] : [];
        $widgetSecurityService->mergeSessionClaims($request, $claims);
        $request->attributes->set('widget_session_claims', $claims);
        $request->attributes->set('widget_session_token', (string) ($tokenCheck['token'] ?? ''));

        $result = $orchestratorService->handleMessage($widget->tenant, $widget, $request->all());
        $result['widget_session_token'] = (string) ($tokenCheck['token'] ?? $payload['widget_session_token']);

        return response()->json(['data' => $result]);
    }

    private function normalizedVisitorUuid(mixed $raw): string
    {
        $candidate = trim((string) ($raw ?? ''));
        if (Str::isUuid($candidate)) {
            return strtolower($candidate);
        }

        return (string) Str::uuid();
    }
}
