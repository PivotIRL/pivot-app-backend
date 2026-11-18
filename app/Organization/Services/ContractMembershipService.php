<?php

namespace App\Organization\Services;

use App\Models\User;
use App\Organization\Enums\AuditAction;
use App\Organization\Enums\AuditActorType;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Repositories\Contracts\OrganizationMembershipRepositoryInterface;

/**
 * What happens to employee memberships when a contract ends and when access comes back.
 *
 * Expiry: every ACTIVE membership becomes EXPIRED with reason "organization_contract_expired".
 * Restore (organization becomes ACTIVE again): only memberships expired by the contract are restored, and only if
 *   - the user is not active in another organization (one active organization per user),
 *   - the membership's own expires_at has not passed,
 *   - there is a free seat under the current seat_limit (longest-standing members first).
 * Memberships that were offboarded, deactivated or suspended for any other reason are never restored.
 */
class ContractMembershipService
{
    public function __construct(
        protected OrganizationMembershipRepositoryInterface $memberships,
        protected AuditLogger $audit,
    ) {}

    public function expireForContractEnd(Organization $organization): int
    {
        return $this->memberships->expireActive($organization->id, OrganizationMembership::REASON_CONTRACT_EXPIRED, now());
    }

    /**
     * Call inside the transaction that makes the organization ACTIVE.
     *
     * @param  User|null  $actor  Null when a scheduled job activates the organization.
     */
    public function restoreAfterContractExpiry(Organization $organization, ?User $actor = null): int
    {
        $now = now();
        $eligible = $this->memberships
            ->restorableAfterContractExpiry($organization->id, $now)
            ->unique('user_id');

        if ($eligible->isEmpty()) {
            return 0;
        }

        $freeSeats = max(0, $organization->seat_limit - $this->memberships->countActive($organization->id));
        $restored = $this->memberships->restore($eligible->take($freeSeats)->pluck('id')->all(), $now);

        $this->audit->record(
            action: AuditAction::ORGANIZATION_MEMBERSHIPS_RESTORED,
            organization: $organization,
            target: $organization,
            metadata: [
                'restored' => $restored,
                'not_restored_no_free_seat' => $eligible->count() - $restored,
                'seat_limit' => $organization->seat_limit,
            ],
            actor: $actor,
            actorType: $actor ? null : AuditActorType::SYSTEM,
        );

        return $restored;
    }
}
