<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class AuditLogService
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $metadata
     */
    public function logMutation(
        Request $request,
        string $action,
        Model|string $entity,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
    ): void {
        try {
            $user = $request->user();
            $tenant = $request->attributes->get('tenant');

            AuditLog::query()->create([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->id : null,
                'actor_user_id' => $user instanceof User ? $user->id : null,
                'actor_role' => $this->resolveActorRole($request),
                'action' => $action,
                'entity_type' => $this->resolveEntityType($entity),
                'entity_id' => $this->resolveEntityId($entity),
                'request_method' => $request->method(),
                'request_path' => $request->path(),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
                'before_json' => $this->sanitize($before),
                'after_json' => $this->sanitize($after),
                'metadata_json' => $this->sanitize($metadata),
            ]);
        } catch (Throwable) {
            // Audit trail should never break business flow.
        }
    }

    /**
     * @param  Model|string  $entity
     */
    private function resolveEntityType(Model|string $entity): string
    {
        if (is_string($entity)) {
            return $entity;
        }

        return (string) $entity->getTable();
    }

    /**
     * @param  Model|string  $entity
     */
    private function resolveEntityId(Model|string $entity): ?string
    {
        if (is_string($entity)) {
            return null;
        }

        $key = $entity->getKey();
        if ($key === null) {
            return null;
        }

        return (string) $key;
    }

    private function resolveActorRole(Request $request): ?string
    {
        $role = $request->attributes->get('auth_role');
        if (! is_string($role) || trim($role) === '') {
            return null;
        }

        return trim($role);
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $keyString = is_string($key) ? strtolower($key) : '';
                if ($this->shouldRedact($keyString)) {
                    $sanitized[$key] = '[REDACTED]';
                    continue;
                }
                $sanitized[$key] = $this->sanitize($item);
            }

            return $sanitized;
        }

        if (is_object($value)) {
            return $this->sanitize((array) $value);
        }

        return $value;
    }

    private function shouldRedact(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        $sensitiveParts = [
            'password',
            'secret',
            'token',
            'api_key',
            'credential',
            'authorization',
        ];

        foreach ($sensitiveParts as $part) {
            if (str_contains($key, $part)) {
                return true;
            }
        }

        return false;
    }
}
