<?php

namespace App\Services\Widget;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class WidgetChallengeService
{
    public function isEnabled(): bool
    {
        if (! (bool) config('services.widget.challenge.enabled', false)) {
            return false;
        }

        $provider = $this->provider();
        if (! in_array($provider, ['turnstile', 'hcaptcha'], true)) {
            return false;
        }

        return $this->siteKey() !== '' && $this->secretKey() !== '';
    }

    public function provider(): string
    {
        return strtolower(trim((string) config('services.widget.challenge.provider', 'turnstile')));
    }

    /**
     * @return array<string, mixed>
     */
    public function publicConfig(): array
    {
        if (! $this->isEnabled()) {
            return [
                'enabled' => false,
            ];
        }

        return [
            'enabled' => true,
            'provider' => $this->provider(),
            'site_key' => $this->siteKey(),
            'action' => $this->expectedAction(),
            'script_url' => $this->scriptUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function verifySessionStart(Request $request, string $token): array
    {
        if (! $this->isEnabled()) {
            return ['ok' => true, 'skipped' => true];
        }

        $normalizedToken = trim($token);
        if ($normalizedToken === '') {
            return [
                'ok' => false,
                'reason' => 'missing_challenge_token',
            ];
        }

        try {
            $response = Http::asForm()
                ->timeout($this->timeoutSeconds())
                ->post($this->verifyUrl(), [
                    'secret' => $this->secretKey(),
                    'response' => $normalizedToken,
                    'remoteip' => (string) $request->ip(),
                    'sitekey' => $this->siteKey(),
                ]);
        } catch (Throwable $throwable) {
            return [
                'ok' => false,
                'reason' => 'challenge_request_failed',
                'details' => $throwable->getMessage(),
            ];
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return [
                'ok' => false,
                'reason' => 'challenge_invalid_response',
            ];
        }

        if (! (bool) ($payload['success'] ?? false)) {
            return [
                'ok' => false,
                'reason' => 'challenge_verification_failed',
                'details' => $this->shortPayloadDetails($payload),
            ];
        }

        if ($this->provider() === 'turnstile') {
            $expectedAction = $this->expectedAction();
            $receivedAction = trim((string) ($payload['action'] ?? ''));

            if ($expectedAction !== '' && $receivedAction !== '' && $receivedAction !== $expectedAction) {
                return [
                    'ok' => false,
                    'reason' => 'challenge_action_mismatch',
                    'details' => [
                        'expected_action' => $expectedAction,
                        'received_action' => $receivedAction,
                    ],
                ];
            }
        }

        return ['ok' => true];
    }

    private function siteKey(): string
    {
        $provider = $this->provider();

        return trim((string) config("services.widget.challenge.{$provider}.site_key", ''));
    }

    private function secretKey(): string
    {
        $provider = $this->provider();

        return trim((string) config("services.widget.challenge.{$provider}.secret_key", ''));
    }

    private function verifyUrl(): string
    {
        $provider = $this->provider();
        $default = $provider === 'hcaptcha'
            ? 'https://hcaptcha.com/siteverify'
            : 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

        $configured = trim((string) config("services.widget.challenge.{$provider}.verify_url", $default));

        return $configured !== '' ? $configured : $default;
    }

    private function scriptUrl(): string
    {
        $provider = $this->provider();
        $default = $provider === 'hcaptcha'
            ? 'https://js.hcaptcha.com/1/api.js?render=explicit'
            : 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

        $configured = trim((string) config("services.widget.challenge.{$provider}.script_url", $default));

        return $configured !== '' ? $configured : $default;
    }

    private function expectedAction(): string
    {
        return trim((string) config('services.widget.challenge.action', 'widget_session_start'));
    }

    private function timeoutSeconds(): int
    {
        return max(2, (int) config('services.widget.challenge.timeout_seconds', 6));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function shortPayloadDetails(array $payload): array
    {
        return [
            'error_codes' => is_array($payload['error-codes'] ?? null) ? array_values($payload['error-codes']) : [],
            'hostname' => isset($payload['hostname']) ? (string) $payload['hostname'] : null,
            'action' => isset($payload['action']) ? (string) $payload['action'] : null,
        ];
    }
}

