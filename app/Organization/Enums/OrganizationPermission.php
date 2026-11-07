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
