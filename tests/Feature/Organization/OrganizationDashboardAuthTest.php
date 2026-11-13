<?php

namespace Tests\Feature\Organization;

use App\Models\User;
use App\Models\Users;
use App\Organization\Enums\AuditAction;
use App\Organization\Enums\MembershipStatus;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Enums\OrganizationStatus;
use App\Organization\Enums\PivotRole;
use App\Organization\Mail\OrganizationDashboardPasswordMail;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationDashboardAccount;
use App\Organization\Models\OrganizationDomain;
use App\Organization\Models\OrganizationMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Organization\Concerns\BuildsOrganizationActors;
use Tests\TestCase;

/**
 * Uses real tokens over HTTP, because Sanctum::actingAs() would skip the guard checks these tests are about.
 */
class OrganizationDashboardAuthTest extends TestCase
{
    use BuildsOrganizationActors, RefreshDatabase;

    private const NEW_PASSWORD = 'Another-Pass-456!';

    // Login

    /**
     * @return array<string, array{OrganizationRole}>
     */
    public static function dashboardRoles(): array
    {
        return [
            'owner' => [OrganizationRole::ORG_OWNER],
            'admin' => [OrganizationRole::ORG_ADMIN],
            'analyst' => [OrganizationRole::ORG_ANALYST],
        ];
    }

    #[DataProvider('dashboardRoles')]
    public function test_dashboard_roles_can_log_in(OrganizationRole $role): void
    {
        $organization = Organization::factory()->create();
        $user = $this->makeOrgUser($organization, $role);
        $account = $this->makeDashboardAccount($user);

        $response = $this->dashboardLogin($account)
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('dashboard_account.status', 'active')
            ->assertJsonPath('organizations.0.organization.id', $organization->id)
            ->assertJsonPath('organizations.0.roles', [$role->value]);

        $this->assertNotEmpty($response->json('access_token'));
        $this->assertNotEmpty($response->json('expires_at'));
        $this->assertStringNotContainsString('$2y$', $response->getContent());
        $this->assertNotNull($account->fresh()->last_login_at);
        $this->assertDatabaseHas('organization_audit_logs', [
            'action' => AuditAction::DASHBOARD_LOGIN->value,
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_login_creates_a_named_expiring_token_owned_by_the_dashboard_account(): void
    {
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_ADMIN);

        $this->dashboardLogin($account)->assertOk();

        $token = $account->tokens()->sole();
        $this->assertSame('organization-dashboard', $token->name);
        $this->assertSame(['organization-dashboard'], $token->abilities);
        $this->assertTrue($token->expires_at->isFuture());
    }

    public function test_login_email_is_case_insensitive(): void
    {
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_ADMIN);

        $this->postJson('/api/v1/organization/auth/login', [
            'email' => strtoupper($account->email),
            'password' => $this->dashboardPassword,
        ])->assertOk();
    }

    public function test_wrong_password_unknown_email_and_unset_password_look_the_same(): void
    {
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_ADMIN);
        $pending = $this->makeDashboardAccount(
            $this->makeOrgUser(Organization::factory()->create(), OrganizationRole::ORG_ADMIN),
            withPassword: false,
        );

        $attempts = [
            [$account->email, 'wrong-password'],
            ['nobody@example.com', $this->dashboardPassword],
            [$pending->email, $this->dashboardPassword],
        ];

        foreach ($attempts as [$email, $password]) {
            $this->postJson('/api/v1/organization/auth/login', ['email' => $email, 'password' => $password])
                ->assertUnauthorized()
                ->assertExactJson(['message' => 'Invalid email or password.']);
        }
    }

