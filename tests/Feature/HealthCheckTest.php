<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/health/live');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure([
                'status',
                'service',
                'timestamp',
            ]);
    }

    public function test_ready_health_endpoint_returns_checks_payload(): void
    {
        $response = $this->getJson('/api/health/ready');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure([
                'status',
                'service',
                'timestamp',
                'checks' => [
                    'database' => ['ok', 'message'],
                    'storage' => ['ok', 'message'],
                    'queue' => ['ok', 'message'],
                ],
            ]);
    }
}
