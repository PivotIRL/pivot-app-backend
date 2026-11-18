<?php

namespace App\Organization\Enums;

enum OrganizationStatus: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
    case EXPIRED = 'expired';
}
