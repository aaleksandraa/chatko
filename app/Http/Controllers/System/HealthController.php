<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        $queueConnection = (string) config('queue.default', 'database');
        if ($queueConnection === 'database') {
            try {
                $pendingJobs = 0;
                $oldestPendingAgeSeconds = 0;

                if (Schema::hasTable('jobs')) {
                    $pendingJobs = (int) DB::table('jobs')->count();
                    $oldestAvailableAt = DB::table('jobs')->min('available_at');
                    if (is_numeric($oldestAvailableAt)) {
                        $oldestPendingAgeSeconds = max(0, now()->timestamp - (int) $oldestAvailableAt);
                    }
                }

                $maxPendingAgeSeconds = max(60, (int) env('QUEUE_HEALTH_MAX_PENDING_AGE_SECONDS', 900));
                $queueHealthy = ! ($pendingJobs > 0 && $oldestPendingAgeSeconds >= $maxPendingAgeSeconds);

                $checks['queue'] = [
                    'ok' => $queueHealthy,
                    'message' => $queueHealthy ? 'database_queue_ok' : 'database_queue_stalled',
                    'meta' => [
                        'connection' => $queueConnection,
                        'pending_jobs' => $pendingJobs,
                        'oldest_pending_age_seconds' => $oldestPendingAgeSeconds,
                        'max_pending_age_seconds' => $maxPendingAgeSeconds,
                    ],
                ];
            } catch (Throwable $e) {
                $checks['queue'] = [
                    'ok' => false,
                    'message' => 'database_queue_check_failed: '.$e->getMessage(),
                ];
            }
        }

        $ready = collect($checks)->every(static fn (array $check): bool => (bool) ($check['ok'] ?? false));

        return response()->json([
            'status' => $ready ? 'ok' : 'degraded',
            'service' => config('app.name', 'Chatko'),
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ], $ready ? 200 : 503);
    }
}
