<?php

use App\Services\Integrations\ScheduledIntegrationSyncService;
use App\Services\AI\OpenAIModelCatalogService;
use App\Models\AiConfig;
use App\Models\Tenant;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command(
    'queue:run-managed {--tries=3} {--sleep=1} {--timeout=120} {--queue=default}',
    function (): void {
        $commands = Artisan::all();
        $hasHorizon = array_key_exists('horizon', $commands);

        if ($hasHorizon) {
            $this->info('Detected Horizon command. Starting Horizon supervisor...');
            $this->call('horizon');

            return;
        }

        $tries = max(1, (int) $this->option('tries'));
        $sleep = max(0, (int) $this->option('sleep'));
        $timeout = max(10, (int) $this->option('timeout'));
        $queue = trim((string) $this->option('queue'));

        $this->warn('Horizon command not found. Falling back to queue:work.');
        $this->call('queue:work', [
            '--tries' => $tries,
            '--sleep' => $sleep,
            '--timeout' => $timeout,
            '--queue' => $queue !== '' ? $queue : 'default',
        ]);
    },
)->purpose('Run Horizon when available; otherwise run queue:work fallback.');

Artisan::command('integrations:sync-scheduled {--limit=100} {--dry-run}', function (ScheduledIntegrationSyncService $service): void {
    $limit = (int) $this->option('limit');
    $dryRun = (bool) $this->option('dry-run');

    $result = $service->runDueSyncs($limit, $dryRun);

    $mode = $dryRun ? 'DRY RUN' : 'EXECUTE';
    $this->info(sprintf(
        '[%s] checked=%d due=%d dispatched=%d would_dispatch=%d skipped_not_due=%d skipped_manual=%d skipped_busy=%d skipped_no_tenant=%d',
        $mode,
        (int) ($result['checked'] ?? 0),
        (int) ($result['due'] ?? 0),
        (int) ($result['dispatched'] ?? 0),
        (int) ($result['would_dispatch'] ?? 0),
        (int) ($result['skipped_not_due'] ?? 0),
        (int) ($result['skipped_manual'] ?? 0),
        (int) ($result['skipped_busy'] ?? 0),
        (int) ($result['skipped_no_tenant'] ?? 0),
    ));
})->purpose('Dispatch due integration sync jobs based on sync_frequency');

Artisan::command('integrations:freshness-report {--limit=100} {--json}', function (ScheduledIntegrationSyncService $service): void {
    $limit = (int) $this->option('limit');
    $asJson = (bool) $this->option('json');

    $result = $service->freshnessReport($limit);

    if ($asJson) {
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return;
    }

    $summary = (array) ($result['summary'] ?? []);
    $this->info(sprintf(
        'checked=%d manual=%d auto=%d overdue=%d never_synced=%d',
        (int) ($summary['checked'] ?? 0),
        (int) ($summary['manual'] ?? 0),
        (int) ($summary['auto'] ?? 0),
        (int) ($summary['overdue'] ?? 0),
        (int) ($summary['never_synced'] ?? 0),
    ));

    $rows = array_slice((array) ($result['rows'] ?? []), 0, 25);
    if ($rows === []) {
        $this->line('No integration rows.');

        return;
    }

    $tableRows = array_map(static function (array $row): array {
        return [
            'tenant' => (string) ($row['tenant_slug'] ?? '-'),
            'id' => (string) ($row['integration_connection_id'] ?? '-'),
            'type' => (string) ($row['type'] ?? '-'),
            'freq' => (string) ($row['sync_frequency'] ?? '-'),
            'last_sync' => (string) ($row['last_sync_at'] ?? '-'),
            'next_due' => (string) ($row['next_due_at'] ?? '-'),
            'overdue' => (bool) ($row['is_overdue'] ?? false) ? 'yes' : 'no',
            'overdue_sec' => (string) ($row['overdue_by_seconds'] ?? 0),
        ];
    }, $rows);

    $this->table(
        ['tenant', 'id', 'type', 'freq', 'last_sync', 'next_due', 'overdue', 'overdue_sec'],
        $tableRows,
    );
})->purpose('Report integration freshness and overdue sync cadence.');

Artisan::command('ai:normalize-models {--dry-run}', function (OpenAIModelCatalogService $catalog): void {
    $dryRun = (bool) $this->option('dry-run');
    $rows = AiConfig::query()->get();

    $updated = 0;

    foreach ($rows as $row) {
        $currentChat = trim((string) ($row->model_name ?? ''));
        $currentEmbedding = trim((string) ($row->embedding_model ?? ''));

        $normalizedChat = $catalog->normalizeChatModel($currentChat);
        $normalizedEmbedding = $catalog->normalizeEmbeddingModel($currentEmbedding);

        $needsUpdate = $normalizedChat !== $currentChat || $normalizedEmbedding !== $currentEmbedding;
        if (! $needsUpdate) {
            continue;
        }

        $updated++;

        if (! $dryRun) {
            $row->model_name = $normalizedChat;
            $row->embedding_model = $normalizedEmbedding;
            $row->save();
        }
    }

    $mode = $dryRun ? 'DRY RUN' : 'EXECUTE';
    $this->info(sprintf(
        '[%s] checked=%d updated=%d chat_allowed=%d embedding_allowed=%d',
        $mode,
        $rows->count(),
        $updated,
        count($catalog->allowedChatModels()),
        count($catalog->allowedEmbeddingModels()),
    ));
})->purpose('Normalize AI model names across all tenant AI configs to backend allowlist.');

