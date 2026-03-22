<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Usage Limit Alert</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.5; margin: 0; padding: 24px; background: #f5f7f8;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 680px; margin: 0 auto; background: #ffffff; border-radius: 10px; border: 1px solid #dbe3e7;">
        <tr>
            <td style="padding: 24px;">
                <h1 style="margin: 0 0 12px; font-size: 22px;">AI usage limit reached</h1>

                <p style="margin: 0 0 12px;">
                    Tenant <strong>{{ data_get($payload, 'tenant_name', 'Tenant') }}</strong>
                    (slug: <code>{{ data_get($payload, 'tenant_slug', '-') }}</code>)
                    reached configured usage threshold.
                </p>

                <p style="margin: 0 0 12px;">
                    <strong>Limit type:</strong> {{ data_get($payload, 'limit_label', '-') }}<br>
                    <strong>Current usage:</strong> {{ number_format((float) data_get($payload, 'current', 0), 0, '.', ',') }}<br>
                    <strong>Configured limit:</strong> {{ number_format((float) data_get($payload, 'limit', 0), 0, '.', ',') }}<br>
                    <strong>Period:</strong> {{ data_get($payload, 'period_label', '-') }}
                </p>

                <p style="margin: 0; color: #4b5563;">
                    If this is expected, adjust limits in Admin -> AI Config.
                    If not, keep blocking enabled and inspect traffic or abuse patterns.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>

