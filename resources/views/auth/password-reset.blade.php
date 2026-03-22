<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password | Chatko</title>
    <style>
        :root {
            color-scheme: light;
            font-family: "Segoe UI", Roboto, Arial, sans-serif;
        }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: linear-gradient(160deg, #edf7f4 0%, #f8fafc 50%, #eaf3fb 100%);
            color: #0f172a;
        }
        .card {
            width: min(92vw, 520px);
            background: #ffffff;
            border: 1px solid #d9e2ec;
            border-radius: 14px;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.12);
            padding: 24px;
        }
        h1 {
            margin: 0 0 12px;
            font-size: 1.45rem;
        }
        p {
            margin: 0 0 14px;
            color: #334155;
        }
        form {
            display: grid;
            gap: 12px;
        }
        label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            color: #1f2937;
        }
        input {
            width: 100%;
            box-sizing: border-box;
            min-height: 40px;
            margin-top: 6px;
            border: 1px solid #c5d1dd;
            border-radius: 10px;
            padding: 8px 10px;
            font-size: 0.95rem;
        }
        input[readonly] {
            background: #f8fafc;
            color: #475569;
        }
        button {
            min-height: 42px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 700;
            color: #ffffff;
            background: linear-gradient(120deg, #0e9f6e, #0f766e);
            margin-top: 6px;
        }
        .alert {
            margin-top: 12px;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 0.9rem;
            display: none;
        }
        .alert.ok {
            display: block;
            background: #e7f8ef;
            border: 1px solid #9ed7b7;
            color: #14532d;
        }
        .alert.error {
            display: block;
            background: #fff1f2;
            border: 1px solid #f5b5bd;
            color: #9f1239;
        }
        .back-link {
            display: inline-block;
            margin-top: 12px;
            color: #0f766e;
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>Set New Password</h1>
        <p>Use this form to set a new password for your Chatko admin account.</p>

        <form id="reset-form">
            <input type="hidden" id="token" value="{{ $token }}">

            <label>Email
                <input id="email" type="email" value="{{ $email }}" required readonly>
            </label>

            <label>Tenant slug
                <input id="tenant-slug" type="text" value="{{ $tenantSlug }}" readonly>
            </label>

            <label>New password
                <input id="password" type="password" minlength="8" required>
            </label>

            <label>Confirm password
                <input id="password-confirmation" type="password" minlength="8" required>
            </label>

            <button type="submit">Reset password</button>
        </form>

        <div id="alert" class="alert"></div>
        <a class="back-link" href="/">Back to login</a>
    </main>

    <script>
        const form = document.getElementById('reset-form');
        const alertBox = document.getElementById('alert');
        const emailInput = document.getElementById('email');
        const tokenInput = document.getElementById('token');
        const passwordInput = document.getElementById('password');
        const passwordConfirmationInput = document.getElementById('password-confirmation');

        function showAlert(message, type) {
            alertBox.className = 'alert ' + type;
            alertBox.textContent = message;
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            showAlert('', '');

            const payload = {
                email: emailInput.value.trim(),
                token: tokenInput.value.trim(),
                password: passwordInput.value,
                password_confirmation: passwordConfirmationInput.value,
            };

            try {
                const response = await fetch('/api/admin/auth/password/reset', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(payload),
                });

                const text = await response.text();
                const data = text ? JSON.parse(text) : {};

                if (!response.ok) {
                    throw new Error(data.message || 'Password reset failed.');
                }

                form.reset();
                showAlert(data.message || 'Password reset successful.', 'ok');
            } catch (error) {
                showAlert(error.message || 'Password reset failed.', 'error');
            }
        });
    </script>
</body>
</html>

