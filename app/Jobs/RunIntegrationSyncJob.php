<?php

namespace App\Jobs;

use App\Models\ImportJob;
use App\Models\ImportJobRow;
use App\Models\IntegrationConnection;
use App\Services\Catalog\ProductUpsertService;
use App\Services\Integrations\Exceptions\IntegrationAdapterException;
use App\Services\Integrations\ProductSourceAdapterRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunIntegrationSyncJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $importJobId)
    {
    }

    public function handle(
        ProductSourceAdapterRegistry $adapterRegistry,
        ProductUpsertService $productUpsertService,
    ): void {
        $importJob = ImportJob::query()->with(['integrationConnection', 'tenant'])->find($this->importJobId);

        if ($importJob === null) {
            return;
        }

        $connection = $importJob->integrationConnection;
        if ($connection === null) {
            $importJob->update([
                'status' => 'failed',
                'finished_at' => now(),
                'log_summary' => 'Integration sync failed: missing integration connection.',
            ]);

            return;
        }

        $importJob->update(['status' => 'processing']);
        $connection->update(['status' => 'syncing']);

        try {
            $adapter = $adapterRegistry->resolve($connection);

            $syncMode = $importJob->job_type === 'initial_sync' ? 'initial' : 'delta';
            $since = $syncMode === 'delta' && $connection->last_sync_at !== null
                ? CarbonImmutable::parse($connection->last_sync_at)
                : null;

            $rows = $adapter->fetchProducts($connection, $syncMode, $since);

            $success = 0;
            $failed = 0;
            $skipped = 0;

            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    $skipped++;
                    ImportJobRow::query()->create([
                        'import_job_id' => $importJob->id,
                        'external_row_ref' => null,
                        'row_index' => $index + 1,
                        'raw_payload_json' => ['raw' => $row],
                        'status' => 'skipped',
                        'error_message' => 'Row is not a valid object.',
                    ]);
                    continue;
                }

                if (! $this->isActiveProductRow($row)) {
                    $skipped++;
                    ImportJobRow::query()->create([
                        'import_job_id' => $importJob->id,
                        'external_row_ref' => $row['external_id'] ?? ($row['id'] ?? null),
                        'row_index' => $index + 1,
                        'raw_payload_json' => $row,
                        'status' => 'skipped',
                        'error_message' => 'Product status is not active.',
                    ]);
                    continue;
                }

                $result = $productUpsertService->upsert(
                    $importJob->tenant,
                    $row,
                    $connection->type,
                    $connection,
                );

                if ($result['ok']) {
                    $success++;
                    ImportJobRow::query()->create([
                        'import_job_id' => $importJob->id,
                        'external_row_ref' => $row['external_id'] ?? ($row['id'] ?? null),
                        'row_index' => $index + 1,
                        'raw_payload_json' => $row,
                        'normalized_payload_json' => $result['product']?->toArray(),
                        'status' => 'processed',
                    ]);
                    continue;
                }

                $failed++;
                ImportJobRow::query()->create([
                    'import_job_id' => $importJob->id,
                    'external_row_ref' => $row['external_id'] ?? ($row['id'] ?? null),
                    'row_index' => $index + 1,
                    'raw_payload_json' => $row,
                    'status' => 'failed',
                    'error_message' => implode('; ', $result['errors']),
                ]);
            }

            $total = count($rows);

            $importJob->update([
                'status' => $failed > 0 ? 'completed_with_errors' : 'completed',
                'total_records' => $total,
                'processed_records' => $total,
                'success_records' => $success,
                'failed_records' => $failed,
                'skipped_records' => $skipped,
                'finished_at' => now(),
                'log_summary' => sprintf(
                    '%s sync finished. success=%d failed=%d skipped=%d',
                    strtoupper($syncMode),
                    $success,
                    $failed,
                    $skipped,
                ),
            ]);

            $connection->update([
                'status' => 'synced',
                'last_sync_at' => now(),
                'last_error' => null,
            ]);
        } catch (IntegrationAdapterException $exception) {
            $this->markFailure($importJob, $connection, $exception->getMessage());
        } catch (\Throwable $exception) {
            Log::error('Integration sync failed with unexpected error.', [
                'import_job_id' => $importJob->id,
                'connection_id' => $connection->id,
                'error' => $exception->getMessage(),
            ]);

            $this->markFailure($importJob, $connection, 'Integration sync failed: '.$exception->getMessage());
        }
    }

    private function markFailure(ImportJob $importJob, IntegrationConnection $connection, string $message): void
    {
        $importJob->update([
            'status' => 'failed',
            'finished_at' => now(),
            'log_summary' => $message,
        ]);

        $connection->update([
            'status' => 'sync_failed',
            'last_error' => $message,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isActiveProductRow(array $row): bool
    {
        $rawStatus = $row['status'] ?? null;
        if (! is_scalar($rawStatus) || trim((string) $rawStatus) === '') {
            return true;
        }

        $status = strtolower(trim((string) $rawStatus));

        return in_array($status, ['active', 'publish', 'published'], true);
    }
}
