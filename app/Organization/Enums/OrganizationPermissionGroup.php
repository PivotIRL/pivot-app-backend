<?php

namespace App\Organization\Enums;

/**
 * Display groups for the Organization Dashboard. Each OrganizationPermission belongs to exactly one.
 * Case order is the order the dashboard shows them in.
 */
enum OrganizationPermissionGroup: string
{
    case ORGANIZATION = 'organization';
    case SETTINGS = 'settings';
    case CONTRACTS = 'contracts';
    case MEMBERS = 'members';
    case INVITATIONS = 'invitations';
    case DOMAINS_AND_CODES = 'domains_and_codes';
    case ROLES = 'roles';
    case REPORTS = 'reports';
    case AUDIT_LOGS = 'audit_logs';

    public function label(): string
    {
        return match ($this) {
            self::ORGANIZATION => 'Organization management',
            self::SETTINGS => 'Settings',
            self::CONTRACTS => 'Contract and seat management',
            self::MEMBERS => 'Member management',
            self::INVITATIONS => 'Invitation management',
            self::DOMAINS_AND_CODES => 'Domains and redemption codes',
            self::ROLES => 'Role management',
            self::REPORTS => 'Reports',
            self::AUDIT_LOGS => 'Audit logs',
        };
    }
}