    public function test_login_validates_its_input(): void
    {
        $this->postJson('/api/v1/organization/auth/login', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_org_member_cannot_log_in(): void
    {
        $user = $this->makeOrgUser(Organization::factory()->create(), OrganizationRole::ORG_MEMBER);

        $this->dashboardLogin($this->makeDashboardAccount($user))
            ->assertForbidden()
            ->assertExactJson(['message' => 'You do not have access to the Organization Dashboard.']);
    }

    public function test_user_with_no_role_cannot_log_in(): void
    {
        $this->dashboardLogin($this->makeDashboardAccount($this->makeUser()))->assertForbidden();
    }

    public function test_disabled_account_cannot_log_in_but_only_the_password_holder_learns_why(): void
    {
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_OWNER);
        $account->forceFill(['disabled_at' => now()])->save();

        $this->postJson('/api/v1/organization/auth/login', ['email' => $account->email, 'password' => 'wrong-password'])
            ->assertUnauthorized();

        $this->dashboardLogin($account)
            ->assertForbidden()
            ->assertExactJson(['message' => 'This dashboard account has been disabled.']);

        $this->assertSame(0, $account->tokens()->count());
    }

    public function test_login_rate_limiting_blocks_after_five_attempts(): void
    {
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_ADMIN);
        $attempt = ['email' => $account->email, 'password' => 'wrong-password'];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/organization/auth/login', $attempt)->assertUnauthorized();
        }

        $this->postJson('/api/v1/organization/auth/login', $attempt)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertExactJson(['message' => 'Too Many Attempts.']);

