<?php

namespace Tests\Feature\Organization;

use App\Organization\Enums\OrganizationPermission;
use App\Organization\Enums\OrganizationPermissionGroup;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Enums\PivotRole;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationDashboardAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Organization\Concerns\BuildsOrganizationActors;
use Tests\TestCase;

class RolePermissionCatalogTest extends TestCase
{
    use BuildsOrganizationActors, RefreshDatabase;

    private const ENDPOINTS = ['/api/v1/corporate/roles', '/api/v1/corporate/permissions'];

    // Authentication

    #[DataProvider('endpoints')]
    public function test_unauthenticated_requests_are_rejected(string $uri): void
    {
        $this->getJson($uri)->assertUnauthorized();
    }

    // Real tokens over HTTP: Sanctum::actingAs() would skip the guard checks these tests are about.

    #[DataProvider('endpoints')]
    public function test_a_mobile_app_token_is_rejected(string $uri): void
    {
        $owner = $this->makeOrgUser(Organization::factory()->create(), OrganizationRole::ORG_OWNER);
        $this->makeDashboardAccount($owner);
        $token = $owner->createToken('mobile')->plainTextToken;

        $this->withToken($token)->getJson($uri)->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_a_pivot_super_admin_mobile_token_is_rejected(string $uri): void
    {
        $staff = $this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN);
        $this->makeDashboardAccount($staff);
        $token = $staff->createToken('mobile')->plainTextToken;

        $this->withToken($token)->getJson($uri)->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_a_real_dashboard_token_is_accepted_and_one_without_the_dashboard_ability_is_rejected(string $uri): void
    {
        $owner = $this->makeOrgUser(Organization::factory()->create(), OrganizationRole::ORG_OWNER);
        $account = $this->makeDashboardAccount($owner);

        $valid = $account->createToken('dashboard', [OrganizationDashboardAccount::TOKEN_ABILITY])->plainTextToken;
        $this->withToken($valid)->getJson($uri)->assertOk();

        $this->app['auth']->forgetGuards();

        $other = $account->createToken('something-else', ['other'])->plainTextToken;
        $this->withToken($other)->getJson($uri)->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_a_user_without_dashboard_access_is_forbidden(string $uri): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsDashboard($this->makeOrgUser($organization, OrganizationRole::ORG_MEMBER));

        $this->getJson($uri)
            ->assertForbidden()
            ->assertExactJson(['message' => 'You do not have access to the Organization Dashboard.']);
    }

    #[DataProvider('endpoints')]
    public function test_a_disabled_dashboard_account_is_rejected(string $uri): void
    {
        $organization = Organization::factory()->create();
        $account = $this->actingAsDashboard($this->makeOrgUser($organization, OrganizationRole::ORG_OWNER));
        $account->forceFill(['disabled_at' => now()])->save();

        $this->getJson($uri)
            ->assertForbidden()
            ->assertExactJson(['message' => 'This dashboard account has been disabled.']);
    }

    // Roles

    public function test_it_returns_every_organization_role_highest_rank_first(): void
    {
        $this->actingAsOrgUser(OrganizationRole::ORG_ADMIN);

        $response = $this->getJson('/api/v1/corporate/roles')
            ->assertOk()
            ->assertJsonCount(count(OrganizationRole::cases()), 'data')
            ->assertJsonStructure(['data' => [[
                'code', 'name', 'description', 'rank', 'is_system', 'can_access_dashboard', 'permissions',
            ]]]);

        $this->assertSame(['ORG_OWNER', 'ORG_ADMIN', 'ORG_ANALYST', 'ORG_MEMBER'], $response->json('data.*.code'));
        $this->assertSame([40, 30, 20, 10], $response->json('data.*.rank'));
    }

    public function test_role_data_matches_the_role_enum(): void
    {
        $this->actingAsOrgUser(OrganizationRole::ORG_OWNER);

        $roles = collect($this->getJson('/api/v1/corporate/roles')->assertOk()->json('data'))->keyBy('code');

        foreach (OrganizationRole::cases() as $role) {
            $this->assertSame([
                'code' => $role->value,
                'name' => $role->label(),
                'description' => $role->description(),
                'rank' => $role->rank(),
                'is_system' => true,
                'can_access_dashboard' => $role->isAdministrative(),
                'permissions' => $this->values($role->permissions()),
            ], $roles[$role->value]);
        }

        $this->assertSame([], $roles['ORG_MEMBER']['permissions']);
        $this->assertFalse($roles['ORG_MEMBER']['can_access_dashboard']);
        $this->assertSame(['organization.view', 'reports.view'], $roles['ORG_ANALYST']['permissions']);
    }

    public function test_pivot_staff_roles_are_not_listed_as_organization_roles(): void
    {
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $codes = $this->getJson('/api/v1/corporate/roles')->assertOk()->json('data.*.code');

        foreach (PivotRole::cases() as $pivotRole) {
            $this->assertNotContains($pivotRole->value, $codes);
        }
    }

    #[DataProvider('dashboardActors')]
    public function test_every_dashboard_user_sees_the_same_role_catalog(?OrganizationRole $orgRole, ?PivotRole $pivotRole): void
    {
        $this->actAs($orgRole, $pivotRole);

        $this->getJson('/api/v1/corporate/roles')
            ->assertOk()
            ->assertJsonPath('data.*.code', ['ORG_OWNER', 'ORG_ADMIN', 'ORG_ANALYST', 'ORG_MEMBER']);
    }

    // Permissions

    public function test_pivot_super_admin_sees_every_permission(): void
    {
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $response = $this->getJson('/api/v1/corporate/permissions')
            ->assertOk()
            ->assertJsonCount(count(OrganizationPermission::cases()), 'data')
            ->assertJsonStructure([
                'data' => [['key', 'name', 'description', 'group', 'scope']],
                'groups' => [['key', 'name', 'permissions']],
            ]);

        $this->assertSame($this->values(OrganizationPermission::cases()), $response->json('data.*.key'));
        $this->assertContains('pivot', $response->json('data.*.scope'));
    }

    public function test_pivot_support_sees_every_permission(): void
    {
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPPORT));

