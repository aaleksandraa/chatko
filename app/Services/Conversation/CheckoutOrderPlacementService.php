<?php

namespace App\Services\Conversation;

use App\Models\ConversationCheckout;
use App\Models\IntegrationConnection;
use App\Models\OrderAttributed;
use App\Services\Integrations\Exceptions\IntegrationAdapterException;
use App\Services\Integrations\OrderPlacementAdapterRegistry;
use Illuminate\Support\Arr;

class CheckoutOrderPlacementService
{
    public function __construct(
        private readonly OrderPlacementAdapterRegistry $orderAdapterRegistry,
        private readonly CheckoutOrderNotificationService $orderNotificationService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function place(ConversationCheckout $checkout): array
    {
        $connection = $this->resolveConnection($checkout);
        if (! $connection instanceof IntegrationConnection) {
            throw new IntegrationAdapterException('Nema aktivne Woo/Shopify/Custom API integracije za kreiranje narudzbe.');
        }

        $payload = $this->orderPayload($checkout);
        $result = $this->orderAdapterRegistry->resolve($connection)->createOrder($connection, $payload);

        $externalOrderId = (string) ($result['external_order_id'] ?? '');
        if ($externalOrderId === '') {
            throw new IntegrationAdapterException('Order adapter nije vratio external_order_id.');
        }

        $total = isset($result['total']) && is_numeric($result['total'])
            ? (float) $result['total']
            : (float) $checkout->estimated_total;

        $currency = (string) ($result['currency'] ?? $checkout->currency ?? 'BAM');
        $checkoutUrl = isset($result['checkout_url']) && is_string($result['checkout_url']) && trim($result['checkout_url']) !== ''
            ? $result['checkout_url']
            : null;

        $checkout->fill([
            'status' => 'placed',
            'external_order_id' => $externalOrderId,
            'external_checkout_url' => $checkoutUrl,
            'external_response_json' => Arr::except($result, ['raw']) + ['raw' => $result['raw'] ?? null],
            'submitted_at' => now(),
            'last_error' => null,
            'estimated_total' => $total,
            'currency' => $currency,
        ])->save();

        $conversation = $checkout->conversation;
        if ($conversation !== null && ! $conversation->converted) {
            $conversation->update(['converted' => true]);
        }

        OrderAttributed::query()->create([
            'tenant_id' => $checkout->tenant_id,
            'conversation_id' => $checkout->conversation_id,
            'external_order_id' => $externalOrderId,
            'order_value' => $total,
            'currency' => $currency,
            'attributed_model' => 'chat_checkout',
            'last_status' => 'placed',
            'last_status_at' => now(),
            'status_payload_json' => [
                'source' => 'checkout_order_placement',
                'status' => 'placed',
            ],
        ]);

        $orderSummary = [
            'external_order_id' => $externalOrderId,
            'status' => isset($result['status']) ? (string) $result['status'] : null,
            'checkout_url' => $checkoutUrl,
            'payment_required' => (bool) ($result['payment_required'] ?? false),
            'currency' => $currency,
            'total' => $total,
            'integration_type' => $connection->type,
        ];

        $this->orderNotificationService->sendOrderPlacedNotifications(
            $checkout,
            $connection,
            $orderSummary,
        );

        return $orderSummary;
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(ConversationCheckout $checkout): array
    {
        $firstName = trim((string) ($checkout->customer_first_name ?? ''));
        $lastName = trim((string) ($checkout->customer_last_name ?? ''));
        $fullName = trim($firstName.' '.$lastName);
        if ($fullName === '') {
            $fullName = trim((string) ($checkout->customer_name ?? ''));
        }

        return [
            'conversation_id' => $checkout->conversation_id,
            'payment_method' => strtolower((string) ($checkout->payment_method ?: 'cod')),
            'currency' => (string) ($checkout->currency ?: 'BAM'),
            'note' => $checkout->customer_note,
            'customer' => [
                'name' => $fullName,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $checkout->customer_email,
                'phone' => (string) ($checkout->customer_phone ?? ''),
                'address_line_1' => (string) ($checkout->delivery_address ?? ''),
                'city' => (string) ($checkout->delivery_city ?? ''),
                'postal_code' => $checkout->delivery_postal_code,
                'country' => strtoupper((string) ($checkout->delivery_country ?: 'BA')),
            ],
            'items' => is_array($checkout->items_json) ? $checkout->items_json : [],
        ];
    }

    private function resolveConnection(ConversationCheckout $checkout): ?IntegrationConnection
    {
        $supportedTypes = ['woocommerce', 'shopify', 'custom_api'];
        $items = is_array($checkout->items_json) ? $checkout->items_json : [];

        $candidateConnectionIds = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $connectionId = (int) ($item['source_connection_id'] ?? 0);
            if ($connectionId > 0) {
                $candidateConnectionIds[] = $connectionId;
            }
        }

        $candidateConnectionIds = array_values(array_unique($candidateConnectionIds));

        if ($candidateConnectionIds !== []) {
            $connection = IntegrationConnection::query()
                ->where('tenant_id', $checkout->tenant_id)
                ->whereIn('type', $supportedTypes)
                ->whereIn('id', $candidateConnectionIds)
                ->first();

            if ($connection !== null) {
                return $connection;
            }
        }

        return IntegrationConnection::query()
            ->where('tenant_id', $checkout->tenant_id)
            ->whereIn('type', $supportedTypes)
            ->orderByRaw("CASE WHEN status = 'connected' THEN 0 WHEN status = 'active' THEN 1 WHEN status = 'draft' THEN 2 ELSE 9 END")
            ->latest('id')
            ->first();
    }
}
