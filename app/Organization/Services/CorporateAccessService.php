<?php

namespace App\Organization\Services;

use App\Models\User;
use App\Organization\Data\CorporateAccessSummary;
use App\Organization\Data\CorporateOrganizationAccess;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Repositories\Contracts\OrganizationRepositoryInterface;

/**
 * Builds the "who am I in Corporate?" summary. All access rules stay in OrganizationAccessService.
 */
class CorporateAccessService
{
    public function __construct(
        protected OrganizationRepositoryInterface $organizations,
        protected OrganizationAccessService $access,
    ) {}

    public function summaryFor(User $user): CorporateAccessSummary
    {
        $organizations = $this->organizations->connectedToUser($user->id);

        return new CorporateAccessSummary(
            pivotRole: $user->pivot_role,
            pivotPermissions: $user->pivot_role?->permissions() ?? [],
            organizations: $organizations->map(fn ($organization) => new CorporateOrganizationAccess(
                organization: $organization,
                membership: $organization->memberships->first(),
                roles: $this->access->rolesIn($user, $organization)
                    ->sortByDesc(fn (OrganizationRole $role) => $role->rank())
                    ->values()
                    ->all(),
                permissions: $this->access->permissionsIn($user, $organization),
                canAccessDashboard: $this->access->canUseDashboard($user, $organization),
            ))->all(),
        );
    }
}
