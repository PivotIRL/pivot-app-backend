<?php

namespace Tests\Feature\Organization;

use App\Organization\Enums\AuditAction;
use App\Organization\Enums\MembershipStatus;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Enums\PivotRole;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationAuditLog;
use App\Organization\Models\OrganizationDomain;
use App\Organization\Models\OrganizationMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Organization\Concerns\BuildsOrganizationActors;
use Tests\TestCase;

/**
 * PUT/PATCH …/members/{membership} (edit name only) and DELETE …/members/{membership}/remove (soft delete).
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

    public function test_removing_soft_deletes_a_deactivated_member_and_hides_them_from_the_list(): void
    {
        $organization = Organization::factory()->create();
        $member = $this->makeMember($organization);
        $membership = $this->membershipOf($member);
        $this->actingAsDashboard($this->makeOrgUser($organization, OrganizationRole::ORG_OWNER));

        // An active member must be deactivated first.
        $this->deleteJson($this->url($organization, "members/{$membership->id}/remove"))->assertStatus(409);

        $this->deleteJson($this->url($organization, "members/{$membership->id}"), ['reason' => 'Left'])->assertOk();
        $this->deleteJson($this->url($organization, "members/{$membership->id}/remove"))
            ->assertOk()
            ->assertJsonPath('status', 'deactivated')
            ->assertJsonPath('deleted_at', fn ($value) => $value !== null);

        $this->assertSoftDeleted($membership);
        $this->assertNotNull($member->fresh());
        $this->assertTrue(OrganizationAuditLog::where('action', AuditAction::MEMBERSHIP_REMOVED)->exists());

        $ids = collect($this->getJson($this->url($organization, 'members'))->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($membership->id, $ids);

        // A removed member is no longer reachable by ID.
        $this->deleteJson($this->url($organization, "members/{$membership->id}/remove"))->assertNotFound();
        $this->postJson($this->url($organization, "members/{$membership->id}/restore"))->assertNotFound();
        $this->patchJson($this->url($organization, "members/{$membership->id}"), ['name' => 'x'])->assertNotFound();
    }

    public function test_removal_follows_the_rank_rules(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->makeOrgUser($organization, OrganizationRole::ORG_OWNER);
        $this->membershipOf($owner)->update(['status' => MembershipStatus::DEACTIVATED]);
        $this->actingAsDashboard($this->makeOrgUser($organization, OrganizationRole::ORG_ADMIN));

        // Status set directly, so the owner's role assignment is still active and the rank check applies.
        $this->deleteJson($this->url($organization, "members/{$this->membershipOf($owner)->id}/remove"))->assertForbidden();
    }

    public function test_a_removed_member_still_cannot_rejoin_by_themselves(): void
    {
        $organization = Organization::factory()->create();
        OrganizationDomain::factory()->create(['organization_id' => $organization->id, 'domain' => 'acme.com']);
        $user = $this->makeUser();
        $user->forceFill(['email' => 'gone@acme.com'])->save();
        $membership = OrganizationMembership::factory()->deactivated()->create(['organization_id' => $organization->id, 'user_id' => $user->id]);
        $membership->delete();

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/organization/join/work-email')
            ->assertForbidden()
            ->assertJsonPath('join_status', 'MEMBERSHIP_DEACTIVATED');

        $this->assertSame(1, OrganizationMembership::withTrashed()->where('user_id', $user->id)->count());
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