Artisan::command(
    'ai:doctor {--tenant=} {--model=} {--embedding=} {--skip-http}',
    function (OpenAIModelCatalogService $catalog): void {
        $tenantOption = trim((string) $this->option('tenant'));
        $chatOverride = trim((string) $this->option('model'));
        $embeddingOverride = trim((string) $this->option('embedding'));
        $skipHttp = (bool) $this->option('skip-http');

        $tenant = null;
        if ($tenantOption !== '') {
            $tenant = Tenant::query()
                ->where('slug', $tenantOption)
                ->orWhere('id', is_numeric($tenantOption) ? (int) $tenantOption : 0)
                ->first();
        } else {
            $tenant = Tenant::query()->orderBy('id')->first();
        }

        $aiConfig = null;
        if ($tenant instanceof Tenant) {
            $aiConfig = AiConfig::query()->where('tenant_id', $tenant->id)->first();
        }

        $runtimeKey = trim((string) config('services.openai.api_key', ''));
        $runtimeKeyLoaded = $runtimeKey !== '';
        $runtimeKeyPrefix = $runtimeKeyLoaded ? substr($runtimeKey, 0, 7).'...' : '(missing)';

        $rawChatModel = $chatOverride !== ''
            ? $chatOverride
            : trim((string) ($aiConfig?->model_name ?? config('services.openai.default_model', 'gpt-5-mini')));
        $rawEmbeddingModel = $embeddingOverride !== ''
            ? $embeddingOverride
            : trim((string) ($aiConfig?->embedding_model ?? config('services.openai.embedding_model', 'text-embedding-3-small')));

        $chatModel = $catalog->normalizeChatModel($rawChatModel);
        $embeddingModel = $catalog->normalizeEmbeddingModel($rawEmbeddingModel);

        $rows = [
            ['app_env', app()->environment()],
            ['tenant', $tenant instanceof Tenant ? "{$tenant->id} ({$tenant->slug})" : '(none found)'],
            ['tenant_ai_model_raw', $rawChatModel !== '' ? $rawChatModel : '(empty)'],
            ['tenant_embedding_raw', $rawEmbeddingModel !== '' ? $rawEmbeddingModel : '(empty)'],
            ['resolved_chat_model', $chatModel],
            ['resolved_embedding_model', $embeddingModel],
            ['runtime_api_key_loaded', $runtimeKeyLoaded ? 'yes' : 'no'],
            ['runtime_api_key_prefix', $runtimeKeyPrefix],
            ['allowed_chat_models_count', (string) count($catalog->allowedChatModels())],
            ['allowed_embedding_models_count', (string) count($catalog->allowedEmbeddingModels())],
        ];

        $this->table(['check', 'value'], $rows);

        if ($skipHttp) {
            $this->warn('HTTP checks skipped (--skip-http).');
            return;
        }

        if (! $runtimeKeyLoaded) {
            $this->error('OPENAI_API_KEY is not loaded in runtime config.');
            return;
        }

        $responseStatus = null;
        $responseBody = null;
        $embeddingStatus = null;
        $embeddingBody = null;

        try {
            $response = Http::withToken($runtimeKey)
                ->acceptJson()
                ->timeout(25)
                ->post('https://api.openai.com/v1/responses', [
                    'model' => $chatModel,
                    'input' => 'ping',
                    'max_output_tokens' => 16,
                ]);

            $responseStatus = $response->status();
            $responseBody = substr((string) $response->body(), 0, 300);
        } catch (\Throwable $exception) {
            $responseStatus = 0;
            $responseBody = 'transport/runtime error: '.$exception->getMessage();
        }

        try {
            $embeddingResponse = Http::withToken($runtimeKey)
                ->acceptJson()
                ->timeout(25)
                ->post('https://api.openai.com/v1/embeddings', [
                    'model' => $embeddingModel,
                    'input' => 'ping',
                ]);

            $embeddingStatus = $embeddingResponse->status();
            $embeddingBody = substr((string) $embeddingResponse->body(), 0, 300);
        } catch (\Throwable $exception) {
            $embeddingStatus = 0;
            $embeddingBody = 'transport/runtime error: '.$exception->getMessage();
        }

        $this->table(
            ['api_check', 'status', 'snippet'],
            [
                ['responses', (string) $responseStatus, (string) $responseBody],
                ['embeddings', (string) $embeddingStatus, (string) $embeddingBody],
            ],
        );

        if ($responseStatus !== 200 || $embeddingStatus !== 200) {
            $this->error('At least one OpenAI API check failed. Inspect status/snippet above.');
            return;
        }

        $this->info('OpenAI runtime checks passed.');
    },
)->purpose('Diagnose runtime OpenAI key/model wiring and test responses/embeddings endpoints.');

Schedule::command('integrations:sync-scheduled --limit=200')
    ->everyMinute()
    ->withoutOverlapping();
