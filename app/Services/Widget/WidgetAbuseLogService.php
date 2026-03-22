<?php

namespace App\Services\Widget;

use App\Models\Widget;
use App\Models\WidgetAbuseLog;
use Illuminate\Http\Request;
use Throwable;

class WidgetAbuseLogService
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function log(
        Request $request,
        string $reason,
        ?Widget $widget = null,
        ?string $publicKey = null,
        array $metadata = [],
    ): void {
        try {
            $routePath = trim((string) $request->path());
            if ($routePath === '') {
                $routePath = '/';
            } elseif (! str_starts_with($routePath, '/')) {
                $routePath = '/'.$routePath;
            }

            WidgetAbuseLog::query()->create([
                'tenant_id' => $widget?->tenant_id,
                'widget_id' => $widget?->id,
                'public_key' => $publicKey ?? $widget?->public_key,
                'route' => $routePath,
                'http_method' => strtoupper((string) $request->method()),
                'reason' => $reason,
                'ip_address' => $request->ip(),
                'origin' => $this->normalizeHeader($request->headers->get('Origin')),
                'referer' => $this->normalizeHeader($request->headers->get('Referer')),
                'user_agent' => $this->normalizeHeader($request->userAgent()),
                'metadata_json' => $metadata !== [] ? $metadata : null,
            ]);
        } catch (Throwable) {
            // Never block request flow because of abuse logging failures.
        }
    }

    private function normalizeHeader(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }
}

