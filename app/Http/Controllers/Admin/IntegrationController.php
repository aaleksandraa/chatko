<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\ImportJob;
use App\Models\IntegrationConnection;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\Integrations\SourceSyncDispatcher;
use App\Services\Integrations\SourceTestService;
use App\Services\Audit\AuditLogService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IntegrationController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $items = IntegrationConnection::query()
            ->where('tenant_id', $tenant->id)
            ->latest()
            ->get();

        return response()->json([
            'data' => $items->map(fn (IntegrationConnection $connection): array => $this->serializeConnection($connection))->values(),
        ]);
    }

    public function store(Request $request, TenantContext $tenantContext, IntegrationConnectionService $service): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $payload = $request->validate([
            'type' => ['required', Rule::in(['woocommerce', 'wordpress_rest', 'shopify', 'custom_api', 'csv', 'manual'])],
            'name' => ['required', 'string', 'max:255'],
            'base_url' => ['nullable', 'string', 'max:2048'],
            'auth_type' => ['nullable', 'string', 'max:64'],
            'credentials' => ['nullable', 'array'],
            'config_json' => ['nullable', 'array'],
            'mapping_json' => ['nullable', 'array'],
            'sync_frequency' => ['nullable', 'string', 'max:64'],
        ]);

        $connection = $service->create($tenant, $payload);

        return response()->json(['data' => $this->serializeConnection($connection)], 201);
    }

    public function update(Request $request, TenantContext $tenantContext, IntegrationConnectionService $service, AuditLogService $auditLogService, int $id): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $connection = IntegrationConnection::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        $payload = $request->validate([
            'type' => ['sometimes', Rule::in(['woocommerce', 'wordpress_rest', 'shopify', 'custom_api', 'csv', 'manual'])],
            'name' => ['sometimes', 'string', 'max:255'],
            'base_url' => ['nullable', 'string', 'max:2048'],
            'auth_type' => ['nullable', 'string', 'max:64'],
            'credentials' => ['nullable', 'array'],
            'config_json' => ['nullable', 'array'],
            'mapping_json' => ['nullable', 'array'],
            'sync_frequency' => ['nullable', 'string', 'max:64'],
        ]);

        $before = $connection->toArray();
        $updated = $service->update($connection, $payload);
        $auditLogService->logMutation(
            $request,
            'updated',
            $updated,
            $before,
            $updated->fresh()?->toArray(),
            ['changed_fields' => array_keys($payload)],
        );

        return response()->json(['data' => $this->serializeConnection($updated)]);
    }

    public function test(Request $request, TenantContext $tenantContext, SourceTestService $sourceTestService, int $id): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $connection = IntegrationConnection::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        $result = $sourceTestService->testConnection($connection);

        return response()->json(['data' => $result]);
    }

    public function sync(
        Request $request,
        TenantContext $tenantContext,
        SourceSyncDispatcher $sourceSyncDispatcher,
        SourceTestService $sourceTestService,
        int $id,
    ): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $payload = $request->validate([
            'mode' => ['nullable', Rule::in(['initial', 'delta'])],
            'validate_connection' => ['nullable', 'boolean'],
        ]);

        $connection = IntegrationConnection::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        $validateConnection = (bool) ($payload['validate_connection'] ?? false);
        $testResult = null;

        if ($validateConnection) {
            $testResult = $sourceTestService->testConnection($connection);

            if (! (bool) ($testResult['ok'] ?? false)) {
                $message = (string) ($testResult['message'] ?? 'Connection test failed.');

                return response()->json([
                    'message' => 'Sync nije pokrenut: '.$message,
                    'data' => [
                        'connection_ok' => false,
                        'connection_message' => $message,
                    ],
                ], 422);
            }
        }

        $mode = (string) ($payload['mode'] ?? 'delta');
        $job = $sourceSyncDispatcher->dispatch($tenant, $connection, $mode, $request->user()?->id);

        return response()->json([
            'message' => 'Sync started.',
            'data' => $this->serializeImportJob($job),
            'meta' => [
                'queue_connection' => (string) config('queue.default', 'sync'),
                'connection_validated' => $validateConnection,
                'connection_message' => is_array($testResult) ? ($testResult['message'] ?? null) : null,
            ],
        ]);
    }

    public function destroy(Request $request, TenantContext $tenantContext, AuditLogService $auditLogService, int $id): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $connection = IntegrationConnection::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        $before = $connection->toArray();
        $connection->delete();
        $auditLogService->logMutation(
            $request,
            'deleted',
            $connection,
            $before,
            null,
        );

        return response()->json([
            'message' => 'Integration deleted.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeConnection(IntegrationConnection $connection): array
    {
        return [
            'id' => (int) $connection->id,
            'tenant_id' => (int) $connection->tenant_id,
            'type' => (string) $connection->type,
            'name' => (string) $connection->name,
            'status' => (string) ($connection->status ?? 'draft'),
            'base_url' => $connection->base_url,
            'auth_type' => $connection->auth_type,
            'config_json' => $connection->config_json,
            'mapping_json' => $connection->mapping_json,
            'sync_frequency' => $connection->sync_frequency,
            'last_tested_at' => $connection->last_tested_at,
            'last_sync_at' => $connection->last_sync_at,
            'last_error' => $connection->last_error,
            'has_credentials' => is_string($connection->credentials_encrypted) && trim($connection->credentials_encrypted) !== '',
            'created_at' => $connection->created_at,
            'updated_at' => $connection->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeImportJob(ImportJob $job): array
    {
        return [
            'id' => (int) $job->id,
            'tenant_id' => (int) $job->tenant_id,
            'integration_connection_id' => $job->integration_connection_id !== null
                ? (int) $job->integration_connection_id
                : null,
            'job_type' => (string) $job->job_type,
            'source_type' => (string) $job->source_type,
            'status' => (string) $job->status,
            'total_records' => (int) ($job->total_records ?? 0),
            'processed_records' => (int) ($job->processed_records ?? 0),
            'success_records' => (int) ($job->success_records ?? 0),
            'failed_records' => (int) ($job->failed_records ?? 0),
            'skipped_records' => (int) ($job->skipped_records ?? 0),
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
            'triggered_by' => $job->triggered_by,
            'log_summary' => $job->log_summary,
            'created_at' => $job->created_at,
            'updated_at' => $job->updated_at,
        ];
    }
}
