<?php

namespace App\Organization\Enums;

enum Package: string
{
    case STANDARD = 'STANDARD';
    case PREMIUM = 'PREMIUM';
    case CUSTOM = 'CUSTOM';
}