        // Even the right password is blocked now.
        $this->dashboardLogin($account)->assertTooManyRequests();
    }

    // Membership and organization status, at login and on every request

    /**
     * @return array<string, array{MembershipStatus}>
     */
    public static function inactiveMembershipStatuses(): array
    {
        return [
            'suspended' => [MembershipStatus::SUSPENDED],
            'deactivated' => [MembershipStatus::DEACTIVATED],
            'expired' => [MembershipStatus::EXPIRED],
            'invited' => [MembershipStatus::INVITED],
        ];
    }

    #[DataProvider('inactiveMembershipStatuses')]
    public function test_inactive_membership_blocks_login(MembershipStatus $status): void
    {
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_OWNER);
        OrganizationMembership::where('user_id', $account->user_id)->update(['status' => $status]);

        $this->dashboardLogin($account)->assertForbidden();
    }

    #[DataProvider('inactiveMembershipStatuses')]
    public function test_inactive_membership_blocks_existing_tokens_on_every_endpoint(MembershipStatus $status): void
    {
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_OWNER);
        $organization = OrganizationMembership::where('user_id', $account->user_id)->sole()->organization;
        $token = $this->dashboardToken($account);

        $this->bearer($token)->getJson('/api/v1/corporate/me')->assertOk();

        OrganizationMembership::where('user_id', $account->user_id)->update(['status' => $status]);

        $this->bearer($token)->getJson('/api/v1/corporate/me')->assertForbidden();
        $this->bearer($token)->getJson("/api/v1/organizations/{$organization->id}")->assertForbidden();
        $this->bearer($token)->postJson('/api/v1/organization/auth/logout')->assertForbidden();
    }

    public function test_access_returns_when_the_membership_is_reactivated(): void
    {
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_OWNER);
        $token = $this->dashboardToken($account);
        $membership = OrganizationMembership::where('user_id', $account->user_id);

        $membership->update(['status' => MembershipStatus::EXPIRED]);
        $this->bearer($token)->getJson('/api/v1/corporate/me')->assertForbidden();

        $membership->update(['status' => MembershipStatus::ACTIVE]);
        $this->bearer($token)->getJson('/api/v1/corporate/me')->assertOk();
    }

    public function test_active_and_pending_organizations_allow_access(): void
    {
        foreach ([OrganizationStatus::ACTIVE, OrganizationStatus::PENDING] as $status) {
            $organization = Organization::factory()->create(['status' => $status]);
            $account = $this->makeDashboardAccount($this->makeOrgUser($organization, OrganizationRole::ORG_ADMIN));

            $token = $this->dashboardLogin($account)->assertOk()->json('access_token');

            $this->bearer($token)->getJson("/api/v1/organizations/{$organization->id}")->assertOk();
        }
    }

    public function test_suspended_and_expired_organizations_block_login_and_tokens(): void
    {
        foreach ([OrganizationStatus::SUSPENDED, OrganizationStatus::EXPIRED] as $status) {
            $organization = Organization::factory()->create();
            $account = $this->makeDashboardAccount($this->makeOrgUser($organization, OrganizationRole::ORG_ADMIN));
            $token = $this->dashboardToken($account);

            $organization->update(['status' => $status]);

            $this->bearer($token)->getJson('/api/v1/corporate/me')->assertForbidden();
            $this->bearer($token)->getJson("/api/v1/organizations/{$organization->id}")->assertForbidden();
            $this->dashboardLogin($account)->assertForbidden();
        }
    }

    public function test_expired_organization_blocks_an_owner_with_no_membership_row(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->makeUser();
        $this->grantRole($owner, $organization, OrganizationRole::ORG_OWNER);
        $account = $this->makeDashboardAccount($owner);

        $this->dashboardLogin($account)->assertOk();

        $organization->update(['status' => OrganizationStatus::EXPIRED]);

        $this->dashboardLogin($account)->assertForbidden();
    }

    public function test_an_inaccessible_organization_is_blocked_while_another_stays_open(): void
    {
        $open = Organization::factory()->create();
        $expired = Organization::factory()->create(['status' => OrganizationStatus::EXPIRED]);
        $user = $this->makeUser();
        $this->grantRole($user, $open, OrganizationRole::ORG_OWNER);
        $this->grantRole($user, $expired, OrganizationRole::ORG_OWNER);
        $token = $this->dashboardToken($this->makeDashboardAccount($user));

        $this->bearer($token)->getJson('/api/v1/corporate/me')
            ->assertOk()
            ->assertJsonCount(1, 'organizations')
            ->assertJsonPath('organizations.0.organization.id', $open->id);

        $this->bearer($token)->getJson("/api/v1/organizations/{$open->id}")->assertOk();
        $this->bearer($token)->getJson("/api/v1/organizations/{$expired->id}")
            ->assertForbidden()
            ->assertExactJson(['message' => "This organization's contract has expired."]);
    }

    public function test_first_owner_with_no_membership_row_can_log_in(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->makeUser();
        $this->grantRole($owner, $organization, OrganizationRole::ORG_OWNER);

        $this->dashboardLogin($this->makeDashboardAccount($owner))
            ->assertOk()
            ->assertJsonPath('organizations.0.membership', null)
            ->assertJsonPath('organizations.0.roles', ['ORG_OWNER']);
    }

    public function test_revoking_the_role_or_disabling_the_account_blocks_existing_tokens(): void
    {
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_ADMIN);
        $token = $this->dashboardToken($account);
        $assignment = $account->user->organizationRoleAssignments()->sole();

        $assignment->update(['revoked_at' => now()]);
        $this->bearer($token)->getJson('/api/v1/corporate/me')->assertForbidden();

        $assignment->update(['revoked_at' => null]);
        $this->bearer($token)->getJson('/api/v1/corporate/me')->assertOk();

        $account->forceFill(['disabled_at' => now()])->save();
        $this->bearer($token)->getJson('/api/v1/corporate/me')
            ->assertForbidden()
            ->assertExactJson(['message' => 'This dashboard account has been disabled.']);
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_ADMIN);
        $token = $this->dashboardToken($account);

        $this->travel(13)->hours();

        $this->bearer($token)->getJson('/api/v1/corporate/me')->assertUnauthorized();
    }

    // Pivot staff

    public function test_pivot_super_admin_keeps_full_authority(): void
    {
        $organization = Organization::factory()->create();
        $staff = $this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN);
        $token = $this->dashboardLogin($this->makeDashboardAccount($staff))
            ->assertOk()
            ->assertJsonPath('pivot_role', 'PIVOT_SUPER_ADMIN')
            ->json('access_token');

        $this->bearer($token)->getJson('/api/v1/organizations')->assertOk();
        $this->bearer($token)->postJson("/api/v1/organizations/{$organization->id}/suspend", ['reason' => 'Test'])->assertOk();

        // Staff can still enter the suspended organization, to reactivate it.
        $this->bearer($token)->getJson("/api/v1/organizations/{$organization->id}")->assertOk();
        $this->bearer($token)->postJson("/api/v1/organizations/{$organization->id}/reactivate", ['reason' => 'Test'])->assertOk();
    }

    public function test_pivot_support_keeps_its_restricted_permissions(): void
    {
        $organization = Organization::factory()->create();
        $staff = $this->makeStaff(PivotRole::PIVOT_SUPPORT);
        $target = $this->makeMember($organization);
        $token = $this->dashboardLogin($this->makeDashboardAccount($staff))
            ->assertOk()
            ->assertJsonPath('pivot_role', 'PIVOT_SUPPORT')
            ->json('access_token');

        // Allowed.
        $this->bearer($token)->getJson('/api/v1/organizations')->assertOk();
        $this->bearer($token)->getJson("/api/v1/organizations/{$organization->id}/members")->assertOk();

        // Not allowed.
        $this->bearer($token)->postJson("/api/v1/organizations/{$organization->id}/suspend", ['reason' => 'Test'])->assertForbidden();
        $this->bearer($token)->deleteJson("/api/v1/organizations/{$organization->id}", ['reason' => 'Test'])->assertForbidden();
        $this->bearer($token)->postJson('/api/v1/organizations', [
            'name' => 'New Organization',
            'slug' => 'new-organization',
            'organization_type' => 'CORPORATE',
            'package_code' => 'STANDARD',
            'seat_limit' => 10,
            'contract_start_at' => now()->toDateString(),
            'contract_end_at' => now()->addYear()->toDateString(),
        ])->assertForbidden();
        $this->bearer($token)->postJson("/api/v1/organizations/{$organization->id}/roles", [
            'user_id' => $target->id,
            'role' => OrganizationRole::ORG_ANALYST->value,
        ])->assertForbidden();

        $this->assertSame(OrganizationStatus::ACTIVE, $organization->fresh()->status);
    }

    public function test_removing_the_pivot_role_blocks_existing_tokens(): void
    {
        $staff = $this->makeStaff(PivotRole::PIVOT_SUPPORT);
        $token = $this->dashboardToken($this->makeDashboardAccount($staff));

        $this->bearer($token)->getJson('/api/v1/corporate/me')->assertOk();

        $staff->forceFill(['pivot_role' => null])->save();

        $this->bearer($token)->getJson('/api/v1/corporate/me')->assertForbidden();
    }

    // Token isolation

    public function test_mobile_tokens_from_every_owner_model_are_rejected_by_dashboard_endpoints(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->makeOrgUser($organization, OrganizationRole::ORG_OWNER);
        $this->makeDashboardAccount($user);

        $emailLoginToken = $this->mobileLogin($user)->assertOk()->json('token');
        $socialLoginToken = $user->createToken('mobile')->plainTextToken;

        foreach ([$emailLoginToken, $socialLoginToken] as $token) {
            $this->bearer($token)->getJson('/api/v1/corporate/me')->assertUnauthorized();
            $this->bearer($token)->postJson('/api/v1/organization/auth/logout')->assertUnauthorized();
            $this->bearer($token)->getJson('/api/v1/organizations')->assertUnauthorized();
            $this->bearer($token)->getJson("/api/v1/organizations/{$organization->id}")->assertUnauthorized();
            $this->bearer($token)->getJson("/api/v1/organizations/{$organization->id}/members")->assertUnauthorized();
        }
    }

    public function test_pivot_staff_mobile_tokens_are_rejected_by_dashboard_endpoints(): void
    {
        $staff = $this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN);
        $token = $this->mobileLogin($staff)->assertOk()->json('token');

        $this->bearer($token)->getJson('/api/v1/organizations')->assertUnauthorized();
    }

    public function test_dashboard_tokens_are_rejected_by_mobile_endpoints(): void
    {
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_OWNER);
        $token = $this->dashboardToken($account);

        $this->bearer($token)->getJson('/api/v1/profile/me')->assertUnauthorized();
        $this->bearer($token)->getJson('/api/v1/user/current-user')->assertUnauthorized();
        $this->bearer($token)->getJson('/api/user')->assertUnauthorized();
        $this->bearer($token)->postJson('/api/v1/auth/logout')->assertUnauthorized();
        $this->bearer($token)->postJson('/api/v1/corporate/join/work-email')->assertUnauthorized();
        $this->bearer($token)->postJson('/api/v1/corporate/join/code', ['code' => 'X'])->assertUnauthorized();
        $this->bearer($token)->postJson('/api/v1/corporate/invitations/accept', ['token' => 'X'])->assertUnauthorized();
    }

    public function test_a_dashboard_guard_token_without_the_dashboard_ability_is_rejected(): void
    {
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_OWNER);
        $token = $account->createToken('something-else', ['other'])->plainTextToken;

        $this->bearer($token)->getJson('/api/v1/corporate/me')->assertUnauthorized();
    }

    public function test_requests_with_no_token_are_unauthorized(): void
    {
        $this->getJson('/api/v1/corporate/me')->assertUnauthorized();
        $this->postJson('/api/v1/organization/auth/logout')->assertUnauthorized();
        $this->getJson('/api/v1/organizations')->assertUnauthorized();
    }

    // Logout

    public function test_logout_revokes_only_the_current_dashboard_token(): void
    {
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_ADMIN);
        $current = $this->dashboardToken($account);
        $otherDevice = $this->dashboardToken($account);
        $mobile = $this->mobileLogin($account->user)->assertOk()->json('token');

        $this->bearer($current)->postJson('/api/v1/organization/auth/logout')
            ->assertOk()
            ->assertExactJson(['message' => 'Logged out successfully.']);

        $this->bearer($current)->getJson('/api/v1/corporate/me')->assertUnauthorized();
        $this->bearer($otherDevice)->getJson('/api/v1/corporate/me')->assertOk();
        $this->bearer($mobile)->getJson('/api/v1/user/current-user')->assertOk();
    }

    public function test_mobile_logout_does_not_revoke_dashboard_tokens(): void
    {
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_ADMIN);
        $dashboard = $this->dashboardToken($account);
        $mobile = $this->mobileLogin($account->user)->assertOk()->json('token');

        $this->bearer($mobile)->postJson('/api/v1/auth/logout')->assertOk();

        $this->bearer($mobile)->getJson('/api/v1/user/current-user')->assertUnauthorized();
        $this->bearer($dashboard)->getJson('/api/v1/corporate/me')->assertOk();
    }

    // Separate credentials

    public function test_mobile_password_does_not_work_on_the_dashboard(): void
    {
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_OWNER);

        $this->postJson('/api/v1/organization/auth/login', ['email' => $account->email, 'password' => 'password'])
            ->assertUnauthorized();
    }

    public function test_dashboard_password_does_not_work_on_mobile(): void
    {
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_OWNER);

        $this->postJson('/api/v1/auth/login', ['email' => $account->email, 'password' => $this->dashboardPassword])
            ->assertUnprocessable();
    }

    public function test_user_with_a_mobile_account_but_no_dashboard_account_cannot_log_in(): void
    {
        $user = $this->makeOrgUser(Organization::factory()->create(), OrganizationRole::ORG_OWNER);

        $this->postJson('/api/v1/organization/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnauthorized();
    }

    // Password reset

    public function test_password_reset_changes_only_the_dashboard_password_and_revokes_only_dashboard_tokens(): void
    {
        Mail::fake();
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_ADMIN);
        $mobilePasswordHash = $account->user->password;
        $dashboardToken = $this->dashboardToken($account);
        $mobileToken = $this->mobileLogin($account->user)->assertOk()->json('token');

        $this->postJson('/api/v1/organization/auth/forgot-password', ['email' => $account->email])->assertOk();

        $link = $this->sentPasswordMail($account->email);
        $this->assertFalse($link->isSetup);

        $this->resetPassword($account->email, $this->tokenFrom($link))->assertOk();

        $this->assertNotNull($account->fresh()->password_set_at);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $account->fresh()->password));
        $this->assertSame($mobilePasswordHash, $account->user->fresh()->password);

        $this->bearer($dashboardToken)->getJson('/api/v1/corporate/me')->assertUnauthorized();
        $this->bearer($mobileToken)->getJson('/api/v1/user/current-user')->assertOk();

        $this->dashboardLogin($account)->assertUnauthorized();
        $this->postJson('/api/v1/organization/auth/login', ['email' => $account->email, 'password' => self::NEW_PASSWORD])->assertOk();
        $this->mobileLogin($account->user)->assertOk();

        $this->assertDatabaseHas('organization_audit_logs', ['action' => AuditAction::DASHBOARD_PASSWORD_RESET->value]);
    }

    public function test_forgot_password_response_does_not_reveal_whether_the_email_exists(): void
    {
        Mail::fake();
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_ADMIN);

        $known = $this->postJson('/api/v1/organization/auth/forgot-password', ['email' => $account->email])->assertOk();
        $unknown = $this->postJson('/api/v1/organization/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();

        $this->assertSame($known->json(), $unknown->json());
        Mail::assertSentCount(1);
    }

    public function test_disabled_account_receives_no_link_and_cannot_reset(): void
    {
        Mail::fake();
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_ADMIN);

        $this->postJson('/api/v1/organization/auth/forgot-password', ['email' => $account->email])->assertOk();
        $token = $this->tokenFrom($this->sentPasswordMail($account->email));

        $account->forceFill(['disabled_at' => now()])->save();

        $this->resetPassword($account->email, $token)->assertUnprocessable();

        $this->travel(2)->minutes();
        $this->postJson('/api/v1/organization/auth/forgot-password', ['email' => $account->email])->assertOk();
        Mail::assertSentCount(1);
    }

    public function test_reset_rejects_bad_tokens_unknown_emails_and_reused_links_the_same_way(): void
    {
        Mail::fake();
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_ADMIN);
        $message = ['message' => 'This password link is invalid or has expired.'];

        $this->resetPassword($account->email, 'not-a-real-token')->assertUnprocessable()->assertExactJson($message);
        $this->resetPassword('nobody@example.com', 'not-a-real-token')->assertUnprocessable()->assertExactJson($message);

        $this->postJson('/api/v1/organization/auth/forgot-password', ['email' => $account->email])->assertOk();
        $token = $this->tokenFrom($this->sentPasswordMail($account->email));

        $this->resetPassword($account->email, $token)->assertOk();
        $this->resetPassword($account->email, $token)->assertUnprocessable()->assertExactJson($message);
    }

    public function test_reset_link_expires_after_sixty_minutes(): void
    {
        Mail::fake();
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_ADMIN);

        $this->postJson('/api/v1/organization/auth/forgot-password', ['email' => $account->email])->assertOk();
        $token = $this->tokenFrom($this->sentPasswordMail($account->email));

        $this->travel(61)->minutes();

        $this->resetPassword($account->email, $token)->assertUnprocessable();
    }

    public function test_reset_enforces_the_password_policy(): void
    {
        $this->postJson('/api/v1/organization/auth/reset-password', [
            'email' => 'someone@example.com',
            'token' => 'token',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        Mail::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/organization/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();
        }

        $this->postJson('/api/v1/organization/auth/forgot-password', ['email' => 'nobody@example.com'])->assertTooManyRequests();
    }

    public function test_dashboard_reset_does_not_touch_mobile_otps(): void
    {
        Mail::fake();
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_ADMIN);
        DB::table('password_reset_tokens')->insert([
            'email' => $account->email,
            'otp' => '123456',
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        $this->postJson('/api/v1/organization/auth/forgot-password', ['email' => $account->email])->assertOk();
        $this->resetPassword($account->email, $this->tokenFrom($this->sentPasswordMail($account->email)))->assertOk();

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $account->email, 'otp' => '123456']);
    }

    // First-time setup

    public function test_first_time_setup_activates_the_account(): void
    {
        Mail::fake();
        $user = $this->makeOrgUser(Organization::factory()->create(), OrganizationRole::ORG_OWNER);
        $account = $this->makeDashboardAccount($user, withPassword: false);

        $this->postJson('/api/v1/organization/auth/forgot-password', ['email' => $account->email])->assertOk();

        $link = $this->sentPasswordMail($account->email);
        $this->assertTrue($link->isSetup);
        $this->assertStringStartsWith('https://dashboard.pivot.test/set-password?', $link->url);
        $this->assertStringContainsString('type=setup', $link->url);

        $this->resetPassword($account->email, $this->tokenFrom($link))->assertOk();

        $this->assertNotNull($account->fresh()->password_set_at);
        $this->assertDatabaseHas('organization_audit_logs', ['action' => AuditAction::DASHBOARD_PASSWORD_SET->value]);

        $this->postJson('/api/v1/organization/auth/login', ['email' => $account->email, 'password' => self::NEW_PASSWORD])
            ->assertOk()
            ->assertJsonPath('dashboard_account.status', 'active');
    }

    public function test_the_password_email_really_sends_and_contains_the_link(): void
    {
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_ADMIN);

        $this->postJson('/api/v1/organization/auth/forgot-password', ['email' => $account->email])->assertOk();

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);

        $body = $messages->first()->toString();
        $this->assertStringContainsString($account->email, $body);
        $this->assertStringContainsString('Reset your Pivot Organization Dashboard password', $body);
        $this->assertStringContainsString('dashboard.pivot.test', quoted_printable_decode($body));
    }

    public function test_setup_link_lasts_72_hours(): void
    {
        Mail::fake();
        $first = $this->makeDashboardAccount(
            $this->makeOrgUser(Organization::factory()->create(), OrganizationRole::ORG_OWNER),
            withPassword: false,
        );
        $second = $this->makeDashboardAccount(
            $this->makeOrgUser(Organization::factory()->create(), OrganizationRole::ORG_OWNER),
            withPassword: false,
        );

        $this->postJson('/api/v1/organization/auth/forgot-password', ['email' => $first->email])->assertOk();
        $this->postJson('/api/v1/organization/auth/forgot-password', ['email' => $second->email])->assertOk();
        $firstToken = $this->tokenFrom($this->sentPasswordMail($first->email));
        $secondToken = $this->tokenFrom($this->sentPasswordMail($second->email));

        $this->travel(71)->hours();
        $this->resetPassword($first->email, $firstToken)->assertOk();

        $this->travel(2)->hours();
        $this->resetPassword($second->email, $secondToken)->assertUnprocessable();
    }

    // Existing mobile behavior

    public function test_mobile_login_still_issues_a_token_owned_by_the_mobile_model(): void
    {
        $user = $this->makeUser();

        $response = $this->mobileLogin($user)
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);

        $token = PersonalAccessToken::findToken($response->json('token'));
        $this->assertSame('mobile', $token->name);
        $this->assertSame(User::class, $token->tokenable_type);
    }

    public function test_mobile_tokens_from_both_owner_models_still_work_on_mobile_endpoints(): void
    {
        $user = $this->makeUser();
        $emailLoginToken = $this->mobileLogin($user)->assertOk()->json('token');
        $socialLoginToken = $user->createToken('mobile')->plainTextToken;

        $legacyToken = Users::findOrFail($user->id)->createToken('mobile')->plainTextToken;
        foreach ([$emailLoginToken, $socialLoginToken, $legacyToken] as $token) {
            $this->bearer($token)->getJson('/api/v1/user/current-user')->assertOk();
            $this->bearer($token)->getJson('/api/v1/profile/me')->assertSuccessful();
        }
    }

    public function test_mobile_login_failure_is_unchanged(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Invalid email or password.');
    }

    public function test_mobile_password_reset_does_not_touch_the_dashboard(): void
    {
        $account = $this->dashboardAccountFor(OrganizationRole::ORG_ADMIN);
        $dashboardHash = $account->password;
        $dashboardToken = $this->dashboardToken($account);

        Users::where('id', $account->user_id)->update(['password' => Hash::make('changed-mobile-pass')]);
        Users::find($account->user_id)->tokens()->delete();

        $this->assertSame($dashboardHash, $account->fresh()->password);
        $this->bearer($dashboardToken)->getJson('/api/v1/corporate/me')->assertOk();
    }

    // Mobile joining flows, with real mobile tokens

    public static function mobileTokenOwners(): array
    {
        return ['canonical' => [User::class], 'legacy' => [Users::class]];
    }

    #[DataProvider('mobileTokenOwners')]
    public function test_work_email_join_works_with_a_real_mobile_token(string $owner): void
    {
        $organization = Organization::factory()->create();
        OrganizationDomain::factory()->create([
            'organization_id' => $organization->id,
            'domain' => 'acme.com',
            'is_primary' => true,
        ]);
        $user = $this->makeUser();
        $user->forceFill(['email' => 'alice@acme.com'])->save();
        $token = $owner === Users::class
            ? Users::findOrFail($user->id)->createToken('mobile')->plainTextToken
            : $this->mobileLogin($user)->assertOk()->json('token');

        $this->bearer($token)->postJson('/api/v1/corporate/join/work-email')
            ->assertCreated()
            ->assertJsonPath('join_status', 'JOINED')
            ->assertJsonPath('organization.id', $organization->id);
    }

    public function test_invitation_flow_uses_a_dashboard_token_to_invite_and_a_mobile_token_to_accept(): void
    {
        Mail::fake();
        $organization = Organization::factory()->create();
        $staffToken = $this->dashboardToken($this->makeDashboardAccount($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN)));
        $invitee = $this->makeUser();

        $invitationToken = $this->bearer($staffToken)
            ->postJson("/api/v1/organizations/{$organization->id}/invitations", ['email' => $invitee->email])
            ->assertCreated()
            ->json('one_time_token');

        $this->assertNotEmpty($invitationToken);

        // A dashboard token cannot accept on the employee's behalf.
        $this->bearer($staffToken)
            ->postJson('/api/v1/corporate/invitations/accept', ['token' => $invitationToken])
            ->assertUnauthorized();

        $mobileToken = $this->mobileLogin($invitee)->assertOk()->json('token');

        $this->bearer($mobileToken)
            ->postJson('/api/v1/corporate/invitations/accept', ['token' => $invitationToken])
            ->assertCreated()
            ->assertJsonPath('join_status', 'JOINED');
    }

    private function dashboardAccountFor(OrganizationRole $role): OrganizationDashboardAccount
    {
        return $this->makeDashboardAccount($this->makeOrgUser(Organization::factory()->create(), $role));
    }

    private function dashboardLogin(OrganizationDashboardAccount $account): TestResponse
    {
        return $this->freshRequest()->postJson('/api/v1/organization/auth/login', [
            'email' => $account->email,
            'password' => $this->dashboardPassword,
        ]);
    }

    private function dashboardToken(OrganizationDashboardAccount $account): string
    {
        return $this->dashboardLogin($account)->assertOk()->json('access_token');
    }

    private function mobileLogin(User $user): TestResponse
    {
        return $this->freshRequest()->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);
    }

    private function resetPassword(string $email, string $token): TestResponse
    {
        return $this->freshRequest()->postJson('/api/v1/organization/auth/reset-password', [
            'email' => $email,
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);
    }

    private function bearer(string $token): static
    {
        return $this->freshRequest()->withToken($token);
    }

    // In production each request builds new guards. Tests share one app, so reset them to match.
    private function freshRequest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders();
    }

    private function sentPasswordMail(string $email): OrganizationDashboardPasswordMail
    {
        $sent = Mail::sent(OrganizationDashboardPasswordMail::class, fn ($mail) => $mail->hasTo($email));

        $this->assertNotEmpty($sent, "No dashboard password email was sent to {$email}.");

        return $sent->last();
    }

    private function tokenFrom(OrganizationDashboardPasswordMail $mail): string
    {
        parse_str((string) parse_url($mail->url, PHP_URL_QUERY), $query);

        return $query['token'];
    }
}
