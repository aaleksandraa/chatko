<!DOCTYPE html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Potvrda narudzbe</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.5; margin: 0; padding: 24px; background: #f5f7f8;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 700px; margin: 0 auto; background: #ffffff; border-radius: 10px; border: 1px solid #dbe3e7;">
        <tr>
            <td style="padding: 24px;">
                <h1 style="margin: 0 0 12px; font-size: 22px;">Potvrda narudzbe</h1>
                <p style="margin: 0 0 10px;">
                    Zdravo {{ (string) data_get($data, 'customer.name', '') !== '' ? data_get($data, 'customer.name') : 'kupce' }},
                </p>
                <p style="margin: 0 0 14px;">
                    Uspjesno smo zaprimili vasu narudzbu kod trgovine <strong>{{ data_get($data, 'tenant_name', 'Chatko Shop') }}</strong>.
                </p>
                <p style="margin: 0 0 12px;">
                    <strong>Broj narudzbe:</strong> #{{ data_get($data, 'order_id', '-') }}<br>
                    <strong>Nacin placanja:</strong> {{ data_get($data, 'payment_method_label', '-') }}<br>
                    <strong>Ukupno:</strong> {{ number_format((float) data_get($data, 'total', 0), 2, '.', '') }} {{ data_get($data, 'currency', 'BAM') }}
                </p>

                @if ((bool) data_get($data, 'payment_required', false) && (string) data_get($data, 'checkout_url', '') !== '')
                    <p style="margin: 0 0 16px;">
                        Za zavrsetak online placanja koristite ovaj link:<br>
                        <a href="{{ data_get($data, 'checkout_url') }}">{{ data_get($data, 'checkout_url') }}</a>
                    </p>
                @endif

                <h2 style="margin: 16px 0 8px; font-size: 17px;">Stavke narudzbe</h2>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse: collapse; border: 1px solid #e5e7eb;">
                    <thead>
                        <tr style="background: #f9fafb;">
                            <th align="left" style="padding: 8px; border-bottom: 1px solid #e5e7eb;">Proizvod</th>
                            <th align="right" style="padding: 8px; border-bottom: 1px solid #e5e7eb;">Kol.</th>
                            <th align="right" style="padding: 8px; border-bottom: 1px solid #e5e7eb;">Cijena</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ((array) data_get($data, 'items', []) as $item)
                            <tr>
                                <td style="padding: 8px; border-bottom: 1px solid #f3f4f6;">{{ data_get($item, 'name', 'Proizvod') }}</td>
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

                <p style="margin: 16px 0 0; color: #4b5563;">
                    Hvala vam na povjerenju.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>

