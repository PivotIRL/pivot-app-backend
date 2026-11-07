<?php

namespace App\Organization\Data;

use App\Organization\Enums\OrganizationPermission;
use App\Organization\Enums\PivotRole;

/**
 * The signed-in user's Corporate access, returned by GET /corporate/me.
 */
final class CorporateAccessSummary
{
    /**
     * @param  list<OrganizationPermission>  $pivotPermissions  Global permissions from the Pivot staff role.
     * @param  list<CorporateOrganizationAccess>  $organizations
     */
    public function __construct(
        public readonly ?PivotRole $pivotRole,
        public readonly array $pivotPermissions,
        public readonly array $organizations,
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
