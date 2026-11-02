<?php

namespace App\Organization\Policies;

use App\Models\User;
use App\Organization\Enums\OrganizationPermission;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationRoleAssignment;
use App\Organization\Services\OrganizationAccessService;

class OrganizationRoleAssignmentPolicy
{
    public function __construct(
        protected OrganizationAccessService $access
    ) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->access->hasPermission($user, OrganizationPermission::ROLES_VIEW, $organization);
    }

    /**
     * Pivot override can assign any role. Inside an organization a user can only assign
     * roles ranked below their own: ORG_OWNER → ADMIN/ANALYST/MEMBER, ORG_ADMIN → ANALYST/MEMBER.
     */
    public function assign(User $user, Organization $organization, OrganizationRole $role): bool
    {
        if ($this->access->hasPermission($user, OrganizationPermission::ORGANIZATION_OVERRIDE, $organization)) {
            return true;
        }

        if (! $this->access->hasPermission($user, OrganizationPermission::ROLES_MANAGE, $organization)) {
            return false;
        }

        return $this->access->highestRoleIn($user, $organization)?->outranks($role) ?? false;
    }

    // Same rank rule as assigning; this also stops admins from revoking their own role.
    public function revoke(User $user, OrganizationRoleAssignment $assignment): bool
    {
        return $this->assign($user, $assignment->organization, $assignment->role);
    }
}
