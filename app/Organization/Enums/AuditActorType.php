<?php

namespace App\Organization\Enums;

enum AuditActorType: string
{
    case USER = 'user';
    case PIVOT_STAFF = 'pivot_staff';
    case SYSTEM = 'system';
    case SSO = 'sso';
    case SCIM = 'scim';
}
