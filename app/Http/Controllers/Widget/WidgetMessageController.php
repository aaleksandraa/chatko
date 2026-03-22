<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Widget;
use App\Services\Conversation\ConversationOrchestratorService;
use App\Services\Widget\WidgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WidgetMessageController extends Controller
{
    public function message(Request $request, WidgetService $widgetService, ConversationOrchestratorService $orchestratorService): JsonResponse
    {
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
            'widget_session_token' => ['nullable', 'string', 'max:2048'],
        ]);

        $widget = $request->attributes->get('widget');
        if (! $widget instanceof Widget) {
            $widget = $widgetService->resolveByPublicKey($payload['public_key']);
        }

        if ($widget === null) {
            return response()->json(['message' => 'Invalid widget key.'], 422);
        }

        $result = $orchestratorService->handleMessage($widget->tenant, $widget, $payload);
        $sessionToken = (string) $request->attributes->get('widget_session_token', '');
        if ($sessionToken !== '') {
            $result['widget_session_token'] = $sessionToken;
        }

        return response()->json(['data' => $result]);
    }
}
