<?php

namespace App\Services\Integrations;

use App\Models\IntegrationConnection;
use App\Services\Integrations\Exceptions\IntegrationAdapterException;
use Illuminate\Support\Carbon;

class SourceTestService
{
    public function __construct(private readonly ProductSourceAdapterRegistry $adapterRegistry)
    {
    }

    public function testConnection(IntegrationConnection $connection): array
    {
        if (in_array($connection->type, ['csv', 'manual'], true)) {
            $connection->last_tested_at = Carbon::now();
            $connection->status = 'connected';
            $connection->last_error = null;
            $connection->save();

            return [
                'ok' => true,
                'message' => 'Connection test passed.',
            ];
        }

        try {
            $adapter = $this->adapterRegistry->resolve($connection);
            $result = $adapter->testConnection($connection);
            $isValid = (bool) ($result['ok'] ?? false);
            $message = (string) ($result['message'] ?? ($isValid ? 'Connection test passed.' : 'Connection test failed.'));
        } catch (IntegrationAdapterException $exception) {
            $isValid = false;
            $message = $exception->getMessage();
            $result = [];
        } catch (\Throwable $exception) {
            $isValid = false;
            $message = 'Connection test failed: '.$exception->getMessage();
            $result = [];
        }

        $connection->last_tested_at = Carbon::now();
        $connection->status = $isValid ? 'connected' : 'test_failed';
        $connection->last_error = $isValid ? null : $message;
        $connection->save();

        return [
            'ok' => $isValid,
            'message' => $isValid ? $message : $connection->last_error,
            'meta' => $result['meta'] ?? [],
        ];
    }
}
