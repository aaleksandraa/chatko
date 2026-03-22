<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Widget;
use App\Services\Conversation\AnalyticsService;
use App\Services\Conversation\LeadCaptureService;
use App\Services\Widget\WidgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WidgetLeadController extends Controller
{
    public function store(Request $request, WidgetService $widgetService, LeadCaptureService $leadCaptureService, AnalyticsService $analyticsService): JsonResponse
    {
        $payload = $request->validate([
            'public_key' => ['required', 'string'],
            'conversation_id' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string'],
            'consent' => ['nullable', 'boolean'],
            'lead_status' => ['nullable', 'string', 'max:64'],
            'widget_session_token' => ['nullable', 'string', 'max:2048'],
        ]);

        $widget = $request->attributes->get('widget');
        if (! $widget instanceof Widget) {
            $widget = $widgetService->resolveByPublicKey($payload['public_key']);
        }

        if ($widget === null) {
            return response()->json(['message' => 'Invalid widget key.'], 422);
        }

        $conversation = Conversation::query()
            ->where('tenant_id', $widget->tenant_id)
            ->where('widget_id', $widget->id)
            ->findOrFail($payload['conversation_id']);

        $lead = $leadCaptureService->capture($conversation, $payload);

        $analyticsService->track($conversation, 'email_captured', null, [
            'lead_id' => $lead->id,
        ]);

        return response()->json(['data' => $lead], 201);
    }
}
