<?php

namespace App\Organization\Enums;

enum OrganizationDomainStatus: string
{
    case PENDING = 'pending';
    case VERIFIED = 'verified';
}
