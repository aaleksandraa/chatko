<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AdminPasswordResetLinkMail;
use App\Models\ApiToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ApiTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request, ApiTokenService $tokenService): JsonResponse
    {
        $payload = $request->validate([
            'tenant_slug' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:6'],
            'token_name' => ['nullable', 'string', 'max:255'],
            'ttl_minutes' => ['nullable', 'integer', 'min:15', 'max:10080'],
        ]);

        $user = User::query()->where('email', $payload['email'])->first();
        if ($user === null || ! Hash::check($payload['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $tenantSlug = trim((string) ($payload['tenant_slug'] ?? ''));
        $membership = null;

        if ($tenantSlug !== '') {
            $tenant = Tenant::query()->where('slug', $tenantSlug)->first();
            if ($tenant === null) {
                return response()->json(['message' => 'Tenant not found.'], 404);
            }

            $membership = $user->tenants()->withPivot('role')->where('tenant_id', $tenant->id)->first();
            if ($membership === null) {
                return response()->json(['message' => 'User is not a member of this tenant.'], 403);
            }
        } else {
            $membership = $user->tenants()
                ->withPivot('role')
                ->orderByRaw("
                    CASE tenant_users.role
                        WHEN 'owner' THEN 0
                        WHEN 'admin' THEN 1
                        WHEN 'editor' THEN 2
                        WHEN 'support' THEN 3
                        ELSE 9
                    END
                ")
                ->orderBy('tenants.id')
                ->first();

            if ($membership === null) {
                return response()->json(['message' => 'User is not assigned to any tenant.'], 403);
            }
        }

        $tenant = $membership;
        $role = (string) $membership->pivot->role;
        $abilities = $tokenService->abilitiesForRole($role);

        $expiresAt = null;
        if (! empty($payload['ttl_minutes'])) {
            $expiresAt = CarbonImmutable::now()->addMinutes((int) $payload['ttl_minutes']);
        }

        $issued = $tokenService->issueToken(
            $user,
            $tenant,
            $role,
            $abilities,
            (string) ($payload['token_name'] ?? 'admin_token'),
            $expiresAt,
        );

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

    public function me(Request $request): JsonResponse
    {
        $token = $request->attributes->get('api_token');
        if (! $token instanceof ApiToken) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return response()->json([
            'data' => [
                'user' => $token->user,
                'tenant_id' => $token->tenant_id,
                'tenant' => $token->tenant === null ? null : [
                    'id' => $token->tenant->id,
                    'slug' => $token->tenant->slug,
                    'name' => $token->tenant->name,
                ],
                'role' => $token->role,
                'abilities' => $token->abilities_json ?? [],
                'expires_at' => $token->expires_at,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->attributes->get('api_token');
        if ($token instanceof ApiToken) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Token revoked.',
        ]);
    }

    public function requestPasswordResetLink(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'tenant_slug' => ['nullable', 'string', 'max:255'],
        ]);

        $genericMessage = 'If the account exists, a password reset link has been sent.';

        $user = User::query()->where('email', (string) $payload['email'])->first();
        if (! $user instanceof User) {
            return response()->json(['message' => $genericMessage]);
        }

        $tenantSlug = trim((string) ($payload['tenant_slug'] ?? ''));
        $membershipsQuery = $user->tenants()->withPivot('role');
        if ($tenantSlug !== '') {
            $membershipsQuery->where('tenants.slug', $tenantSlug);
        } else {
            $membershipsQuery->orderByRaw("
                CASE tenant_users.role
                    WHEN 'owner' THEN 0
                    WHEN 'admin' THEN 1
                    WHEN 'editor' THEN 2
                    WHEN 'support' THEN 3
                    ELSE 9
                END
            ");
        }

        $tenant = $membershipsQuery->first();
        if (! $tenant instanceof Tenant) {
            return response()->json(['message' => $genericMessage]);
        }

        $token = Password::broker('users')->createToken($user);
        $resetUrl = rtrim((string) config('app.url', ''), '/').'/password/reset?token='
            .urlencode($token)
            .'&email='.urlencode((string) $user->email)
            .'&tenant_slug='.urlencode((string) $tenant->slug);

        Mail::to($user->email)->send(new AdminPasswordResetLinkMail(
            (string) $user->name,
            (string) $tenant->name,
            $resetUrl,
            (int) config('auth.passwords.users.expire', 60),
        ));

        return response()->json([
            'message' => $genericMessage,
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::broker('users')->reset(
            [
                'email' => (string) $payload['email'],
                'password' => (string) $payload['password'],
                'token' => (string) $payload['token'],
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                ApiToken::query()->where('user_id', $user->id)->delete();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => __($status),
            ], 422);
        }

        return response()->json([
            'message' => 'Password has been reset. You can now login.',
        ]);
    }
}
