<?php

namespace App\Organization\Enums;

/**
 * How a membership became active. Stored on organization_memberships.joined_via.
 */
enum MembershipJoinMethod: string
{
    case WORK_EMAIL = 'work_email';
    case CODE = 'code';
    case INVITATION = 'invitation';
    case ADMIN = 'admin';
    case SSO = 'sso';
    case SCIM = 'scim';

    /**
     * Self-service joins cannot undo an admin decision: a member who was suspended or
     * deactivated in an organization can only come back through an admin or an invitation.
     */
    public function isSelfService(): bool
    {
        return in_array($this, [self::WORK_EMAIL, self::CODE], true);
    }
}
