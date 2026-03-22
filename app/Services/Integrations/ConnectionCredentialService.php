<?php

namespace App\Services\Integrations;

use App\Models\IntegrationConnection;
use Illuminate\Contracts\Encryption\Encrypter;

class ConnectionCredentialService
{
    public function __construct(private readonly Encrypter $encrypter)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function decryptCredentials(IntegrationConnection $connection): array
    {
        if ($connection->credentials_encrypted === null || trim($connection->credentials_encrypted) === '') {
            return [];
        }

        try {
            $raw = $this->encrypter->decrypt($connection->credentials_encrypted);
            if (! is_string($raw)) {
                return [];
            }

            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }
}

