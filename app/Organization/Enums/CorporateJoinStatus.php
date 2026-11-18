<?php

namespace App\Organization\Enums;

/**
 * Machine-readable result of every corporate join attempt (work email, code, invitation, registration).
 * Clients branch on the value; the message is for display only.
 */
enum CorporateJoinStatus: string
{
    case JOINED = 'JOINED';
    case ALREADY_MEMBER = 'ALREADY_MEMBER';

    case DOMAIN_NOT_RECOGNIZED = 'DOMAIN_NOT_RECOGNIZED';
    case INVALID_CODE = 'INVALID_CODE';
    case CODE_NOT_STARTED = 'CODE_NOT_STARTED';
    case CODE_EXPIRED = 'CODE_EXPIRED';
    case CODE_LIMIT_REACHED = 'CODE_LIMIT_REACHED';
    case INVALID_INVITATION = 'INVALID_INVITATION';
    case INVITATION_EXPIRED = 'INVITATION_EXPIRED';
    case INVITATION_EMAIL_MISMATCH = 'INVITATION_EMAIL_MISMATCH';

    case ORGANIZATION_NOT_ACTIVE = 'ORGANIZATION_NOT_ACTIVE';
    case ORGANIZATION_SUSPENDED = 'ORGANIZATION_SUSPENDED';
    case CONTRACT_EXPIRED = 'CONTRACT_EXPIRED';
    case SEAT_LIMIT_REACHED = 'SEAT_LIMIT_REACHED';
    case ALREADY_MEMBER_OF_OTHER_ORGANIZATION = 'ALREADY_MEMBER_OF_OTHER_ORGANIZATION';
    case MEMBERSHIP_SUSPENDED = 'MEMBERSHIP_SUSPENDED';
    case MEMBERSHIP_DEACTIVATED = 'MEMBERSHIP_DEACTIVATED';

    // Registration only: the join could not be attempted; the personal account is still created.
    case UNAVAILABLE = 'UNAVAILABLE';

    public function isMember(): bool
    {
        return $this === self::JOINED || $this === self::ALREADY_MEMBER;
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::JOINED => 201,
            self::ALREADY_MEMBER => 200,
            self::DOMAIN_NOT_RECOGNIZED, self::INVALID_CODE, self::INVALID_INVITATION => 404,
            self::INVITATION_EMAIL_MISMATCH, self::MEMBERSHIP_SUSPENDED, self::MEMBERSHIP_DEACTIVATED => 403,
            self::UNAVAILABLE => 503,
            default => 409,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::JOINED => 'You have joined the organization.',
            self::ALREADY_MEMBER => 'You are already a member of this organization.',
            self::DOMAIN_NOT_RECOGNIZED => 'Your email domain is not linked to an organization.',
            self::INVALID_CODE => 'This organization code is not valid.',
            self::CODE_NOT_STARTED => 'This organization code is not active yet.',
            self::CODE_EXPIRED => 'This organization code has expired.',
            self::CODE_LIMIT_REACHED => 'This organization code has reached its use limit.',
            self::INVALID_INVITATION => 'This invitation is not valid.',
            self::INVITATION_EXPIRED => 'This invitation has expired.',
            self::INVITATION_EMAIL_MISMATCH => 'This invitation was sent to a different email address.',
            self::ORGANIZATION_NOT_ACTIVE => 'This organization is not accepting members yet.',
            self::ORGANIZATION_SUSPENDED => 'This organization is suspended.',
            self::CONTRACT_EXPIRED => "This organization's contract has expired.",
            self::SEAT_LIMIT_REACHED => 'This organization has no free seats.',
            self::ALREADY_MEMBER_OF_OTHER_ORGANIZATION => 'You are already an active member of another organization.',
            self::MEMBERSHIP_SUSPENDED => 'Your membership in this organization is suspended. Contact your administrator.',
            self::MEMBERSHIP_DEACTIVATED => 'Your membership in this organization was deactivated. Contact your administrator.',
            self::UNAVAILABLE => 'Corporate join could not be completed right now.',
        };
    }
}
