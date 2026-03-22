<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Widget;
use App\Services\Widget\WidgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WidgetConfigController extends Controller
{
    public function show(Request $request, WidgetService $widgetService, string $publicKey): JsonResponse
    {
        $widget = $request->attributes->get('widget');
        if (! $widget instanceof Widget) {
            $widget = $widgetService->resolveByPublicKey($publicKey);
        }

        if ($widget === null) {
            return response()->json(['message' => 'Widget not found.'], 404);
        }

        return response()->json([
            'data' => $widgetService->publicConfig($widget),
        ]);
    }
}
