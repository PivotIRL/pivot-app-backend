<?php

namespace App\Organization\Services;

use App\Models\User;
use App\Organization\Enums\OrganizationPermission;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Enums\OrganizationStatus;
use App\Organization\Models\Organization;
use App\Organization\Repositories\Contracts\OrganizationMembershipRepositoryInterface;
use App\Organization\Repositories\Contracts\OrganizationRoleAssignmentRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Answers "who can do this?" for the Corporate module.
 * Global Pivot roles apply everywhere; organization roles apply only inside their own organization.
 */
class OrganizationAccessService
{
    public function __construct(
        protected OrganizationRoleAssignmentRepositoryInterface $roleAssignments,
        protected OrganizationMembershipRepositoryInterface $memberships,
    ) {}

    /**
     * @return Collection<int, OrganizationRole>
     */
    public function rolesIn(User $user, Organization $organization): Collection
    {
        // A suspended member keeps their role assignments but cannot use them until restored.
        if ($this->memberships->isSuspendedMember($organization->id, $user->id)) {
            return collect();
        }

        return $this->roleAssignments->activeRolesFor($user->id, $organization->id);
    }

    public function highestRoleIn(User $user, Organization $organization): ?OrganizationRole
    {
        return $this->rolesIn($user, $organization)
            ->sortByDesc(fn (OrganizationRole $role) => $role->rank())
            ->first();
    }

    /**
     * The highest role the user holds, even while their membership is suspended (used to rank targets).
     */
    public function highestAssignedRoleIn(User $user, Organization $organization): ?OrganizationRole
    {
        return $this->roleAssignments->activeRolesFor($user->id, $organization->id)
            ->sortByDesc(fn (OrganizationRole $role) => $role->rank())
            ->first();
    }

    public function hasPermission(User $user, OrganizationPermission $permission, ?Organization $organization = null): bool
    {
        if ($user->pivot_role?->grants($permission)) {
            return true;
        }

        if ($organization === null || ! $this->allowsDelegatedAdministration($organization)) {
            return false;
        }

        return $this->rolesIn($user, $organization)
            ->contains(fn (OrganizationRole $role) => $role->grants($permission));
    }

    /**
     * Can the user enter this organization's admin scope at all (before any action-level policy check)?
     */
    public function canEnterTenant(User $user, Organization $organization): bool
    {
        if ($user->isPivotStaff()) {
            return true;
        }

        return $this->rolesIn($user, $organization)
            ->contains(fn (OrganizationRole $role) => $role->isAdministrative());
    }

    /**
     * Suspended or removed organizations lose all delegated rights. Pivot staff keep theirs.
     */
    public function allowsDelegatedAdministration(Organization $organization): bool
    {
        return $organization->status !== OrganizationStatus::SUSPENDED && ! $organization->trashed();
    }
}
