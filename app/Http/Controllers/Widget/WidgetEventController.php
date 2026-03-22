<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Widget;
use App\Services\Conversation\AnalyticsService;
use App\Services\Widget\WidgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WidgetEventController extends Controller
{
    public function store(Request $request, WidgetService $widgetService, AnalyticsService $analyticsService): JsonResponse
    {
        $payload = $request->validate([
            'public_key' => ['required', 'string'],
            'conversation_id' => ['nullable', 'integer'],
            'visitor_uuid' => ['nullable', 'string', 'max:64'],
            'event_name' => ['required', 'string', 'max:128'],
            'event_value' => ['nullable', 'numeric'],
            'metadata_json' => ['nullable', 'array'],
            'widget_session_token' => ['nullable', 'string', 'max:2048'],
        ]);

        $widget = $request->attributes->get('widget');
        if (! $widget instanceof Widget) {
            $widget = $widgetService->resolveByPublicKey($payload['public_key']);
        }

        if ($widget === null) {
            return response()->json(['message' => 'Invalid widget key.'], 422);
        }

        $conversation = null;

        if (! empty($payload['conversation_id'])) {
            $conversation = Conversation::query()
                ->where('tenant_id', $widget->tenant_id)
                ->where('widget_id', $widget->id)
                ->find($payload['conversation_id']);
        }

        if ($conversation !== null) {
            $event = $analyticsService->track(
                $conversation,
                $payload['event_name'],
                isset($payload['event_value']) ? (float) $payload['event_value'] : null,
                $payload['metadata_json'] ?? [],
            );

            return response()->json(['data' => $event], 201);
        }

        $event = \App\Models\AnalyticsEvent::query()->create([
            'tenant_id' => $widget->tenant_id,
            'visitor_uuid' => $payload['visitor_uuid'] ?? null,
            'event_name' => $payload['event_name'],
            'event_value' => isset($payload['event_value']) ? (float) $payload['event_value'] : null,
            'metadata_json' => $payload['metadata_json'] ?? null,
        ]);

        return response()->json(['data' => $event], 201);
    }
}
