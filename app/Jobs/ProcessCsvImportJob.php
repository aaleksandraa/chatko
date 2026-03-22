<?php

namespace App\Jobs;

use App\Models\ImportJob;
use App\Services\Catalog\CsvImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessCsvImportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $importJobId,
        private readonly string $absolutePath,
    ) {
    }

    public function handle(CsvImportService $csvImportService): void
    {
        $importJob = ImportJob::query()->find($this->importJobId);

        if ($importJob === null) {
            return;
        }

        $tenant = $importJob->tenant;

        $csvImportService->process($importJob, $tenant, $this->absolutePath, 'csv');
    }
}
