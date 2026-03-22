<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Widget;
use App\Models\WidgetAbuseLog;
use App\Services\Auth\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WidgetAbuseLogsAdminApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Widget $widget;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::query()->create([
            'name' => 'Starter',
            'code' => 'starter',
        ]);

        $this->tenant = Tenant::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Abuse Logs Tenant',
            'slug' => 'abuse-logs-tenant',
            'plan_id' => $plan->id,
            'locale' => 'bs',
            'timezone' => 'Europe/Sarajevo',
        ]);

        $this->widget = Widget::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Abuse Widget',
            'public_key' => 'wpk_abuse_logs_test',
            'secret_key' => 'wsk_abuse_logs_secret',
            'default_locale' => 'bs',
            'is_active' => true,
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'abuse-owner@test.local',
            'password' => Hash::make('password123'),
        ]);
        $this->tenant->users()->attach($owner->id, ['role' => 'owner']);

        $issued = app(ApiTokenService::class)->issueToken(
            $owner,
            $this->tenant,
            'owner',
            ['*'],
            'test_token',
            null,
        );
        $this->adminToken = $issued['plain_text_token'];
    }

    public function test_admin_can_filter_widget_abuse_logs(): void
    {
        WidgetAbuseLog::query()->create([
            'tenant_id' => $this->tenant->id,
            'widget_id' => $this->widget->id,
            'public_key' => $this->widget->public_key,
            'route' => '/api/widget/session/start',
            'http_method' => 'POST',
            'reason' => 'rate_limited',
            'ip_address' => '10.20.30.40',
            'origin' => 'https://shop.example.com',
        ]);

        WidgetAbuseLog::query()->create([
            'tenant_id' => $this->tenant->id,
            'widget_id' => $this->widget->id,
            'public_key' => $this->widget->public_key,
            'route' => '/api/widget/session/start',
            'http_method' => 'POST',
            'reason' => 'missing_challenge_token',
            'ip_address' => '192.168.1.2',
            'origin' => 'https://shop.example.com',
        ]);

        $filtered = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/widget-abuse-logs?reason=rate_limited&ip=10.20&public_key=wpk_abuse');

        $filtered->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'rate_limited')
            ->assertJsonPath('data.0.ip_address', '10.20.30.40')
            ->assertJsonPath('data.0.widget.public_key', $this->widget->public_key);

        $all = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/widget-abuse-logs');

        $all->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        return [
            'X-Tenant-Slug' => $this->tenant->slug,
            'Authorization' => 'Bearer '.$this->adminToken,
        ];
    }
}

