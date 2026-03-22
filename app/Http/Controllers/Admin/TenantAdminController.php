<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Auth\ApiTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantAdminController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const MANAGE_ROLES = ['owner', 'admin'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $token = $request->attributes->get('api_token');
        $currentTenantId = $token instanceof ApiToken ? (int) ($token->tenant_id ?? 0) : 0;

        $tenants = $user->tenants()
            ->select([
                'tenants.id',
                'tenants.uuid',
                'tenants.name',
                'tenants.slug',
                'tenants.domain',
                'tenants.status',
                'tenants.locale',
                'tenants.timezone',
                'tenants.brand_name',
                'tenants.support_email',
                'tenants.created_at',
                'tenants.updated_at',
            ])
            ->orderBy('tenants.name')
            ->get();

        return response()->json([
            'data' => $tenants
                ->map(fn (Tenant $tenant): array => $this->serializeMembershipTenant($tenant, $currentTenantId))
                ->values(),
        ]);
    }

    public function switch(Request $request, ApiTokenService $tokenService): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $payload = $request->validate([
            'tenant_id' => ['required_without:tenant_slug', 'integer', 'min:1'],
            'tenant_slug' => ['required_without:tenant_id', 'string', 'max:255'],
            'token_name' => ['nullable', 'string', 'max:255'],
            'ttl_minutes' => ['nullable', 'integer', 'min:15', 'max:10080'],
        ]);

        $query = $user->tenants()->withPivot('role');
        if (! empty($payload['tenant_id'])) {
            $query->where('tenants.id', (int) $payload['tenant_id']);
        } else {
            $query->where('tenants.slug', (string) $payload['tenant_slug']);
        }

        $tenant = $query->first();
        if (! $tenant instanceof Tenant) {
            return response()->json(['message' => 'User is not a member of requested tenant.'], 403);
        }

        $role = (string) ($tenant->pivot?->role ?? '');
        if ($role === '') {
            return response()->json(['message' => 'No role assigned for this tenant.'], 403);
        }

        $expiresAt = null;
        if (! empty($payload['ttl_minutes'])) {
            $expiresAt = CarbonImmutable::now()->addMinutes((int) $payload['ttl_minutes']);
        }

        $issued = $tokenService->issueToken(
            $user,
            $tenant,
            $role,
            $tokenService->abilitiesForRole($role),
            (string) ($payload['token_name'] ?? 'tenant_switch_token'),
            $expiresAt,
        );

        $currentToken = $request->attributes->get('api_token');
        if ($currentToken instanceof ApiToken) {
            $currentToken->delete();
        }

        return response()->json([
            'data' => [
                'token' => $issued['plain_text_token'],
                'token_type' => 'Bearer',
                'expires_at' => $issued['token']->expires_at,
                'role' => $role,
                'tenant' => [
                    'id' => $tenant->id,
                    'slug' => $tenant->slug,
                    'name' => $tenant->name,
                ],
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_system_admin' => (bool) $user->is_system_admin,
                ],
            ],
        ]);
    }

    public function update(
        Request $request,
        AuditLogService $auditLogService,
        int $id,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $tenant = $user->tenants()->withPivot('role')->where('tenants.id', $id)->first();
        if (! $tenant instanceof Tenant) {
            return response()->json(['message' => 'Tenant membership not found.'], 404);
        }

        $membershipRole = (string) ($tenant->pivot?->role ?? '');
        if (! in_array($membershipRole, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Only owner/admin can edit tenant settings.'], 403);
        }

        $payload = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/', Rule::unique('tenants', 'slug')->ignore($tenant->id)],
            'domain' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'max:64'],
            'locale' => ['sometimes', 'string', 'max:16'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'brand_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'support_email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ]);

        $before = $this->snapshotTenant($tenant);
        $tenant->fill($payload)->save();
        $tenant->refresh();
        $after = $this->snapshotTenant($tenant);

        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('auth_role', $membershipRole);
        $auditLogService->logMutation(
            $request,
            'updated',
            $tenant,
            $before,
            $after,
            [
                'entity' => 'tenant',
                'membership_role' => $membershipRole,
                'changed_fields' => array_keys($payload),
            ],
        );

        $currentToken = $request->attributes->get('api_token');
        $currentTenantId = $currentToken instanceof ApiToken ? (int) ($currentToken->tenant_id ?? 0) : 0;

        return response()->json([
            'data' => $this->serializeMembershipTenant($tenant, $currentTenantId),
        ]);
    }

    public function destroy(
        Request $request,
        AuditLogService $auditLogService,
        int $id,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $tenant = $user->tenants()->withPivot('role')->where('tenants.id', $id)->first();
        if (! $tenant instanceof Tenant) {
            return response()->json(['message' => 'Tenant membership not found.'], 404);
        }

        $membershipRole = (string) ($tenant->pivot?->role ?? '');
        if ($membershipRole !== 'owner') {
            return response()->json(['message' => 'Only owner can delete tenant.'], 403);
        }

        $currentToken = $request->attributes->get('api_token');
        $currentTenantDeleted = $currentToken instanceof ApiToken
            && (int) $currentToken->tenant_id === (int) $tenant->id;

        $before = $this->snapshotTenant($tenant);
        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('auth_role', $membershipRole);
        $auditLogService->logMutation(
            $request,
            'deleted',
            $tenant,
            $before,
            null,
            [
                'entity' => 'tenant',
                'membership_role' => $membershipRole,
            ],
        );

        $tenant->delete();

        $remainingTenants = Tenant::query()
            ->whereHas('users', fn ($query) => $query->where('users.id', $user->id))
            ->count();

        return response()->json([
            'message' => 'Tenant deleted.',
            'meta' => [
                'current_tenant_deleted' => $currentTenantDeleted,
                'remaining_tenants' => $remainingTenants,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMembershipTenant(Tenant $tenant, int $currentTenantId): array
    {
        $role = (string) ($tenant->pivot?->role ?? '');

        return [
            'id' => (int) $tenant->id,
            'uuid' => (string) $tenant->uuid,
            'name' => (string) $tenant->name,
            'slug' => (string) $tenant->slug,
            'domain' => $tenant->domain === null ? null : (string) $tenant->domain,
            'status' => (string) ($tenant->status ?? 'active'),
            'locale' => (string) ($tenant->locale ?? 'bs'),
            'timezone' => (string) ($tenant->timezone ?? 'Europe/Sarajevo'),
            'brand_name' => $tenant->brand_name === null ? null : (string) $tenant->brand_name,
            'support_email' => $tenant->support_email === null ? null : (string) $tenant->support_email,
            'role' => $role,
            'can_manage' => in_array($role, self::MANAGE_ROLES, true),
            'can_delete' => $role === 'owner',
            'is_current' => $currentTenantId > 0 && (int) $tenant->id === $currentTenantId,
            'created_at' => $tenant->created_at,
            'updated_at' => $tenant->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotTenant(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'uuid' => $tenant->uuid,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'domain' => $tenant->domain,
            'status' => $tenant->status,
            'locale' => $tenant->locale,
            'timezone' => $tenant->timezone,
            'brand_name' => $tenant->brand_name,
            'support_email' => $tenant->support_email,
        ];
    }
}
