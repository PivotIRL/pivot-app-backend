<?php

namespace Tests\Feature\Organization;

use App\Organization\Enums\OrganizationRole;
use App\Organization\Enums\OrganizationStatus;
use App\Organization\Enums\PivotRole;
use App\Organization\Mail\OrganizationDashboardPasswordMail;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationDashboardAccount;
use App\Organization\Services\OrganizationDashboardProvisioningService;
use App\Organization\Services\PivotRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Feature\Organization\Concerns\BuildsOrganizationActors;
use Tests\TestCase;

class OrganizationDashboardProvisioningTest extends TestCase
{
    use BuildsOrganizationActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_assigning_a_dashboard_role_provisions_an_account_and_sends_a_setup_link(): void
    {
        $organization = Organization::factory()->create();
        $target = $this->makeMember($organization);
        $mobilePasswordHash = $target->password;
        $this->actingAsDashboard($this->makeOrgUser($organization, OrganizationRole::ORG_OWNER));

        $this->assignRole($organization, $target->id, OrganizationRole::ORG_ADMIN)->assertCreated();

        $account = OrganizationDashboardAccount::where('user_id', $target->id)->sole();
        $this->assertNull($account->password);
        $this->assertSame(strtolower($target->email), $account->email);
        $this->assertSame($mobilePasswordHash, $target->fresh()->password);

        Mail::assertSent(OrganizationDashboardPasswordMail::class, fn ($mail) => $mail->hasTo($account->email) && $mail->isSetup);
    }

    public function test_first_owner_assigned_by_staff_is_provisioned_without_a_membership(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->makeUser();
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->assignRole($organization, $owner->id, OrganizationRole::ORG_OWNER)->assertCreated();

        $this->assertDatabaseHas('organization_dashboard_accounts', ['user_id' => $owner->id]);
        Mail::assertSentCount(1);
    }

    public function test_assigning_org_member_does_not_provision_an_account(): void
    {
        $organization = Organization::factory()->create();
        $target = $this->makeMember($organization);
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->assignRole($organization, $target->id, OrganizationRole::ORG_MEMBER)->assertCreated();

        $this->assertDatabaseMissing('organization_dashboard_accounts', ['user_id' => $target->id]);
        Mail::assertNothingSent();
    }

    public function test_a_user_who_already_set_a_password_keeps_it_and_gets_no_email(): void
    {
        $organization = Organization::factory()->create();
        $target = $this->makeMember($organization);
        $account = $this->makeDashboardAccount($target);
        $hash = $account->password;
        $this->actingAsDashboard($this->makeOrgUser($organization, OrganizationRole::ORG_OWNER));

        $this->assignRole($organization, $target->id, OrganizationRole::ORG_ANALYST)->assertCreated();

        $this->assertSame($hash, $account->fresh()->password);
        Mail::assertNothingSent();
    }

    public function test_accepting_an_admin_invitation_provisions_an_account(): void
    {
        $organization = Organization::factory()->create();
        $invitee = $this->makeUser();
        $this->actingAsDashboard($this->makeOrgUser($organization, OrganizationRole::ORG_OWNER));

        $token = $this->postJson("/api/v1/organizations/{$organization->id}/invitations", [
            'email' => $invitee->email,
            'role' => OrganizationRole::ORG_ADMIN->value,
        ])->assertCreated()->json('one_time_token');

        Mail::assertNothingSent();

        Sanctum::actingAs($invitee);
        $this->postJson('/api/v1/corporate/invitations/accept', ['token' => $token])->assertCreated();

        $this->assertDatabaseHas('organization_dashboard_accounts', ['user_id' => $invitee->id]);
        Mail::assertSent(OrganizationDashboardPasswordMail::class, fn ($mail) => $mail->hasTo(strtolower($invitee->email)));
    }

    public function test_accepting_a_member_invitation_does_not_provision_an_account(): void
    {
        $organization = Organization::factory()->create();
        $invitee = $this->makeUser();
        $this->actingAsDashboard($this->makeOrgUser($organization, OrganizationRole::ORG_OWNER));

        $token = $this->postJson("/api/v1/organizations/{$organization->id}/invitations", ['email' => $invitee->email])
            ->assertCreated()
            ->json('one_time_token');

        Sanctum::actingAs($invitee);
        $this->postJson('/api/v1/corporate/invitations/accept', ['token' => $token])->assertCreated();

        $this->assertDatabaseMissing('organization_dashboard_accounts', ['user_id' => $invitee->id]);
        Mail::assertNothingSent();
    }

    public function test_granting_a_pivot_role_provisions_an_account(): void
    {
        $user = $this->makeUser();

        app(PivotRoleService::class)->grant($user, PivotRole::PIVOT_SUPPORT);

        $this->assertDatabaseHas('organization_dashboard_accounts', ['user_id' => $user->id]);
        Mail::assertSentCount(1);
    }

