<?php

namespace App\Organization\Enums;

/**
 * Every action that can be authorized in the Corporate module.
 * Which role grants which permission lives on PivotRole and OrganizationRole.
 */
enum OrganizationPermission: string
{
    // Pivot-controlled: global authority that is never delegated to an organization.
    case ORGANIZATIONS_VIEW_ANY = 'organizations.view_any';
    case ORGANIZATION_CREATE = 'organization.create';
    case ORGANIZATION_MANAGE_CONTRACT = 'organization.contract.manage';
    case ORGANIZATION_MANAGE_AUTH_SETTINGS = 'organization.auth_settings.manage';
    case ORGANIZATION_MANAGE_REPORTING_SETTINGS = 'organization.reporting_settings.manage';
    case ORGANIZATION_SUSPEND = 'organization.suspend';
    case ORGANIZATION_OVERRIDE = 'organization.override';
    case ORGANIZATION_REMOVE = 'organization.remove';

    // Delegated: can be granted to organization roles inside their own organization.
    case ORGANIZATION_VIEW = 'organization.view';
    case ORGANIZATION_UPDATE_SETTINGS = 'organization.settings.update';
    case MEMBERS_VIEW = 'members.view';
    case MEMBERS_MANAGE = 'members.manage';
    case INVITATIONS_MANAGE = 'invitations.manage';
    case DOMAINS_MANAGE = 'domains.manage';
    case CODES_MANAGE = 'codes.manage';
    case ROLES_VIEW = 'roles.view';
    case ROLES_MANAGE = 'roles.manage';
    case REPORTS_VIEW = 'reports.view';
    case AUDIT_LOGS_VIEW = 'audit_logs.view';

    /**
     * Pivot-controlled permissions are global authority held only by Pivot staff roles.
     * Delegated permissions are the only ones an organization role can ever hold.
     */
    public function isDelegated(): bool
    {
        return ! in_array($this, [
            self::ORGANIZATIONS_VIEW_ANY,
            self::ORGANIZATION_CREATE,
            self::ORGANIZATION_MANAGE_CONTRACT,
            self::ORGANIZATION_MANAGE_AUTH_SETTINGS,
            self::ORGANIZATION_MANAGE_REPORTING_SETTINGS,
            self::ORGANIZATION_SUSPEND,
            self::ORGANIZATION_OVERRIDE,
            self::ORGANIZATION_REMOVE,
        ], true);
    }

    /**
     * @return list<self>
     */
    public static function delegated(): array
    {
        return array_values(array_filter(self::cases(), fn (self $permission) => $permission->isDelegated()));
    }

