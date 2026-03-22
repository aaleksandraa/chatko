<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Widget;
use App\Services\Conversation\AnalyticsService;
use App\Services\Widget\WidgetSecurityService;
use App\Services\Widget\WidgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WidgetSessionController extends Controller
{
    public function start(
        Request $request,
        WidgetService $widgetService,
        WidgetSecurityService $widgetSecurityService,
        AnalyticsService $analyticsService,
    ): JsonResponse
    {
        $payload = $request->validate([
            'public_key' => ['required', 'string'],
            'visitor_uuid' => ['nullable', 'string', 'max:64'],
            'session_id' => ['nullable', 'string', 'max:64'],
            'source_url' => ['nullable', 'string', 'max:2048'],
            'utm_json' => ['nullable', 'array'],
            'locale' => ['nullable', 'string', 'max:16'],
            'challenge_token' => ['nullable', 'string', 'max:4096'],
        ]);

        $widget = $request->attributes->get('widget');
        if (! $widget instanceof Widget) {
            $widget = $widgetService->resolveByPublicKey($payload['public_key']);
        }

        if (! $widget instanceof Widget) {
            return response()->json(['message' => 'Invalid widget key.'], 422);
        }

        $conversation = Conversation::query()->create([
            'tenant_id' => $widget->tenant_id,
            'widget_id' => $widget->id,
            'visitor_uuid' => $payload['visitor_uuid'] ?? (string) Str::uuid(),
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
}
