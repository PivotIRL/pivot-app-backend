<?php

namespace App\Organization\Data;

use App\Organization\Enums\CorporateJoinStatus;
use App\Organization\Models\OrganizationMembership;

/**
 * Outcome of a join attempt. A membership is present only when the user is (now) an active member.
 */
final class CorporateJoinResult
{
    public function __construct(
        public readonly CorporateJoinStatus $status,
        public readonly ?OrganizationMembership $membership = null,
    ) {}

    public static function joined(OrganizationMembership $membership): self
    {
        return new self(CorporateJoinStatus::JOINED, $membership);
    }

    public static function alreadyMember(OrganizationMembership $membership): self
    {
        return new self(CorporateJoinStatus::ALREADY_MEMBER, $membership);
    }

    public static function failed(CorporateJoinStatus $status): self
    {
        return new self($status);
    }

    public function isNewSeat(): bool
    {
        return $this->status === CorporateJoinStatus::JOINED;
    }
}