    public function group(): OrganizationPermissionGroup
    {
        return match ($this) {
            self::ORGANIZATIONS_VIEW_ANY,
            self::ORGANIZATION_CREATE,
            self::ORGANIZATION_VIEW,
            self::ORGANIZATION_SUSPEND,
            self::ORGANIZATION_OVERRIDE,
            self::ORGANIZATION_REMOVE => OrganizationPermissionGroup::ORGANIZATION,
            self::ORGANIZATION_UPDATE_SETTINGS,
            self::ORGANIZATION_MANAGE_AUTH_SETTINGS,
            self::ORGANIZATION_MANAGE_REPORTING_SETTINGS => OrganizationPermissionGroup::SETTINGS,
            self::ORGANIZATION_MANAGE_CONTRACT => OrganizationPermissionGroup::CONTRACTS,
            self::MEMBERS_VIEW, self::MEMBERS_MANAGE => OrganizationPermissionGroup::MEMBERS,
            self::INVITATIONS_MANAGE => OrganizationPermissionGroup::INVITATIONS,
            self::DOMAINS_MANAGE, self::CODES_MANAGE => OrganizationPermissionGroup::DOMAINS_AND_CODES,
            self::ROLES_VIEW, self::ROLES_MANAGE => OrganizationPermissionGroup::ROLES,
            self::REPORTS_VIEW => OrganizationPermissionGroup::REPORTS,
            self::AUDIT_LOGS_VIEW => OrganizationPermissionGroup::AUDIT_LOGS,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ORGANIZATIONS_VIEW_ANY => 'View all organizations',
            self::ORGANIZATION_CREATE => 'Create organizations',
            self::ORGANIZATION_MANAGE_CONTRACT => 'Manage contract',
            self::ORGANIZATION_MANAGE_AUTH_SETTINGS => 'Manage authentication settings',
            self::ORGANIZATION_MANAGE_REPORTING_SETTINGS => 'Manage reporting settings',
            self::ORGANIZATION_SUSPEND => 'Suspend and reactivate organization',
            self::ORGANIZATION_OVERRIDE => 'Override organization settings',
            self::ORGANIZATION_REMOVE => 'Remove organization',
            self::ORGANIZATION_VIEW => 'View organization',
            self::ORGANIZATION_UPDATE_SETTINGS => 'Update organization settings',
            self::MEMBERS_VIEW => 'View members',
            self::MEMBERS_MANAGE => 'Manage members',
            self::INVITATIONS_MANAGE => 'Manage invitations',
            self::DOMAINS_MANAGE => 'Manage domains',
            self::CODES_MANAGE => 'Manage redemption codes',
            self::ROLES_VIEW => 'View roles',
            self::ROLES_MANAGE => 'Manage roles',
            self::REPORTS_VIEW => 'View reports',
            self::AUDIT_LOGS_VIEW => 'View audit logs',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ORGANIZATIONS_VIEW_ANY => 'List and search every organization on Pivot.',
            self::ORGANIZATION_CREATE => 'Create new organizations with their first contract.',
            self::ORGANIZATION_MANAGE_CONTRACT => "View, update and renew an organization's contracts, including its package and seat limit.",
            self::ORGANIZATION_MANAGE_AUTH_SETTINGS => "Manage an organization's authentication settings.",
            self::ORGANIZATION_MANAGE_REPORTING_SETTINGS => "Manage an organization's reporting settings.",
            self::ORGANIZATION_SUSPEND => 'Suspend an organization, or reactivate a suspended one.',
            self::ORGANIZATION_OVERRIDE => 'Bypass role rank rules when managing members and roles, including owners.',
            self::ORGANIZATION_REMOVE => 'Remove an organization from Pivot.',
            self::ORGANIZATION_VIEW => "View the organization's profile and status.",
            self::ORGANIZATION_UPDATE_SETTINGS => "Update the organization's settings.",
            self::MEMBERS_VIEW => "View the organization's members.",
            self::MEMBERS_MANAGE => 'Suspend, restore and remove members.',
            self::INVITATIONS_MANAGE => 'Send and cancel invitations to join the organization.',
            self::DOMAINS_MANAGE => 'Add, verify, set primary and remove work email domains.',
            self::CODES_MANAGE => 'Create and revoke redemption codes.',
            self::ROLES_VIEW => "View who holds each of the organization's roles.",
            self::ROLES_MANAGE => 'Assign and revoke roles ranked below your own.',
            self::REPORTS_VIEW => "View the organization's reports.",
            self::AUDIT_LOGS_VIEW => "View the organization's audit history.",
        };
    }

    /**
     * The 403 message shown when a user lacks this permission.
     */
    public function deniedMessage(): string
    {
        return match ($this) {
            self::ORGANIZATIONS_VIEW_ANY => 'Only Pivot staff can view the list of all organizations.',
            self::ORGANIZATION_CREATE => 'You do not have permission to create organizations.',
            self::ORGANIZATION_MANAGE_CONTRACT => "You do not have permission to manage this organization's contract.",
            self::ORGANIZATION_MANAGE_AUTH_SETTINGS => "You do not have permission to manage this organization's authentication settings.",
            self::ORGANIZATION_MANAGE_REPORTING_SETTINGS => "You do not have permission to manage this organization's reporting settings.",
            self::ORGANIZATION_SUSPEND => 'You do not have permission to suspend or reactivate this organization.',
            self::ORGANIZATION_OVERRIDE => "You do not have permission to override this organization's settings.",
            self::ORGANIZATION_REMOVE => 'You do not have permission to remove this organization.',
            self::ORGANIZATION_VIEW => 'You do not have permission to view this organization.',
            self::ORGANIZATION_UPDATE_SETTINGS => "You do not have permission to update this organization's settings.",
            self::MEMBERS_VIEW => "You do not have permission to view this organization's members.",
            self::MEMBERS_MANAGE => "You do not have permission to manage this organization's members.",
            self::INVITATIONS_MANAGE => "You do not have permission to manage this organization's invitations.",
            self::DOMAINS_MANAGE => "You do not have permission to manage this organization's domains.",
            self::CODES_MANAGE => "You do not have permission to manage this organization's redemption codes.",
            self::ROLES_VIEW => "You do not have permission to view this organization's roles.",
            self::ROLES_MANAGE => "You do not have permission to manage this organization's roles.",
            self::REPORTS_VIEW => "You do not have permission to view this organization's reports.",
            self::AUDIT_LOGS_VIEW => "You do not have permission to view this organization's audit logs.",
        };
    }
}
