<!DOCTYPE html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nova chatbot narudzba</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.5; margin: 0; padding: 24px; background: #f5f7f8;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 760px; margin: 0 auto; background: #ffffff; border-radius: 10px; border: 1px solid #dbe3e7;">
        <tr>
            <td style="padding: 24px;">
                <h1 style="margin: 0 0 12px; font-size: 22px;">Nova chatbot narudzba</h1>
                <p style="margin: 0 0 12px;">
                    Zaprimljena je nova narudzba preko AI asistenta za tenant <strong>{{ data_get($data, 'tenant_name', 'Chatko Shop') }}</strong>.
                </p>

                <p style="margin: 0 0 14px;">
                    <strong>Order ID:</strong> #{{ data_get($data, 'order_id', '-') }}<br>
                    <strong>Conversation ID:</strong> {{ data_get($data, 'conversation_id', '-') }}<br>
                    <strong>Provider:</strong> {{ strtoupper((string) data_get($data, 'integration_type', '-')) }}<br>
                    <strong>Nacin placanja:</strong> {{ data_get($data, 'payment_method_label', '-') }}<br>
                    <strong>Ukupno:</strong> {{ number_format((float) data_get($data, 'total', 0), 2, '.', '') }} {{ data_get($data, 'currency', 'BAM') }}
                </p>

                <h2 style="margin: 16px 0 8px; font-size: 17px;">Podaci kupca</h2>
                <p style="margin: 0 0 14px;">
                    <strong>Ime:</strong> {{ data_get($data, 'customer.name', '-') }}<br>
                    <strong>Email:</strong> {{ data_get($data, 'customer.email', '-') }}<br>
                    <strong>Telefon:</strong> {{ data_get($data, 'customer.phone', '-') }}<br>
                    <strong>Adresa:</strong> {{ data_get($data, 'customer.delivery_address', '-') }},
                    {{ data_get($data, 'customer.delivery_city', '-') }}
                    {{ data_get($data, 'customer.delivery_postal_code', '') }}
                    {{ data_get($data, 'customer.delivery_country', '') }}<br>
                    @if ((string) data_get($data, 'customer.note', '') !== '')
                        <strong>Napomena:</strong> {{ data_get($data, 'customer.note') }}
                    @endif
                </p>

                @if ((string) data_get($data, 'checkout_url', '') !== '')
                    <p style="margin: 0 0 12px;">
                        <strong>Checkout/Payment URL:</strong><br>
                        <a href="{{ data_get($data, 'checkout_url') }}">{{ data_get($data, 'checkout_url') }}</a>
                    </p>
                @endif

                <h2 style="margin: 16px 0 8px; font-size: 17px;">Stavke narudzbe</h2>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse: collapse; border: 1px solid #e5e7eb;">
                    <thead>
                        <tr style="background: #f9fafb;">
                            <th align="left" style="padding: 8px; border-bottom: 1px solid #e5e7eb;">Proizvod</th>
                            <th align="right" style="padding: 8px; border-bottom: 1px solid #e5e7eb;">Kol.</th>
                            <th align="right" style="padding: 8px; border-bottom: 1px solid #e5e7eb;">Ukupno</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ((array) data_get($data, 'items', []) as $item)
                            <tr>
                                <td style="padding: 8px; border-bottom: 1px solid #f3f4f6;">
                                    {{ data_get($item, 'name', 'Proizvod') }}
                                    @if ((string) data_get($item, 'sku', '') !== '')
                                        <span style="color: #6b7280;">(SKU: {{ data_get($item, 'sku') }})</span>
                                    @endif
                                </td>
                                <td align="right" style="padding: 8px; border-bottom: 1px solid #f3f4f6;">{{ (int) data_get($item, 'quantity', 1) }}</td>
                                <td align="right" style="padding: 8px; border-bottom: 1px solid #f3f4f6;">
                                    {{ number_format((float) data_get($item, 'line_total', 0), 2, '.', '') }} {{ data_get($item, 'currency', data_get($data, 'currency', 'BAM')) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" style="padding: 8px;">Detalji stavki nisu dostupni.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

