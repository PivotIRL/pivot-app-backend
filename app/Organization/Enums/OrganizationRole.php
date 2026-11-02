<?php

namespace App\Organization\Enums;

use App\Organization\Enums\OrganizationPermission as Permission;

enum OrganizationRole: string
{
    case ORG_OWNER = 'ORG_OWNER';
    case ORG_ADMIN = 'ORG_ADMIN';
    case ORG_ANALYST = 'ORG_ANALYST';
    case ORG_MEMBER = 'ORG_MEMBER';

    /**
     * Delegated permissions inside the role's own organization.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        $administration = [
            Permission::ORGANIZATION_VIEW,
            Permission::ORGANIZATION_UPDATE_SETTINGS,
            Permission::MEMBERS_VIEW,
            Permission::MEMBERS_MANAGE,
            Permission::INVITATIONS_MANAGE,
            Permission::DOMAINS_MANAGE,
            Permission::CODES_MANAGE,
            Permission::ROLES_VIEW,
            Permission::ROLES_MANAGE,
            Permission::REPORTS_VIEW,
            Permission::AUDIT_LOGS_VIEW,
        ];

        return match ($this) {
            self::ORG_OWNER, self::ORG_ADMIN => $administration,
            self::ORG_ANALYST => [Permission::ORGANIZATION_VIEW, Permission::REPORTS_VIEW],
            self::ORG_MEMBER => [],
        };
    }

    public function grants(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Higher rank wins. A role can only assign or revoke roles ranked below it.
     */
    public function rank(): int
    {
        return match ($this) {
            self::ORG_OWNER => 40,
            self::ORG_ADMIN => 30,
            self::ORG_ANALYST => 20,
            self::ORG_MEMBER => 10,
        };
    }

    public function outranks(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    /**
     * Roles that may use the Corporate Admin portal. ORG_MEMBER is mobile access only.
     */
    public function isAdministrative(): bool
    {
        return $this !== self::ORG_MEMBER;
    }
}
