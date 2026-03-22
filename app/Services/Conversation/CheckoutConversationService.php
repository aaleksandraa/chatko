<?php

namespace App\Services\Conversation;

use App\Models\Conversation;
use App\Models\ConversationCheckout;
use App\Models\IntegrationConnection;
use App\Models\Product;
use App\Services\Integrations\Exceptions\IntegrationAdapterException;
use Illuminate\Support\Collection;

class CheckoutConversationService
{
    public function __construct(
        private readonly CheckoutOrderPlacementService $orderPlacementService,
    ) {
    }

    /**
     * @param Collection<int, Product> $products
     * @return array<string, mixed>|null
     */
    public function handleMessage(Conversation $conversation, string $message, string $intent, Collection $products): ?array
    {
        $checkout = $this->findCheckout($conversation);
        $triggered = $this->isCheckoutTriggered($message, $intent);

        if (! $checkout instanceof ConversationCheckout && ! $triggered) {
            return null;
        }

        if (! $checkout instanceof ConversationCheckout) {
            $checkout = $this->createCheckout($conversation);
        } elseif (in_array($checkout->status, ['placed', 'cancelled'], true) && $triggered) {
            $checkout = $this->resetCheckout($checkout);
        }

        $updates = $this->extractCustomerUpdates($message);
        $quantity = $this->extractQuantity($message);
        if ($this->shouldAutoAttachProductFromSearch($message, $products)) {
            $this->applyItemsFromProducts($checkout, $products, $quantity);
        }
        $this->applyUpdates($checkout, $updates);

        if ($this->isCancelRequest($message)) {
            $checkout->fill([
                'status' => 'cancelled',
                'last_error' => null,
            ])->save();

            return [
                'answer_text' => 'Razumijem, checkout je prekinut. Kad budete spremni, mogu odmah nastaviti od pocetka.',
                'checkout' => $this->publicCheckoutPayload($checkout),
            ];
        }

        $missingFields = $this->missingRequiredFields($checkout);

        if ($this->isConfirmRequest($message)) {
            if ($missingFields !== []) {
                $checkout->fill(['status' => 'collecting_customer'])->save();

                return [
                    'answer_text' => 'Prije potvrde treba mi jos: '.$this->missingFieldsLabel($missingFields).'.',
                    'checkout' => $this->publicCheckoutPayload($checkout),
                ];
            }

            return $this->attemptOrderPlacement($checkout);
        }

        if ($this->checkoutItems($checkout) === []) {
            return [
                'answer_text' => 'Da zavrsimo narudzbu, prvo mi potvrdi koji proizvod zelis (ime proizvoda ili link).',
                'checkout' => $this->publicCheckoutPayload($checkout),
            ];
        }

        if ($missingFields !== []) {
            $checkout->fill(['status' => 'collecting_customer'])->save();

            return [
                'answer_text' => 'Super, mogu voditi checkout. Posalji mi jos: '.$this->missingFieldsLabel($missingFields).'.',
                'checkout' => $this->publicCheckoutPayload($checkout),
            ];
        }

        $checkout->fill(['status' => 'awaiting_confirmation'])->save();

        return [
            'answer_text' => $this->checkoutSummaryText($checkout).' Ako je sve tacno, napisi "potvrdjujem narudzbu".',
            'checkout' => $this->publicCheckoutPayload($checkout),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function upsertCheckout(Conversation $conversation, array $payload): array
    {
        $checkout = $this->findCheckout($conversation) ?? $this->createCheckout($conversation);
        if (in_array($checkout->status, ['placed', 'cancelled'], true)) {
            $checkout = $this->resetCheckout($checkout);
        }

        $updates = [];
        foreach ([
            'customer_first_name',
            'customer_last_name',
            'customer_name',
            'customer_email',
            'customer_phone',
            'delivery_address',
            'delivery_city',
            'delivery_postal_code',
            'delivery_country',
            'customer_note',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $updates[$field] = $payload[$field];
            }
        }

        if (array_key_exists('payment_method', $payload)) {
            $updates['payment_method'] = $this->normalizePaymentMethod((string) $payload['payment_method']);
        }

        $this->applyUpdates($checkout, $updates);

        if (isset($payload['items']) && is_array($payload['items'])) {
            $this->applyItemsFromPayload($checkout, $payload['items']);
        }

        $missing = $this->missingRequiredFields($checkout);
        if ($missing !== []) {
            $checkout->fill(['status' => 'collecting_customer'])->save();
            return [
                'message' => 'Sacuvano. Za potvrdu narudzbe jos nedostaje: '.$this->missingFieldsLabel($missing).'.',
                'checkout' => $this->publicCheckoutPayload($checkout),
            ];
        }

        $checkout->fill(['status' => 'awaiting_confirmation'])->save();

        return [
            'message' => 'Podaci su sacuvani. Mozete potvrditi narudzbu.',
            'checkout' => $this->publicCheckoutPayload($checkout),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmCheckout(Conversation $conversation): array
    {
        $checkout = $this->findCheckout($conversation);

        if (! $checkout instanceof ConversationCheckout) {
            throw new IntegrationAdapterException('Checkout jos nije zapocet u ovoj konverzaciji.');
        }

        if ($checkout->status === 'placed') {
            return [
                'message' => 'Narudzba je vec potvrdena.',
                'order' => [
                    'external_order_id' => $checkout->external_order_id,
                    'checkout_url' => $checkout->external_checkout_url,
                ],
                'checkout' => $this->publicCheckoutPayload($checkout),
            ];
        }

        $missing = $this->missingRequiredFields($checkout);
        if ($missing !== []) {
            $checkout->fill(['status' => 'collecting_customer'])->save();

            throw new IntegrationAdapterException(
                'Nedostaju podaci za potvrdu narudzbe: '.$this->missingFieldsLabel($missing).'.',
            );
        }

        $result = $this->orderPlacementService->place($checkout);
        $checkout->refresh();

        $message = ((bool) ($result['payment_required'] ?? false))
            ? 'Narudzba je kreirana. Posaljem i link za online placanje.'
            : 'Narudzba je uspjesno kreirana (placanje pouzecem).';

        return [
            'message' => $message,
            'order' => $result,
            'checkout' => $this->publicCheckoutPayload($checkout),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicCheckoutPayload(ConversationCheckout $checkout): array
    {
        $missingFields = $this->missingRequiredFields($checkout);
        $items = $this->checkoutItems($checkout);

        return [
            'status' => $checkout->status,
            'items' => $items,
            'missing_fields' => $missingFields,
            'can_confirm' => $missingFields === [] && $items !== [] && $checkout->status !== 'placed',
            'customer' => [
                'first_name' => $this->effectiveCustomerFirstName($checkout),
                'last_name' => $this->effectiveCustomerLastName($checkout),
                'name' => $this->effectiveCustomerFullName($checkout),
                'email' => $checkout->customer_email,
                'phone' => $checkout->customer_phone,
                'delivery_address' => $checkout->delivery_address,
                'delivery_city' => $checkout->delivery_city,
                'delivery_postal_code' => $checkout->delivery_postal_code,
                'delivery_country' => $checkout->delivery_country ?: 'BA',
                'note' => $checkout->customer_note,
            ],
            'payment_method' => $checkout->payment_method ?: 'cod',
            'available_payment_methods' => $this->availablePaymentMethods($checkout),
            'estimated_total' => (float) $checkout->estimated_total,
            'currency' => $checkout->currency ?: 'BAM',
            'order' => [
                'external_order_id' => $checkout->external_order_id,
                'checkout_url' => $checkout->external_checkout_url,
                'submitted_at' => $checkout->submitted_at?->toIso8601String(),
            ],
            'last_error' => $checkout->last_error,
        ];
    }

    private function findCheckout(Conversation $conversation): ?ConversationCheckout
    {
        return ConversationCheckout::query()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->first();
    }

    private function createCheckout(Conversation $conversation): ConversationCheckout
    {
        return ConversationCheckout::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'status' => 'collecting_customer',
            'payment_method' => 'cod',
            'delivery_country' => 'BA',
            'currency' => 'BAM',
        ]);
    }

    private function resetCheckout(ConversationCheckout $checkout): ConversationCheckout
    {
        $checkout->fill([
            'status' => 'collecting_customer',
            'items_json' => [],
            'customer_first_name' => null,
            'customer_last_name' => null,
            'customer_name' => null,
            'customer_email' => null,
            'customer_phone' => null,
            'delivery_address' => null,
            'delivery_city' => null,
            'delivery_postal_code' => null,
            'delivery_country' => 'BA',
            'customer_note' => null,
            'payment_method' => 'cod',
            'estimated_total' => 0,
            'currency' => 'BAM',
            'external_order_id' => null,
            'external_checkout_url' => null,
            'external_response_json' => null,
            'submitted_at' => null,
            'last_error' => null,
        ])->save();

        return $checkout;
    }

    private function isCheckoutTriggered(string $message, string $intent): bool
    {
        if ($intent === 'checkout_ready') {
            return true;
        }

        return (bool) preg_match('/\b(kupi|kupim|naruci|naruči|checkout|korpa|placanje|plaćanje|pouzece|pouzeće)\b/iu', $message);
    }

    private function isConfirmRequest(string $message): bool
    {
        return (bool) preg_match('/\b(potvrd(jujem|ujem)?|confirm|zavrsi narudzbu|zavrsi kupovinu|potvrdi narudzbu)\b/iu', $message);
    }

    private function isCancelRequest(string $message): bool
    {
        return (bool) preg_match('/\b(otkazi|otkaži|prekini|odustani|cancel)\b/iu', $message);
    }

    /**
     * @param array<string, mixed> $updates
     */
    private function applyUpdates(ConversationCheckout $checkout, array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $fillable = [
            'customer_first_name',
            'customer_last_name',
            'customer_name',
            'customer_email',
            'customer_phone',
            'delivery_address',
            'delivery_city',
            'delivery_postal_code',
            'delivery_country',
            'customer_note',
            'payment_method',
        ];

        $changes = [];
        foreach ($fillable as $field) {
            if (! array_key_exists($field, $updates)) {
                continue;
            }

            $value = $updates[$field];
            if (is_string($value)) {
                $value = trim($value);
            }

            if ($field === 'payment_method' && $value !== null) {
                $value = $this->normalizePaymentMethod((string) $value);
            }

            if ($field === 'delivery_country' && is_string($value) && $value !== '') {
                $value = strtoupper(substr($value, 0, 2));
            }

            $changes[$field] = $value === '' ? null : $value;
        }

        if (
            array_key_exists('customer_name', $changes)
            && ! array_key_exists('customer_first_name', $changes)
            && ! array_key_exists('customer_last_name', $changes)
            && is_string($changes['customer_name'])
        ) {
            [$firstNameFromFull, $lastNameFromFull] = $this->splitName($changes['customer_name']);
            if ($firstNameFromFull !== '') {
                $changes['customer_first_name'] = $firstNameFromFull;
            }
            if ($lastNameFromFull !== '') {
                $changes['customer_last_name'] = $lastNameFromFull;
            }
        }

        if (
            array_key_exists('customer_first_name', $changes)
            || array_key_exists('customer_last_name', $changes)
        ) {
            $firstName = array_key_exists('customer_first_name', $changes)
                ? trim((string) ($changes['customer_first_name'] ?? ''))
                : $this->effectiveCustomerFirstName($checkout);
            $lastName = array_key_exists('customer_last_name', $changes)
                ? trim((string) ($changes['customer_last_name'] ?? ''))
                : $this->effectiveCustomerLastName($checkout);

            $fullName = trim($firstName.' '.$lastName);
            $changes['customer_name'] = $fullName !== '' ? $fullName : null;
        }

        if ($changes !== []) {
            $checkout->fill($changes)->save();
        }
    }

    /**
     * @param array<int, array<string, mixed>> $payloadItems
     */
    private function applyItemsFromPayload(ConversationCheckout $checkout, array $payloadItems): void
    {
        $productIds = [];
        foreach ($payloadItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId > 0) {
                $productIds[$productId] = max(1, (int) ($item['quantity'] ?? 1));
            }
        }

        if ($productIds === []) {
            return;
        }

        $products = Product::query()
            ->where('tenant_id', $checkout->tenant_id)
            ->whereIn('id', array_keys($productIds))
            ->get()
            ->keyBy('id');

        $items = [];
        $total = 0.0;
        $currency = $checkout->currency ?: 'BAM';

        foreach ($productIds as $productId => $quantity) {
            /** @var Product|null $product */
            $product = $products->get($productId);
            if (! $product instanceof Product) {
                continue;
            }

            $unitPrice = (float) ($product->sale_price !== null ? $product->sale_price : $product->price);
            $lineTotal = $unitPrice * $quantity;
            $total += $lineTotal;
            $currency = (string) ($product->currency ?: $currency);

            $items[] = [
                'product_id' => $product->id,
                'external_id' => $product->external_id,
                'sku' => $product->sku,
                'name' => $product->name,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'currency' => $currency,
                'source_connection_id' => $product->source_connection_id,
                'source_type' => $product->source_type,
                'product_url' => $product->product_url,
            ];
        }

        if ($items === []) {
            return;
        }

        $checkout->fill([
            'items_json' => $items,
            'estimated_total' => $total,
            'currency' => $currency,
        ])->save();
    }

    /**
     * @param Collection<int, Product> $products
     */
    private function applyItemsFromProducts(ConversationCheckout $checkout, Collection $products, ?int $quantity): void
    {
        $existing = $this->checkoutItems($checkout);
        $safeQuantity = max(1, $quantity ?? 1);

        if ($existing !== []) {
            if ($quantity !== null) {
                $existing[0]['quantity'] = $safeQuantity;
                $this->applyItemsFromPayload($checkout, [[
                    'product_id' => (int) ($existing[0]['product_id'] ?? 0),
                    'quantity' => $safeQuantity,
                ]]);
            }
            return;
        }

        /** @var Product|null $first */
        $first = $products->first();
        if (! $first instanceof Product) {
            return;
        }

        $this->applyItemsFromPayload($checkout, [[
            'product_id' => $first->id,
            'quantity' => $safeQuantity,
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractCustomerUpdates(string $message): array
    {
        $updates = [];
        $text = trim($message);

        if (preg_match('/([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})/iu', $text, $emailMatch) === 1) {
            $updates['customer_email'] = mb_strtolower((string) $emailMatch[1]);
        }

        if (preg_match('/(\+?\d[\d\s\-\/]{6,}\d)/u', $text, $phoneMatch) === 1) {
            $updates['customer_phone'] = preg_replace('/\s+/', '', (string) $phoneMatch[1]);
        }

        if (preg_match('/\b(placanje|plaćanje)\s*[:\-]?\s*(pouzece|pouzeće|kartica|online)\b/iu', $text, $paymentMatch) === 1) {
            $updates['payment_method'] = $this->normalizePaymentMethod((string) $paymentMatch[2]);
        } elseif (preg_match('/\b(pouzece|pouzeće|cash on delivery|cod)\b/iu', $text) === 1) {
            $updates['payment_method'] = 'cod';
        } elseif (preg_match('/\b(kartica|online|payment link|placanje link)\b/iu', $text) === 1) {
            $updates['payment_method'] = 'online';
        }

        if (preg_match('/\bime\s*(?:je|:)?\s*([\p{L}\'\-]{2,60})/iu', $text, $firstNameMatch) === 1) {
            $updates['customer_first_name'] = trim((string) $firstNameMatch[1], " \t\n\r\0\x0B,.");
        }

        if (preg_match('/\bprezime\s*(?:je|:)?\s*([\p{L}\'\-\s]{2,80})/iu', $text, $lastNameMatch) === 1) {
            $updates['customer_last_name'] = trim((string) $lastNameMatch[1], " \t\n\r\0\x0B,.");
        }

        if (
            ! isset($updates['customer_first_name'])
            && ! isset($updates['customer_last_name'])
            && preg_match('/\b(?:zovem se|ja sam)\s+([\p{L}\s\'\-]{3,80})/u', $text, $nameMatch) === 1
        ) {
            $fullName = trim((string) $nameMatch[1], " \t\n\r\0\x0B,.");
            [$first, $last] = $this->splitName($fullName);
            if ($first !== '') {
                $updates['customer_first_name'] = $first;
            }
            if ($last !== '') {
                $updates['customer_last_name'] = $last;
            }
            $updates['customer_name'] = $fullName;
        }

        if (preg_match('/\b(?:adresa|ulica)\s*(?:je|:)?\s*([^\n,]{4,120})/iu', $text, $addressMatch) === 1) {
            $updates['delivery_address'] = trim((string) $addressMatch[1]);
        }

        if (preg_match('/\b(?:grad|mjesto|opcina|općina)\s*(?:je|:)?\s*([\p{L}\s\-]{2,80})/u', $text, $cityMatch) === 1) {
            $updates['delivery_city'] = trim((string) $cityMatch[1], " \t\n\r\0\x0B,.");
        }

        if (preg_match('/\b(?:postanski|poštanski)?\s*(?:broj|zip)?\s*[:\-]?\s*(\d{4,6})\b/iu', $text, $postalMatch) === 1) {
            $updates['delivery_postal_code'] = (string) $postalMatch[1];
        }

        if (preg_match('/\b(?:drzava|država|country)\s*(?:je|:)?\s*([a-zA-Z]{2})\b/u', $text, $countryMatch) === 1) {
            $updates['delivery_country'] = strtoupper((string) $countryMatch[1]);
        }

        if (preg_match('/\b(?:napomena|note)\s*(?:je|:)\s*(.{3,200})/iu', $text, $noteMatch) === 1) {
            $updates['customer_note'] = trim((string) $noteMatch[1]);
        }

        return $updates;
    }

    private function extractQuantity(string $message): ?int
    {
        if (preg_match('/\b(\d{1,3})\s*(kom|komada|x)\b/iu', $message, $match) === 1) {
            return max(1, (int) $match[1]);
        }

        if (preg_match('/\b(?:kolicina|količina|qty)\s*[:=]?\s*(\d{1,3})\b/iu', $message, $match) === 1) {
            return max(1, (int) $match[1]);
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function missingRequiredFields(ConversationCheckout $checkout): array
    {
        $missing = [];

        if ($this->effectiveCustomerFirstName($checkout) === '') {
            $missing[] = 'customer_first_name';
        }

        if ($this->effectiveCustomerLastName($checkout) === '') {
            $missing[] = 'customer_last_name';
        }

        $email = trim((string) ($checkout->customer_email ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $missing[] = 'customer_email';
        }

        if (trim((string) ($checkout->customer_phone ?? '')) === '') {
            $missing[] = 'customer_phone';
        }

        if (! $this->hasStreetAndNumber((string) ($checkout->delivery_address ?? ''))) {
            $missing[] = 'delivery_address';
        }

        if (trim((string) ($checkout->delivery_city ?? '')) === '') {
            $missing[] = 'delivery_city';
        }

        if (trim((string) ($checkout->delivery_postal_code ?? '')) === '') {
            $missing[] = 'delivery_postal_code';
        }

        if (trim((string) ($checkout->payment_method ?? '')) === '') {
            $missing[] = 'payment_method';
        }

        if ($this->checkoutItems($checkout) === []) {
            $missing[] = 'items';
        }

        return array_values(array_unique($missing));
    }

    /**
     * @param array<int, string> $missingFields
     */
    private function missingFieldsLabel(array $missingFields): string
    {
        $labels = [];
        foreach ($missingFields as $field) {
            $labels[] = $this->fieldLabel($field);
        }

        return implode(', ', array_values(array_unique($labels)));
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'customer_first_name' => 'ime',
            'customer_last_name' => 'prezime',
            'customer_name' => 'ime i prezime',
            'customer_email' => 'email',
            'customer_phone' => 'telefon',
            'delivery_address' => 'adresa (ulica i broj)',
            'delivery_city' => 'mjesto',
            'delivery_postal_code' => 'postanski broj',
            'payment_method' => 'nacin placanja (pouzece ili online)',
            'items' => 'proizvod za narudzbu',
            default => $field,
        };
    }

    private function checkoutSummaryText(ConversationCheckout $checkout): string
    {
        $items = $this->checkoutItems($checkout);
        $parts = [];
        foreach ($items as $item) {
            $parts[] = sprintf(
                '%s x%s',
                (string) ($item['name'] ?? 'Proizvod'),
                (string) ($item['quantity'] ?? 1),
            );
        }

        $paymentLabel = strtolower((string) ($checkout->payment_method ?? 'cod')) === 'cod'
            ? 'placanje pouzecem'
            : 'online placanje';

        return sprintf(
            'Provjera narudzbe: %s | Dostava: %s, %s | Placanje: %s | Ukupno: %.2f %s.',
            implode('; ', $parts),
            (string) ($checkout->delivery_address ?? ''),
            (string) ($checkout->delivery_city ?? ''),
            $paymentLabel,
            (float) $checkout->estimated_total,
            (string) ($checkout->currency ?? 'BAM'),
        );
    }

    private function effectiveCustomerFirstName(ConversationCheckout $checkout): string
    {
        $firstName = trim((string) ($checkout->customer_first_name ?? ''));
        if ($firstName !== '') {
            return $firstName;
        }

        [$firstFromFull] = $this->splitName((string) ($checkout->customer_name ?? ''));

        return $firstFromFull;
    }

    private function effectiveCustomerLastName(ConversationCheckout $checkout): string
    {
        $lastName = trim((string) ($checkout->customer_last_name ?? ''));
        if ($lastName !== '') {
            return $lastName;
        }

        [, $lastFromFull] = $this->splitName((string) ($checkout->customer_name ?? ''));

        return $lastFromFull;
    }

    private function effectiveCustomerFullName(ConversationCheckout $checkout): string
    {
        $firstName = $this->effectiveCustomerFirstName($checkout);
        $lastName = $this->effectiveCustomerLastName($checkout);
        $fullName = trim($firstName.' '.$lastName);

        if ($fullName !== '') {
            return $fullName;
        }

        return trim((string) ($checkout->customer_name ?? ''));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
        if ($parts === []) {
            return ['', ''];
        }

        $first = (string) array_shift($parts);
        $last = implode(' ', $parts);

        return [$first, $last];
    }

    private function hasStreetAndNumber(string $address): bool
    {
        $trimmed = trim($address);
        if ($trimmed === '') {
            return false;
        }

        return preg_match('/\d+/', $trimmed) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function attemptOrderPlacement(ConversationCheckout $checkout): array
    {
        try {
            $result = $this->orderPlacementService->place($checkout);
            $checkout->refresh();

            if ((bool) ($result['payment_required'] ?? false)) {
                $answer = 'Narudzba je kreirana. Posaljem vam checkout link za online placanje.';
            } else {
                $answer = 'Narudzba je kreirana uspjesno uz placanje pouzecem. Dostava ide na unesenu adresu.';
            }

            return [
                'answer_text' => $answer,
                'checkout' => $this->publicCheckoutPayload($checkout),
                'order' => $result,
            ];
        } catch (IntegrationAdapterException $exception) {
            $checkout->fill([
                'status' => 'failed',
                'last_error' => $exception->getMessage(),
            ])->save();

            return [
                'answer_text' => 'Nisam uspio poslati narudzbu ka integraciji: '.$exception->getMessage(),
                'checkout' => $this->publicCheckoutPayload($checkout),
            ];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function checkoutItems(ConversationCheckout $checkout): array
    {
        $items = $checkout->items_json;

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    private function normalizePaymentMethod(string $value): string
    {
        $normalized = strtolower(trim($value));

        if (in_array($normalized, ['pouzece', 'pouzeće', 'cod', 'cash_on_delivery', 'cash on delivery'], true)) {
            return 'cod';
        }

        return 'online';
    }

    /**
     * @return array<int, string>
     */
    private function availablePaymentMethods(ConversationCheckout $checkout): array
    {
        $default = ['cod', 'online'];
        $items = $this->checkoutItems($checkout);

        $sourceConnectionId = null;
        foreach ($items as $item) {
            $candidate = (int) ($item['source_connection_id'] ?? 0);
            if ($candidate > 0) {
                $sourceConnectionId = $candidate;
                break;
            }
        }

        if ($sourceConnectionId === null) {
            return $default;
        }

        $connection = IntegrationConnection::query()
            ->where('tenant_id', $checkout->tenant_id)
            ->find($sourceConnectionId);

        if (! $connection instanceof IntegrationConnection) {
            return $default;
        }

        $methods = data_get($connection->config_json, 'order.payment_methods', []);
        if (! is_array($methods) || $methods === []) {
            return $default;
        }

        $normalized = [];
        foreach ($methods as $method) {
            if (! is_string($method) || trim($method) === '') {
                continue;
            }
            $normalized[] = $this->normalizePaymentMethod($method);
        }

        if ($normalized === []) {
            return $default;
        }

        $normalized = array_values(array_unique($normalized));

        if (! in_array('cod', $normalized, true)) {
            array_unshift($normalized, 'cod');
        }

        return $normalized;
    }

    /**
     * @param Collection<int, Product> $products
     */
    private function shouldAutoAttachProductFromSearch(string $message, Collection $products): bool
    {
        if ($products->isEmpty()) {
            return false;
        }

        $text = mb_strtolower(trim($message));
        if ($text === '') {
            return false;
        }

        if (preg_match('/https?:\/\//i', $text) === 1) {
            return true;
        }

        if (preg_match('/\b(sku|id)\s*[:#=]?\s*[a-z0-9\-]{2,}\b/iu', $text) === 1) {
            return true;
        }

        if ($products->count() > 1) {
            return false;
        }

        $checkoutOnly = preg_replace('/[^a-z0-9\s]+/iu', ' ', $text);
        if (! is_string($checkoutOnly)) {
            return false;
        }

        $tokens = preg_split('/\s+/', $checkoutOnly) ?: [];
        $genericWords = [
            'mogu', 'li', 'moze', 'mozemo', 'da',
            'zelim', 'hocu', 'hoću',
            'kupi', 'kupim', 'kupiti',
            'naruci', 'naruciti', 'naruči', 'naručiti',
            'narudzba', 'narudžba', 'narudzbu', 'narudžbu', 'porudzbina', 'porudžbina',
            'checkout', 'korpa', 'poruciti', 'poručiti',
        ];

        $semanticTokens = [];
        foreach ($tokens as $token) {
            $clean = trim($token);
            if ($clean === '' || mb_strlen($clean) < 3 || in_array($clean, $genericWords, true)) {
                continue;
            }
            $semanticTokens[] = $clean;
        }

        return count($semanticTokens) >= 2;
    }
}
