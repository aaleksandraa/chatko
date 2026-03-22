<?php

namespace App\Services\Widget;

use App\Models\Conversation;
use App\Models\Widget;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class WidgetSecurityService
{
    /**
     * @return array<string, mixed>
     */
    public function validateOrigin(Request $request, Widget $widget): array
    {
        $rules = $this->normalizedAllowedDomains($widget);
        if ($rules === []) {
            return ['ok' => true, 'origin' => null];
        }

        $origin = $this->requestOrigin($request);
        if ($origin === null) {
            $allowMissing = (bool) config('services.widget.allow_missing_origin', false);
            if ($allowMissing || app()->environment(['local', 'testing'])) {
                return ['ok' => true, 'origin' => null];
            }

            return [
                'ok' => false,
                'reason' => 'missing_origin',
                'origin' => null,
            ];
        }

        foreach ($rules as $rule) {
            if ($this->originMatchesRule($origin, $rule)) {
                return ['ok' => true, 'origin' => $origin['canonical']];
            }
        }

        return [
            'ok' => false,
            'reason' => 'origin_not_allowed',
            'origin' => $origin['canonical'],
        ];
    }

    public function issueSessionToken(Widget $widget, Conversation $conversation): string
    {
        $now = now();
        $payload = [
            'v' => 1,
            'wid' => (int) $widget->id,
            'tid' => (int) $widget->tenant_id,
            'cid' => (int) $conversation->id,
            'sid' => (string) $conversation->session_id,
            'vid' => (string) $conversation->visitor_uuid,
            'iat' => $now->getTimestamp(),
            'exp' => $now->addSeconds($this->sessionTokenTtlSeconds())->getTimestamp(),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json) || $json === '') {
            $json = '{}';
        }

        $encodedPayload = $this->base64UrlEncode($json);
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $encodedPayload, $this->tokenSigningKey(), true));

        return $encodedPayload.'.'.$signature;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function validateSessionToken(Request $request, Widget $widget, array $payload): array
    {
        $token = trim((string) ($payload['widget_session_token'] ?? $request->headers->get('X-Widget-Session', '')));
        if ($token === '') {
            return [
                'ok' => false,
                'reason' => 'missing_widget_session_token',
            ];
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return [
                'ok' => false,
                'reason' => 'malformed_widget_session_token',
            ];
        }

        [$encodedPayload, $encodedSignature] = $parts;
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $encodedPayload, $this->tokenSigningKey(), true));
        if (! hash_equals($expected, $encodedSignature)) {
            return [
                'ok' => false,
                'reason' => 'invalid_widget_session_signature',
            ];
        }

        $decodedPayload = $this->base64UrlDecode($encodedPayload);
        if ($decodedPayload === null) {
            return [
                'ok' => false,
                'reason' => 'invalid_widget_session_payload',
            ];
        }

        $claims = json_decode($decodedPayload, true);
        if (! is_array($claims)) {
            return [
                'ok' => false,
                'reason' => 'invalid_widget_session_claims',
            ];
        }

        if ((int) ($claims['wid'] ?? 0) !== (int) $widget->id) {
            return [
                'ok' => false,
                'reason' => 'widget_session_widget_mismatch',
            ];
        }

        if ((int) ($claims['tid'] ?? 0) !== (int) $widget->tenant_id) {
            return [
                'ok' => false,
                'reason' => 'widget_session_tenant_mismatch',
            ];
        }

        $expiresAt = (int) ($claims['exp'] ?? 0);
        if ($expiresAt <= 0 || Carbon::createFromTimestamp($expiresAt)->isPast()) {
            return [
                'ok' => false,
                'reason' => 'expired_widget_session_token',
            ];
        }

        $providedConversationId = $this->intOrNull($payload['conversation_id'] ?? null);
        if ($providedConversationId !== null && $providedConversationId !== (int) ($claims['cid'] ?? 0)) {
            return [
                'ok' => false,
                'reason' => 'widget_session_conversation_mismatch',
            ];
        }

        $providedSessionId = trim((string) ($payload['session_id'] ?? ''));
        if ($providedSessionId !== '' && $providedSessionId !== (string) ($claims['sid'] ?? '')) {
            return [
                'ok' => false,
                'reason' => 'widget_session_id_mismatch',
            ];
        }

        $providedVisitorUuid = trim((string) ($payload['visitor_uuid'] ?? ''));
        if ($providedVisitorUuid !== '' && $providedVisitorUuid !== (string) ($claims['vid'] ?? '')) {
            return [
                'ok' => false,
                'reason' => 'widget_session_visitor_mismatch',
            ];
        }

        return [
            'ok' => true,
            'claims' => $claims,
            'token' => $token,
        ];
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function mergeSessionClaims(Request $request, array $claims): void
    {
        $merge = [];
        $conversationId = $this->intOrNull($request->input('conversation_id'));
        if ($conversationId === null && isset($claims['cid'])) {
            $merge['conversation_id'] = (int) $claims['cid'];
        }

        $sessionId = trim((string) $request->input('session_id', ''));
        if ($sessionId === '' && isset($claims['sid'])) {
            $merge['session_id'] = (string) $claims['sid'];
        }

        $visitorUuid = trim((string) $request->input('visitor_uuid', ''));
        if ($visitorUuid === '' && isset($claims['vid'])) {
            $merge['visitor_uuid'] = (string) $claims['vid'];
        }

        if ($merge !== []) {
            $request->merge($merge);
        }
    }

    /**
     * @return array<int, array{host: string, scheme: string|null, port: int|null, wildcard: bool}>
     */
    private function normalizedAllowedDomains(Widget $widget): array
    {
        $entries = is_array($widget->allowed_domains_json) ? $widget->allowed_domains_json : [];
        $normalized = [];

        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $rule = $this->normalizeDomainRule($entry);
            if ($rule !== null) {
                $normalized[] = $rule;
            }
        }

        return $normalized;
    }

    /**
     * @return array{canonical: string, host: string, scheme: string|null, port: int|null}|null
     */
    private function requestOrigin(Request $request): ?array
    {
        $origin = trim((string) $request->headers->get('Origin', ''));
        if ($origin !== '' && strtolower($origin) !== 'null') {
            $parsed = $this->parseOriginLikeValue($origin);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        $referer = trim((string) $request->headers->get('Referer', ''));
        if ($referer !== '') {
            $parsed = $this->parseOriginLikeValue($referer);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * @param array{host: string, scheme: string|null, port: int|null, wildcard: bool} $rule
     * @param array{canonical: string, host: string, scheme: string|null, port: int|null} $origin
     */
    private function originMatchesRule(array $origin, array $rule): bool
    {
        $originHost = $origin['host'];
        $ruleHost = $rule['host'];

        $hostMatches = $rule['wildcard']
            ? ($originHost === $ruleHost || str_ends_with($originHost, '.'.$ruleHost))
            : ($originHost === $ruleHost);

        if (! $hostMatches) {
            return false;
        }

        if ($rule['scheme'] !== null && $origin['scheme'] !== $rule['scheme']) {
            return false;
        }

        if ($rule['port'] !== null && $origin['port'] !== $rule['port']) {
            return false;
        }

        return true;
    }

    /**
     * @return array{host: string, scheme: string|null, port: int|null, wildcard: bool}|null
     */
    private function normalizeDomainRule(string $value): ?array
    {
        $text = trim($value);
        if ($text === '') {
            return null;
        }

        $hasScheme = str_contains($text, '://');
        $parsed = $this->parseOriginLikeValue($hasScheme ? $text : 'https://'.$text);

        if ($parsed === null) {
            return null;
        }

        $host = $parsed['host'];
        $wildcard = false;

        if (str_starts_with($host, '*.')) {
            $wildcard = true;
            $host = substr($host, 2);
        }

        if ($host === '') {
            return null;
        }

        return [
            'host' => $host,
            'scheme' => $hasScheme ? $parsed['scheme'] : null,
            'port' => $parsed['port'],
            'wildcard' => $wildcard,
        ];
    }

    /**
     * @return array{canonical: string, host: string, scheme: string|null, port: int|null}|null
     */
    private function parseOriginLikeValue(string $value): ?array
    {
        $parsed = parse_url(trim($value));
        if (! is_array($parsed)) {
            return null;
        }

        $host = strtolower(trim((string) ($parsed['host'] ?? '')));
        if ($host === '') {
            return null;
        }

        $scheme = strtolower(trim((string) ($parsed['scheme'] ?? '')));
        $scheme = $scheme !== '' ? $scheme : null;
        $port = isset($parsed['port']) ? (int) $parsed['port'] : null;

        $canonical = ($scheme !== null ? $scheme.'://' : '').$host;
        if ($port !== null) {
            $canonical .= ':'.$port;
        }

        return [
            'canonical' => $canonical,
            'host' => $host,
            'scheme' => $scheme,
            'port' => $port,
        ];
    }

    private function sessionTokenTtlSeconds(): int
    {
        return max(300, (int) config('services.widget.session_token_ttl_seconds', 86400));
    }

    private function tokenSigningKey(): string
    {
        $key = (string) config('app.key', '');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        if ($key !== '') {
            return $key;
        }

        return 'widget-signing-fallback-key';
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $normalized = strtr($value, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);

        return is_string($decoded) ? $decoded : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}

