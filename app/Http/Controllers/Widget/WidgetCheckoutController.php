<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Widget;
use App\Services\Conversation\CheckoutConversationService;
use App\Services\Integrations\Exceptions\IntegrationAdapterException;
use App\Services\Widget\WidgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WidgetCheckoutController extends Controller
{
    public function upsert(Request $request, WidgetService $widgetService, CheckoutConversationService $checkoutService): JsonResponse
    {
        $payload = $request->validate([
            'public_key' => ['required', 'string'],
            'conversation_id' => ['required', 'integer'],
            'customer_first_name' => ['nullable', 'string', 'max:120'],
            'customer_last_name' => ['nullable', 'string', 'max:120'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:64'],
            'delivery_address' => ['nullable', 'string', 'max:255'],
            'delivery_city' => ['nullable', 'string', 'max:120'],
            'delivery_postal_code' => ['nullable', 'string', 'max:32'],
            'delivery_country' => ['nullable', 'string', 'max:2'],
            'customer_note' => ['nullable', 'string', 'max:1000'],
            'payment_method' => ['nullable', Rule::in(['cod', 'online'])],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required_with:items', 'integer'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
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

        try {
            $result = $checkoutService->upsertCheckout($conversation, $payload);
        } catch (IntegrationAdapterException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }

    public function confirm(Request $request, WidgetService $widgetService, CheckoutConversationService $checkoutService): JsonResponse
    {
        $payload = $request->validate([
            'public_key' => ['required', 'string'],
            'conversation_id' => ['required', 'integer'],
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

        try {
            $result = $checkoutService->confirmCheckout($conversation);
        } catch (IntegrationAdapterException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }
}
