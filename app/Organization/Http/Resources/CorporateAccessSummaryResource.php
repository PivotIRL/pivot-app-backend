<?php

namespace App\Organization\Http\Resources;

use App\Organization\Data\CorporateOrganizationAccess;
use App\Organization\Enums\OrganizationPermission;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Models\OrganizationMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Organization\Data\CorporateAccessSummary
 */
class CorporateAccessSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ],
            'dashboard_account' => OrganizationDashboardAccountResource::make($this->account)->resolve($request),
            'pivot_role' => $this->pivotRole?->value,
            'pivot_permissions' => $this->permissionValues($this->pivotPermissions),
            'can_access_dashboard' => $this->canAccessDashboard(),
            'organizations' => array_map(
                fn (CorporateOrganizationAccess $access) => $this->organizationAccess($access, $request),
                $this->organizations,
            ),
            'memberships' => array_map(
                fn (OrganizationMembership $membership) => [
                    ...OrganizationMembershipResource::make($membership)->resolve($request),
                    'organization_name' => $membership->organization->name,
                ],
                $this->memberships,
            ),
        ];
    }

    private function organizationAccess(CorporateOrganizationAccess $access, Request $request): array
    {
        $organization = $access->organization;

        return [
            // A summary only: members must not see seat limits or contract history here.
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'status' => $organization->status?->value,
                'package_code' => $organization->package_code?->value,
                'contract_end_at' => $organization->contract_end_at,
            ],
            'membership' => $access->membership
                ? OrganizationMembershipResource::make($access->membership)->resolve($request)
                : null,
            'roles' => array_map(fn (OrganizationRole $role) => $role->value, $access->roles),
            'permissions' => $this->permissionValues($access->permissions),
            'can_access_dashboard' => $access->canAccessDashboard,
        ];
    }

    /**
     * @param  list<OrganizationPermission>  $permissions
     * @return list<string>
     */
    private function permissionValues(array $permissions): array
    {
        return array_map(fn (OrganizationPermission $permission) => $permission->value, $permissions);
    }
}
