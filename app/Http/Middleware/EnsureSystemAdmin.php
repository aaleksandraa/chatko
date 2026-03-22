<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSystemAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return new JsonResponse([
                'message' => 'Unauthorized.',
            ], 401);
        }

        if (! (bool) $user->is_system_admin) {
            return new JsonResponse([
                'message' => 'Only system admin can perform this action.',
            ], 403);
        }

        return $next($request);
    }
}
