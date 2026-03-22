<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Services\TenantResolverService;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantContext
{
    public function __construct(
        private readonly TenantResolverService $tenantResolverService,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->tenantResolverService->resolveFromRequest($request);

        if ($tenant === null) {
            return new JsonResponse([
                'message' => 'Tenant context is required. Provide X-Tenant-Id or X-Tenant-Slug header.',
            ], 422);
        }

        $token = $request->attributes->get('api_token');
        if ($token instanceof ApiToken && $token->tenant_id !== null && (int) $token->tenant_id !== (int) $tenant->id) {
            return new JsonResponse([
                'message' => 'Token does not match tenant context. Use tenant switch or login again.',
            ], 403);
        }

        $this->tenantContext->setTenant($tenant);
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }
}
