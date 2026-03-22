<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Services\Auth\ApiTokenService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiTokenAuthenticated
{
    public function __construct(private readonly ApiTokenService $tokenService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = (string) $request->bearerToken();
        if ($bearer === '') {
            return new JsonResponse([
                'message' => 'Missing bearer token.',
            ], 401);
        }

        $token = $this->tokenService->findByPlainTextToken($bearer);

        if (! $token instanceof ApiToken) {
            return new JsonResponse([
                'message' => 'Invalid API token.',
            ], 401);
        }

        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            return new JsonResponse([
                'message' => 'API token expired.',
            ], 401);
        }

        $token->update([
            'last_used_at' => now(),
        ]);

        $request->attributes->set('api_token', $token);
        $request->attributes->set('auth_role', $token->role);
        $request->setUserResolver(static fn () => $token->user);

        return $next($request);
    }
}

