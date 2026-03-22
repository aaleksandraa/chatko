<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Mail\AdminPasswordResetLinkMail;
use App\Models\ApiToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Auth\ApiTokenService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TenantUserController extends Controller
{
    use ResolvesTenant;

    /**
     * @var array<int, string>
     */
    private const ROLES = ['support', 'editor', 'admin', 'owner'];

    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $users = $tenant->users()
            ->select('users.id', 'users.name', 'users.email', 'users.created_at', 'users.updated_at')
            ->orderBy('users.id')
            ->get();

        $currentUserId = (int) ($request->user()?->id ?? 0);

        return response()->json([
            'data' => $users->map(fn (User $user): array => $this->serializeTenantUser($user, $currentUserId))->values(),
        ]);
    }

    public function store(
        Request $request,
        TenantContext $tenantContext,
        ApiTokenService $apiTokenService,
        AuditLogService $auditLogService,
    ): JsonResponse {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $payload = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in(self::ROLES)],
            'is_system_admin' => ['sometimes', 'boolean'],
        ]);

        $requestedRole = (string) $payload['role'];
        $this->ensureActorCanManageOwnerRole($request, '', $requestedRole);
        if (array_key_exists('is_system_admin', $payload)) {
            $this->ensureActorCanManageSystemAdminFlag($request);
        }

        $email = (string) $payload['email'];
        $user = User::query()->where('email', $email)->first();
        $createdUser = false;
        $updatedUser = false;

        if (! $user instanceof User) {
            $name = trim((string) ($payload['name'] ?? ''));
            $password = (string) ($payload['password'] ?? '');

            if ($name === '') {
                throw ValidationException::withMessages([
                    'name' => ['Name je obavezan za novi korisnicki nalog.'],
                ]);
            }

            if ($password === '') {
                throw ValidationException::withMessages([
                    'password' => ['Password je obavezan za novi korisnicki nalog.'],
                ]);
            }

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'is_system_admin' => (bool) ($payload['is_system_admin'] ?? false),
            ]);
            $createdUser = true;
        } else {
            $userPayload = [];
            if (array_key_exists('name', $payload) && trim((string) $payload['name']) !== '') {
                $userPayload['name'] = trim((string) $payload['name']);
            }
            if (array_key_exists('password', $payload) && (string) $payload['password'] !== '') {
                $userPayload['password'] = Hash::make((string) $payload['password']);
            }
            if (array_key_exists('is_system_admin', $payload)) {
                $userPayload['is_system_admin'] = (bool) $payload['is_system_admin'];
            }
            if ($userPayload !== []) {
                $user->fill($userPayload)->save();
                $updatedUser = true;
            }
        }

        $existingMembership = $tenant->users()->where('users.id', $user->id)->first();
        $before = $existingMembership instanceof User ? $this->snapshot($existingMembership) : null;

        $createdMembership = false;
        if (! $existingMembership instanceof User) {
            $tenant->users()->attach($user->id, ['role' => $requestedRole]);
            $createdMembership = true;
        } else {
            $existingRole = (string) ($existingMembership->pivot?->role ?? '');
            if ($existingRole !== $requestedRole) {
                $this->ensureRoleChangeAllowed($tenant, $existingRole, $requestedRole);
                $tenant->users()->updateExistingPivot($user->id, ['role' => $requestedRole]);
            }
        }

        $this->syncTenantTokenRole($tenant, $user->id, $requestedRole, $apiTokenService);

        $member = $tenant->users()->where('users.id', $user->id)->firstOrFail();
        $after = $this->snapshot($member);

        $auditLogService->logMutation(
            $request,
            $createdMembership ? 'created' : 'updated',
            $member,
            $before,
            $after,
            [
                'entity' => 'tenant_user',
                'created_user' => $createdUser,
                'updated_user' => $updatedUser,
                'created_membership' => $createdMembership,
                'role' => $requestedRole,
            ],
        );

        return response()->json([
            'data' => $this->serializeTenantUser($member),
        ], $createdMembership ? 201 : 200);
    }

    public function update(
        Request $request,
        TenantContext $tenantContext,
        ApiTokenService $apiTokenService,
        AuditLogService $auditLogService,
        int $id,
    ): JsonResponse {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $member = $tenant->users()->where('users.id', $id)->firstOrFail();
        $before = $this->snapshot($member);

        $payload = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($member->id)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'confirmed'],
            'role' => ['sometimes', Rule::in(self::ROLES)],
            'is_system_admin' => ['sometimes', 'boolean'],
        ]);

        $currentRole = (string) ($member->pivot?->role ?? '');
        $requestedRole = (string) ($payload['role'] ?? $currentRole);
        $this->ensureActorCanManageOwnerRole($request, $currentRole, $requestedRole);

        if (array_key_exists('role', $payload) && $requestedRole !== $currentRole) {
            $this->ensureRoleChangeAllowed($tenant, $currentRole, $requestedRole);
            $tenant->users()->updateExistingPivot($member->id, ['role' => $requestedRole]);
            $this->syncTenantTokenRole($tenant, $member->id, $requestedRole, $apiTokenService);
        }

        $userPayload = [];
        if (array_key_exists('name', $payload)) {
            $userPayload['name'] = trim((string) $payload['name']);
        }
        if (array_key_exists('email', $payload)) {
            $userPayload['email'] = (string) $payload['email'];
        }
        if (array_key_exists('password', $payload) && (string) $payload['password'] !== '') {
            $userPayload['password'] = Hash::make((string) $payload['password']);
        }
        if (array_key_exists('is_system_admin', $payload)) {
            $this->ensureActorCanManageSystemAdminFlag($request);
            $userPayload['is_system_admin'] = (bool) $payload['is_system_admin'];
        }
        if ($userPayload !== []) {
            $member->fill($userPayload)->save();
        }

        $updated = $tenant->users()->where('users.id', $id)->firstOrFail();
        $after = $this->snapshot($updated);

        $auditLogService->logMutation(
            $request,
            'updated',
            $updated,
            $before,
            $after,
            [
                'entity' => 'tenant_user',
                'changed_fields' => array_keys($payload),
            ],
        );

        return response()->json([
            'data' => $this->serializeTenantUser($updated),
        ]);
    }

    public function destroy(
        Request $request,
        TenantContext $tenantContext,
        AuditLogService $auditLogService,
        int $id,
    ): JsonResponse {
        $tenant = $this->tenantFromRequest($request, $tenantContext);

        $member = $tenant->users()->where('users.id', $id)->firstOrFail();
        $currentRole = (string) ($member->pivot?->role ?? '');
        $this->ensureActorCanManageOwnerRole($request, $currentRole, null);
        $this->ensureRoleChangeAllowed($tenant, $currentRole, null);

        $before = $this->snapshot($member);

        $tenant->users()->detach($member->id);
        $revokedTokens = ApiToken::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $member->id)
            ->delete();

        $auditLogService->logMutation(
            $request,
            'deleted',
            $member,
            $before,
            null,
            [
                'entity' => 'tenant_user',
                'revoked_tokens' => $revokedTokens,
            ],
        );

        return response()->json([
            'message' => 'User removed from tenant.',
        ]);
    }

    public function sendPasswordResetLink(
        Request $request,
        TenantContext $tenantContext,
        AuditLogService $auditLogService,
        int $id,
    ): JsonResponse {
        $tenant = $this->tenantFromRequest($request, $tenantContext);
        $member = $tenant->users()->where('users.id', $id)->firstOrFail();

        $token = Password::broker('users')->createToken($member);
        $resetUrl = rtrim((string) config('app.url', ''), '/').'/password/reset?token='
            .urlencode($token)
            .'&email='.urlencode((string) $member->email)
            .'&tenant_slug='.urlencode((string) $tenant->slug);

        Mail::to($member->email)->send(new AdminPasswordResetLinkMail(
            (string) $member->name,
            (string) $tenant->name,
            $resetUrl,
            (int) config('auth.passwords.users.expire', 60),
        ));

        $auditLogService->logMutation(
            $request,
            'password_reset_requested',
            $member,
            null,
            null,
            [
                'entity' => 'tenant_user',
                'reset_email' => $member->email,
            ],
        );

        return response()->json([
            'message' => 'Password reset email sent.',
        ]);
    }

    private function ensureActorCanManageOwnerRole(Request $request, string $currentRole, ?string $nextRole): void
    {
        $touchesOwnerRole = $currentRole === 'owner' || $nextRole === 'owner';
        if (! $touchesOwnerRole) {
            return;
        }

        $actorRole = (string) $request->attributes->get('tenant_role');
        if ($actorRole !== 'owner') {
            abort(403, 'Only owner can assign or modify owner role.');
        }
    }

    private function ensureActorCanManageSystemAdminFlag(Request $request): void
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! (bool) $actor->is_system_admin) {
            abort(403, 'Only system admin can manage system admin flag.');
        }
    }

    private function ensureRoleChangeAllowed(Tenant $tenant, string $currentRole, ?string $nextRole): void
    {
        if ($currentRole !== 'owner') {
            return;
        }

        if ($nextRole === 'owner') {
            return;
        }

        $ownerCount = (int) $tenant->users()->wherePivot('role', 'owner')->count();
        if ($ownerCount <= 1) {
            throw ValidationException::withMessages([
                'role' => ['Tenant mora imati najmanje jednog owner korisnika.'],
            ]);
        }
    }

    private function syncTenantTokenRole(Tenant $tenant, int $userId, string $role, ApiTokenService $apiTokenService): void
    {
        ApiToken::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $userId)
            ->update([
                'role' => $role,
                'abilities_json' => $apiTokenService->abilitiesForRole($role),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => (string) ($user->pivot?->role ?? ''),
            'is_system_admin' => (bool) $user->is_system_admin,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTenantUser(User $user, int $currentUserId = 0): array
    {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'role' => (string) ($user->pivot?->role ?? ''),
            'is_system_admin' => (bool) $user->is_system_admin,
            'is_current_user' => $currentUserId > 0 && $currentUserId === (int) $user->id,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }
}
