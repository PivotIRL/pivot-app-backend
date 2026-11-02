<?php

namespace Tests\Feature\Organization;

use App\Organization\Enums\AuditAction;
use App\Organization\Enums\AuditActorType;
use App\Organization\Enums\MembershipJoinMethod;
use App\Organization\Enums\MembershipStatus;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Enums\OrganizationStatus;
use App\Organization\Enums\PivotRole;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationAuditLog;
use App\Organization\Models\OrganizationDomain;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Models\OrganizationRoleAssignment;
use App\Organization\Services\SeatReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Organization\Concerns\BuildsOrganizationActors;
use Tests\TestCase;

/**
 * B4 membership lifecycle (suspend / restore / deactivate), offboarding and seat reconciliation.
 */
class MembershipLifecycleTest extends TestCase
{
    use BuildsOrganizationActors, RefreshDatabase;

    public function test_suspending_frees_the_seat_and_restoring_takes_it_again(): void
    {
        $organization = Organization::factory()->create(['seat_limit' => 1]);
        $member = $this->makeMember($organization);
        $membership = $this->membershipOf($member);
        Sanctum::actingAs($this->makeOrgUser($organization, OrganizationRole::ORG_OWNER));
        $organization->update(['seat_limit' => 2]); // the owner's own membership also holds a seat

        $this->postJson($this->url($organization, "members/{$membership->id}/suspend"), ['reason' => 'Leave of absence'])
            ->assertOk()
            ->assertJsonPath('status', 'suspended');
        $this->assertSame(1, $this->activeCount($organization));

        // Someone else takes the free seat, so restoring now has no seat.
        $this->makeMember($organization);
        $this->postJson($this->url($organization, "members/{$membership->id}/restore"))
            ->assertStatus(409)
            ->assertJsonPath('join_status', 'SEAT_LIMIT_REACHED');

        $organization->update(['seat_limit' => 3]);
        $this->postJson($this->url($organization, "members/{$membership->id}/restore"))
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('joined_via', 'work_email'); // the original join method is kept
    }

    public function test_deactivation_releases_the_seat_revokes_roles_and_keeps_the_account(): void
    {
        $organization = Organization::factory()->create();
        $analyst = $this->makeOrgUser($organization, OrganizationRole::ORG_ANALYST);
        $membership = $this->membershipOf($analyst);
        Sanctum::actingAs($this->makeOrgUser($organization, OrganizationRole::ORG_ADMIN));

        $this->deleteJson($this->url($organization, "members/{$membership->id}"), ['reason' => 'Left the company'])
            ->assertOk()
            ->assertJsonPath('status', 'deactivated')
            ->assertJsonPath('deactivation_reason', 'Left the company');

        $this->assertNotNull($membership->fresh()->deactivated_at);
        $this->assertFalse(OrganizationRoleAssignment::where('user_id', $analyst->id)->whereNull('revoked_at')->exists());
        $this->assertNotNull($analyst->fresh());

        $log = OrganizationAuditLog::where('action', AuditAction::MEMBERSHIP_DEACTIVATED)->sole();
        $this->assertSame(['ORG_ANALYST'], $log->metadata['roles_revoked']);
    }

