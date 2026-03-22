<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Http\Request;

trait ResolvesTenant
{
    protected function tenantFromRequest(Request $request, TenantContext $tenantContext): Tenant
    {
        $tenant = $request->attributes->get('tenant');

        if ($tenant instanceof Tenant) {
            return $tenant;
        }

        $fallback = $tenantContext->tenant();
        if ($fallback instanceof Tenant) {
            return $fallback;
        }

        abort(422, 'Tenant context not found.');
    }
}
