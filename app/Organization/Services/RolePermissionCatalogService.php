<?php

namespace App\Organization\Services;

use App\Models\User;
use App\Organization\Enums\OrganizationPermission;
use App\Organization\Enums\OrganizationPermissionGroup;
use App\Organization\Enums\OrganizationRole;

/**
 * The role and permission definitions the Organization Dashboard can show, read straight from the enums.
<<<<<<< HEAD
 * It describes what exists, not what the user holds: GET /corporate/me answers that.
=======
 * It describes what exists, not what the user holds: GET /organization/me answers that.
>>>>>>> origin/corporate-backend
 */
class RolePermissionCatalogService
{
    /**
     * Every organization role, highest rank first. Pivot staff roles are global and not part of this list.
     *
     * @return list<OrganizationRole>
     */
    public function roles(): array
    {
        $roles = OrganizationRole::cases();

        usort($roles, fn (OrganizationRole $a, OrganizationRole $b) => $b->rank() <=> $a->rank());

        return $roles;
    }

    /**
     * Pivot staff see every permission. Organization users see only delegated ones: Pivot-controlled
     * permissions can never be granted to an organization role, so they are Pivot-internal.
     *
     * @return list<OrganizationPermission>
     */
    public function permissionsVisibleTo(User $user): array
    {
        return $user->isPivotStaff()
            ? OrganizationPermission::cases()
            : OrganizationPermission::delegated();
    }

    /**
     * The groups that contain at least one of the given permissions, in display order.
     *
     * @param  list<OrganizationPermission>  $permissions
     * @return list<array{group: OrganizationPermissionGroup, permissions: list<OrganizationPermission>}>
     */
    public function grouped(array $permissions): array
    {
        $groups = [];

        foreach (OrganizationPermissionGroup::cases() as $group) {
            $members = array_values(array_filter(
                $permissions,
                fn (OrganizationPermission $permission) => $permission->group() === $group,
            ));

            if ($members !== []) {
                $groups[] = ['group' => $group, 'permissions' => $members];
            }
        }

        return $groups;
    }
}
