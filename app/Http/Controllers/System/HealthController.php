<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => config('app.name', 'Chatko'),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => ['ok' => false, 'message' => 'not_checked'],
            'storage' => ['ok' => false, 'message' => 'not_checked'],
            'queue' => ['ok' => true, 'message' => (string) config('queue.default')],
        ];

        try {
            DB::connection()->getPdo();
            DB::select('select 1 as ok');
            $checks['database'] = ['ok' => true, 'message' => 'connected'];
        } catch (Throwable $e) {
            $checks['database'] = ['ok' => false, 'message' => $e->getMessage()];
        }

        $storageWritable = is_writable(storage_path());
        $checks['storage'] = [
            'ok' => $storageWritable,
            'message' => $storageWritable ? 'writable' : 'not_writable',
        ];

        $ready = collect($checks)->every(static fn (array $check): bool => (bool) ($check['ok'] ?? false));

        return response()->json([
            'status' => $ready ? 'ok' : 'degraded',
            'service' => config('app.name', 'Chatko'),
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ], $ready ? 200 : 503);
    }
}
