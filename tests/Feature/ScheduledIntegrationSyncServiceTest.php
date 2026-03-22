<?php

namespace Tests\Feature;

use App\Models\ImportJob;
use App\Models\IntegrationConnection;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Integrations\ScheduledIntegrationSyncService;
use App\Services\Integrations\SourceSyncDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ScheduledIntegrationSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::query()->create([
            'name' => 'Starter',
            'code' => 'starter',
        ]);

        $this->tenant = Tenant::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Scheduler Tenant',
            'slug' => 'scheduler-tenant',
            'plan_id' => $plan->id,
            'locale' => 'bs',
            'timezone' => 'Europe/Sarajevo',
        ]);
    }

    public function test_service_dispatches_due_delta_sync_and_skips_not_due_and_busy(): void
    {
        $due = IntegrationConnection::query()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'custom_api',
            'name' => 'Due Source',
            'status' => 'connected',
            'base_url' => 'https://api.example.com',
            'sync_frequency' => 'every_15m',
            'last_sync_at' => now()->subMinutes(20),
        ]);

        $notDue = IntegrationConnection::query()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'custom_api',
            'name' => 'Not Due Source',
            'status' => 'connected',
            'base_url' => 'https://api2.example.com',
            'sync_frequency' => 'every_15m',
            'last_sync_at' => now()->subMinutes(5),
        ]);

        $busy = IntegrationConnection::query()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'custom_api',
            'name' => 'Busy Source',
            'status' => 'connected',
            'base_url' => 'https://api3.example.com',
            'sync_frequency' => 'every_15m',
            'last_sync_at' => now()->subMinutes(25),
        ]);

        ImportJob::query()->create([
            'tenant_id' => $this->tenant->id,
            'integration_connection_id' => $busy->id,
            'job_type' => 'delta_sync',
            'source_type' => 'custom_api',
            'status' => 'processing',
            'started_at' => now()->subMinute(),
        ]);

        $dispatchedJob = new ImportJob([
            'id' => 999,
            'tenant_id' => $this->tenant->id,
            'integration_connection_id' => $due->id,
            'job_type' => 'delta_sync',
            'source_type' => 'custom_api',
            'status' => 'pending',
            'started_at' => now(),
        ]);

        $dispatcherMock = Mockery::mock(SourceSyncDispatcher::class);
        $dispatcherMock
            ->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($tenant, $connection, $mode, $triggeredBy) use ($due): bool {
                return (int) $tenant->id === (int) $due->tenant_id
                    && (int) $connection->id === (int) $due->id
                    && $mode === 'delta'
                    && $triggeredBy === null;
            })
            ->andReturn($dispatchedJob);

        $this->app->instance(SourceSyncDispatcher::class, $dispatcherMock);

        $service = $this->app->make(ScheduledIntegrationSyncService::class);
        $result = $service->runDueSyncs();

        $this->assertSame(3, (int) $result['checked']);
        $this->assertSame(2, (int) $result['due']);
        $this->assertSame(1, (int) $result['dispatched']);
        $this->assertSame(1, (int) $result['skipped_not_due']);
        $this->assertSame(1, (int) $result['skipped_busy']);
    }

    public function test_service_can_run_in_dry_run_mode_without_dispatching(): void
    {
        IntegrationConnection::query()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'custom_api',
            'name' => 'Dry Run Source',
            'status' => 'connected',
            'base_url' => 'https://dry.example.com',
            'sync_frequency' => 'every_5m',
            'last_sync_at' => now()->subMinutes(10),
        ]);

        $dispatcherMock = Mockery::mock(SourceSyncDispatcher::class);
        $dispatcherMock->shouldNotReceive('dispatch');
        $this->app->instance(SourceSyncDispatcher::class, $dispatcherMock);

        $service = $this->app->make(ScheduledIntegrationSyncService::class);
        $result = $service->runDueSyncs(100, true);

        $this->assertSame(1, (int) $result['checked']);
        $this->assertSame(1, (int) $result['due']);
        $this->assertSame(1, (int) $result['would_dispatch']);
        $this->assertSame(0, (int) $result['dispatched']);
    }
}
