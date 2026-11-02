<?php

namespace Tests\Feature\Organization;

use App\Models\User;
use App\Organization\Enums\AuditAction;
use App\Organization\Enums\CorporateJoinStatus;
use App\Organization\Enums\InvitationStatus;
use App\Organization\Enums\MembershipJoinMethod;
use App\Organization\Enums\MembershipStatus;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Enums\OrganizationStatus;
use App\Organization\Enums\PivotRole;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationDomain;
use App\Organization\Models\OrganizationInvitation;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Models\OrganizationRedemptionCode;
use App\Organization\Models\OrganizationRoleAssignment;
use App\Organization\Services\CorporateOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Organization\Concerns\BuildsOrganizationActors;
use Tests\TestCase;

/**
 * B4 onboarding flows: work email, organization code, invitation and the registration join status.
 */
class CorporateOnboardingTest extends TestCase
{
    use BuildsOrganizationActors, RefreshDatabase;

    // Work email

    public function test_work_email_joins_the_organization_that_verified_the_domain(): void
    {
        $organization = $this->organizationWithDomain('acme.com');
        $user = $this->userWithEmail('  Alice@ACME.com ');

        $this->joinByWorkEmail($user)
            ->assertCreated()
            ->assertJsonPath('join_status', 'JOINED')
            ->assertJsonPath('organization.id', $organization->id)
            ->assertJsonPath('membership.status', 'active')
            ->assertJsonPath('membership.joined_via', 'work_email');

        $this->assertSame(1, $this->activeCount($organization));
        $this->assertDatabaseHas('organization_audit_logs', [
            'organization_id' => $organization->id,
            'action' => AuditAction::MEMBERSHIP_ACTIVATED->value,
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_work_email_join_is_idempotent(): void
    {
        $organization = $this->organizationWithDomain('acme.com');
        $user = $this->userWithEmail('alice@acme.com');

        $this->joinByWorkEmail($user)->assertCreated();
        $this->joinByWorkEmail($user)->assertOk()->assertJsonPath('join_status', 'ALREADY_MEMBER');

        $this->assertSame(1, OrganizationMembership::where('user_id', $user->id)->count());
        $this->assertSame(1, $this->activeCount($organization));
    }

    public function test_subdomains_match_only_when_the_domain_includes_them(): void
    {
        $organization = $this->organizationWithDomain('acme.com', includeSubdomains: true);
        $this->organizationWithDomain('globex.com');

        $this->joinByWorkEmail($this->userWithEmail('bob@eng.acme.com'))
            ->assertCreated()
            ->assertJsonPath('organization.id', $organization->id);

        $this->joinByWorkEmail($this->userWithEmail('carol@eng.globex.com'))
            ->assertNotFound()
            ->assertJsonPath('join_status', 'DOMAIN_NOT_RECOGNIZED');
    }

    public function test_pending_domains_are_never_used_to_join(): void
    {
        $this->organizationWithDomain('acme.com', status: 'pending');

        $this->joinByWorkEmail($this->userWithEmail('alice@acme.com'))
            ->assertNotFound()
            ->assertJsonPath('join_status', 'DOMAIN_NOT_RECOGNIZED');
    }

    public function test_join_is_refused_when_the_seat_limit_is_reached(): void
    {
        $organization = $this->organizationWithDomain('acme.com', seatLimit: 1);
        $this->joinByWorkEmail($this->userWithEmail('first@acme.com'))->assertCreated();

        $this->joinByWorkEmail($this->userWithEmail('second@acme.com'))
            ->assertStatus(409)
            ->assertJsonPath('join_status', 'SEAT_LIMIT_REACHED');

        $this->assertSame(1, $this->activeCount($organization));
    }

    public function test_join_checks_organization_status_and_contract_dates(): void
    {
        $cases = [
            'ORGANIZATION_SUSPENDED' => ['status' => OrganizationStatus::SUSPENDED],
            'CONTRACT_EXPIRED' => ['contract_end_at' => now()->subMinute()],
            'ORGANIZATION_NOT_ACTIVE' => ['status' => OrganizationStatus::PENDING, 'contract_start_at' => now()->addWeek()],
        ];

        foreach ($cases as $expected => $attributes) {
            $domain = strtolower($expected).'.com';
            $this->organizationWithDomain($domain)->update($attributes);

            $this->joinByWorkEmail($this->userWithEmail("person@{$domain}"))
                ->assertStatus(409)
                ->assertJsonPath('join_status', $expected);
        }

        $this->assertSame(0, OrganizationMembership::count());
    }

    public function test_user_active_in_another_organization_cannot_join_a_second_one(): void
    {
        $this->organizationWithDomain('acme.com');
        $user = $this->userWithEmail('alice@acme.com');
        $this->makeMember(Organization::factory()->create(), $user);

        $this->joinByWorkEmail($user)
            ->assertStatus(409)
            ->assertJsonPath('join_status', 'ALREADY_MEMBER_OF_OTHER_ORGANIZATION');
    }

    public function test_work_email_links_the_existing_account_and_never_creates_a_user(): void
    {
        $this->organizationWithDomain('acme.com');
        $user = $this->userWithEmail('alice@acme.com');
        $users = User::count();

        $this->joinByWorkEmail($user)->assertCreated();

        $this->assertSame($users, User::count());
        $this->assertSame($user->id, OrganizationMembership::sole()->user_id);
    }

    // Organization code

    public function test_code_redemption_creates_the_membership_and_counts_the_use(): void
    {
        $organization = Organization::factory()->create();
        $code = $this->code($organization, 'PIVOT-ABC123', ['max_uses' => 5]);
        $user = $this->makeUser();

        // Codes are matched case-insensitively and trimmed.
        $this->redeem($user, '  pivot-abc123 ')
            ->assertCreated()
            ->assertJsonPath('join_status', 'JOINED')
            ->assertJsonPath('membership.joined_via', 'code');

        $code->refresh();
        $this->assertSame(1, $code->uses_count);
        $this->assertNotNull($code->last_used_at);

        // A retry returns the same membership and does not use the code again.
        $this->redeem($user, 'PIVOT-ABC123')->assertOk()->assertJsonPath('join_status', 'ALREADY_MEMBER');
        $this->assertSame(1, $code->fresh()->uses_count);
    }

    public function test_code_use_limit_is_enforced(): void
    {
        $organization = Organization::factory()->create();
        $code = $this->code($organization, 'PIVOT-ONCE', ['max_uses' => 1]);
        $first = $this->makeUser();

        $this->redeem($first, 'PIVOT-ONCE')->assertCreated();
        $this->redeem($this->makeUser(), 'PIVOT-ONCE')
            ->assertStatus(409)
            ->assertJsonPath('join_status', 'CODE_LIMIT_REACHED');

        // The member who already used it can still retry safely.
        $this->redeem($first, 'PIVOT-ONCE')->assertOk()->assertJsonPath('join_status', 'ALREADY_MEMBER');
        $this->assertSame(1, $code->fresh()->uses_count);
    }

    public function test_expired_not_started_inactive_and_unknown_codes_are_refused(): void
    {
        $organization = Organization::factory()->create();
        $this->code($organization, 'PIVOT-EXPIRED', ['expires_at' => now()->subDay()]);
        $this->code($organization, 'PIVOT-LATER', ['starts_at' => now()->addDay()]);
        $this->code($organization, 'PIVOT-OFF', ['is_active' => false]);
        $user = $this->makeUser();

        $this->redeem($user, 'PIVOT-EXPIRED')->assertStatus(409)->assertJsonPath('join_status', 'CODE_EXPIRED');
        $this->redeem($user, 'PIVOT-LATER')->assertStatus(409)->assertJsonPath('join_status', 'CODE_NOT_STARTED');
        $this->redeem($user, 'PIVOT-OFF')->assertNotFound()->assertJsonPath('join_status', 'INVALID_CODE');
        $this->redeem($user, 'PIVOT-NOPE')->assertNotFound()->assertJsonPath('join_status', 'INVALID_CODE');

        $this->assertSame(0, OrganizationMembership::count());
    }

    public function test_code_for_a_suspended_organization_is_refused_without_using_the_code(): void
    {
        $organization = Organization::factory()->suspended()->create();
        $code = $this->code($organization, 'PIVOT-SUSP');

        $this->redeem($this->makeUser(), 'PIVOT-SUSP')
            ->assertStatus(409)
            ->assertJsonPath('join_status', 'ORGANIZATION_SUSPENDED');

        $this->assertSame(0, $code->fresh()->uses_count);
    }

    public function test_a_failed_seat_allocation_does_not_use_the_code(): void
    {
        $organization = Organization::factory()->create(['seat_limit' => 1]);
        $this->makeMember($organization);
        $code = $this->code($organization, 'PIVOT-FULL');

        $this->redeem($this->makeUser(), 'PIVOT-FULL')->assertStatus(409)->assertJsonPath('join_status', 'SEAT_LIMIT_REACHED');

        $this->assertSame(0, $code->fresh()->uses_count);
    }

    // Invitations

    public function test_invited_admin_accepts_and_gets_a_seat_and_the_role(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->makeOrgUser($organization, OrganizationRole::ORG_OWNER);
        Sanctum::actingAs($owner);

        $response = $this->postJson($this->url($organization, 'invitations'), ['email' => 'New.Admin@Acme.com', 'role' => 'ORG_ADMIN'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'new.admin@acme.com')
            ->assertJsonPath('data.status', 'pending');

        $token = $response->json('one_time_token');
        $invitation = OrganizationInvitation::sole();
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);

        $invitee = $this->userWithEmail('new.admin@acme.com');
        $this->accept($invitee, $token)
            ->assertCreated()
            ->assertJsonPath('join_status', 'JOINED')
            ->assertJsonPath('membership.joined_via', 'invitation');

        $invitation->refresh();
        $this->assertSame(InvitationStatus::ACCEPTED, $invitation->status);
        $this->assertSame($invitee->id, $invitation->accepted_by_user_id);
        $this->assertTrue(OrganizationRoleAssignment::where('user_id', $invitee->id)->where('role', 'ORG_ADMIN')->whereNull('revoked_at')->exists());

        // Accepting again is safe.
        $this->accept($invitee, $token)->assertOk()->assertJsonPath('join_status', 'ALREADY_MEMBER');
    }

    public function test_invitation_is_bound_to_the_invited_email_and_expiry(): void
    {
        $organization = Organization::factory()->create();
        $token = $this->invite($organization, 'alice@acme.com');

        $this->accept($this->userWithEmail('mallory@acme.com'), $token)
            ->assertForbidden()
            ->assertJsonPath('join_status', 'INVITATION_EMAIL_MISMATCH');

        $this->travel(8)->days();
        $this->accept($this->userWithEmail('alice@acme.com'), $token)
            ->assertStatus(409)
            ->assertJsonPath('join_status', 'INVITATION_EXPIRED');

        $this->accept($this->makeUser(), 'not-a-token')->assertNotFound()->assertJsonPath('join_status', 'INVALID_INVITATION');
    }

    public function test_reinviting_replaces_the_old_token_and_revoking_stops_it(): void
    {
        $organization = Organization::factory()->create();
        $invitee = $this->userWithEmail('alice@acme.com');

        $oldToken = $this->invite($organization, 'alice@acme.com');
        $this->assertSame(MembershipStatus::INVITED, OrganizationMembership::sole()->status);

        $newToken = $this->invite($organization, 'alice@acme.com');
        $this->accept($invitee, $oldToken)->assertNotFound()->assertJsonPath('join_status', 'INVALID_INVITATION');

        $owner = $this->makeOrgUser($organization, OrganizationRole::ORG_OWNER);
        Sanctum::actingAs($owner);
        $pending = OrganizationInvitation::where('status', 'pending')->sole();
        $this->deleteJson($this->url($organization, "invitations/{$pending->id}"))->assertOk()->assertJsonPath('status', 'revoked');

        $this->accept($invitee, $newToken)->assertNotFound();
        $membership = OrganizationMembership::where('user_id', $invitee->id)->sole();
        $this->assertSame(MembershipStatus::DEACTIVATED, $membership->status);
        $this->assertSame(OrganizationMembership::REASON_INVITATION_REVOKED, $membership->deactivation_reason);
    }

    public function test_admin_cannot_invite_a_role_at_or_above_their_own(): void
    {
        $organization = Organization::factory()->create();
        Sanctum::actingAs($this->makeOrgUser($organization, OrganizationRole::ORG_ADMIN));

        $this->postJson($this->url($organization, 'invitations'), ['email' => 'x@acme.com', 'role' => 'ORG_OWNER'])->assertForbidden();
        $this->postJson($this->url($organization, 'invitations'), ['email' => 'x@acme.com', 'role' => 'ORG_ADMIN'])->assertForbidden();
        $this->postJson($this->url($organization, 'invitations'), ['email' => 'x@acme.com'])->assertCreated();
    }

    public function test_active_members_cannot_be_invited_again(): void
    {
        $organization = Organization::factory()->create();
        $member = $this->makeMember($organization);
        Sanctum::actingAs($this->makeOrgUser($organization, OrganizationRole::ORG_OWNER));

        $this->postJson($this->url($organization, 'invitations'), ['email' => strtoupper($member->email)])->assertStatus(409);
    }

    // Registration

    public function test_registration_join_never_fails_and_reports_the_status(): void
    {
        $organization = $this->organizationWithDomain('acme.com', seatLimit: 1);
        $onboarding = app(CorporateOnboardingService::class);

        $joined = $onboarding->joinOnRegistration($this->userWithEmail('first@acme.com')->id);
        $noSeat = $onboarding->joinOnRegistration($this->userWithEmail('second@acme.com')->id);
        $personal = $onboarding->joinOnRegistration($this->userWithEmail('someone@gmail.com')->id);

        $this->assertSame(CorporateJoinStatus::JOINED, $joined->status);
        $this->assertSame(MembershipJoinMethod::WORK_EMAIL, $joined->membership->joined_via);
        $this->assertSame(CorporateJoinStatus::SEAT_LIMIT_REACHED, $noSeat->status);
        $this->assertNull($noSeat->membership);
        $this->assertSame(CorporateJoinStatus::DOMAIN_NOT_RECOGNIZED, $personal->status);
        $this->assertSame(1, $this->activeCount($organization));
    }

    // Helpers

    private function organizationWithDomain(
        string $domain,
        int $seatLimit = 50,
        bool $includeSubdomains = false,
        string $status = 'verified',
    ): Organization {
        $organization = Organization::factory()->create(['seat_limit' => $seatLimit]);

        OrganizationDomain::factory()->create([
            'organization_id' => $organization->id,
            'domain' => $domain,
            'status' => $status,
            'include_subdomains' => $includeSubdomains,
            'verified_at' => $status === 'verified' ? now() : null,
        ]);

        return $organization;
    }

    private function userWithEmail(string $email): User
    {
        return User::create(['name' => 'Employee', 'email' => $email, 'password' => 'password']);
    }

    private function code(Organization $organization, string $plain, array $attributes = []): OrganizationRedemptionCode
    {
        return OrganizationRedemptionCode::factory()->create([
            'organization_id' => $organization->id,
            'code_hash' => hash('sha256', $plain),
            'code_prefix' => substr($plain, 0, 8),
            'starts_at' => null,
            ...$attributes,
        ]);
    }

    private function invite(Organization $organization, string $email): string
    {
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        return $this->postJson($this->url($organization, 'invitations'), ['email' => $email])
            ->assertCreated()
            ->json('one_time_token');
    }

    private function joinByWorkEmail(User $user): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/corporate/join/work-email');
    }

    private function redeem(User $user, string $code): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/corporate/join/code', ['code' => $code]);
    }

    private function accept(User $user, string $token): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/corporate/invitations/accept', ['token' => $token]);
    }

    private function url(Organization $organization, string $path): string
    {
        return "/api/v1/organizations/{$organization->id}/{$path}";
    }

    private function activeCount(Organization $organization): int
    {
        return $organization->memberships()->where('status', MembershipStatus::ACTIVE)->count();
    }
}
