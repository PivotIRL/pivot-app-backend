<?php

namespace App\Organization\Concerns;

use App\Organization\Enums\PivotRole;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Models\OrganizationRoleAssignment;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Adds global Pivot staff roles and organization relations to the User model.
 * pivot_role is intentionally not fillable: it is only set through PivotRoleService.
 */
trait HasOrganizationRoles
{
    public function initializeHasOrganizationRoles(): void
    {
        $this->mergeCasts(['pivot_role' => PivotRole::class]);
    }

    public function organizationRoleAssignments(): HasMany
    {
        return $this->hasMany(OrganizationRoleAssignment::class);
    }

    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function isPivotStaff(): bool
    {
        return $this->pivot_role instanceof PivotRole;
    }

    public function hasPivotRole(PivotRole $role): bool
    {
        return $this->pivot_role === $role;
    }
}
