<?php

namespace App\Services\Conversation;

use App\Mail\TenantUsageLimitAlertMail;
use App\Models\AiConfig;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Tenant;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TenantUsageLimitService
{
    /**
     * @return array<string, mixed>
     */
    public function evaluateBeforeResponse(Tenant $tenant, ?AiConfig $config = null): array
    {
        $snapshot = $this->snapshot($tenant, $config);
        $blocking = $this->blockingExceeded($snapshot);

        return $snapshot + [
            'blocked' => $blocking !== null,
            'blocking' => $blocking,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Tenant $tenant, ?AiConfig $config = null): array
    {
        $limits = $this->resolvedLimits($tenant, $config);
        $usage = $this->usageSnapshot($tenant);
        $exceeded = $this->exceededLimits($limits, $usage);

        return [
            'limits' => $limits,
            'usage' => $usage,
            'exceeded' => $exceeded,
        ];
    }

    /**
     * @param array<string, mixed> $limitEntry
     */
    public function blockedMessage(array $limitEntry): string
    {
        $label = (string) ($limitEntry['label'] ?? 'usage limit');
        $current = (int) ($limitEntry['current'] ?? 0);
        $limit = max(1, (int) ($limitEntry['limit'] ?? 1));

        return sprintf(
            'Dosegnut je limit (%s: %d/%d). Trenutno ne mogu nastaviti chat. Kontaktirajte podrsku.',
            $label,
            $current,
            $limit,
        );
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function sendAlertsForExceeded(Tenant $tenant, Conversation $conversation, array $snapshot): void
    {
        $limits = is_array($snapshot['limits'] ?? null) ? $snapshot['limits'] : [];
        if (! (bool) ($limits['alert_on_limit'] ?? true)) {
            return;
        }

        $exceeded = is_array($snapshot['exceeded'] ?? null) ? $snapshot['exceeded'] : [];
        if ($exceeded === []) {
            return;
        }

        $recipients = $this->alertRecipients($tenant);
        if ($recipients === []) {
            return;
        }

        foreach ($exceeded as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (! $this->shouldSendAlertNow($tenant, $entry)) {
                continue;
            }

            $payload = [
                'tenant_id' => $tenant->id,
                'tenant_name' => (string) ($tenant->name ?: 'Tenant'),
                'tenant_slug' => (string) ($tenant->slug ?: '-'),
                'conversation_id' => $conversation->id,
                'limit_type' => (string) ($entry['type'] ?? 'unknown'),
                'limit_label' => (string) ($entry['label'] ?? 'Limit'),
                'period_label' => (string) ($entry['period_label'] ?? '-'),
                'current' => (int) ($entry['current'] ?? 0),
                'limit' => (int) ($entry['limit'] ?? 0),
            ];

            foreach ($recipients as $recipient) {
                $this->queueWithFallback(
                    $recipient,
                    fn (): Mailable => new TenantUsageLimitAlertMail($payload),
                    [
                        'tenant_id' => $tenant->id,
                        'conversation_id' => $conversation->id,
                        'limit_type' => $payload['limit_type'],
                    ],
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $llmResponse
     * @return array{input_tokens: int|null, output_tokens: int|null, total_tokens: int|null}
     */
    public function usageFromLlmResponse(array $llmResponse): array
    {
        $usage = is_array($llmResponse['_usage'] ?? null) ? $llmResponse['_usage'] : [];

        $input = $this->intOrNull($usage['input_tokens'] ?? null);
        $output = $this->intOrNull($usage['output_tokens'] ?? null);
        $total = $this->intOrNull($usage['total_tokens'] ?? null);

        if ($total === null && ($input !== null || $output !== null)) {
            $total = ($input ?? 0) + ($output ?? 0);
        }

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvedLimits(Tenant $tenant, ?AiConfig $config = null): array
    {
        $tenant->loadMissing('plan');

        $configLimitMessages = $this->positiveIntOrNull($config?->max_messages_monthly);
        $planLimitMessages = $this->positiveIntOrNull($tenant->plan?->max_messages_monthly);

        return [
            'max_messages_monthly' => $configLimitMessages ?? $planLimitMessages,
            'max_messages_monthly_source' => $configLimitMessages !== null ? 'ai_config' : ($planLimitMessages !== null ? 'plan' : 'none'),
            'max_tokens_daily' => $this->positiveIntOrNull($config?->max_tokens_daily),
            'max_tokens_monthly' => $this->positiveIntOrNull($config?->max_tokens_monthly),
            'block_on_limit' => (bool) ($config?->block_on_limit ?? true),
            'alert_on_limit' => (bool) ($config?->alert_on_limit ?? true),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function usageSnapshot(Tenant $tenant): array
    {
        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $dayStart = $now->copy()->startOfDay();

        $messagesMonthly = (int) ConversationMessage::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'user')
            ->where('created_at', '>=', $monthStart)
            ->count();

        $tokensMonthly = (int) ConversationMessage::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'assistant')
            ->where('created_at', '>=', $monthStart)
            ->selectRaw('COALESCE(SUM(COALESCE(tokens_input, 0) + COALESCE(tokens_output, 0)), 0) as total_tokens')
            ->value('total_tokens');

        $tokensDaily = (int) ConversationMessage::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'assistant')
            ->where('created_at', '>=', $dayStart)
            ->selectRaw('COALESCE(SUM(COALESCE(tokens_input, 0) + COALESCE(tokens_output, 0)), 0) as total_tokens')
            ->value('total_tokens');

        $lastAssistant = ConversationMessage::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'assistant')
            ->latest('id')
            ->first(['metadata_json', 'created_at']);

        $lastResponseSource = null;
        $lastResponseAt = null;
        if ($lastAssistant instanceof ConversationMessage) {
            $lastResponseSource = data_get($lastAssistant->metadata_json, 'response_source');
            $lastResponseAt = $lastAssistant->created_at?->toIso8601String();
        }

        return [
            'messages_monthly' => $messagesMonthly,
            'tokens_daily' => $tokensDaily,
            'tokens_monthly' => $tokensMonthly,
            'last_response_source' => is_string($lastResponseSource) ? $lastResponseSource : null,
            'last_response_at' => $lastResponseAt,
        ];
    }

    /**
     * @param array<string, mixed> $limits
     * @param array<string, int> $usage
     * @return array<int, array<string, mixed>>
     */
    private function exceededLimits(array $limits, array $usage): array
    {
        $entries = [];

        $maxMessagesMonthly = $this->positiveIntOrNull($limits['max_messages_monthly'] ?? null);
        if ($maxMessagesMonthly !== null && ($usage['messages_monthly'] ?? 0) >= $maxMessagesMonthly) {
            $entries[] = [
                'type' => 'messages_monthly',
                'label' => 'mjesečni broj poruka',
                'period' => 'monthly',
                'period_label' => now()->format('Y-m'),
                'current' => (int) ($usage['messages_monthly'] ?? 0),
                'limit' => $maxMessagesMonthly,
            ];
        }

        $maxTokensDaily = $this->positiveIntOrNull($limits['max_tokens_daily'] ?? null);
        if ($maxTokensDaily !== null && ($usage['tokens_daily'] ?? 0) >= $maxTokensDaily) {
            $entries[] = [
                'type' => 'tokens_daily',
                'label' => 'dnevni token limit',
                'period' => 'daily',
                'period_label' => now()->format('Y-m-d'),
                'current' => (int) ($usage['tokens_daily'] ?? 0),
                'limit' => $maxTokensDaily,
            ];
        }

        $maxTokensMonthly = $this->positiveIntOrNull($limits['max_tokens_monthly'] ?? null);
        if ($maxTokensMonthly !== null && ($usage['tokens_monthly'] ?? 0) >= $maxTokensMonthly) {
            $entries[] = [
                'type' => 'tokens_monthly',
                'label' => 'mjesečni token limit',
                'period' => 'monthly',
                'period_label' => now()->format('Y-m'),
                'current' => (int) ($usage['tokens_monthly'] ?? 0),
                'limit' => $maxTokensMonthly,
            ];
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>|null
     */
    private function blockingExceeded(array $snapshot): ?array
    {
        $limits = is_array($snapshot['limits'] ?? null) ? $snapshot['limits'] : [];
        if (! (bool) ($limits['block_on_limit'] ?? true)) {
            return null;
        }

        $exceeded = is_array($snapshot['exceeded'] ?? null) ? $snapshot['exceeded'] : [];
        if ($exceeded === []) {
            return null;
        }

        $priority = ['messages_monthly', 'tokens_monthly', 'tokens_daily'];

        foreach ($priority as $type) {
            foreach ($exceeded as $entry) {
                if (is_array($entry) && (($entry['type'] ?? null) === $type)) {
                    return $entry;
                }
            }
        }

        return is_array($exceeded[0] ?? null) ? $exceeded[0] : null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function shouldSendAlertNow(Tenant $tenant, array $entry): bool
    {
        $type = (string) ($entry['type'] ?? 'unknown');
        $period = (string) ($entry['period'] ?? 'monthly');

        if ($period === 'daily') {
            $bucket = now()->format('Y-m-d');
            $expiresAt = now()->copy()->endOfDay()->addHour();
        } else {
            $bucket = now()->format('Y-m');
            $expiresAt = now()->copy()->endOfMonth()->addHour();
        }

        $key = sprintf('usage_limit_alert:%d:%s:%s', $tenant->id, $type, $bucket);
        if (Cache::get($key) === true) {
            return false;
        }

        Cache::put($key, true, $expiresAt);

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function alertRecipients(Tenant $tenant): array
    {
        $tenant->loadMissing('users');

        $emails = [];
        $support = $this->normalizedEmail((string) ($tenant->support_email ?? ''));
        if ($support !== null) {
            $emails[] = $support;
        }

        $members = $tenant->users()
            ->wherePivotIn('role', ['owner', 'admin'])
            ->select('users.email')
            ->get();

        foreach ($members as $member) {
            $email = $this->normalizedEmail((string) ($member->email ?? ''));
            if ($email !== null) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    private function normalizedEmail(string $email): ?string
    {
        $normalized = trim(mb_strtolower($email));
        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $normalized;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        $int = $this->intOrNull($value);
        if ($int === null || $int <= 0) {
            return null;
        }

        return $int;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param callable(): Mailable $mailableFactory
     * @param array<string, mixed> $context
     */
    private function queueWithFallback(string $recipientEmail, callable $mailableFactory, array $context = []): void
    {
        $queueError = null;

        try {
            Mail::to($recipientEmail)->queue($mailableFactory());

            return;
        } catch (\Throwable $exception) {
            $queueError = $exception;
            Log::warning('Usage limit alert queue dispatch failed, attempting sync fallback.', $context + [
                'email' => $recipientEmail,
                'queue_error' => $exception->getMessage(),
            ]);
        }

        try {
            Mail::to($recipientEmail)->send($mailableFactory());
        } catch (\Throwable $exception) {
            Log::error('Usage limit alert sync fallback failed.', $context + [
                'email' => $recipientEmail,
                'queue_error' => $queueError?->getMessage(),
                'send_error' => $exception->getMessage(),
            ]);
        }
    }
}
