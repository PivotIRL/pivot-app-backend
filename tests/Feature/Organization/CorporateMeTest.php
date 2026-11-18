<?php

namespace Tests\Feature\Organization;

use App\Organization\Enums\MembershipStatus;
use App\Organization\Enums\OrganizationPermission;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Enums\PivotRole;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Services\OrganizationAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Organization\Concerns\BuildsOrganizationActors;
use Tests\TestCase;

class CorporateMeTest extends TestCase
{
    use BuildsOrganizationActors, RefreshDatabase;

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/corporate/me')->assertUnauthorized();
    }

    public function test_a_user_with_no_organization_has_no_dashboard_access(): void
    {
        $this->actingAsDashboard($this->makeUser());

        $this->getJson('/api/v1/corporate/me')
            ->assertForbidden()
            ->assertExactJson(['message' => 'You do not have access to the Organization Dashboard.']);
    }

    public function test_it_returns_the_user_account_organization_role_and_permissions(): void
    {
        $organization = Organization::factory()->create(['name' => 'Acme']);
        $admin = $this->makeOrgUser($organization, OrganizationRole::ORG_ADMIN);
        $account = $this->actingAsDashboard($admin);

        $this->getJson('/api/v1/corporate/me')
            ->assertOk()
            ->assertJsonPath('user.id', $admin->id)
            ->assertJsonPath('user.email', $admin->email)
            ->assertJsonPath('dashboard_account.id', $account->id)
            ->assertJsonPath('dashboard_account.status', 'active')
            ->assertJsonPath('can_access_dashboard', true)
            ->assertJsonCount(1, 'organizations')
            ->assertJsonPath('organizations.0.organization.id', $organization->id)
            ->assertJsonPath('organizations.0.organization.name', 'Acme')
            ->assertJsonPath('organizations.0.membership.status', 'active')
            ->assertJsonPath('organizations.0.roles', ['ORG_ADMIN'])
            ->assertJsonPath('organizations.0.can_access_dashboard', true)
            ->assertJsonPath('organizations.0.permissions', $this->values(OrganizationRole::ORG_ADMIN->permissions()))
            ->assertJsonPath('memberships.0.organization_id', $organization->id)
            ->assertJsonPath('memberships.0.organization_name', 'Acme')
            ->assertJsonPath('memberships.0.status', 'active');
    }

    public function test_it_never_exposes_password_data(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsDashboard($this->makeOrgUser($organization, OrganizationRole::ORG_OWNER));

        $response = $this->getJson('/api/v1/corporate/me')->assertOk();

        $response->assertJsonMissingPath('user.password');
        $response->assertJsonMissingPath('dashboard_account.password');
        $this->assertStringNotContainsString('$2y$', $response->getContent());
    }

    public function test_the_organization_id_it_returns_opens_the_dashboard_routes(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsDashboard($this->makeOrgUser($organization, OrganizationRole::ORG_OWNER));

        $id = $this->getJson('/api/v1/corporate/me')->json('organizations.0.organization.id');

        $this->getJson("/api/v1/organizations/{$id}")->assertOk();
    }

    public function test_it_returns_every_organization_the_user_can_access(): void
    {
        $first = Organization::factory()->create(['name' => 'Alpha']);
        $second = Organization::factory()->create(['name' => 'Beta']);
        $user = $this->makeUser();
        $this->grantRole($user, $first, OrganizationRole::ORG_OWNER);
        $this->grantRole($user, $second, OrganizationRole::ORG_ANALYST);
        $this->actingAsDashboard($user);

        $this->getJson('/api/v1/corporate/me')
            ->assertOk()
            ->assertJsonCount(2, 'organizations')
            ->assertJsonPath('organizations.0.organization.id', $first->id)
            ->assertJsonPath('organizations.0.roles', ['ORG_OWNER'])
            ->assertJsonPath('organizations.1.organization.id', $second->id)
            ->assertJsonPath('organizations.1.roles', ['ORG_ANALYST']);
    }

    public function test_organizations_the_user_cannot_access_are_not_listed_but_their_membership_is(): void
    {
        $authorized = Organization::factory()->create();
        $employer = Organization::factory()->create(['name' => 'Employer']);
        $user = $this->makeMember($employer);
        $this->grantRole($user, $authorized, OrganizationRole::ORG_OWNER);
        $this->actingAsDashboard($user);

        $this->getJson('/api/v1/corporate/me')
            ->assertOk()
            ->assertJsonCount(1, 'organizations')
            ->assertJsonPath('organizations.0.organization.id', $authorized->id)
            ->assertJsonCount(1, 'memberships')
            ->assertJsonPath('memberships.0.organization_name', 'Employer');
    }

    public function test_an_organization_member_has_no_dashboard_access(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsDashboard($this->makeOrgUser($organization, OrganizationRole::ORG_MEMBER));

        $this->getJson('/api/v1/corporate/me')->assertForbidden();
        $this->getJson("/api/v1/organizations/{$organization->id}")->assertForbidden();
    }

    public function test_an_owner_assigned_before_joining_is_listed_with_no_membership(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->makeUser();
        $this->grantRole($owner, $organization, OrganizationRole::ORG_OWNER);
        $this->actingAsDashboard($owner);

        $this->getJson('/api/v1/corporate/me')
            ->assertOk()
            ->assertJsonPath('organizations.0.membership', null)
            ->assertJsonPath('organizations.0.roles', ['ORG_OWNER'])
            ->assertJsonPath('organizations.0.can_access_dashboard', true)
            ->assertJsonPath('memberships', []);

        $this->getJson("/api/v1/organizations/{$organization->id}")->assertOk();
    }

    public function test_a_suspended_member_loses_access_until_restored(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->makeOrgUser($organization, OrganizationRole::ORG_ADMIN);
        OrganizationMembership::where('user_id', $admin->id)->update(['status' => MembershipStatus::SUSPENDED]);
        $this->actingAsDashboard($admin);

        $this->getJson('/api/v1/corporate/me')->assertForbidden();
        $this->getJson("/api/v1/organizations/{$organization->id}")->assertForbidden();
    }

    public function test_past_and_pending_connections_give_no_access(): void
    {
        $user = $this->makeUser();

        // Offboarded from one organization.
        OrganizationMembership::factory()->deactivated()->create(['user_id' => $user->id]);

        // Invited to another, but has not accepted.
        OrganizationMembership::factory()->create(['user_id' => $user->id, 'status' => MembershipStatus::INVITED]);

        // Role revoked in a third.
        $this->grantRole($user, Organization::factory()->create(), OrganizationRole::ORG_ADMIN)
            ->update(['revoked_at' => now()]);

        // A removed organization.
        $removed = Organization::factory()->create();
        $this->grantRole($user, $removed, OrganizationRole::ORG_ADMIN);
        $removed->delete();

        $this->actingAsDashboard($user);

        $this->getJson('/api/v1/corporate/me')->assertForbidden();
    }

    public function test_it_shows_only_a_summary_of_the_users_own_organization(): void
    {
        $mine = Organization::factory()->create();
        Organization::factory()->create();
        $other = Organization::factory()->create();
        $this->makeOrgUser($other, OrganizationRole::ORG_OWNER);
        $this->actingAsDashboard($this->makeOrgUser($mine, OrganizationRole::ORG_ANALYST));

        $response = $this->getJson('/api/v1/corporate/me')
            ->assertOk()
            ->assertJsonCount(1, 'organizations')
            ->assertJsonPath('organizations.0.organization.id', $mine->id);

        $this->assertSame(
            ['id', 'name', 'slug', 'status', 'package_code', 'contract_end_at'],
            array_keys($response->json('organizations.0.organization')),
        );
    }

    public function test_roles_are_listed_highest_first(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->makeOrgUser($organization, OrganizationRole::ORG_ANALYST);
        $this->grantRole($user, $organization, OrganizationRole::ORG_OWNER);
        $this->actingAsDashboard($user);

        $this->getJson('/api/v1/corporate/me')
            ->assertOk()
            ->assertJsonPath('organizations.0.roles', ['ORG_OWNER', 'ORG_ANALYST']);
    }

    public function test_pivot_staff_get_global_access_without_listing_every_organization(): void
    {
        Organization::factory()->count(2)->create();
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPPORT));

        $this->getJson('/api/v1/corporate/me')
            ->assertOk()
            ->assertJsonPath('pivot_role', 'PIVOT_SUPPORT')
            ->assertJsonPath('pivot_permissions', $this->values(PivotRole::PIVOT_SUPPORT->permissions()))
            ->assertJsonPath('can_access_dashboard', true)
            ->assertJsonPath('organizations', []);
    }

    public function test_reported_permissions_match_what_the_access_service_enforces(): void
    {
        $access = app(OrganizationAccessService::class);
        $organization = Organization::factory()->create();

        $actors = [
            $this->makeOrgUser($organization, OrganizationRole::ORG_OWNER),
            $this->makeOrgUser($organization, OrganizationRole::ORG_ANALYST),
            $this->makeOrgUser($organization, OrganizationRole::ORG_MEMBER),
            $this->makeStaff(PivotRole::PIVOT_SUPPORT),
        ];

        foreach ($actors as $actor) {
            $expected = array_values(array_filter(
                OrganizationPermission::cases(),
                fn (OrganizationPermission $permission) => $access->hasPermission($actor, $permission, $organization),
            ));

            $this->assertSame($expected, $access->permissionsIn($actor, $organization));
        }
    }

    /**
     * @param  list<OrganizationPermission>  $permissions
     * @return list<string>
     */
    private function values(array $permissions): array
    {
        return array_map(fn (OrganizationPermission $permission) => $permission->value, $permissions);
    }
}
