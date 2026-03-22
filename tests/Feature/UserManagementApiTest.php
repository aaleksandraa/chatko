<?php

namespace Tests\Feature;

use App\Mail\AdminPasswordResetLinkMail;
use App\Models\ApiToken;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class UserManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private string $ownerToken;

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
            'name' => 'User Management Tenant',
            'slug' => 'user-management-tenant',
            'plan_id' => $plan->id,
            'locale' => 'bs',
            'timezone' => 'Europe/Sarajevo',
        ]);

        $this->owner = User::query()->create([
            'name' => 'Owner User',
            'email' => 'owner-user-management@test.local',
            'password' => Hash::make('password123'),
        ]);
        $this->tenant->users()->attach($this->owner->id, ['role' => 'owner']);

        $admin = User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin-user-management@test.local',
            'password' => Hash::make('password123'),
        ]);
        $this->tenant->users()->attach($admin->id, ['role' => 'admin']);

        $tokenService = app(ApiTokenService::class);

        $ownerIssued = $tokenService->issueToken(
            $this->owner,
            $this->tenant,
            'owner',
            ['*'],
            'owner_test_token',
            null,
        );
        $this->ownerToken = $ownerIssued['plain_text_token'];

        $adminIssued = $tokenService->issueToken(
            $admin,
            $this->tenant,
            'admin',
            $tokenService->abilitiesForRole('admin'),
            'admin_test_token',
            null,
        );
        $this->adminToken = $adminIssued['plain_text_token'];
    }

    public function test_owner_can_create_update_and_remove_tenant_users(): void
    {
        $create = $this->withHeaders($this->ownerHeaders())->postJson('/api/admin/users', [
            'name' => 'Support Agent',
            'email' => 'support-agent@test.local',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'support',
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.email', 'support-agent@test.local')
            ->assertJsonPath('data.role', 'support');

        $userId = (int) $create->json('data.id');
        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $userId,
            'role' => 'support',
        ]);

        $member = User::query()->findOrFail($userId);
        $tokenService = app(ApiTokenService::class);
        $issued = $tokenService->issueToken(
            $member,
            $this->tenant,
            'support',
            $tokenService->abilitiesForRole('support'),
            'support_session_token',
            null,
        );
        $issuedHash = hash('sha256', $issued['plain_text_token']);

        $update = $this->withHeaders($this->ownerHeaders())->putJson("/api/admin/users/{$userId}", [
            'name' => 'Support Agent Updated',
            'role' => 'editor',
        ]);

        $update->assertOk()
            ->assertJsonPath('data.name', 'Support Agent Updated')
            ->assertJsonPath('data.role', 'editor');

        $tokenRow = ApiToken::query()->where('token_hash', $issuedHash)->firstOrFail();
        $this->assertSame('editor', $tokenRow->role);
        $this->assertContains('products:write', $tokenRow->abilities_json ?? []);

        $list = $this->withHeaders($this->ownerHeaders())->getJson('/api/admin/users');
        $list->assertOk()
            ->assertJsonFragment([
                'email' => 'support-agent@test.local',
                'role' => 'editor',
            ]);

        $this->withHeaders($this->ownerHeaders())->deleteJson("/api/admin/users/{$userId}")
            ->assertOk();

        $this->assertDatabaseMissing('tenant_users', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $userId,
        ]);
        $this->assertDatabaseMissing('api_tokens', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $userId,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'deleted',
            'entity_type' => 'users',
            'entity_id' => (string) $userId,
        ]);
    }

    public function test_admin_can_send_password_reset_email_and_user_can_reset_password(): void
    {
        Mail::fake();

        $member = User::query()->create([
            'name' => 'Reset User',
            'email' => 'reset-user@test.local',
            'password' => Hash::make('password123'),
        ]);
        $this->tenant->users()->attach($member->id, ['role' => 'support']);

        $tokenService = app(ApiTokenService::class);
        $tokenService->issueToken(
            $member,
            $this->tenant,
            'support',
            $tokenService->abilitiesForRole('support'),
            'support_reset_test_token',
            null,
        );

        $response = $this->withHeaders($this->ownerHeaders())
            ->postJson("/api/admin/users/{$member->id}/password-reset-link");

        $response->assertOk()
            ->assertJsonPath('message', 'Password reset email sent.');

        $resetUrl = '';
        Mail::assertSent(AdminPasswordResetLinkMail::class, function (AdminPasswordResetLinkMail $mail) use ($member, &$resetUrl): bool {
            $resetUrl = $mail->resetUrl;
            return $mail->hasTo($member->email);
        });

        $this->assertNotSame('', $resetUrl);
        $parts = parse_url($resetUrl);
        parse_str($parts['query'] ?? '', $query);

        $token = (string) ($query['token'] ?? '');
        $email = (string) ($query['email'] ?? '');
        $this->assertNotSame('', $token);
        $this->assertSame($member->email, $email);

        $this->postJson('/api/admin/auth/password/reset', [
            'email' => $email,
            'token' => $token,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $member->refresh();
        $this->assertTrue(Hash::check('new-password-123', $member->password));
        $this->assertDatabaseMissing('api_tokens', [
            'user_id' => $member->id,
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_admin_cannot_modify_owner_role(): void
    {
        $this->withHeaders($this->adminHeaders())->postJson('/api/admin/users', [
            'name' => 'Owner Candidate',
            'email' => 'owner-candidate@test.local',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'owner',
        ])->assertForbidden();

        $this->withHeaders($this->adminHeaders())->putJson("/api/admin/users/{$this->owner->id}", [
            'role' => 'admin',
        ])->assertForbidden();

        $this->withHeaders($this->adminHeaders())->deleteJson("/api/admin/users/{$this->owner->id}")
            ->assertForbidden();
    }

    public function test_public_password_request_sends_reset_email_for_existing_user(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/admin/auth/password/request', [
            'email' => $this->owner->email,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'If the account exists, a password reset link has been sent.');

        $capturedResetUrl = '';
        Mail::assertSent(AdminPasswordResetLinkMail::class, function (AdminPasswordResetLinkMail $mail) use (&$capturedResetUrl): bool {
            $capturedResetUrl = (string) $mail->resetUrl;

            return $mail->hasTo($this->owner->email);
        });

        $this->assertNotSame('', $capturedResetUrl);
        $parts = parse_url($capturedResetUrl);
        parse_str($parts['query'] ?? '', $query);
        $this->assertSame($this->tenant->slug, (string) ($query['tenant_slug'] ?? ''));
    }

    public function test_public_password_request_returns_generic_message_for_unknown_email(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/admin/auth/password/request', [
            'email' => 'unknown-user@test.local',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'If the account exists, a password reset link has been sent.');

        Mail::assertNothingSent();
    }

    public function test_owner_cannot_remove_last_owner_in_tenant(): void
    {
        $this->withHeaders($this->ownerHeaders())->putJson("/api/admin/users/{$this->owner->id}", [
            'role' => 'admin',
        ])->assertStatus(422);

        $this->withHeaders($this->ownerHeaders())->deleteJson("/api/admin/users/{$this->owner->id}")
            ->assertStatus(422);
    }

    public function test_non_system_admin_cannot_manage_system_admin_flag(): void
    {
        $this->withHeaders($this->ownerHeaders())->postJson('/api/admin/users', [
            'name' => 'Platform Admin Candidate',
            'email' => 'platform-admin-candidate@test.local',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
            'is_system_admin' => true,
        ])->assertForbidden()->assertJsonPath('message', 'Only system admin can manage system admin flag.');

        $this->withHeaders($this->ownerHeaders())->putJson("/api/admin/users/{$this->owner->id}", [
            'is_system_admin' => true,
        ])->assertForbidden()->assertJsonPath('message', 'Only system admin can manage system admin flag.');
    }

    public function test_system_admin_can_set_and_update_system_admin_flag(): void
    {
        $this->owner->forceFill([
            'is_system_admin' => true,
        ])->save();

        $create = $this->withHeaders($this->ownerHeaders())->postJson('/api/admin/users', [
            'name' => 'Platform Admin',
            'email' => 'platform-admin@test.local',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
            'is_system_admin' => true,
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.email', 'platform-admin@test.local')
            ->assertJsonPath('data.is_system_admin', true);

        $userId = (int) $create->json('data.id');

        $update = $this->withHeaders($this->ownerHeaders())->putJson("/api/admin/users/{$userId}", [
            'is_system_admin' => false,
        ]);

        $update->assertOk()
            ->assertJsonPath('data.id', $userId)
            ->assertJsonPath('data.is_system_admin', false);

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'is_system_admin' => false,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function ownerHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->ownerToken,
            'X-Tenant-Slug' => $this->tenant->slug,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->adminToken,
            'X-Tenant-Slug' => $this->tenant->slug,
        ];
    }
}
