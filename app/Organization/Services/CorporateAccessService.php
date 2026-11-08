<?php

namespace App\Organization\Services;

use App\Models\User;
use App\Organization\Data\CorporateAccessSummary;
use App\Organization\Data\CorporateOrganizationAccess;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Models\OrganizationDashboardAccount;
use App\Organization\Repositories\Contracts\OrganizationRepositoryInterface;

/**
 * Builds the "who am I on the dashboard?" summary. All access rules stay in OrganizationAccessService.
 */
class CorporateAccessService
{
    public function __construct(
        protected OrganizationRepositoryInterface $organizations,
        protected OrganizationAccessService $access,
    ) {}

    public function summaryFor(User $user, OrganizationDashboardAccount $account): CorporateAccessSummary
    {
        $organizations = $this->organizations->connectedToUser($user->id);

        return new CorporateAccessSummary(
            user: $user,
            account: $account,
            pivotRole: $user->pivot_role,
            pivotPermissions: $user->pivot_role?->permissions() ?? [],
            organizations: $organizations
                ->filter(fn ($organization) => $this->access->canUseDashboard($user, $organization))
                ->map(fn ($organization) => new CorporateOrganizationAccess(
                    organization: $organization,
                    membership: $organization->memberships->first(),
                    roles: $this->access->rolesIn($user, $organization)
                        ->sortByDesc(fn (OrganizationRole $role) => $role->rank())
                        ->values()
                        ->all(),
                    permissions: $this->access->permissionsIn($user, $organization),
                    canAccessDashboard: true,
                ))->values()->all(),
            memberships: $organizations
                ->filter(fn ($organization) => $organization->memberships->isNotEmpty())
                ->map(fn ($organization) => $organization->memberships->first()->setRelation('organization', $organization))
                ->values()
                ->all(),
        );
    }
}
