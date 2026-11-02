<?php

namespace Tests\Feature\Organization;

use App\Organization\Enums\AuditAction;
use App\Organization\Enums\AuditActorType;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Enums\PivotRole;
use App\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Organization\Concerns\BuildsOrganizationActors;
use Tests\TestCase;

class RoleAssignmentTest extends TestCase
{
    use BuildsOrganizationActors, RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
    }

    public function test_admin_assigns_analyst_and_an_audit_row_is_written(): void
    {
        $admin = $this->makeOrgUser($this->organization, OrganizationRole::ORG_ADMIN);
        $member = $this->makeMember($this->organization);
        Sanctum::actingAs($admin);

        $response = $this->withHeader('User-Agent', 'PivotAdminTest/1.0')
            ->postJson($this->rolesUrl(), ['user_id' => $member->id, 'role' => 'ORG_ANALYST'])
            ->assertCreated()
            ->assertJsonPath('role', 'ORG_ANALYST')
            ->assertJsonPath('user.id', $member->id)
            ->assertJsonPath('is_active', true);

        $this->assertDatabaseHas('organization_audit_logs', [
            'organization_id' => $this->organization->id,
            'actor_user_id' => $admin->id,
            'actor_type' => AuditActorType::USER->value,
            'action' => AuditAction::ORGANIZATION_ROLE_ASSIGNED->value,
            'target_type' => 'organization_role_assignment',
            'target_id' => (string) $response->json('id'),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PivotAdminTest/1.0',
        ]);
    }

    public function test_admin_cannot_assign_admin_or_owner(): void
    {
        Sanctum::actingAs($this->makeOrgUser($this->organization, OrganizationRole::ORG_ADMIN));
        $member = $this->makeMember($this->organization);

        foreach (['ORG_ADMIN', 'ORG_OWNER'] as $role) {
            $this->postJson($this->rolesUrl(), ['user_id' => $member->id, 'role' => $role])->assertForbidden();
        }
    }

    public function test_owner_can_assign_admin_but_not_owner(): void
    {
        Sanctum::actingAs($this->makeOrgUser($this->organization, OrganizationRole::ORG_OWNER));
        $member = $this->makeMember($this->organization);

        $this->postJson($this->rolesUrl(), ['user_id' => $member->id, 'role' => 'ORG_ADMIN'])->assertCreated();
        $this->postJson($this->rolesUrl(), ['user_id' => $member->id, 'role' => 'ORG_OWNER'])->assertForbidden();
    }

    public function test_corporate_admin_can_only_assign_roles_to_active_members(): void
    {
        Sanctum::actingAs($this->makeOrgUser($this->organization, OrganizationRole::ORG_ADMIN));
        $outsider = $this->makeUser();

        $this->postJson($this->rolesUrl(), ['user_id' => $outsider->id, 'role' => 'ORG_ANALYST'])
            ->assertStatus(422);
    }

    public function test_assigning_the_same_active_role_twice_conflicts(): void
    {
        Sanctum::actingAs($this->makeOrgUser($this->organization, OrganizationRole::ORG_ADMIN));
        $member = $this->makeMember($this->organization);
        $payload = ['user_id' => $member->id, 'role' => 'ORG_ANALYST'];

        $this->postJson($this->rolesUrl(), $payload)->assertCreated();
        $this->postJson($this->rolesUrl(), $payload)->assertStatus(409);
    }

    public function test_super_admin_can_assign_first_owner_to_a_non_member(): void
    {
        Sanctum::actingAs($superAdmin = $this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));
        $user = $this->makeUser();

        $this->postJson($this->rolesUrl(), ['user_id' => $user->id, 'role' => 'ORG_OWNER'])->assertCreated();

        $this->assertDatabaseHas('organization_audit_logs', [
            'actor_user_id' => $superAdmin->id,
            'actor_type' => AuditActorType::PIVOT_STAFF->value,
            'action' => AuditAction::ORGANIZATION_ROLE_ASSIGNED->value,
        ]);
    }

    public function test_support_can_view_roles_but_not_assign(): void
    {
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPPORT));
        $member = $this->makeMember($this->organization);

        $this->getJson($this->rolesUrl())->assertOk();
        $this->postJson($this->rolesUrl(), ['user_id' => $member->id, 'role' => 'ORG_ANALYST'])->assertForbidden();
    }

    public function test_admin_revokes_analyst_and_an_audit_row_is_written(): void
    {
        $admin = $this->makeOrgUser($this->organization, OrganizationRole::ORG_ADMIN);
        $analyst = $this->makeOrgUser($this->organization, OrganizationRole::ORG_ANALYST);
        $assignment = $analyst->organizationRoleAssignments()->first();
        Sanctum::actingAs($admin);

        $this->deleteJson($this->rolesUrl($assignment->id), ['reason' => 'Left the HR team'])
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->assertNotNull($assignment->fresh()->revoked_at);
        $this->assertDatabaseHas('organization_audit_logs', [
            'action' => AuditAction::ORGANIZATION_ROLE_REVOKED->value,
            'actor_user_id' => $admin->id,
            'target_id' => (string) $assignment->id,
        ]);

        $this->deleteJson($this->rolesUrl($assignment->id))->assertStatus(409);
    }

    public function test_admin_cannot_revoke_owner_or_own_admin_role(): void
    {
        $admin = $this->makeOrgUser($this->organization, OrganizationRole::ORG_ADMIN);
        $owner = $this->makeOrgUser($this->organization, OrganizationRole::ORG_OWNER);
        Sanctum::actingAs($admin);

        $this->deleteJson($this->rolesUrl($owner->organizationRoleAssignments()->first()->id))->assertForbidden();
        $this->deleteJson($this->rolesUrl($admin->organizationRoleAssignments()->first()->id))->assertForbidden();
    }

    public function test_invalid_role_is_rejected(): void
    {
        Sanctum::actingAs($this->makeOrgUser($this->organization, OrganizationRole::ORG_OWNER));

        $this->postJson($this->rolesUrl(), ['user_id' => $this->makeMember($this->organization)->id, 'role' => 'PIVOT_SUPER_ADMIN'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    private function rolesUrl(?int $assignmentId = null): string
    {
        return "/api/v1/organizations/{$this->organization->id}/roles".($assignmentId ? "/{$assignmentId}" : '');
    }
}
