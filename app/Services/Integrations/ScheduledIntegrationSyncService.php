<?php

namespace App\Services\Integrations;

use App\Models\ImportJob;
use App\Models\IntegrationConnection;
use App\Support\IntegrationSyncFrequency;
use Carbon\CarbonImmutable;

class ScheduledIntegrationSyncService
{
    public function __construct(private readonly SourceSyncDispatcher $sourceSyncDispatcher)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function runDueSyncs(int $limit = 100, bool $dryRun = false): array
    {
        $safeLimit = max(1, min($limit, 1000));
        $now = CarbonImmutable::now();

        $connections = IntegrationConnection::query()
            ->with('tenant')
            ->whereNotIn('type', ['manual', 'csv'])
            ->where('status', '!=', 'draft')
            ->orderBy('id')
            ->limit($safeLimit)
            ->get();

        $stats = [
            'checked' => 0,
            'due' => 0,
            'dispatched' => 0,
            'would_dispatch' => 0,
            'skipped_not_due' => 0,
            'skipped_manual' => 0,
            'skipped_busy' => 0,
            'skipped_no_tenant' => 0,
            'timestamp' => $now->toIso8601String(),
            'jobs' => [],
        ];

        foreach ($connections as $connection) {
            $stats['checked']++;

            $frequency = trim((string) ($connection->sync_frequency ?? ''));
            if ($frequency === '') {
                $frequency = IntegrationSyncFrequency::EVERY_15M;
                $connection->sync_frequency = $frequency;
                $connection->save();
            }

            $normalizedFrequency = IntegrationSyncFrequency::normalize($frequency);
            if ($normalizedFrequency === IntegrationSyncFrequency::MANUAL) {
                $stats['skipped_manual']++;
                continue;
            }

            $intervalSeconds = IntegrationSyncFrequency::intervalSeconds($normalizedFrequency);
            if ($intervalSeconds === null) {
                $stats['skipped_not_due']++;
                continue;
            }

            if (! $this->isDue($connection, $now, $intervalSeconds)) {
                $stats['skipped_not_due']++;
                continue;
            }

            $stats['due']++;

            $hasActiveImport = ImportJob::query()
                ->where('integration_connection_id', $connection->id)
                ->whereIn('status', ['pending', 'processing'])
                ->exists();

            if ($hasActiveImport) {
                $stats['skipped_busy']++;
                continue;
            }

            $tenant = $connection->tenant;
            if ($tenant === null) {
                $stats['skipped_no_tenant']++;
                continue;
            }

            $mode = $connection->last_sync_at === null ? 'initial' : 'delta';

            if ($dryRun) {
                $stats['would_dispatch']++;
                continue;
            }

            $job = $this->sourceSyncDispatcher->dispatch($tenant, $connection, $mode, null);
            $stats['dispatched']++;
            $stats['jobs'][] = [
                'integration_connection_id' => (int) $connection->id,
                'tenant_id' => (int) $tenant->id,
                'mode' => $mode,
                'import_job_id' => (int) $job->id,
            ];
        }

        return $stats;
    }

    /**
     * @return array<string, mixed>
     */
    public function freshnessReport(int $limit = 100): array
    {
        $safeLimit = max(1, min($limit, 2000));
        $now = CarbonImmutable::now();

        $connections = IntegrationConnection::query()
            ->with('tenant')
            ->whereNotIn('type', ['manual', 'csv'])
            ->where('status', '!=', 'draft')
            ->orderBy('id')
            ->limit($safeLimit)
            ->get();

        $rows = [];
        $summary = [
            'checked' => 0,
            'manual' => 0,
            'auto' => 0,
            'overdue' => 0,
            'never_synced' => 0,
        ];

        foreach ($connections as $connection) {
            $summary['checked']++;

            $frequencyRaw = trim((string) ($connection->sync_frequency ?? ''));
            $frequency = $frequencyRaw === ''
                ? IntegrationSyncFrequency::EVERY_15M
                : IntegrationSyncFrequency::normalize($frequencyRaw);
            $intervalSeconds = IntegrationSyncFrequency::intervalSeconds($frequency);

            if ($intervalSeconds === null) {
                $summary['manual']++;
            } else {
                $summary['auto']++;
            }

            $lastSyncAt = null;
            if ($connection->last_sync_at !== null) {
                try {
                    $lastSyncAt = CarbonImmutable::parse($connection->last_sync_at);
                } catch (\Throwable) {
                    $lastSyncAt = null;
                }
            }

            $nextDueAt = null;
            if ($intervalSeconds !== null) {
                $nextDueAt = $lastSyncAt !== null
                    ? $lastSyncAt->addSeconds($intervalSeconds)
                    : $now;
            }

            $isOverdue = $intervalSeconds !== null && $nextDueAt !== null && $nextDueAt->lessThanOrEqualTo($now);
            if ($isOverdue) {
                $summary['overdue']++;
            }

            if ($intervalSeconds !== null && $lastSyncAt === null) {
                $summary['never_synced']++;
            }

            $overdueBySeconds = 0;
            if ($isOverdue && $nextDueAt !== null) {
                $overdueBySeconds = max(0, $now->diffInSeconds($nextDueAt));
            }

            $rows[] = [
                'tenant_slug' => $connection->tenant?->slug,
                'integration_connection_id' => (int) $connection->id,
                'name' => (string) $connection->name,
                'type' => (string) $connection->type,
                'status' => (string) ($connection->status ?? 'draft'),
                'sync_frequency' => $frequency,
                'last_sync_at' => $lastSyncAt?->toIso8601String(),
                'next_due_at' => $nextDueAt?->toIso8601String(),
                'is_overdue' => $isOverdue,
                'overdue_by_seconds' => $overdueBySeconds,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return (int) ($b['overdue_by_seconds'] ?? 0) <=> (int) ($a['overdue_by_seconds'] ?? 0);
        });

        return [
            'timestamp' => $now->toIso8601String(),
            'summary' => $summary,
            'rows' => $rows,
        ];
    }

    private function isDue(IntegrationConnection $connection, CarbonImmutable $now, int $intervalSeconds): bool
    {
        if ($connection->last_sync_at === null) {
            return true;
        }

        try {
            $lastSyncAt = CarbonImmutable::parse($connection->last_sync_at);
        } catch (\Throwable) {
            return true;
        }

        return $lastSyncAt->addSeconds($intervalSeconds)->lessThanOrEqualTo($now);
    }
}
