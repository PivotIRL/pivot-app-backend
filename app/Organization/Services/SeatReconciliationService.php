<?php

namespace App\Organization\Services;

use App\Organization\Enums\AuditAction;
use App\Organization\Enums\AuditActorType;
use App\Organization\Enums\OrganizationStatus;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Repositories\Contracts\OrganizationMembershipRepositoryInterface;
use App\Organization\Repositories\Contracts\OrganizationRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Compares every organization's seat_limit and status with its active memberships.
 *
 * Fixed automatically (the seat must not be held):
 *   - active memberships in a removed organization → deactivated,
 *   - active memberships in an expired organization → expired (as the contract expiry job does).
 * Reported only (needs a human decision): more active members than seat_limit, e.g. after the limit was lowered.
 *
 * Every organization with a finding gets one SYSTEM audit row and a warning log line.
 */
class SeatReconciliationService
{
    public function __construct(
        protected OrganizationRepositoryInterface $organizations,
        protected OrganizationMembershipRepositoryInterface $memberships,
        protected AuditLogger $audit,
    ) {}

    /**
     * @return array{organizations_checked: int, organizations_with_findings: int, memberships_fixed: int}
     */
    public function reconcile(): array
    {
        $summary = ['organizations_checked' => 0, 'organizations_with_findings' => 0, 'memberships_fixed' => 0];

        $this->organizations->eachWithActiveMembershipCount(function (Organization $organization) use (&$summary): void {
            $summary['organizations_checked']++;

            $findings = $this->reconcileOrganization($organization);

            if ($findings !== []) {
                $summary['organizations_with_findings']++;
                $summary['memberships_fixed'] += $findings['memberships_fixed'] ?? 0;
            }
        });

        return $summary;
    }

    private function reconcileOrganization(Organization $organization): array
    {
        $active = (int) $organization->active_memberships_count;

        if ($active === 0) {
            return [];
        }

        return DB::transaction(function () use ($organization, $active) {
            $findings = match (true) {
                $organization->trashed() => [
                    'issue' => 'active_members_in_removed_organization',
                    'memberships_fixed' => $this->memberships->deactivateAll(
                        $organization->id, OrganizationMembership::REASON_ORGANIZATION_REMOVED, now()
                    ),
                ],
                $organization->status === OrganizationStatus::EXPIRED => [
                    'issue' => 'active_members_in_expired_organization',
                    'memberships_fixed' => $this->memberships->expireActive(
                        $organization->id, OrganizationMembership::REASON_CONTRACT_EXPIRED, now()
                    ),
                ],
                $active > $organization->seat_limit => [
                    'issue' => 'over_seat_limit',
                    'seats_over_limit' => $active - $organization->seat_limit,
                ],
                default => [],
            };

            if ($findings === []) {
                return [];
            }

            $metadata = ['active_memberships' => $active, 'seat_limit' => $organization->seat_limit, ...$findings];

            $this->audit->record(
                action: AuditAction::SEATS_RECONCILED,
                organization: $organization,
                target: $organization,
                metadata: $metadata,
                actorType: AuditActorType::SYSTEM,
            );

            Log::warning('Organization seat reconciliation finding.', ['organization_id' => $organization->id, ...$metadata]);

            return $findings;
        });
    }
}
