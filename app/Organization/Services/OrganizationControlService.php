<?php

namespace App\Organization\Services;

use App\Models\User;
use App\Organization\Enums\AuditAction;
use App\Organization\Enums\OrganizationStatus;
use App\Organization\Exceptions\OrganizationActionException;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Repositories\Contracts\OrganizationRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * The Pivot control boundary: Super Admin can always suspend, reactivate or remove any organization.
 * Normal organization lifecycle (create, edit, renew, expire) belongs to OrganizationService (B3).
 */
class OrganizationControlService
{
    public function __construct(
        protected OrganizationRepositoryInterface $organizations,
        protected AuditLogger $audit,
        protected ContractMembershipService $contractMemberships,
        protected SeatService $seats,
    ) {}

    public function suspend(Organization $organization, User $actor, string $reason): Organization
    {
        if ($organization->status === OrganizationStatus::SUSPENDED) {
            throw OrganizationActionException::invalidStatusTransition($organization->status, 'suspended');
        }

        return $this->changeStatus($organization, OrganizationStatus::SUSPENDED, AuditAction::ORGANIZATION_SUSPENDED, $actor, $reason);
    }

    public function reactivate(Organization $organization, User $actor, string $reason): Organization
    {
        if ($organization->status !== OrganizationStatus::SUSPENDED) {
            throw OrganizationActionException::invalidStatusTransition($organization->status, 'reactivated');
        }

        if ($organization->contract_end_at?->isPast()) {
            throw OrganizationActionException::invalidStatusTransition($organization->status, 'reactivated because its contract has ended; renew the contract instead');
        }

        // Lifting a suspension never makes an organization active before its contract starts.
        $status = $organization->contract_start_at?->isFuture()
            ? OrganizationStatus::PENDING
            : OrganizationStatus::ACTIVE;

        return DB::transaction(function () use ($organization, $status, $actor, $reason) {
            $organization = $this->changeStatus($organization, $status, AuditAction::ORGANIZATION_REACTIVATED, $actor, $reason);

            if ($status === OrganizationStatus::ACTIVE) {
                $this->contractMemberships->restoreAfterContractExpiry($organization, $actor);
            }

            return $organization;
        });
    }

    public function remove(Organization $organization, User $actor, string $reason): void
    {
        DB::transaction(function () use ($organization, $actor, $reason) {
            $this->organizations->delete($organization);
            // Released seats let these users join another organization (one active organization per user).
            $this->seats->releaseAll($organization, OrganizationMembership::REASON_ORGANIZATION_REMOVED, $actor);

            $this->audit->record(
                action: AuditAction::ORGANIZATION_REMOVED,
                organization: $organization,
                target: $organization,
                metadata: ['reason' => $reason],
                actor: $actor,
            );
        });
    }

    private function changeStatus(
        Organization $organization,
        OrganizationStatus $status,
        AuditAction $action,
        User $actor,
        string $reason,
    ): Organization {
        $previous = $organization->status;

        return DB::transaction(function () use ($organization, $status, $action, $actor, $reason, $previous) {
            $this->organizations->updateStatus($organization, $status);
            $organization->currentContract?->update(['status' => $status]);

            $this->audit->record(
                action: $action,
                organization: $organization,
                target: $organization,
                metadata: [
                    'previous_status' => $previous->value,
                    'new_status' => $status->value,
                    'reason' => $reason,
                ],
                actor: $actor,
            );

            return $organization;
        });
    }
}
