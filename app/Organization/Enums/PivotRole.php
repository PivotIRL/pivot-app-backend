<?php

namespace App\Organization\Enums;

use App\Organization\Enums\OrganizationPermission as Permission;

/**
 * Global roles for Pivot staff, stored on users.pivot_role.
 */
enum PivotRole: string
{
    case PIVOT_SUPER_ADMIN = 'PIVOT_SUPER_ADMIN';
    case PIVOT_SUPPORT = 'PIVOT_SUPPORT';

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            // Super Admin always keeps full authority, including over permissions added later.
            self::PIVOT_SUPER_ADMIN => Permission::cases(),

            // Support: no commercial, suspension, removal, role or authentication changes.
            self::PIVOT_SUPPORT => [
                Permission::ORGANIZATIONS_VIEW_ANY,
                Permission::ORGANIZATION_VIEW,
                Permission::MEMBERS_VIEW,
                Permission::MEMBERS_MANAGE,
                Permission::INVITATIONS_MANAGE,
                Permission::ROLES_VIEW,
                Permission::REPORTS_VIEW,
                Permission::AUDIT_LOGS_VIEW,
            ],
        };
    }

    public function grants(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
