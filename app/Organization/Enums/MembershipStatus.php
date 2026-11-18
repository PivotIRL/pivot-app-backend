<?php

namespace App\Organization\Enums;

enum MembershipStatus: string
{
    case INVITED = 'invited';
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
    case DEACTIVATED = 'deactivated';
    case EXPIRED = 'expired';

    // Only ACTIVE memberships hold a seat.

    public function canBeSuspended(): bool
    {
        return $this === self::ACTIVE;
    }

    public function canBeDeactivated(): bool
    {
        return in_array($this, [self::ACTIVE, self::SUSPENDED, self::INVITED], true);
    }

    // Restoring takes a seat again, so it always goes through SeatService::allocate().
    public function canBeRestored(): bool
    {
        return in_array($this, [self::SUSPENDED, self::DEACTIVATED, self::EXPIRED], true);
    }
}
