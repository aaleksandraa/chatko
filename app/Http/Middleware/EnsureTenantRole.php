<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantRole
{
    /**
     * @var array<string, int>
     */
    private array $rank = [
        'support' => 10,
        'editor' => 20,
        'admin' => 30,
        'owner' => 40,
    ];

    public function handle(Request $request, Closure $next, string $requiredRole = 'support'): Response
    {
        $user = $request->user();
        $tenant = $request->attributes->get('tenant');

        if (! $tenant instanceof Tenant || $user === null) {
            return new JsonResponse([
                'message' => 'Tenant context or authenticated user missing.',
            ], 403);
        }

        $tokenRole = $request->attributes->get('auth_role');
        $membership = $user->tenants()->where('tenant_id', $tenant->id)->first();
        $membershipRole = $membership?->pivot?->role;

        $role = is_string($tokenRole) && $tokenRole !== '' ? $tokenRole : (string) $membershipRole;

        if ($role === '' || ! isset($this->rank[$role])) {
            return new JsonResponse([
                'message' => 'No role assigned for this tenant.',
            ], 403);
        }

        $requiredRank = $this->rank[$requiredRole] ?? $this->rank['support'];
        $actualRank = $this->rank[$role];

        if ($actualRank < $requiredRank) {
            return new JsonResponse([
                'message' => sprintf('Insufficient role. Required: %s, current: %s.', $requiredRole, $role),
            ], 403);
        }

        $request->attributes->set('tenant_role', $role);

        return $next($request);
    }
}

