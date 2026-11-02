<?php

namespace App\Organization\Exceptions;

use App\Organization\Enums\MembershipStatus;
use App\Organization\Enums\OrganizationStatus;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Business-rule failures in the Corporate module. Rendered as { "message": ... } by the API handler.
 */
class OrganizationActionException extends HttpException
{
    public static function notAnActiveMember(): self
    {
        return new self(422, 'The user must be an active member of this organization.');
    }

    public static function roleAlreadyAssigned(): self
    {
        return new self(409, 'The user already has this role in this organization.');
    }

    public static function roleAlreadyRevoked(): self
    {
        return new self(409, 'This role assignment has already been revoked.');
    }

    public static function invalidStatusTransition(OrganizationStatus $current, string $action): self
    {
        return new self(409, "An organization with status '{$current->value}' cannot be {$action}.");
    }

    public static function domainAlreadyVerified(): self
    {
        return new self(409, 'This domain has already been verified.');
    }

    public static function domainVerificationFailed(): self
    {
        return new self(422, 'The domain DNS verification record was not found or did not match.');
    }

    public static function domainVerifiedByAnotherOrganization(): self
    {
        return new self(409, 'This domain is already verified by another organization.');
    }

    public static function domainMustBeVerified(): self
    {
        return new self(409, 'Verify the domain before setting it as primary.');
    }

    public static function codeAlreadyInactive(): self
    {
        return new self(409, 'This redemption code is already inactive.');
    }

    public static function invalidMembershipTransition(MembershipStatus $current, string $action): self
    {
        return new self(409, "A membership with status '{$current->value}' cannot be {$action}.");
    }

    public static function alreadyActiveMember(): self
    {
        return new self(409, 'This person is already an active member of this organization.');
    }

    public static function invitationNotPending(): self
    {
        return new self(409, 'Only pending invitations can be revoked.');
    }
}
