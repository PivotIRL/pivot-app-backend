<?php

namespace App\Organization\Data;

use App\Models\User;
use App\Organization\Enums\OrganizationPermission;
use App\Organization\Enums\PivotRole;
use App\Organization\Models\OrganizationDashboardAccount;
use App\Organization\Models\OrganizationMembership;

/**
 * The signed-in dashboard user's access, returned by dashboard login and GET /corporate/me.
 */
final class CorporateAccessSummary
{
    /**
     * @param  list<OrganizationPermission>  $pivotPermissions  Global permissions from the Pivot staff role.
     * @param  list<CorporateOrganizationAccess>  $organizations  Only organizations the user can access right now.
     * @param  list<OrganizationMembership>  $memberships  Every current membership, with `organization` loaded.
     */
    public function __construct(
        public readonly User $user,
        public readonly OrganizationDashboardAccount $account,
        public readonly ?PivotRole $pivotRole,
        public readonly array $pivotPermissions,
        public readonly array $organizations,
        public readonly array $memberships,
    ) {}

    /**
     * Pivot staff can always enter; everyone else needs at least one organization that lets them in.
     */
    public function canAccessDashboard(): bool
    {
        if ($this->pivotRole !== null) {
            return true;
        }

        foreach ($this->organizations as $access) {
            if ($access->canAccessDashboard) {
                return true;
            }
        }

        return false;
    }
}
