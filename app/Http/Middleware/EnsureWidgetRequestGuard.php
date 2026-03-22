<?php

namespace App\Http\Middleware;

use App\Models\Widget;
use App\Services\Widget\WidgetAbuseLogService;
use App\Services\Widget\WidgetSecurityService;
use App\Services\Widget\WidgetService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWidgetRequestGuard
{
    public function __construct(
        private readonly WidgetService $widgetService,
        private readonly WidgetSecurityService $widgetSecurityService,
        private readonly WidgetAbuseLogService $widgetAbuseLogService,
    ) {
    }

    public function handle(Request $request, Closure $next, string $sessionMode = 'none'): Response
    {
        $publicKey = $this->resolvePublicKey($request);
        if ($publicKey === '') {
            $this->widgetAbuseLogService->log(
                $request,
                'missing_widget_public_key',
                null,
                null,
            );

            return new JsonResponse(['message' => 'Invalid widget key.'], 422);
        }

        $widget = $this->widgetService->resolveByPublicKey($publicKey);
        if (! $widget instanceof Widget) {
            $this->widgetAbuseLogService->log(
                $request,
                'invalid_widget_key',
                null,
                $publicKey,
            );

            if ($this->isWidgetConfigRoute($request)) {
                return new JsonResponse(['message' => 'Widget not found.'], 404);
            }

            return new JsonResponse(['message' => 'Invalid widget key.'], 422);
        }

        $request->attributes->set('widget', $widget);

        $originCheck = $this->widgetSecurityService->validateOrigin($request, $widget);
        if (! (bool) ($originCheck['ok'] ?? false)) {
            $this->widgetAbuseLogService->log(
                $request,
                (string) ($originCheck['reason'] ?? 'origin_not_allowed'),
                $widget,
                $publicKey,
                ['origin' => $originCheck['origin'] ?? null],
            );

            return new JsonResponse(['message' => 'Widget origin is not allowed.'], 403);
        }

        if ($sessionMode === 'required' || ($sessionMode === 'optional' && $this->hasSessionToken($request))) {
            $payload = $request->all();
            $tokenCheck = $this->widgetSecurityService->validateSessionToken($request, $widget, is_array($payload) ? $payload : []);

            if (! (bool) ($tokenCheck['ok'] ?? false)) {
                $this->widgetAbuseLogService->log(
                    $request,
                    (string) ($tokenCheck['reason'] ?? 'invalid_widget_session_token'),
                    $widget,
                    $publicKey,
                );

                return new JsonResponse(['message' => 'Invalid widget session token.'], 401);
            }

            $claims = is_array($tokenCheck['claims'] ?? null) ? $tokenCheck['claims'] : [];
            $this->widgetSecurityService->mergeSessionClaims($request, $claims);
            $request->attributes->set('widget_session_claims', $claims);
            $request->attributes->set('widget_session_token', (string) ($tokenCheck['token'] ?? ''));
        }

        return $next($request);
    }

    private function resolvePublicKey(Request $request): string
    {
        $fromInput = trim((string) $request->input('public_key', ''));
        if ($fromInput !== '') {
            return $fromInput;
        }

        $fromRoute = trim((string) ($request->route('publicKey') ?? ''));
        if ($fromRoute !== '') {
            $request->merge(['public_key' => $fromRoute]);
        }

        return $fromRoute;
    }

    private function hasSessionToken(Request $request): bool
    {
        $fromPayload = trim((string) $request->input('widget_session_token', ''));
        if ($fromPayload !== '') {
            return true;
        }

        $fromHeader = trim((string) $request->headers->get('X-Widget-Session', ''));

        return $fromHeader !== '';
    }

    private function isWidgetConfigRoute(Request $request): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }

        $path = trim((string) $request->path());

        return str_starts_with($path, 'api/widget/config/');
    }
}
