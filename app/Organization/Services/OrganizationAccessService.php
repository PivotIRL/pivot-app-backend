<?php

namespace App\Organization\Services;

use App\Models\User;
use App\Organization\Enums\MembershipStatus;
use App\Organization\Enums\OrganizationPermission;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Enums\OrganizationStatus;
use App\Organization\Models\Organization;
use App\Organization\Repositories\Contracts\OrganizationMembershipRepositoryInterface;
use App\Organization\Repositories\Contracts\OrganizationRepositoryInterface;
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
        protected OrganizationRepositoryInterface $organizations,
    ) {}

    /**
     * @return Collection<int, OrganizationRole>
     */
    public function rolesIn(User $user, Organization $organization): Collection
    {
        // A member who is not active keeps their role assignments but cannot use them until restored.
        // A role with no membership row stays usable: the first-owner flow assigns ORG_OWNER before joining.
        $membership = $this->memberships->findFor($organization->id, $user->id);

        if ($membership && $membership->status !== MembershipStatus::ACTIVE) {
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
     * Only active or pending organizations have delegated rights. Suspended, expired and removed
     * organizations lose them until reactivated or renewed. Pivot staff keep theirs.
     */
    public function allowsDelegatedAdministration(Organization $organization): bool
    {
        return in_array($organization->status, [OrganizationStatus::ACTIVE, OrganizationStatus::PENDING], true)
            && ! $organization->trashed();
    }

    /**
     * Would the tenant middleware let this user into the organization's dashboard routes right now?
     */
    public function canUseDashboard(User $user, Organization $organization): bool
    {
        if ($user->isPivotStaff()) {
            return true;
        }

        return $this->allowsDelegatedAdministration($organization) && $this->canEnterTenant($user, $organization);
    }

    /**
     * Can the user use the Organization Dashboard at all right now? Checked at login and on every request.
     */
    public function hasDashboardAccess(User $user): bool
    {
        if ($user->isPivotStaff()) {
            return true;
        }

        return $this->organizations->withAdministrativeRoleHeldBy($user->id)
            ->contains(fn (Organization $organization) => $this->canUseDashboard($user, $organization));
    }

    /**
     * Every permission hasPermission() would grant in this organization, without a query per permission.
     *
     * @return list<OrganizationPermission>
     */
    public function permissionsIn(User $user, Organization $organization): array
    {
        $roles = $this->allowsDelegatedAdministration($organization)
            ? $this->rolesIn($user, $organization)
            : collect();

        return array_values(array_filter(
            OrganizationPermission::cases(),
            fn (OrganizationPermission $permission) => $user->pivot_role?->grants($permission)
                || $roles->contains(fn (OrganizationRole $role) => $role->grants($permission)),
        ));
    }
}