    public function test_setup_email_waits_for_the_transaction_to_commit(): void
    {
        $user = $this->makeUser();
        $provisioning = app(OrganizationDashboardProvisioningService::class);

        DB::transaction(function () use ($provisioning, $user) {
            $provisioning->provision($user);

            Mail::assertNothingSent();
        });

        Mail::assertSentCount(1);
    }

    public function test_no_email_is_sent_when_the_transaction_rolls_back(): void
    {
        $user = $this->makeUser();
        $provisioning = app(OrganizationDashboardProvisioningService::class);

        try {
            DB::transaction(function () use ($provisioning, $user) {
                $provisioning->provision($user);

                throw new RuntimeException('Rolled back.');
            });
        } catch (RuntimeException) {
        }

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('organization_dashboard_accounts', ['user_id' => $user->id]);
    }

    public function test_an_email_used_by_another_account_does_not_fail_the_role_assignment(): void
    {
        $organization = Organization::factory()->create();
        $target = $this->makeMember($organization);
        $other = $this->makeUser();
        $this->makeDashboardAccount($other)->forceFill(['email' => strtolower($target->email)])->save();
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->assignRole($organization, $target->id, OrganizationRole::ORG_ADMIN)->assertCreated();

        $this->assertDatabaseMissing('organization_dashboard_accounts', ['user_id' => $target->id]);
        $this->assertDatabaseHas('organization_role_assignments', ['user_id' => $target->id, 'role' => 'ORG_ADMIN']);
    }

    public function test_a_mail_failure_does_not_fail_the_role_assignment(): void
    {
        config(['organization.dashboard.url' => null]);
        $organization = Organization::factory()->create();
        $target = $this->makeMember($organization);
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->assignRole($organization, $target->id, OrganizationRole::ORG_ADMIN)->assertCreated();

        $this->assertDatabaseHas('organization_dashboard_accounts', ['user_id' => $target->id]);
    }

    public function test_command_provisions_existing_eligible_users_only(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->makeOrgUser($organization, OrganizationRole::ORG_ADMIN);
        $staff = $this->makeStaff(PivotRole::PIVOT_SUPPORT);
        $member = $this->makeOrgUser($organization, OrganizationRole::ORG_MEMBER);
        $noAccess = $this->makeOrgUser(
            Organization::factory()->create(['status' => OrganizationStatus::EXPIRED]),
            OrganizationRole::ORG_OWNER,
        );

        $this->artisan('organization:dashboard-provision')->assertSuccessful();

        $this->assertDatabaseHas('organization_dashboard_accounts', ['user_id' => $admin->id]);
        $this->assertDatabaseHas('organization_dashboard_accounts', ['user_id' => $staff->id]);
        $this->assertDatabaseMissing('organization_dashboard_accounts', ['user_id' => $member->id]);
        $this->assertDatabaseMissing('organization_dashboard_accounts', ['user_id' => $noAccess->id]);
        Mail::assertSentCount(2);
    }

    public function test_command_dry_run_changes_nothing(): void
    {
        $this->makeOrgUser(Organization::factory()->create(), OrganizationRole::ORG_ADMIN);

        $this->artisan('organization:dashboard-provision', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseCount('organization_dashboard_accounts', 0);
        Mail::assertNothingSent();
    }

    public function test_command_is_safe_to_run_again_and_only_resends_when_asked(): void
    {
        $admin = $this->makeOrgUser(Organization::factory()->create(), OrganizationRole::ORG_ADMIN);

        $this->artisan('organization:dashboard-provision')->assertSuccessful();
        Mail::assertSentCount(1);

        $this->travel(2)->minutes();

        $this->artisan('organization:dashboard-provision')->assertSuccessful();
        Mail::assertSentCount(1);

        $this->artisan('organization:dashboard-provision', ['--resend' => true])->assertSuccessful();
        Mail::assertSentCount(2);

        $this->assertDatabaseCount('organization_dashboard_accounts', 1);
        $this->assertDatabaseHas('organization_dashboard_accounts', ['user_id' => $admin->id]);
    }

    public function test_command_refuses_to_run_without_a_dashboard_url(): void
    {
        config(['organization.dashboard.url' => null]);
        $this->makeOrgUser(Organization::factory()->create(), OrganizationRole::ORG_ADMIN);

        $this->artisan('organization:dashboard-provision')->assertFailed();

        $this->assertDatabaseCount('organization_dashboard_accounts', 0);
    }

    private function assignRole(Organization $organization, int $userId, OrganizationRole $role)
    {
        return $this->postJson("/api/v1/organizations/{$organization->id}/roles", [
            'user_id' => $userId,
            'role' => $role->value,
        ]);
    }
}
