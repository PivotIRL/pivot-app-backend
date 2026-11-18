<?php

namespace Tests\Feature\Organization;

use App\Organization\Enums\OrganizationPermission as P;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Enums\PivotRole;
use App\Organization\Models\Organization;
use App\Organization\Services\OrganizationAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Organization\Concerns\BuildsOrganizationActors;
use Tests\TestCase;

/**
 * The expected matrix is written out here on purpose, independent of the enums,
 * so any change to who-can-do-what has to be made in two places.
 */
class PermissionMatrixTest extends TestCase
{
    use BuildsOrganizationActors, RefreshDatabase;

    private const ORG_ADMINISTRATION = [
        P::ORGANIZATION_VIEW, P::ORGANIZATION_UPDATE_SETTINGS, P::MEMBERS_VIEW, P::MEMBERS_MANAGE,
        P::INVITATIONS_MANAGE, P::DOMAINS_MANAGE, P::CODES_MANAGE, P::ROLES_VIEW, P::ROLES_MANAGE,
        P::REPORTS_VIEW, P::AUDIT_LOGS_VIEW,
    ];

    public static function organizationRoleMatrix(): array
    {
        return [
            'owner' => [OrganizationRole::ORG_OWNER, self::ORG_ADMINISTRATION],
            'admin' => [OrganizationRole::ORG_ADMIN, self::ORG_ADMINISTRATION],
            'analyst' => [OrganizationRole::ORG_ANALYST, [P::ORGANIZATION_VIEW, P::REPORTS_VIEW]],
            'member' => [OrganizationRole::ORG_MEMBER, []],
        ];
    }

    public static function pivotRoleMatrix(): array
    {
        return [
            'super admin' => [PivotRole::PIVOT_SUPER_ADMIN, P::cases()],
            'support' => [PivotRole::PIVOT_SUPPORT, [
                P::ORGANIZATIONS_VIEW_ANY, P::ORGANIZATION_VIEW, P::MEMBERS_VIEW, P::MEMBERS_MANAGE,
                P::INVITATIONS_MANAGE, P::ROLES_VIEW, P::REPORTS_VIEW, P::AUDIT_LOGS_VIEW,
            ]],
        ];
    }

    #[DataProvider('organizationRoleMatrix')]
    public function test_organization_role_permissions_inside_own_organization(OrganizationRole $role, array $allowed): void
    {
        $organization = Organization::factory()->create();
        $user = $this->makeOrgUser($organization, $role);

        $this->assertMatrix($allowed, fn (P $permission) => $this->access()->hasPermission($user, $permission, $organization));
    }

    #[DataProvider('pivotRoleMatrix')]
    public function test_pivot_role_permissions_in_any_organization(PivotRole $role, array $allowed): void
    {
        $organization = Organization::factory()->create();
        $staff = $this->makeStaff($role);

        $this->assertMatrix($allowed, fn (P $permission) => $this->access()->hasPermission($staff, $permission, $organization));
    }

    #[DataProvider('organizationRoleMatrix')]
    public function test_organization_roles_grant_nothing_in_another_organization(OrganizationRole $role): void
    {
        $own = Organization::factory()->create();
        $other = Organization::factory()->create();
        $user = $this->makeOrgUser($own, $role);

        $this->assertMatrix([], fn (P $permission) => $this->access()->hasPermission($user, $permission, $other));
    }

    public function test_revoked_roles_grant_nothing(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->makeMember($organization);
        $this->grantRole($user, $organization, OrganizationRole::ORG_ADMIN)->update(['revoked_at' => now()]);

        $this->assertMatrix([], fn (P $permission) => $this->access()->hasPermission($user, $permission, $organization));
    }

    public function test_suspended_organization_loses_delegated_rights_but_super_admin_keeps_control(): void
    {
        $organization = Organization::factory()->suspended()->create();
        $owner = $this->makeOrgUser($organization, OrganizationRole::ORG_OWNER);
        $superAdmin = $this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN);

        $this->assertMatrix([], fn (P $permission) => $this->access()->hasPermission($owner, $permission, $organization));
        $this->assertMatrix(P::cases(), fn (P $permission) => $this->access()->hasPermission($superAdmin, $permission, $organization));
    }

    public function test_plain_users_have_no_global_permissions(): void
    {
        $user = $this->makeUser();

        $this->assertMatrix([], fn (P $permission) => $this->access()->hasPermission($user, $permission));
    }

    private function assertMatrix(array $allowed, callable $check): void
    {
        foreach (P::cases() as $permission) {
            $this->assertSame(
                in_array($permission, $allowed, true),
                $check($permission),
                "Unexpected result for permission [{$permission->value}].",
            );
        }
    }

    private function access(): OrganizationAccessService
    {
        return app(OrganizationAccessService::class);
    }
}
