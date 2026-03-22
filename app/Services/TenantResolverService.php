<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Widget;
use Illuminate\Http\Request;

class TenantResolverService
{
    public function resolveFromRequest(Request $request): ?Tenant
    {
        $tenantId = $request->header('X-Tenant-Id');
        $tenantSlug = $request->header('X-Tenant-Slug');

        if ($tenantId !== null && $tenantId !== '') {
            return Tenant::query()
                ->where('id', $tenantId)
                ->orWhere('uuid', $tenantId)
                ->first();
        }

        if ($tenantSlug !== null && $tenantSlug !== '') {
            return Tenant::query()->where('slug', $tenantSlug)->first();
        }

        return null;
    }

    public function resolveByWidgetPublicKey(string $publicKey): ?Tenant
    {
        $widget = Widget::query()
            ->where('public_key', $publicKey)
            ->where('is_active', true)
            ->first();

        return $widget?->tenant;
    }
}
