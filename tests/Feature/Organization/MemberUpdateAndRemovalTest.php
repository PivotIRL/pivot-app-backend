<?php

namespace Tests\Feature\Organization;

use App\Organization\Enums\AuditAction;
use App\Organization\Enums\InvitationStatus;
use App\Organization\Enums\MembershipStatus;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Enums\PivotRole;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationAuditLog;
use App\Organization\Models\OrganizationDomain;
use App\Organization\Models\OrganizationInvitation;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Models\OrganizationRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Organization\Concerns\BuildsOrganizationActors;
use Tests\TestCase;

/**
 * PUT/PATCH …/members/{membership} (edit name only) and DELETE …/members/{membership}/remove (permanent delete).
 */
class MemberUpdateAndRemovalTest extends TestCase
{
    use BuildsOrganizationActors, RefreshDatabase;

    public function test_admin_updates_a_members_name(): void
    {
        $organization = Organization::factory()->create();
        $member = $this->makeMember($organization);
        $membership = $this->membershipOf($member);
        $oldName = $member->name;
        $this->actingAsDashboard($this->makeOrgUser($organization, OrganizationRole::ORG_ADMIN));

        $this->patchJson($this->url($organization, "members/{$membership->id}"), ['name' => '  Jane Doe '])
            ->assertOk()
            ->assertJsonPath('id', $membership->id)
            ->assertJsonPath('user.name', 'Jane Doe')
            ->assertJsonPath('user.email', $member->email);

        $this->assertSame('Jane Doe', $member->fresh()->name);

        $log = OrganizationAuditLog::where('action', AuditAction::MEMBER_PROFILE_UPDATED)->sole();
        // assertEquals: JSONB does not keep key order.
        $this->assertEquals(['name' => ['from' => $oldName, 'to' => 'Jane Doe']], $log->metadata['changes']);
    }

