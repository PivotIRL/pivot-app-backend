<?php

namespace App\Organization\Enums;

enum OrganizationType: string
{
    case CORPORATE = 'CORPORATE';
    case EDUCATION = 'EDUCATION';
    case HEALTHCARE = 'HEALTHCARE';
    case GOVERNMENT = 'GOVERNMENT';
    case OTHER = 'OTHER';
}
