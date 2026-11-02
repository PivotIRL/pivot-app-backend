<?php

namespace App\Organization\Policies;

use App\Models\User;
use App\Organization\Enums\OrganizationPermission;
use App\Organization\Models\Organization;
use App\Organization\Services\OrganizationAccessService;

/**
 * One ability per Corporate action. The role → permission matrix lives on PivotRole and OrganizationRole.
 */
class OrganizationPolicy
{
    public function __construct(
        protected OrganizationAccessService $access
    ) {}

    // Pivot-controlled abilities.

    public function viewAny(User $user): bool
    {
        return $this->allows($user, OrganizationPermission::ORGANIZATIONS_VIEW_ANY);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, OrganizationPermission::ORGANIZATION_CREATE);
    }

    public function manageContract(User $user, Organization $organization): bool
    {
        return $this->allows($user, OrganizationPermission::ORGANIZATION_MANAGE_CONTRACT, $organization);
    }

    public function manageAuthSettings(User $user, Organization $organization): bool
    {
        return $this->allows($user, OrganizationPermission::ORGANIZATION_MANAGE_AUTH_SETTINGS, $organization);
    }

    public function manageReportingSettings(User $user, Organization $organization): bool
    {
        return $this->allows($user, OrganizationPermission::ORGANIZATION_MANAGE_REPORTING_SETTINGS, $organization);
    }

    public function suspend(User $user, Organization $organization): bool
    {
        return $this->allows($user, OrganizationPermission::ORGANIZATION_SUSPEND, $organization);
    }

    public function reactivate(User $user, Organization $organization): bool
    {
        return $this->allows($user, OrganizationPermission::ORGANIZATION_SUSPEND, $organization);
    }

    public function override(User $user, Organization $organization): bool
    {
        return $this->allows($user, OrganizationPermission::ORGANIZATION_OVERRIDE, $organization);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $this->allows($user, OrganizationPermission::ORGANIZATION_REMOVE, $organization);
    }

    // Delegated abilities.

    public function view(User $user, Organization $organization): bool
    {
        return $this->allows($user, OrganizationPermission::ORGANIZATION_VIEW, $organization);
    }

    public function updateSettings(User $user, Organization $organization): bool
    {
        return $this->allows($user, OrganizationPermission::ORGANIZATION_UPDATE_SETTINGS, $organization);
    }

    public function viewMembers(User $user, Organization $organization): bool
    {
        return $this->allows($user, OrganizationPermission::MEMBERS_VIEW, $organization);
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $this->allows($user, OrganizationPermission::MEMBERS_MANAGE, $organization);
    }

    public function manageInvitations(User $user, Organization $organization): bool
    {
        return $this->allows($user, OrganizationPermission::INVITATIONS_MANAGE, $organization);
    }

    public function manageDomains(User $user, Organization $organization): bool
    {
        return $this->allows($user, OrganizationPermission::DOMAINS_MANAGE, $organization);
    }

    public function manageCodes(User $user, Organization $organization): bool
    {
        return $this->allows($user, OrganizationPermission::CODES_MANAGE, $organization);
    }

    public function viewReports(User $user, Organization $organization): bool
    {
        return $this->allows($user, OrganizationPermission::REPORTS_VIEW, $organization);
    }

    public function viewAuditLogs(User $user, Organization $organization): bool
    {
        return $this->allows($user, OrganizationPermission::AUDIT_LOGS_VIEW, $organization);
    }

    private function allows(User $user, OrganizationPermission $permission, ?Organization $organization = null): bool
    {
        return $this->access->hasPermission($user, $permission, $organization);
    }
}
