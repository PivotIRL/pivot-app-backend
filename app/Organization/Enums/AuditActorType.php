<?php

namespace App\Organization\Enums;

enum AuditActorType: string
{
    case USER = 'user';
    case PIVOT_STAFF = 'pivot_staff';
    case SYSTEM = 'system';
    case SSO = 'sso';
    case SCIM = 'scim';
    // A Pivot App Admin Dashboard admin (App\Models\Admin). actor_user_id stays NULL; metadata holds admin_id and admin_name.
    case ADMIN = 'admin';
}