    public function test_offboarded_or_suspended_members_cannot_rejoin_by_themselves(): void
    {
        $organization = Organization::factory()->create();
        OrganizationDomain::factory()->create(['organization_id' => $organization->id, 'domain' => 'acme.com']);
        $deactivated = $this->makeUser();
        $deactivated->forceFill(['email' => 'gone@acme.com'])->save();
        $suspended = $this->makeUser();
        $suspended->forceFill(['email' => 'paused@acme.com'])->save();

        OrganizationMembership::factory()->deactivated()->create(['organization_id' => $organization->id, 'user_id' => $deactivated->id]);
        OrganizationMembership::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $suspended->id,
            'status' => MembershipStatus::SUSPENDED,
        ]);

        Sanctum::actingAs($deactivated);
        $this->postJson('/api/v1/corporate/join/work-email')->assertForbidden()->assertJsonPath('join_status', 'MEMBERSHIP_DEACTIVATED');

        Sanctum::actingAs($suspended);
        $this->postJson('/api/v1/corporate/join/work-email')->assertForbidden()->assertJsonPath('join_status', 'MEMBERSHIP_SUSPENDED');
    }

    public function test_suspended_admin_loses_delegated_rights_until_restored(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->makeOrgUser($organization, OrganizationRole::ORG_ADMIN);
        $this->membershipOf($admin)->update(['status' => MembershipStatus::SUSPENDED]);

        Sanctum::actingAs($admin);
        $this->getJson($this->url($organization, 'members'))->assertForbidden();
    }

    public function test_admins_cannot_act_on_themselves_or_on_an_equal_or_higher_role(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->makeOrgUser($organization, OrganizationRole::ORG_OWNER);
        $otherAdmin = $this->makeOrgUser($organization, OrganizationRole::ORG_ADMIN);
        $admin = $this->makeOrgUser($organization, OrganizationRole::ORG_ADMIN);
        Sanctum::actingAs($admin);

        foreach ([$owner, $otherAdmin, $admin] as $target) {
            $this->deleteJson($this->url($organization, "members/{$this->membershipOf($target)->id}"), ['reason' => 'x'])
                ->assertForbidden();
        }

        // Even while suspended, the owner still ranks above the admin.
        $this->membershipOf($owner)->update(['status' => MembershipStatus::SUSPENDED]);
        $this->deleteJson($this->url($organization, "members/{$this->membershipOf($owner)->id}"), ['reason' => 'x'])->assertForbidden();

        // Pivot Super Admin can act on anyone.
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));
        $this->deleteJson($this->url($organization, "members/{$this->membershipOf($otherAdmin)->id}"), ['reason' => 'x'])->assertOk();
    }

    public function test_members_of_another_organization_are_not_reachable(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $foreign = $this->membershipOf($this->makeMember($organizationB));
        Sanctum::actingAs($this->makeOrgUser($organizationA, OrganizationRole::ORG_OWNER));

        $this->deleteJson($this->url($organizationA, "members/{$foreign->id}"), ['reason' => 'x'])->assertNotFound();
        $this->getJson($this->url($organizationB, 'members'))->assertForbidden();
        $this->assertSame(MembershipStatus::ACTIVE, $foreign->fresh()->status);
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $organization = Organization::factory()->create();
        $membership = $this->membershipOf($this->makeMember($organization));
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->postJson($this->url($organization, "members/{$membership->id}/restore"))->assertStatus(409);
        $this->deleteJson($this->url($organization, "members/{$membership->id}"), ['reason' => 'x'])->assertOk();
        $this->postJson($this->url($organization, "members/{$membership->id}/suspend"), ['reason' => 'x'])->assertStatus(409);
        $this->deleteJson($this->url($organization, "members/{$membership->id}"), ['reason' => 'x'])->assertStatus(409);
    }

    public function test_removing_an_organization_releases_its_seats(): void
    {
        $organization = Organization::factory()->create();
        $member = $this->makeMember($organization);
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->deleteJson($this->url($organization, ''), ['reason' => 'Contract cancelled'])->assertOk();

        $membership = $this->membershipOf($member);
        $this->assertSame(MembershipStatus::DEACTIVATED, $membership->status);
        $this->assertSame(OrganizationMembership::REASON_ORGANIZATION_REMOVED, $membership->deactivation_reason);

        // The released user can now join another organization.
        $other = Organization::factory()->create();
        $this->makeMember($other, $member);
        $this->assertSame(1, OrganizationMembership::where('user_id', $member->id)->where('status', 'active')->count());
    }

    public function test_seat_reconciliation_fixes_stale_seats_and_reports_over_allocation(): void
    {
        $removed = Organization::factory()->create();
        $removedMember = $this->membershipOf($this->makeMember($removed));
        $removed->delete(); // removed without releasing seats (legacy data)

        $expired = Organization::factory()->create(['status' => OrganizationStatus::EXPIRED]);
        $expiredMember = $this->membershipOf($this->makeMember($expired));

        $over = Organization::factory()->create(['seat_limit' => 1]);
        $this->makeMember($over);
        $this->makeMember($over);

        $healthy = Organization::factory()->create();
        $this->makeMember($healthy);

        $summary = app(SeatReconciliationService::class)->reconcile();

        $this->assertSame(3, $summary['organizations_with_findings']);
        $this->assertSame(2, $summary['memberships_fixed']);
        $this->assertSame(MembershipStatus::DEACTIVATED, $removedMember->fresh()->status);
        $this->assertSame(MembershipStatus::EXPIRED, $expiredMember->fresh()->status);
        $this->assertSame(2, $this->activeCount($over)); // reported only, nobody is removed

        $log = OrganizationAuditLog::where('organization_id', $over->id)->where('action', AuditAction::SEATS_RECONCILED)->sole();
        $this->assertSame(AuditActorType::SYSTEM, $log->actor_type);
        $this->assertSame('over_seat_limit', $log->metadata['issue']);
        $this->assertSame(1, $log->metadata['seats_over_limit']);
        $this->assertFalse(OrganizationAuditLog::where('organization_id', $healthy->id)->exists());
    }

    public function test_member_list_is_filtered_by_status(): void
    {
        $organization = Organization::factory()->create();
        $this->makeMember($organization);
        OrganizationMembership::factory()->deactivated()->create(['organization_id' => $organization->id]);
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPPORT));

        $this->getJson($this->url($organization, 'members?status=deactivated'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'deactivated');
    }

    private function membershipOf($user): OrganizationMembership
    {
        return OrganizationMembership::where('user_id', $user->id)->latest('id')->first();
    }

    private function url(Organization $organization, string $path): string
    {
        return rtrim("/api/v1/organizations/{$organization->id}/{$path}", '/');
    }

    private function activeCount(Organization $organization): int
    {
        return $organization->memberships()->where('status', MembershipStatus::ACTIVE)->count();
    }
}
