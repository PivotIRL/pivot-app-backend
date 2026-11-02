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
}
