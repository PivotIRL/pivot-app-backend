<?php

namespace App\Organization\Enums;

enum OrganizationDashboardAccountStatus: string
{
    case PENDING_SETUP = 'pending_setup';
    case ACTIVE = 'active';
    case DISABLED = 'disabled';
}
