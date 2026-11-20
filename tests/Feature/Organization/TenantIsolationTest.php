<?php

namespace Tests\Feature\Organization;

use App\Organization\Enums\AuditAction;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Enums\PivotRole;
use App\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Organization\Concerns\BuildsOrganizationActors;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use BuildsOrganizationActors, RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
    }

    public function test_admin_of_organization_a_cannot_read_organization_b(): void
    {
        $adminA = $this->makeOrgUser($this->orgA, OrganizationRole::ORG_ADMIN);
        $this->actingAsDashboard($adminA);

        $this->getJson($this->url($this->orgB, 'roles'))->assertForbidden();
        $this->getJson($this->url($this->orgB, 'audit-logs'))->assertForbidden();

        $this->assertDatabaseHas('organization_audit_logs', [
            'action' => AuditAction::TENANT_ACCESS_DENIED->value,
            'actor_user_id' => $adminA->id,
            'organization_id' => null,
            'target_type' => 'organization',
            'target_id' => $this->orgB->id,
        ]);
    }

    public function test_admin_can_read_own_organization(): void
    {
        $this->actingAsDashboard($this->makeOrgUser($this->orgA, OrganizationRole::ORG_ADMIN));

        $this->getJson($this->url($this->orgA, 'roles'))->assertOk();
        $this->getJson($this->url($this->orgA, 'audit-logs'))->assertOk();
    }

    public function test_admin_cannot_change_organization_b(): void
    {
        $adminA = $this->makeOrgUser($this->orgA, OrganizationRole::ORG_ADMIN);
        $memberB = $this->makeMember($this->orgB);
        $this->actingAsDashboard($adminA);

        $this->postJson($this->url($this->orgB, 'roles'), [
            'user_id' => $memberB->id,
            'role' => OrganizationRole::ORG_ANALYST->value,
        ])->assertForbidden();

        $this->assertDatabaseMissing('organization_role_assignments', ['user_id' => $memberB->id]);
    }

    public function test_child_resource_of_organization_b_is_not_reachable_through_organization_a(): void
    {
        $ownerA = $this->makeOrgUser($this->orgA, OrganizationRole::ORG_OWNER);
        $analystB = $this->makeOrgUser($this->orgB, OrganizationRole::ORG_ANALYST);
        $assignmentB = $analystB->organizationRoleAssignments()->first();
        $this->actingAsDashboard($ownerA);

        $this->deleteJson($this->url($this->orgA, "roles/{$assignmentB->id}"))->assertNotFound();

        $this->assertNull($assignmentB->fresh()->revoked_at);
    }

    public function test_organization_id_input_must_match_the_route_organization(): void
    {
        $this->actingAsDashboard($this->makeOrgUser($this->orgA, OrganizationRole::ORG_ADMIN));

        $this->getJson($this->url($this->orgA, 'roles').'?organization_id='.$this->orgB->id)
            ->assertForbidden()
            ->assertJson(['message' => 'Cross-organization requests are not allowed.']);
    }

    public function test_member_without_admin_role_cannot_enter_admin_scope(): void
    {
        $this->actingAsDashboard($this->makeOrgUser($this->orgA, OrganizationRole::ORG_MEMBER));

        $this->getJson($this->url($this->orgA, 'roles'))->assertForbidden();
    }

    public function test_analyst_can_enter_but_cannot_view_roles_or_audit_logs(): void
    {
        $this->actingAsDashboard($this->makeOrgUser($this->orgA, OrganizationRole::ORG_ANALYST));

        $this->getJson($this->url($this->orgA, 'roles'))->assertForbidden();
        $this->getJson($this->url($this->orgA, 'audit-logs'))->assertForbidden();
    }

    public function test_suspended_organization_blocks_its_admins_but_not_pivot_staff(): void
    {
        $suspended = Organization::factory()->suspended()->create();
        $owner = $this->makeOrgUser($suspended, OrganizationRole::ORG_OWNER);

        // Their only organization is suspended, so they have no dashboard access at all.
        $this->actingAsDashboard($owner);
        $this->getJson($this->url($suspended, 'roles'))
            ->assertForbidden()
            ->assertJson(['message' => 'You do not have access to the Organization Dashboard.']);

        // With access elsewhere, tenant isolation explains why this organization is closed.
        $this->grantRole($owner, $this->orgA, OrganizationRole::ORG_OWNER);
        $this->getJson($this->url($suspended, 'roles'))
            ->assertForbidden()
            ->assertJson(['message' => 'This organization is suspended.']);

        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));
        $this->getJson($this->url($suspended, 'roles'))->assertOk();
    }

    public function test_pivot_staff_can_enter_any_organization(): void
    {
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPPORT));

        $this->getJson($this->url($this->orgA, 'roles'))->assertOk();
        $this->getJson($this->url($this->orgB, 'audit-logs'))->assertOk();
    }

    public function test_token_from_the_mobile_login_endpoint_is_rejected(): void
    {
        $admin = $this->makeOrgUser($this->orgA, OrganizationRole::ORG_ADMIN);

        $token = $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertOk()
            ->json('token');

        $this->withToken($token)->getJson($this->url($this->orgA, 'roles'))->assertUnauthorized();
    }

    public function test_token_from_the_dashboard_login_endpoint_stays_inside_its_own_tenant(): void
    {
        $admin = $this->makeOrgUser($this->orgA, OrganizationRole::ORG_ADMIN);
        $this->makeDashboardAccount($admin);

        $token = $this->postJson('/api/v1/organization/auth/login', ['email' => $admin->email, 'password' => $this->dashboardPassword])
            ->assertOk()
            ->json('access_token');

        $this->withToken($token)->getJson($this->url($this->orgA, 'roles'))->assertOk();
        $this->withToken($token)->getJson($this->url($this->orgB, 'roles'))->assertForbidden();
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson($this->url($this->orgA, 'roles'))->assertUnauthorized();
    }

    public function test_audit_logs_only_contain_the_requested_organization(): void
    {
        $ownerA = $this->makeOrgUser($this->orgA, OrganizationRole::ORG_OWNER);
        $ownerB = $this->makeOrgUser($this->orgB, OrganizationRole::ORG_OWNER);

        $this->actingAsDashboard($ownerB);
        $this->postJson($this->url($this->orgB, 'roles'), [
            'user_id' => $this->makeMember($this->orgB)->id,
            'role' => OrganizationRole::ORG_ANALYST->value,
        ])->assertCreated();

        $this->actingAsDashboard($ownerA);
        $this->getJson($this->url($this->orgA, 'audit-logs'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_organization_ids_are_sequential_integers(): void
    {
        $this->assertIsInt($this->orgA->id);
        $this->assertGreaterThan($this->orgA->id, $this->orgB->id);
    }

    public function test_guessing_neighbouring_numeric_ids_never_exposes_other_tenants(): void
    {
        $adminA = $this->makeOrgUser($this->orgA, OrganizationRole::ORG_ADMIN);
        $this->actingAsDashboard($adminA);

        foreach (['members', 'domains', 'redemption-codes', 'invitations', 'roles', 'audit-logs'] as $path) {
            $this->getJson("/api/v1/organizations/{$this->orgB->id}/{$path}")->assertForbidden();
            $this->getJson('/api/v1/organizations/'.($this->orgB->id + 1000)."/{$path}")->assertNotFound();
        }

        $this->getJson("/api/v1/organizations/{$this->orgB->id}")->assertForbidden();
    }

    public function test_cross_organization_id_in_body_is_rejected(): void
    {
        $adminA = $this->makeOrgUser($this->orgA, OrganizationRole::ORG_ADMIN);
        $this->actingAsDashboard($adminA);

        $this->postJson($this->url($this->orgA, 'invitations'), [
            'organization_id' => $this->orgB->id,
            'email' => 'someone@example.com',
            'role' => OrganizationRole::ORG_ANALYST->value,
        ])->assertForbidden();
    }

    public function test_non_numeric_and_uuid_organization_ids_return_not_found(): void
    {
        $this->actingAsDashboard($this->makeOrgUser($this->orgA, OrganizationRole::ORG_ADMIN));

        $this->getJson('/api/v1/organizations/abc/roles')->assertNotFound();
        $this->getJson('/api/v1/organizations/9d3f6b1e-1c2a-4f5b-8a7d-0e1f2a3b4c5d/roles')->assertNotFound();
    }

    public function test_api_returns_organization_ids_as_numbers(): void
    {
        $adminA = $this->makeOrgUser($this->orgA, OrganizationRole::ORG_ADMIN);
        $this->actingAsDashboard($adminA);

        $this->assertSame($this->orgA->id, $this->getJson('/api/v1/organization/me')->assertOk()->json('organizations.0.organization.id'));
    }

    private function url(Organization $organization, string $path): string
    {
        return "/api/v1/organizations/{$organization->id}/{$path}";
    }
}
