<?php

namespace App\Services\Catalog;

use App\Models\ImportJob;
use App\Models\ImportJobRow;
use App\Models\Tenant;
use SplFileObject;

class CsvImportService
{
    public function __construct(private readonly ProductUpsertService $productUpsertService)
    {
    }

    public function process(ImportJob $importJob, Tenant $tenant, string $path, string $sourceType = 'csv'): ImportJob
    {
        $importJob->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);

        $headers = null;
        $rowIndex = 0;

        $success = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($file as $row) {
            if (! is_array($row) || $row === [null]) {
                continue;
            }

            if ($headers === null) {
                $headers = array_map(static fn ($value) => trim((string) $value), $row);
                continue;
            }

            $rowIndex++;

            $payload = [];
            foreach ($headers as $idx => $header) {
                if ($header === '') {
                    continue;
                }

                $payload[$header] = $row[$idx] ?? null;
            }

            if (($payload['name'] ?? null) === null || trim((string) $payload['name']) === '') {
                $skipped++;
                ImportJobRow::query()->create([
                    'import_job_id' => $importJob->id,
                    'row_index' => $rowIndex,
                    'raw_payload_json' => $payload,
                    'status' => 'skipped',
                    'error_message' => 'Missing required field: name',
                ]);
                continue;
            }

            $result = $this->productUpsertService->upsert($tenant, $payload, $sourceType, null);

            if ($result['ok']) {
                $success++;
                ImportJobRow::query()->create([
                    'import_job_id' => $importJob->id,
                    'row_index' => $rowIndex,
                    'raw_payload_json' => $payload,
                    'normalized_payload_json' => $result['product']?->toArray(),
                    'status' => 'processed',
                ]);
            } else {
                $failed++;
                ImportJobRow::query()->create([
                    'import_job_id' => $importJob->id,
                    'row_index' => $rowIndex,
                    'raw_payload_json' => $payload,
                    'status' => 'failed',
                    'error_message' => implode('; ', $result['errors']),
                ]);
            }
        }

        $total = $success + $failed + $skipped;

        $importJob->update([
            'status' => $failed > 0 ? 'completed_with_errors' : 'completed',
            'total_records' => $total,
            'processed_records' => $total,
            'success_records' => $success,
            'failed_records' => $failed,
            'skipped_records' => $skipped,
            'finished_at' => now(),
            'log_summary' => sprintf('CSV import completed. success=%d failed=%d skipped=%d', $success, $failed, $skipped),
        ]);

        return $importJob->fresh();
    }
}
