<?php

namespace App\Services\Conversation;

use App\Mail\CheckoutOrderCustomerMail;
use App\Mail\CheckoutOrderMerchantMail;
use App\Models\ConversationCheckout;
use App\Models\IntegrationConnection;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckoutOrderNotificationService
{
    /**
     * @param array<string, mixed> $orderSummary
     */
    public function sendOrderPlacedNotifications(
        ConversationCheckout $checkout,
        IntegrationConnection $connection,
        array $orderSummary,
    ): void {
        $checkout->loadMissing('tenant');
        $notificationData = $this->buildNotificationData($checkout, $connection, $orderSummary);

        $customerEmail = $this->normalizeEmail((string) data_get($notificationData, 'customer.email', ''));
        if ($customerEmail !== null) {
            $this->queueWithFallback(
                $customerEmail,
                fn (): Mailable => new CheckoutOrderCustomerMail($notificationData),
                [
                    'tenant_id' => $checkout->tenant_id,
                    'conversation_id' => $checkout->conversation_id,
                    'order_id' => $notificationData['order_id'] ?? null,
                    'recipient_type' => 'customer',
                ],
            );
        }

        $merchantEmail = $this->resolveMerchantEmail($checkout, $connection);
        if ($merchantEmail !== null) {
            $this->queueWithFallback(
                $merchantEmail,
                fn (): Mailable => new CheckoutOrderMerchantMail($notificationData),
                [
                    'tenant_id' => $checkout->tenant_id,
                    'conversation_id' => $checkout->conversation_id,
                    'order_id' => $notificationData['order_id'] ?? null,
                    'recipient_type' => 'merchant',
                ],
            );
        }
    }

    /**
     * @param array<string, mixed> $orderSummary
     * @return array<string, mixed>
     */
    private function buildNotificationData(
        ConversationCheckout $checkout,
        IntegrationConnection $connection,
        array $orderSummary,
    ): array {
        $tenantName = (string) ($checkout->tenant?->brand_name ?: $checkout->tenant?->name ?: 'Vasa trgovina');
        $orderId = (string) ($orderSummary['external_order_id'] ?? $checkout->external_order_id ?? '');
        $total = isset($orderSummary['total']) && is_numeric($orderSummary['total'])
            ? (float) $orderSummary['total']
            : (float) $checkout->estimated_total;
        $currency = (string) ($orderSummary['currency'] ?? $checkout->currency ?? 'BAM');
        $paymentMethod = strtolower((string) ($checkout->payment_method ?: 'cod'));
        $firstName = trim((string) ($checkout->customer_first_name ?? ''));
        $lastName = trim((string) ($checkout->customer_last_name ?? ''));
        $fullName = trim($firstName.' '.$lastName);
        if ($fullName === '') {
            $fullName = trim((string) ($checkout->customer_name ?? ''));
        }

        return [
            'tenant_name' => $tenantName,
            'conversation_id' => (int) $checkout->conversation_id,
            'order_id' => $orderId,
            'integration_type' => (string) $connection->type,
            'placed_at' => ($checkout->submitted_at ?? now())->toIso8601String(),
            'total' => $total,
            'currency' => $currency,
            'status' => isset($orderSummary['status']) ? (string) $orderSummary['status'] : null,
            'payment_method' => $paymentMethod,
            'payment_method_label' => $paymentMethod === 'cod' ? 'Placanje pouzecem' : 'Online placanje',
            'payment_required' => (bool) ($orderSummary['payment_required'] ?? false),
            'checkout_url' => isset($orderSummary['checkout_url']) && is_string($orderSummary['checkout_url']) && trim($orderSummary['checkout_url']) !== ''
                ? $orderSummary['checkout_url']
                : null,
            'customer' => [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'name' => $fullName,
                'email' => (string) ($checkout->customer_email ?? ''),
                'phone' => (string) ($checkout->customer_phone ?? ''),
                'delivery_address' => (string) ($checkout->delivery_address ?? ''),
                'delivery_city' => (string) ($checkout->delivery_city ?? ''),
                'delivery_postal_code' => (string) ($checkout->delivery_postal_code ?? ''),
                'delivery_country' => (string) ($checkout->delivery_country ?? ''),
                'note' => (string) ($checkout->customer_note ?? ''),
            ],
            'items' => $this->normalizedItems($checkout, $currency),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizedItems(ConversationCheckout $checkout, string $fallbackCurrency): array
    {
        $items = is_array($checkout->items_json) ? $checkout->items_json : [];
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $unitPrice = isset($item['unit_price']) && is_numeric($item['unit_price'])
                ? (float) $item['unit_price']
                : 0.0;
            $currency = isset($item['currency']) && is_string($item['currency']) && trim($item['currency']) !== ''
                ? strtoupper((string) $item['currency'])
                : $fallbackCurrency;

            $normalized[] = [
                'name' => (string) ($item['name'] ?? 'Proizvod'),
                'sku' => (string) ($item['sku'] ?? ''),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $quantity * $unitPrice,
                'currency' => $currency,
                'product_url' => isset($item['product_url']) && is_string($item['product_url']) && trim($item['product_url']) !== ''
                    ? $item['product_url']
                    : null,
            ];
        }

        return $normalized;
    }

    private function resolveMerchantEmail(ConversationCheckout $checkout, IntegrationConnection $connection): ?string
    {
        $candidates = [
            data_get($connection->config_json, 'order.notification_email'),
            data_get($connection->config_json, 'order.merchant_email'),
            data_get($connection->config_json, 'orders.notification_email'),
            data_get($connection->config_json, 'orders.merchant_email'),
            $checkout->tenant?->support_email,
        ];

        foreach ($candidates as $candidate) {
            $email = $this->normalizeEmail((string) $candidate);
            if ($email !== null) {
                return $email;
            }
        }

        $tenant = $checkout->tenant;
        if ($tenant !== null) {
            $member = $tenant->users()
                ->select('users.email')
                ->orderByRaw("CASE WHEN tenant_users.role = 'owner' THEN 0 WHEN tenant_users.role = 'admin' THEN 1 WHEN tenant_users.role = 'editor' THEN 2 WHEN tenant_users.role = 'support' THEN 3 ELSE 9 END")
                ->orderBy('users.id')
                ->first();

            if ($member !== null) {
                return $this->normalizeEmail((string) $member->email);
            }
        }

        return null;
    }

    private function normalizeEmail(string $email): ?string
    {
        $normalized = trim(mb_strtolower($email));
        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $normalized;
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
            Log::warning('Order email queue dispatch failed, attempting sync fallback.', $context + [
                'email' => $recipientEmail,
                'queue_error' => $exception->getMessage(),
            ]);
        }

        try {
            Mail::to($recipientEmail)->send($mailableFactory());
        } catch (\Throwable $exception) {
            Log::error('Order email sync fallback failed.', $context + [
                'email' => $recipientEmail,
                'queue_error' => $queueError?->getMessage(),
                'send_error' => $exception->getMessage(),
            ]);
        }
    }
}
