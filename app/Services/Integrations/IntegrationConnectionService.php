<?php

namespace App\Services\Integrations;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use Illuminate\Contracts\Encryption\Encrypter;

class IntegrationConnectionService
{
    public function __construct(private readonly Encrypter $encrypter)
    {
    }

    public function create(Tenant $tenant, array $payload): IntegrationConnection
    {
        return IntegrationConnection::query()->create([
            'tenant_id' => $tenant->id,
            'type' => $payload['type'],
            'name' => $payload['name'],
            'status' => 'draft',
            'base_url' => $payload['base_url'] ?? null,
            'credentials_encrypted' => isset($payload['credentials']) ? $this->encrypter->encrypt(json_encode($payload['credentials'])) : null,
            'auth_type' => $payload['auth_type'] ?? null,
            'config_json' => $payload['config_json'] ?? null,
            'mapping_json' => $payload['mapping_json'] ?? null,
            'sync_frequency' => $payload['sync_frequency'] ?? null,
        ]);
    }

    public function update(IntegrationConnection $connection, array $payload): IntegrationConnection
    {
        $connection->fill([
            'type' => $payload['type'] ?? $connection->type,
            'name' => $payload['name'] ?? $connection->name,
            'base_url' => $payload['base_url'] ?? $connection->base_url,
            'auth_type' => $payload['auth_type'] ?? $connection->auth_type,
            'config_json' => $payload['config_json'] ?? $connection->config_json,
            'mapping_json' => $payload['mapping_json'] ?? $connection->mapping_json,
            'sync_frequency' => $payload['sync_frequency'] ?? $connection->sync_frequency,
        ]);

        if (isset($payload['credentials'])) {
            $connection->credentials_encrypted = $this->encrypter->encrypt(json_encode($payload['credentials']));
        }

        $connection->save();

        return $connection;
    }
}
