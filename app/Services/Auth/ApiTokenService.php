<?php

namespace App\Services\Auth;

use App\Models\ApiToken;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class ApiTokenService
{
    /**
     * @param array<int, string> $abilities
     * @return array{plain_text_token: string, token: ApiToken}
     */
    public function issueToken(
        User $user,
        ?Tenant $tenant,
        string $role,
        array $abilities,
        string $name = 'admin_token',
        ?CarbonImmutable $expiresAt = null,
    ): array {
        $plainToken = 'atk_'.Str::random(64);
        $hashedToken = hash('sha256', $plainToken);

        $token = ApiToken::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant?->id,
            'name' => $name,
            'role' => $role,
            'token_hash' => $hashedToken,
            'abilities_json' => array_values(array_unique($abilities)),
            'expires_at' => $expiresAt,
        ]);

        return [
            'plain_text_token' => $plainToken,
            'token' => $token,
        ];
    }

    public function findByPlainTextToken(string $plainTextToken): ?ApiToken
    {
        if ($plainTextToken === '') {
            return null;
        }

        $tokenHash = hash('sha256', $plainTextToken);

        return ApiToken::query()->where('token_hash', $tokenHash)->first();
    }

    /**
     * @return array<int, string>
     */
    public function abilitiesForRole(string $role): array
    {
        return match ($role) {
            'owner' => ['*'],
            'admin' => [
                'integrations:read', 'integrations:write',
                'products:read', 'products:write',
                'knowledge:read', 'knowledge:write',
                'widgets:write', 'ai_config:write',
                'conversations:read', 'analytics:read',
            ],
            'editor' => [
                'products:read', 'products:write',
                'knowledge:read', 'knowledge:write',
                'conversations:read',
            ],
            'support' => [
                'products:read',
                'knowledge:read',
                'conversations:read',
                'analytics:read',
            ],
            default => ['products:read'],
        };
    }
}