    public function test_the_personal_account_email_can_never_be_changed(): void
    {
        $organization = Organization::factory()->create();
        $member = $this->makeMember($organization);
        $membership = $this->membershipOf($member);
        $originalEmail = $member->email;
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        // An email sent alongside a name is ignored.
        $this->patchJson($this->url($organization, "members/{$membership->id}"), [
            'name' => 'New Name',
            'email' => 'attacker@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', $originalEmail);

        // An email on its own is not a valid update.
        $this->patchJson($this->url($organization, "members/{$membership->id}"), ['email' => 'attacker@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertSame($originalEmail, $member->fresh()->email);
        $this->assertArrayNotHasKey(
            'email',
            OrganizationAuditLog::where('action', AuditAction::MEMBER_PROFILE_UPDATED)->sole()->metadata['changes']
        );
    }

    public function test_unchanged_name_is_not_saved_or_audited(): void
    {
        $organization = Organization::factory()->create();
        $membership = $this->membershipOf($this->makeMember($organization));
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->putJson($this->url($organization, "members/{$membership->id}"), ['name' => 'Only Name'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Only Name');
        $this->putJson($this->url($organization, "members/{$membership->id}"), ['name' => 'Only Name'])->assertOk();

        $this->assertSame(1, OrganizationAuditLog::where('action', AuditAction::MEMBER_PROFILE_UPDATED)->count());
    }

    public function test_update_validation(): void
    {
        $organization = Organization::factory()->create();
        $membership = $this->membershipOf($this->makeMember($organization));
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->patchJson($this->url($organization, "members/{$membership->id}"), [])
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->patchJson($this->url($organization, "members/{$membership->id}"), ['name' => '   '])
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->patchJson($this->url($organization, "members/{$membership->id}"), ['name' => ['not', 'a', 'string']])
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->patchJson($this->url($organization, "members/{$membership->id}"), ['name' => str_repeat('a', 256)])
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);
    }

    public function test_update_follows_the_rank_rules_and_tenant_isolation(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->makeOrgUser($organization, OrganizationRole::ORG_OWNER);
        $admin = $this->makeOrgUser($organization, OrganizationRole::ORG_ADMIN);
        $analyst = $this->makeOrgUser($organization, OrganizationRole::ORG_ANALYST);
        $foreign = $this->membershipOf($this->makeMember(Organization::factory()->create()));

        $this->actingAsDashboard($admin);
        $this->patchJson($this->url($organization, "members/{$this->membershipOf($owner)->id}"), ['name' => 'x'])->assertForbidden();
        $this->patchJson($this->url($organization, "members/{$this->membershipOf($admin)->id}"), ['name' => 'x'])->assertForbidden();
        $this->patchJson($this->url($organization, "members/{$foreign->id}"), ['name' => 'x'])->assertNotFound();

        // An analyst has no members.manage permission.
        $this->actingAsDashboard($analyst);
        $this->patchJson($this->url($organization, "members/{$this->membershipOf($this->makeMember($organization))->id}"), ['name' => 'x'])
            ->assertForbidden();
    }

    public function test_deactivated_members_cannot_be_edited(): void
    {
        $organization = Organization::factory()->create();
        $membership = OrganizationMembership::factory()->deactivated()->create(['organization_id' => $organization->id]);
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->patchJson($this->url($organization, "members/{$membership->id}"), ['name' => 'x'])->assertStatus(409);
    }

    public function test_removing_an_active_member_deletes_the_row_frees_the_seat_and_revokes_roles(): void
    {
        $organization = Organization::factory()->create();
        $analyst = $this->makeOrgUser($organization, OrganizationRole::ORG_ANALYST);
        $membership = $this->membershipOf($analyst);
        $this->actingAsDashboard($this->makeOrgUser($organization, OrganizationRole::ORG_OWNER));
        $activeBefore = OrganizationMembership::where('organization_id', $organization->id)->where('status', 'active')->count();

        $this->deleteJson($this->url($organization, "members/{$membership->id}/remove"))
            ->assertOk()
            ->assertExactJson(['message' => 'Member removed.']);

        $this->assertDatabaseMissing('organization_memberships', ['id' => $membership->id]);
        $this->assertSame($activeBefore - 1, OrganizationMembership::where('organization_id', $organization->id)->where('status', 'active')->count());
        $this->assertFalse(OrganizationRoleAssignment::where('user_id', $analyst->id)->whereNull('revoked_at')->exists());
        $this->assertNotNull($analyst->fresh()); // the personal account is kept

        $log = OrganizationAuditLog::where('action', AuditAction::MEMBERSHIP_REMOVED)->sole();
        $this->assertSame('active', $log->metadata['previous_status']);
        $this->assertSame(['ORG_ANALYST'], $log->metadata['roles_revoked']);

        $ids = collect($this->getJson($this->url($organization, 'members'))->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($membership->id, $ids);

        // The row is gone, so every member endpoint now returns 404.
        $this->deleteJson($this->url($organization, "members/{$membership->id}/remove"))->assertNotFound();
        $this->postJson($this->url($organization, "members/{$membership->id}/restore"))->assertNotFound();
        $this->patchJson($this->url($organization, "members/{$membership->id}"), ['name' => 'x'])->assertNotFound();
    }

    public function test_members_in_any_status_can_be_removed(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        foreach (MembershipStatus::cases() as $status) {
            $membership = OrganizationMembership::factory()->create([
                'organization_id' => $organization->id,
                'status' => $status,
            ]);

            $this->deleteJson($this->url($organization, "members/{$membership->id}/remove"))->assertOk();
            $this->assertDatabaseMissing('organization_memberships', ['id' => $membership->id]);
        }
    }

    public function test_removing_an_invited_member_revokes_their_pending_invitation(): void
    {
        $organization = Organization::factory()->create();
        $invitee = $this->makeUser();
        $this->actingAsDashboard($this->makeOrgUser($organization, OrganizationRole::ORG_OWNER));

        $this->postJson($this->url($organization, 'invitations'), ['email' => $invitee->email])->assertCreated();
        $membership = $this->membershipOf($invitee);
        $this->assertSame(MembershipStatus::INVITED, $membership->status);

        $this->deleteJson($this->url($organization, "members/{$membership->id}/remove"))->assertOk();

        $this->assertSame(InvitationStatus::REVOKED, OrganizationInvitation::where('email', strtolower($invitee->email))->sole()->status);
    }

    public function test_removal_follows_the_rank_rules_and_tenant_isolation(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->makeOrgUser($organization, OrganizationRole::ORG_OWNER);
        $admin = $this->makeOrgUser($organization, OrganizationRole::ORG_ADMIN);
        $foreign = $this->membershipOf($this->makeMember(Organization::factory()->create()));
        $this->actingAsDashboard($admin);

        $this->deleteJson($this->url($organization, "members/{$this->membershipOf($owner)->id}/remove"))->assertForbidden();
        $this->deleteJson($this->url($organization, "members/{$this->membershipOf($admin)->id}/remove"))->assertForbidden();
        $this->deleteJson($this->url($organization, "members/{$foreign->id}/remove"))->assertNotFound();

        $this->assertDatabaseHas('organization_memberships', ['id' => $foreign->id]);
        $this->assertDatabaseHas('organization_memberships', ['id' => $this->membershipOf($owner)->id]);
    }

    public function test_a_removed_member_can_join_again_like_a_new_member(): void
    {
        $organization = Organization::factory()->create();
        OrganizationDomain::factory()->create(['organization_id' => $organization->id, 'domain' => 'acme.com', 'verified_at' => now()]);
        $user = $this->makeUser();
        $user->forceFill(['email' => 'back@acme.com'])->save();
        $membership = OrganizationMembership::factory()->deactivated()->create(['organization_id' => $organization->id, 'user_id' => $user->id]);
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        // While the deactivated row exists, self-service rejoining is blocked.
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/organization/join/work-email')->assertForbidden();

        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));
        $this->deleteJson($this->url($organization, "members/{$membership->id}/remove"))->assertOk();

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/organization/join/work-email')->assertCreated()->assertJsonPath('join_status', 'JOINED');
    }

    private function membershipOf($user): OrganizationMembership
    {
        return OrganizationMembership::where('user_id', $user->id)->latest('id')->first();
    }

    private function url(Organization $organization, string $path): string
    {
        return rtrim("/api/v1/organizations/{$organization->id}/{$path}", '/');
    }
}
