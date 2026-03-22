<?php

namespace App\Services\Integrations;

use App\Models\Conversation;
use App\Models\ConversationCheckout;
use App\Models\ConversationMessage;
use App\Models\IntegrationConnection;
use App\Models\OrderAttributed;
use App\Models\OrderStatusEvent;
use App\Services\Conversation\AnalyticsService;
use App\Services\Integrations\Exceptions\IntegrationAdapterException;
use Carbon\CarbonImmutable;

class OrderStatusWebhookService
{
    public function __construct(private readonly AnalyticsService $analyticsService)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function process(IntegrationConnection $connection, array $payload, string $rawBody, array $headers, ?string $requestToken = null): array
    {
        $this->assertAuthorized($connection, $rawBody, $headers, $requestToken);

        $externalOrderId = $this->extractExternalOrderId($connection, $payload);
        if ($externalOrderId === '') {
            throw new IntegrationAdapterException('Webhook payload does not contain external_order_id.');
        }

        $providerStatus = $this->extractProviderStatus($connection, $payload);
        $normalizedStatus = $this->normalizeStatus($connection, $providerStatus, $payload);
        $trackingUrl = $this->extractTrackingUrl($connection, $payload);
        $occurredAt = $this->extractOccurredAt($connection, $payload);

        $orderAttributed = OrderAttributed::query()
            ->where('tenant_id', $connection->tenant_id)
            ->where('external_order_id', $externalOrderId)
            ->latest('id')
            ->first();

        $conversation = null;
        if ($orderAttributed !== null) {
            $conversation = Conversation::query()
                ->where('tenant_id', $connection->tenant_id)
                ->find($orderAttributed->conversation_id);
        }

        if ($conversation === null) {
            $checkout = ConversationCheckout::query()
                ->where('tenant_id', $connection->tenant_id)
                ->where('external_order_id', $externalOrderId)
                ->latest('id')
                ->first();

            if ($checkout instanceof ConversationCheckout) {
                $conversation = Conversation::query()
                    ->where('tenant_id', $connection->tenant_id)
                    ->find($checkout->conversation_id);
            }
        }

        $messageText = $this->statusMessage($externalOrderId, $normalizedStatus, $providerStatus, $trackingUrl);

        $event = OrderStatusEvent::query()->create([
            'tenant_id' => $connection->tenant_id,
            'integration_connection_id' => $connection->id,
            'conversation_id' => $conversation?->id,
            'order_attributed_id' => $orderAttributed?->id,
            'external_order_id' => $externalOrderId,
            'provider_status' => $providerStatus,
            'normalized_status' => $normalizedStatus,
            'tracking_url' => $trackingUrl,
            'message_text' => $messageText,
            'payload_json' => $payload,
            'occurred_at' => $occurredAt?->toDateTimeString(),
        ]);

        if ($orderAttributed !== null) {
            $orderAttributed->fill([
                'last_status' => $normalizedStatus,
                'last_status_at' => $occurredAt?->toDateTimeString() ?? now()->toDateTimeString(),
                'status_payload_json' => $payload,
            ])->save();
        }

        if ($conversation instanceof Conversation) {
            ConversationMessage::query()->create([
                'tenant_id' => $conversation->tenant_id,
                'conversation_id' => $conversation->id,
                'role' => 'system',
                'message_text' => $messageText,
                'normalized_text' => mb_strtolower($messageText),
                'intent' => 'order_status_update',
                'metadata_json' => [
                    'external_order_id' => $externalOrderId,
                    'provider_status' => $providerStatus,
                    'normalized_status' => $normalizedStatus,
                    'tracking_url' => $trackingUrl,
                    'integration_connection_id' => $connection->id,
                    'order_status_event_id' => $event->id,
                ],
            ]);

            $checkout = ConversationCheckout::query()
                ->where('tenant_id', $conversation->tenant_id)
                ->where('conversation_id', $conversation->id)
                ->first();

            if ($checkout instanceof ConversationCheckout && $checkout->external_order_id === $externalOrderId) {
                $checkout->fill([
                    'status' => $normalizedStatus,
                    'last_error' => null,
                ])->save();
            }

            if (in_array($normalizedStatus, ['paid', 'shipped'], true)) {
                if (! $conversation->converted) {
                    $conversation->update(['converted' => true]);
                }
            } elseif ($normalizedStatus === 'cancelled') {
                $conversation->update([
                    'converted' => false,
                    'status' => 'cancelled',
                    'ended_at' => $conversation->ended_at ?? now(),
                ]);
            }

            $this->analyticsService->track($conversation, 'order_status_synced', null, [
                'external_order_id' => $externalOrderId,
                'provider_status' => $providerStatus,
                'normalized_status' => $normalizedStatus,
                'tracking_url' => $trackingUrl,
                'integration_connection_id' => $connection->id,
                'order_status_event_id' => $event->id,
            ]);
        }

        return [
            'external_order_id' => $externalOrderId,
            'provider_status' => $providerStatus,
            'normalized_status' => $normalizedStatus,
            'tracking_url' => $trackingUrl,
            'conversation_id' => $conversation?->id,
            'event_id' => $event->id,
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    private function assertAuthorized(IntegrationConnection $connection, string $rawBody, array $headers, ?string $requestToken): void
    {
        $configuredToken = trim((string) data_get($connection->config_json, 'order.webhook_token', ''));
        if ($configuredToken === '') {
            throw new IntegrationAdapterException('Webhook token is not configured for this integration.');
        }

        $headerToken = trim((string) ($headers['x-chatko-webhook-token'] ?? ''));
        $token = $requestToken !== null && trim($requestToken) !== '' ? trim($requestToken) : $headerToken;

        if (! hash_equals($configuredToken, $token)) {
            throw new IntegrationAdapterException('Webhook token is invalid.');
        }

        $signatureSecret = trim((string) data_get($connection->config_json, 'order.webhook_hmac_secret', ''));
        if ($signatureSecret === '') {
            return;
        }

        if ($connection->type === 'shopify') {
            $incoming = (string) ($headers['x-shopify-hmac-sha256'] ?? '');
            $expected = base64_encode(hash_hmac('sha256', $rawBody, $signatureSecret, true));
            if ($incoming === '' || ! hash_equals($expected, $incoming)) {
                throw new IntegrationAdapterException('Shopify webhook signature is invalid.');
            }

            return;
        }

        if ($connection->type === 'woocommerce') {
            $incoming = (string) ($headers['x-wc-webhook-signature'] ?? '');
            $expected = base64_encode(hash_hmac('sha256', $rawBody, $signatureSecret, true));
            if ($incoming === '' || ! hash_equals($expected, $incoming)) {
                throw new IntegrationAdapterException('WooCommerce webhook signature is invalid.');
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractExternalOrderId(IntegrationConnection $connection, array $payload): string
    {
        $path = (string) data_get($connection->config_json, 'order.webhook_order_id_path', '');
        if ($path !== '') {
            $id = data_get($payload, $path);

            return $this->scalarToString($id);
        }

        if ($connection->type === 'shopify') {
            return $this->scalarToString($payload['id'] ?? null);
        }

        if ($connection->type === 'woocommerce') {
            return $this->scalarToString($payload['id'] ?? null);
        }

        return $this->scalarToString($payload['external_order_id'] ?? $payload['order_id'] ?? $payload['id'] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractProviderStatus(IntegrationConnection $connection, array $payload): ?string
    {
        $path = (string) data_get($connection->config_json, 'order.webhook_status_path', '');
        if ($path !== '') {
            $value = data_get($payload, $path);

            return $this->scalarToNullableString($value);
        }

        if ($connection->type === 'shopify') {
            if (! empty($payload['cancelled_at'])) {
                return 'cancelled';
            }

            if (isset($payload['fulfillment_status']) && $payload['fulfillment_status'] !== null) {
                return (string) $payload['fulfillment_status'];
            }

            return $this->scalarToNullableString($payload['financial_status'] ?? null);
        }

        return $this->scalarToNullableString($payload['status'] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function normalizeStatus(IntegrationConnection $connection, ?string $providerStatus, array $payload): string
    {
        $status = mb_strtolower(trim((string) ($providerStatus ?? '')));

        if ($connection->type === 'shopify') {
            if (! empty($payload['cancelled_at'])) {
                return 'cancelled';
            }

            $financial = mb_strtolower(trim((string) ($payload['financial_status'] ?? '')));
            $fulfillment = mb_strtolower(trim((string) ($payload['fulfillment_status'] ?? '')));

            if (in_array($financial, ['voided', 'refunded'], true)) {
                return 'cancelled';
            }

            if ($fulfillment === 'fulfilled') {
                return 'shipped';
            }

            if (in_array($financial, ['paid', 'partially_paid'], true)) {
                return 'paid';
            }

            if (in_array($financial, ['pending', 'authorized'], true)) {
                return 'processing';
            }
        }

        if ($connection->type === 'woocommerce') {
            return match ($status) {
                'completed' => 'shipped',
                'processing' => 'paid',
                'cancelled', 'refunded', 'failed' => 'cancelled',
                'on-hold', 'pending' => 'processing',
                default => $this->normalizeCommonStatus($status),
            };
        }

        return $this->normalizeCommonStatus($status);
    }

    private function normalizeCommonStatus(string $status): string
    {
        if ($status === '') {
            return 'unknown';
        }

        if (in_array($status, ['paid', 'payment_received'], true)) {
            return 'paid';
        }

        if (in_array($status, ['shipped', 'fulfilled', 'delivered', 'completed'], true)) {
            return 'shipped';
        }

        if (in_array($status, ['cancelled', 'canceled', 'voided', 'failed', 'refunded'], true)) {
            return 'cancelled';
        }

        if (in_array($status, ['processing', 'pending', 'in_progress'], true)) {
            return 'processing';
        }

        return 'unknown';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractTrackingUrl(IntegrationConnection $connection, array $payload): ?string
    {
        $path = (string) data_get($connection->config_json, 'order.webhook_tracking_url_path', '');
        if ($path !== '') {
            $value = data_get($payload, $path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $fallbacks = [
            'tracking_url',
            'tracking.url',
            'fulfillments.0.tracking_url',
            'fulfillment.tracking_url',
        ];

        foreach ($fallbacks as $fallback) {
            $value = data_get($payload, $fallback);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractOccurredAt(IntegrationConnection $connection, array $payload): ?CarbonImmutable
    {
        $path = (string) data_get($connection->config_json, 'order.webhook_occurred_at_path', '');
        $candidate = $path !== '' ? data_get($payload, $path) : null;

        if ($candidate === null) {
            foreach (['occurred_at', 'updated_at', 'processed_at', 'date_modified', 'created_at'] as $fallback) {
                $candidate = data_get($payload, $fallback);
                if ($candidate !== null && $candidate !== '') {
                    break;
                }
            }
        }

        if ($candidate === null || $candidate === '') {
            return CarbonImmutable::now();
        }

        try {
            if (is_numeric($candidate)) {
                return CarbonImmutable::createFromTimestamp((int) $candidate);
            }

            return CarbonImmutable::parse((string) $candidate);
        } catch (\Throwable) {
            return CarbonImmutable::now();
        }
    }

    private function statusMessage(string $externalOrderId, string $normalizedStatus, ?string $providerStatus, ?string $trackingUrl): string
    {
        $base = match ($normalizedStatus) {
            'paid' => sprintf('Narudzba #%s je placena.', $externalOrderId),
            'shipped' => sprintf('Narudzba #%s je poslana.', $externalOrderId),
            'cancelled' => sprintf('Narudzba #%s je otkazana.', $externalOrderId),
            'processing' => sprintf('Narudzba #%s je u obradi.', $externalOrderId),
            default => sprintf(
                'Narudzba #%s ima novi status: %s.',
                $externalOrderId,
                $providerStatus !== null && trim($providerStatus) !== '' ? trim($providerStatus) : 'unknown',
            ),
        };

        if ($trackingUrl !== null) {
            return $base.' Link za pracenje: '.$trackingUrl;
        }

        return $base;
    }

    private function scalarToString(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    private function scalarToNullableString(mixed $value): ?string
    {
        $string = $this->scalarToString($value);

        return $string === '' ? null : $string;
    }
}
