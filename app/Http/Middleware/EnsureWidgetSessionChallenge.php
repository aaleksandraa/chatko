<?php

namespace App\Http\Middleware;

use App\Models\Widget;
use App\Services\Widget\WidgetAbuseLogService;
use App\Services\Widget\WidgetChallengeService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWidgetSessionChallenge
{
    public function __construct(
        private readonly WidgetChallengeService $widgetChallengeService,
        private readonly WidgetAbuseLogService $widgetAbuseLogService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->widgetChallengeService->isEnabled()) {
            return $next($request);
        }

        $token = trim((string) $request->input('challenge_token', ''));
        $verification = $this->widgetChallengeService->verifySessionStart($request, $token);
        if (! (bool) ($verification['ok'] ?? false)) {
            $widget = $request->attributes->get('widget');
            $publicKey = trim((string) $request->input('public_key', ''));

            $this->widgetAbuseLogService->log(
                $request,
                (string) ($verification['reason'] ?? 'challenge_verification_failed'),
                $widget instanceof Widget ? $widget : null,
                $publicKey !== '' ? $publicKey : null,
                [
                    'provider' => $this->widgetChallengeService->provider(),
                    'details' => is_array($verification['details'] ?? null) ? $verification['details'] : ($verification['details'] ?? null),
                ],
            );

            return new JsonResponse([
                'message' => 'Challenge verification failed. Please try again.',
            ], 422);
        }

        return $next($request);
    }
}

