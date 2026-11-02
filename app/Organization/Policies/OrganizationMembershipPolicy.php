<?php

namespace App\Organization\Policies;

use App\Models\User;
use App\Organization\Enums\OrganizationPermission;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Services\OrganizationAccessService;

class OrganizationMembershipPolicy
{
    public function __construct(
        protected OrganizationAccessService $access
    ) {}

    /**
     * Suspend, restore or deactivate a member. Deactivation revokes roles, so the same rank rule as
     * role management applies: an admin cannot act on themselves or on someone with an equal or higher role.
     * Pivot override can act on anyone.
     */
    public function manage(User $user, OrganizationMembership $membership): bool
    {
        $organization = $membership->organization;

        if (! $this->access->hasPermission($user, OrganizationPermission::MEMBERS_MANAGE, $organization)) {
            return false;
        }

        if ($this->access->hasPermission($user, OrganizationPermission::ORGANIZATION_OVERRIDE, $organization)) {
            return true;
        }

        if ($user->id === $membership->user_id) {
            return false;
        }

        $targetRole = $this->access->highestAssignedRoleIn($membership->user, $organization);

        if ($targetRole === null) {
            return true;
        }

        return $this->access->highestRoleIn($user, $organization)?->outranks($targetRole) ?? false;
    }
}
