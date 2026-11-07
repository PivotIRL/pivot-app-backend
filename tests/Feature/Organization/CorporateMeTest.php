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
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Organization\Concerns\BuildsOrganizationActors;
use Tests\TestCase;

class CorporateMeTest extends TestCase
{
    use BuildsOrganizationActors, RefreshDatabase;

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/corporate/me')->assertUnauthorized();
    }

    public function test_a_user_with_no_organization_gets_an_empty_summary(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/v1/corporate/me')
            ->assertOk()
            ->assertExactJson([
                'pivot_role' => null,
                'pivot_permissions' => [],
                'can_access_dashboard' => false,
                'organizations' => [],
            ]);
    }

    public function test_an_org_admin_gets_their_organization_id_role_and_permissions(): void
    {
        $organization = Organization::factory()->create(['name' => 'Acme']);
        Sanctum::actingAs($this->makeOrgUser($organization, OrganizationRole::ORG_ADMIN));

        $this->getJson('/api/v1/corporate/me')
            ->assertOk()
            ->assertJsonPath('can_access_dashboard', true)
            ->assertJsonCount(1, 'organizations')
            ->assertJsonPath('organizations.0.organization.id', $organization->id)
            ->assertJsonPath('organizations.0.organization.name', 'Acme')
            ->assertJsonPath('organizations.0.membership.status', 'active')
            ->assertJsonPath('organizations.0.roles', ['ORG_ADMIN'])
            ->assertJsonPath('organizations.0.can_access_dashboard', true)
            ->assertJsonPath('organizations.0.permissions', $this->values(OrganizationRole::ORG_ADMIN->permissions()));
    }

    public function test_the_organization_id_it_returns_opens_the_dashboard_routes(): void
    {
        $organization = Organization::factory()->create();
        Sanctum::actingAs($this->makeOrgUser($organization, OrganizationRole::ORG_OWNER));

        $id = $this->getJson('/api/v1/corporate/me')->json('organizations.0.organization.id');

        $this->getJson("/api/v1/organizations/{$id}")->assertOk();
    }

    public function test_an_organization_member_is_listed_without_dashboard_access(): void
    {
        $organization = Organization::factory()->create();
        Sanctum::actingAs($this->makeOrgUser($organization, OrganizationRole::ORG_MEMBER));

        $this->getJson('/api/v1/corporate/me')
            ->assertOk()
            ->assertJsonPath('can_access_dashboard', false)
            ->assertJsonPath('organizations.0.roles', ['ORG_MEMBER'])
            ->assertJsonPath('organizations.0.permissions', [])
            ->assertJsonPath('organizations.0.can_access_dashboard', false);

        $this->getJson("/api/v1/organizations/{$organization->id}")->assertForbidden();
    }

    public function test_a_member_with_no_role_assignment_is_listed(): void
    {
        $organization = Organization::factory()->create();
        Sanctum::actingAs($this->makeMember($organization));

        $this->getJson('/api/v1/corporate/me')
            ->assertOk()
            ->assertJsonPath('organizations.0.organization.id', $organization->id)
            ->assertJsonPath('organizations.0.roles', [])
            ->assertJsonPath('organizations.0.can_access_dashboard', false);
    }

    public function test_an_owner_assigned_before_joining_is_listed_with_no_membership(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->makeUser();
        $this->grantRole($owner, $organization, OrganizationRole::ORG_OWNER);
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/corporate/me')
            ->assertOk()
            ->assertJsonPath('organizations.0.membership', null)
            ->assertJsonPath('organizations.0.roles', ['ORG_OWNER'])
            ->assertJsonPath('organizations.0.can_access_dashboard', true);

        $this->getJson("/api/v1/organizations/{$organization->id}")->assertOk();
    }

    public function test_a_suspended_organization_is_listed_but_gives_no_access(): void
    {
        $organization = Organization::factory()->suspended()->create();
        Sanctum::actingAs($this->makeOrgUser($organization, OrganizationRole::ORG_OWNER));

        $this->getJson('/api/v1/corporate/me')
            ->assertOk()
            ->assertJsonPath('can_access_dashboard', false)
            ->assertJsonPath('organizations.0.organization.status', 'suspended')
            ->assertJsonPath('organizations.0.permissions', [])
            ->assertJsonPath('organizations.0.can_access_dashboard', false);

        $this->getJson("/api/v1/organizations/{$organization->id}")->assertForbidden();
    }

    public function test_a_suspended_member_loses_their_roles_until_restored(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->makeOrgUser($organization, OrganizationRole::ORG_ADMIN);
        OrganizationMembership::where('user_id', $admin->id)->update(['status' => MembershipStatus::SUSPENDED]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/corporate/me')
            ->assertOk()
            ->assertJsonPath('organizations.0.membership.status', 'suspended')
            ->assertJsonPath('organizations.0.roles', [])
            ->assertJsonPath('organizations.0.permissions', [])
            ->assertJsonPath('organizations.0.can_access_dashboard', false);

        $this->getJson("/api/v1/organizations/{$organization->id}")->assertForbidden();
    }

    public function test_past_and_pending_connections_are_not_listed(): void
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

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/corporate/me')
            ->assertOk()
            ->assertJsonPath('organizations', [])
            ->assertJsonPath('can_access_dashboard', false);
    }

    public function test_it_shows_only_a_summary_of_the_users_own_organization(): void
    {
        $mine = Organization::factory()->create();
        Organization::factory()->create();
        $other = Organization::factory()->create();
        $this->makeOrgUser($other, OrganizationRole::ORG_OWNER);
        Sanctum::actingAs($this->makeOrgUser($mine, OrganizationRole::ORG_ANALYST));

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
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/corporate/me')
            ->assertOk()
            ->assertJsonPath('organizations.0.roles', ['ORG_OWNER', 'ORG_ANALYST']);
    }

    public function test_pivot_staff_get_global_access_without_listing_every_organization(): void
    {
        Organization::factory()->count(2)->create();
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPPORT));

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
