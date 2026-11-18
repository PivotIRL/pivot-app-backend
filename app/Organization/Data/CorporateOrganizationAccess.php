<?php

namespace App\Organization\Data;

use App\Organization\Enums\OrganizationPermission;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationMembership;

/**
 * What the signed-in user can currently do in one organization.
 */
final class CorporateOrganizationAccess
{
    /**
     * @param  list<OrganizationRole>  $roles  Usable roles, highest first. Empty while the membership is suspended.
     * @param  list<OrganizationPermission>  $permissions  Effective permissions, including any global Pivot ones.
     */
    public function __construct(
        public readonly Organization $organization,
        public readonly ?OrganizationMembership $membership,
        public readonly array $roles,
        public readonly array $permissions,
        public readonly bool $canAccessDashboard,
    ) {}
}