        $this->getJson('/api/v1/corporate/permissions')
            ->assertOk()
            ->assertJsonPath('data.*.key', $this->values(OrganizationPermission::cases()));
    }

    #[DataProvider('organizationRolesWithDashboardAccess')]
    public function test_organization_users_see_only_delegated_permissions(OrganizationRole $role): void
    {
        $this->actingAsOrgUser($role);

        $response = $this->getJson('/api/v1/corporate/permissions')->assertOk();

        $this->assertSame($this->values(OrganizationPermission::delegated()), $response->json('data.*.key'));
        $this->assertSame(['organization'], array_values(array_unique($response->json('data.*.scope'))));

        foreach (['organizations.view_any', 'organization.create', 'organization.contract.manage', 'organization.suspend', 'organization.remove'] as $pivotOnly) {
            $this->assertNotContains($pivotOnly, $response->json('data.*.key'));
            $this->assertStringNotContainsString($pivotOnly, $response->getContent());
        }

        // Groups with no visible permission are left out entirely.
        $this->assertNotContains('contracts', $response->json('groups.*.key'));
    }

    public function test_permission_data_matches_the_permission_enum(): void
    {
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $permissions = collect($this->getJson('/api/v1/corporate/permissions')->json('data'))->keyBy('key');

        foreach (OrganizationPermission::cases() as $permission) {
            $this->assertSame([
                'key' => $permission->value,
                'name' => $permission->label(),
                'description' => $permission->description(),
                'group' => $permission->group()->value,
                'scope' => $permission->isDelegated() ? 'organization' : 'pivot',
            ], $permissions[$permission->value]);
        }

        $this->assertSame('members', $permissions['members.manage']['group']);
        $this->assertSame('invitations', $permissions['invitations.manage']['group']);
        $this->assertSame('contracts', $permissions['organization.contract.manage']['group']);
        $this->assertSame('reports', $permissions['reports.view']['group']);
        $this->assertSame('audit_logs', $permissions['audit_logs.view']['group']);
        $this->assertSame('settings', $permissions['organization.settings.update']['group']);
        $this->assertSame('pivot', $permissions['organization.create']['scope']);
    }

    public function test_groups_list_every_visible_permission_once_in_display_order(): void
    {
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $response = $this->getJson('/api/v1/corporate/permissions')->assertOk();

        $this->assertSame(
            array_map(fn (OrganizationPermissionGroup $group) => $group->value, OrganizationPermissionGroup::cases()),
            $response->json('groups.*.key'),
        );
        $this->assertSame('Contract and seat management', $response->json('groups.2.name'));

        $grouped = array_merge(...$response->json('groups.*.permissions'));
        sort($grouped);
        $flat = $response->json('data.*.key');
        sort($flat);
        $this->assertSame($flat, $grouped);

        foreach ($response->json('groups') as $group) {
            foreach ($group['permissions'] as $key) {
                $this->assertSame($group['key'], OrganizationPermission::from($key)->group()->value);
            }
        }
    }

    // Consistency between the catalog and the enforced model.

    public function test_no_organization_role_holds_a_pivot_controlled_permission(): void
    {
        $granted = array_unique(array_merge(...array_map(
            fn (OrganizationRole $role) => $this->values($role->permissions()),
            OrganizationRole::cases(),
        )));
        sort($granted);

        $delegated = $this->values(OrganizationPermission::delegated());
        sort($delegated);

        $this->assertSame($delegated, $granted);
        $this->assertCount(8, array_filter(OrganizationPermission::cases(), fn ($p) => ! $p->isDelegated()));
    }

    public function test_every_permission_a_role_lists_is_in_the_permissions_catalog_for_that_user(): void
    {
        $this->actingAsOrgUser(OrganizationRole::ORG_OWNER);

        $catalog = $this->getJson('/api/v1/corporate/permissions')->json('data.*.key');

        foreach ($this->getJson('/api/v1/corporate/roles')->json('data') as $role) {
            foreach ($role['permissions'] as $key) {
                $this->assertContains($key, $catalog);
            }
        }
    }

    public function test_the_endpoints_do_not_change_corporate_me(): void
    {
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPPORT));

        $this->getJson('/api/v1/corporate/me')
            ->assertOk()
            ->assertJsonPath('pivot_permissions', $this->values(PivotRole::PIVOT_SUPPORT->permissions()));
    }

    // Helpers

    public static function endpoints(): array
    {
        return array_combine(self::ENDPOINTS, array_map(fn ($uri) => [$uri], self::ENDPOINTS));
    }

    public static function organizationRolesWithDashboardAccess(): array
    {
        return [
            'owner' => [OrganizationRole::ORG_OWNER],
            'admin' => [OrganizationRole::ORG_ADMIN],
            'analyst' => [OrganizationRole::ORG_ANALYST],
        ];
    }

    public static function dashboardActors(): array
    {
        return [
            'super admin' => [null, PivotRole::PIVOT_SUPER_ADMIN],
            'support' => [null, PivotRole::PIVOT_SUPPORT],
            'owner' => [OrganizationRole::ORG_OWNER, null],
            'admin' => [OrganizationRole::ORG_ADMIN, null],
            'analyst' => [OrganizationRole::ORG_ANALYST, null],
        ];
    }

    private function actAs(?OrganizationRole $orgRole, ?PivotRole $pivotRole): void
    {
        $pivotRole
            ? $this->actingAsDashboard($this->makeStaff($pivotRole))
            : $this->actingAsOrgUser($orgRole);
    }

    private function actingAsOrgUser(OrganizationRole $role): void
    {
        $this->actingAsDashboard($this->makeOrgUser(Organization::factory()->create(), $role));
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
