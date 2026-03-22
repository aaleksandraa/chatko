<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenantOwner;

    private Tenant $tenantAdmin;

    private Tenant $tenantSupport;

    private string $ownerToken;

    private string $adminToken;

    private string $supportToken;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::query()->create([
            'name' => 'Starter',
            'code' => 'starter',
        ]);

        $this->tenantOwner = Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Owner Tenant',
            'slug' => 'owner-tenant',
            'plan_id' => $plan->id,
            'locale' => 'bs',
            'timezone' => 'Europe/Sarajevo',
            'status' => 'active',
        ]);

        $this->tenantAdmin = Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Admin Tenant',
            'slug' => 'admin-tenant',
            'plan_id' => $plan->id,
            'locale' => 'bs',
            'timezone' => 'Europe/Sarajevo',
            'status' => 'active',
        ]);

        $this->tenantSupport = Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Support Tenant',
            'slug' => 'support-tenant',
            'plan_id' => $plan->id,
            'locale' => 'bs',
            'timezone' => 'Europe/Sarajevo',
            'status' => 'active',
        ]);

        $this->user = User::query()->create([
            'name' => 'Tenant Manager',
            'email' => 'tenant-manager@test.local',
            'password' => Hash::make('password123'),
        ]);

        $this->tenantOwner->users()->attach($this->user->id, ['role' => 'owner']);
        $this->tenantAdmin->users()->attach($this->user->id, ['role' => 'admin']);
        $this->tenantSupport->users()->attach($this->user->id, ['role' => 'support']);

        $tokenService = app(ApiTokenService::class);

        $ownerIssued = $tokenService->issueToken(
            $this->user,
            $this->tenantOwner,
            'owner',
            ['*'],
            'owner_test_token',
            null,
        );
        $this->ownerToken = $ownerIssued['plain_text_token'];

        $adminIssued = $tokenService->issueToken(
            $this->user,
            $this->tenantAdmin,
            'admin',
            $tokenService->abilitiesForRole('admin'),
            'admin_test_token',
            null,
        );
        $this->adminToken = $adminIssued['plain_text_token'];

        $supportIssued = $tokenService->issueToken(
            $this->user,
            $this->tenantSupport,
            'support',
            $tokenService->abilitiesForRole('support'),
            'support_test_token',
            null,
        );
        $this->supportToken = $supportIssued['plain_text_token'];
    }

    public function test_user_can_list_memberships_and_switch_tenant_context(): void
    {
        $list = $this->withHeaders($this->ownerHeaders())
            ->getJson('/api/admin/auth/tenants');

        $list->assertOk()
            ->assertJsonFragment([
                'slug' => $this->tenantOwner->slug,
                'role' => 'owner',
                'is_current' => true,
            ])
            ->assertJsonFragment([
                'slug' => $this->tenantAdmin->slug,
                'role' => 'admin',
            ])
            ->assertJsonFragment([
                'slug' => $this->tenantSupport->slug,
                'role' => 'support',
            ]);

        $switch = $this->withHeaders($this->ownerHeaders())
            ->postJson('/api/admin/auth/switch-tenant', [
                'tenant_id' => $this->tenantAdmin->id,
            ]);

        $switch->assertOk()
            ->assertJsonPath('data.tenant.slug', $this->tenantAdmin->slug)
            ->assertJsonPath('data.role', 'admin');

        $newToken = (string) $switch->json('data.token');
        $this->assertNotSame('', $newToken);

        $this->withHeaders($this->ownerHeaders())
            ->getJson('/api/admin/auth/me')
            ->assertUnauthorized();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$newToken,
            'X-Tenant-Slug' => $this->tenantAdmin->slug,
        ])->getJson('/api/admin/auth/me')
            ->assertOk()
            ->assertJsonPath('data.tenant.slug', $this->tenantAdmin->slug)
            ->assertJsonPath('data.role', 'admin');
    }

    public function test_update_and_delete_are_protected_by_membership_role(): void
    {
        $this->withHeaders($this->ownerHeaders())
            ->putJson("/api/admin/auth/tenants/{$this->tenantOwner->id}", [
                'name' => 'Owner Tenant Updated',
                'support_email' => 'support@owner-tenant.local',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Owner Tenant Updated');

        $this->assertDatabaseHas('tenants', [
            'id' => $this->tenantOwner->id,
            'name' => 'Owner Tenant Updated',
            'support_email' => 'support@owner-tenant.local',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenantOwner->id,
            'action' => 'updated',
            'entity_type' => 'tenants',
            'entity_id' => (string) $this->tenantOwner->id,
        ]);

        $this->withHeaders($this->ownerHeaders())
            ->putJson("/api/admin/auth/tenants/{$this->tenantSupport->id}", [
                'name' => 'Should Not Update',
            ])
            ->assertForbidden();

        $this->withHeaders($this->adminHeaders())
            ->deleteJson("/api/admin/auth/tenants/{$this->tenantAdmin->id}")
            ->assertForbidden();

        $delete = $this->withHeaders($this->ownerHeaders())
            ->deleteJson("/api/admin/auth/tenants/{$this->tenantOwner->id}");

        $delete->assertOk()
            ->assertJsonPath('meta.current_tenant_deleted', true);

        $this->assertDatabaseMissing('tenants', [
            'id' => $this->tenantOwner->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'deleted',
            'entity_type' => 'tenants',
            'entity_id' => (string) $this->tenantOwner->id,
        ]);
    }

    public function test_token_cannot_access_other_tenant_context_without_switch(): void
    {
        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->ownerToken,
            'X-Tenant-Slug' => $this->tenantAdmin->slug,
        ])->getJson('/api/admin/users')
            ->assertForbidden()
            ->assertJsonPath('message', 'Token does not match tenant context. Use tenant switch or login again.');
    }

    public function test_login_without_tenant_slug_defaults_to_highest_role_membership(): void
    {
        $response = $this->postJson('/api/admin/auth/login', [
            'email' => 'tenant-manager@test.local',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.tenant.slug', 'owner-tenant')
            ->assertJsonPath('data.user.email', 'tenant-manager@test.local');
    }

    public function test_login_with_tenant_slug_still_works_for_target_membership(): void
    {
        $response = $this->postJson('/api/admin/auth/login', [
            'tenant_slug' => 'admin-tenant',
            'email' => 'tenant-manager@test.local',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.tenant.slug', 'admin-tenant');
    }

    public function test_support_role_cannot_access_ai_config_endpoint(): void
    {
        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->supportToken,
            'X-Tenant-Slug' => $this->tenantSupport->slug,
        ])->getJson('/api/admin/ai-config')
            ->assertForbidden()
            ->assertJsonPath('message', 'Insufficient role. Required: admin, current: support.');
    }

    public function test_system_admin_can_list_switch_and_manage_non_member_tenant(): void
    {
        $externalTenant = Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'External Tenant',
            'slug' => 'external-tenant',
            'plan_id' => $this->tenantOwner->plan_id,
            'locale' => 'bs',
            'timezone' => 'Europe/Sarajevo',
            'status' => 'active',
        ]);

        $systemAdmin = User::query()->create([
            'name' => 'System Admin',
            'email' => 'system-admin-tenant@test.local',
            'password' => Hash::make('password123'),
            'is_system_admin' => true,
        ]);
        $this->tenantOwner->users()->attach($systemAdmin->id, ['role' => 'admin']);

        $tokenService = app(ApiTokenService::class);
        $issued = $tokenService->issueToken(
            $systemAdmin,
            $this->tenantOwner,
            'admin',
            $tokenService->abilitiesForRole('admin'),
            'system_admin_test_token',
            null,
        );
        $token = $issued['plain_text_token'];

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Slug' => $this->tenantOwner->slug,
        ])->getJson('/api/admin/auth/tenants')
            ->assertOk()
            ->assertJsonFragment([
                'slug' => $externalTenant->slug,
                'role' => 'admin',
                'can_manage' => true,
                'can_delete' => true,
                'is_member' => false,
            ]);

        $switch = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Slug' => $this->tenantOwner->slug,
        ])->postJson('/api/admin/auth/switch-tenant', [
            'tenant_id' => $externalTenant->id,
        ]);

        $switch->assertOk()
            ->assertJsonPath('data.tenant.slug', $externalTenant->slug)
            ->assertJsonPath('data.role', 'admin');

        $switchedToken = (string) $switch->json('data.token');
        $this->assertNotSame('', $switchedToken);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$switchedToken,
            'X-Tenant-Slug' => $externalTenant->slug,
        ])->putJson("/api/admin/auth/tenants/{$externalTenant->id}", [
            'name' => 'External Tenant Updated',
        ])->assertOk()->assertJsonPath('data.name', 'External Tenant Updated');
    }

    /**
     * @return array<string, string>
     */
    private function ownerHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->ownerToken,
            'X-Tenant-Slug' => $this->tenantOwner->slug,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->adminToken,
            'X-Tenant-Slug' => $this->tenantAdmin->slug,
        ];
    }
}
