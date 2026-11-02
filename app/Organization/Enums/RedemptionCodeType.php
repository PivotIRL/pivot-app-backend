<?php

namespace App\Organization\Enums;

enum RedemptionCodeType: string
{
    case COMPANY = 'company';
    case PILOT = 'pilot';
    case DEPARTMENT = 'department';
}
