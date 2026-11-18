<?php

namespace App\Organization\Policies;

use App\Models\User;
use App\Organization\Enums\OrganizationPermission;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationRoleAssignment;
use App\Organization\Services\OrganizationAccessService;
use Illuminate\Auth\Access\Response;

class OrganizationRoleAssignmentPolicy
{
    public function __construct(
        protected OrganizationAccessService $access
    ) {}

    public function viewAny(User $user, Organization $organization): Response
    {
        return $this->access->hasPermission($user, OrganizationPermission::ROLES_VIEW, $organization)
            ? Response::allow()
            : Response::deny(OrganizationPermission::ROLES_VIEW->deniedMessage());
    }

    /**
     * Pivot override can assign any role. Inside an organization a user can only assign
     * roles ranked below their own: ORG_OWNER → ADMIN/ANALYST/MEMBER, ORG_ADMIN → ANALYST/MEMBER.
     */
    public function assign(User $user, Organization $organization, OrganizationRole $role): Response
    {
        return $this->checkRank($user, $organization, $role, 'You can only assign roles lower than your own.');
    }

    // Same rank rule as assigning; this also stops admins from revoking their own role.
    public function revoke(User $user, OrganizationRoleAssignment $assignment): Response
    {
        return $this->checkRank($user, $assignment->organization, $assignment->role, 'You can only revoke roles lower than your own.');
    }

    private function checkRank(User $user, Organization $organization, OrganizationRole $role, string $rankMessage): Response
    {
        if ($this->access->hasPermission($user, OrganizationPermission::ORGANIZATION_OVERRIDE, $organization)) {
            return Response::allow();
        }

        if (! $this->access->hasPermission($user, OrganizationPermission::ROLES_MANAGE, $organization)) {
            return Response::deny(OrganizationPermission::ROLES_MANAGE->deniedMessage());
        }

        return $this->access->highestRoleIn($user, $organization)?->outranks($role)
            ? Response::allow()
            : Response::deny($rankMessage);
    }
}
