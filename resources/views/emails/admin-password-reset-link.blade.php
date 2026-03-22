<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Password Reset</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.5; margin: 0; padding: 24px; background: #f5f7f8;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 640px; margin: 0 auto; background: #ffffff; border-radius: 10px; border: 1px solid #dbe3e7;">
        <tr>
            <td style="padding: 24px;">
                <h1 style="margin: 0 0 12px; font-size: 22px;">Reset password request</h1>
                <p style="margin: 0 0 12px;">Hi {{ $recipientName !== '' ? $recipientName : 'there' }},</p>
                <p style="margin: 0 0 12px;">
                    An administrator for tenant <strong>{{ $tenantName }}</strong> requested a password reset for your account.
                </p>
                <p style="margin: 0 0 18px;">
                    Click the button below to set a new password. The link expires in {{ $expiresMinutes }} minutes.
                </p>
                <p style="margin: 0 0 20px;">
                    <a href="{{ $resetUrl }}" style="display: inline-block; padding: 11px 16px; background: #0e9f6e; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: bold;">
                        Reset password
                    </a>
                </p>
                <p style="margin: 0 0 8px; color: #4b5563;">If button click does not work, open this URL manually:</p>
                <p style="margin: 0; word-break: break-all;"><a href="{{ $resetUrl }}">{{ $resetUrl }}</a></p>
            </td>
        </tr>
    </table>
</body>
</html>

