<?php

namespace App\Services\Integrations;

use App\Jobs\RunIntegrationSyncJob;
use App\Models\ImportJob;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

class SourceSyncDispatcher
{
    public function dispatch(
        Tenant $tenant,
        IntegrationConnection $connection,
        string $mode = 'delta',
        int|string|null $triggeredBy = null,
    ): ImportJob
    {
        $jobType = $mode === 'initial' ? 'initial_sync' : 'delta_sync';

        $job = ImportJob::query()->create([
            'tenant_id' => $tenant->id,
            'integration_connection_id' => $connection->id,
            'job_type' => $jobType,
            'source_type' => $connection->type,
            'status' => 'pending',
            'started_at' => Carbon::now(),
            'triggered_by' => $triggeredBy,
        ]);

        RunIntegrationSyncJob::dispatch($job->id);

        return $job;
    }
}
