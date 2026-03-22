<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Services\Integrations\Exceptions\IntegrationAdapterException;
use App\Services\Integrations\OrderStatusWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationOrderWebhookController extends Controller
{
    public function status(Request $request, OrderStatusWebhookService $webhookService, int $connectionId): JsonResponse
    {
        $connection = IntegrationConnection::query()->findOrFail($connectionId);

        $payload = $request->json()->all();
        if (! is_array($payload)) {
            $payload = [];
        }

        $headers = [];
        foreach ($request->headers->all() as $key => $values) {
            $value = is_array($values) ? ($values[0] ?? '') : $values;
            $headers[strtolower((string) $key)] = is_string($value) ? $value : '';
        }

        try {
            $result = $webhookService->process(
                $connection,
                $payload,
                (string) $request->getContent(),
                $headers,
                $request->query('token'),
            );
        } catch (IntegrationAdapterException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Order status synchronized.',
            'data' => $result,
        ]);
    }
}
