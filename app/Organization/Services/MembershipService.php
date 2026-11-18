<?php

namespace App\Organization\Services;

use App\Models\User;
use App\Organization\Enums\AuditAction;
use App\Organization\Enums\MembershipJoinMethod;
use App\Organization\Enums\MembershipStatus;
use App\Organization\Exceptions\OrganizationActionException;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Repositories\Contracts\OrganizationMembershipRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Admin membership lifecycle: invited → active → suspended → deactivated / expired, plus restore.
 * Seats are taken and released only through SeatService.
 */
class MembershipService
{
    public function __construct(
        protected OrganizationMembershipRepositoryInterface $memberships,
        protected SeatService $seats,
        protected AuditLogger $audit,
    ) {}

    public function list(Organization $organization, ?MembershipStatus $status = null, int $perPage = 25): LengthAwarePaginator
    {
        return $this->memberships->paginateFor($organization->id, $status, $perPage);
    }

    /**
     * A suspended member does not hold a seat and loses delegated admin rights until restored.
     */
    public function suspend(OrganizationMembership $membership, string $reason, User $actor): OrganizationMembership
    {
        if (! $membership->status->canBeSuspended()) {
            throw OrganizationActionException::invalidMembershipTransition($membership->status, 'suspended');
        }

        return DB::transaction(function () use ($membership, $reason, $actor) {
            $this->memberships->update($membership, [
                'status' => MembershipStatus::SUSPENDED,
                'deactivated_at' => now(),
                'deactivation_reason' => $reason,
            ]);

            $this->audit->record(
                action: AuditAction::MEMBERSHIP_SUSPENDED,
                organization: $membership->organization_id,
                target: $membership,
                metadata: ['user_id' => $membership->user_id, 'reason' => $reason],
                actor: $actor,
            );

            return $membership->load('user:id,name,email');
        });
    }

    /**
     * Restoring takes a seat again, so it is subject to the organization status and the seat limit.
     */
    public function restore(OrganizationMembership $membership, User $actor): OrganizationMembership
    {
        if (! $membership->status->canBeRestored()) {
            throw OrganizationActionException::invalidMembershipTransition($membership->status, 'restored');
        }

        $result = $this->seats->allocate(
            organization: $membership->organization,
            user: $membership->user,
            via: MembershipJoinMethod::ADMIN,
            actor: $actor,
            membership: $membership,
        );

        return $result->membership->load('user:id,name,email');
    }

    public function deactivate(OrganizationMembership $membership, string $reason, User $actor): OrganizationMembership
    {
        return $this->seats->release($membership, $reason, $actor)->load('user:id,name,email');
    }
}
