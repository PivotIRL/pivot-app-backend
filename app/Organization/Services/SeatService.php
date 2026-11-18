<?php

namespace App\Organization\Services;

use App\Models\User;
use App\Organization\Data\CorporateJoinResult;
use App\Organization\Enums\AuditAction;
use App\Organization\Enums\AuditActorType;
use App\Organization\Enums\CorporateJoinStatus;
use App\Organization\Enums\MembershipJoinMethod;
use App\Organization\Enums\MembershipStatus;
use App\Organization\Enums\OrganizationStatus;
use App\Organization\Exceptions\CorporateJoinException;
use App\Organization\Exceptions\OrganizationActionException;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Repositories\Contracts\OrganizationMembershipRepositoryInterface;
use App\Organization\Repositories\Contracts\OrganizationRepositoryInterface;
use App\Organization\Repositories\Contracts\OrganizationRoleAssignmentRepositoryInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only place where a membership takes or gives back a seat.
 *
 * One active membership = one seat. Allocation runs in a transaction with the organization row locked
 * (SELECT … FOR UPDATE), so parallel joins for the same organization are counted one after another and
 * the seat limit can never be passed. A retry for a user who is already active returns the same membership.
 */
class SeatService
{
    public function __construct(
        protected OrganizationRepositoryInterface $organizations,
        protected OrganizationMembershipRepositoryInterface $memberships,
        protected OrganizationRoleAssignmentRepositoryInterface $roleAssignments,
        protected AuditLogger $audit,
    ) {}

    /**
     * @param  User|null  $actor  Who made the change; defaults to the joining user.
     * @param  OrganizationMembership|null  $membership  The row to activate (admin restore); otherwise the user's row is looked up.
     * @param  array<string, mixed>  $auditContext  Extra audit metadata, e.g. the code or invitation used.
     *
     * @throws CorporateJoinException
     */
    public function allocate(
        Organization $organization,
        User $user,
        MembershipJoinMethod $via,
        ?User $actor = null,
        ?OrganizationMembership $membership = null,
        array $auditContext = [],
    ): CorporateJoinResult {
        return DB::transaction(function () use ($organization, $user, $via, $actor, $membership, $auditContext) {
            $organization = $this->organizations->lockForSeatAllocation($organization);
            $this->ensureAcceptingMembers($organization);

            // Idempotency: an active user keeps their seat; a retry never takes a second one.
            $active = $this->memberships->findActiveForUser($user->id);
            if ($active !== null) {
                if ($active->organization_id === $organization->id) {
                    return CorporateJoinResult::alreadyMember($active);
                }

                throw CorporateJoinException::because(CorporateJoinStatus::ALREADY_MEMBER_OF_OTHER_ORGANIZATION);
            }

            $existing = $membership ?? $this->memberships->findFor($organization->id, $user->id);
            if ($via->isSelfService()) {
                $this->ensureNotRemovedByAdmin($existing);
            }

            $seatsUsed = $this->memberships->countActive($organization->id);
            if ($seatsUsed >= $organization->seat_limit) {
                throw CorporateJoinException::because(CorporateJoinStatus::SEAT_LIMIT_REACHED);
            }

            $previousStatus = $existing?->status;
            $membership = $this->activate($organization, $user, $via, $existing);

            $this->audit->record(
                action: AuditAction::MEMBERSHIP_ACTIVATED,
                organization: $organization,
                target: $membership,
                metadata: [
                    'user_id' => $user->id,
                    'joined_via' => $via->value,
                    'previous_status' => $previousStatus?->value,
                    'seats_used' => $seatsUsed + 1,
                    'seat_limit' => $organization->seat_limit,
                    ...$auditContext,
                ],
                actor: $actor ?? $user,
            );

            return CorporateJoinResult::joined($membership);
        });
    }

    /**
     * Offboarding: the seat is released and the user's organization roles are revoked.
     * The personal Pivot account and personal history are not touched.
     */
    public function release(OrganizationMembership $membership, string $reason, User $actor): OrganizationMembership
    {
        if (! $membership->status->canBeDeactivated()) {
            throw OrganizationActionException::invalidMembershipTransition($membership->status, 'deactivated');
        }

        return DB::transaction(function () use ($membership, $reason, $actor) {
            $previousStatus = $membership->status;

            $this->memberships->update($membership, [
                'status' => MembershipStatus::DEACTIVATED,
                'deactivated_at' => now(),
                'deactivation_reason' => $reason,
            ]);

            $revokedRoles = $this->roleAssignments->revokeAllFor($membership->organization_id, $membership->user_id);

            $this->audit->record(
                action: AuditAction::MEMBERSHIP_DEACTIVATED,
                organization: $membership->organization_id,
                target: $membership,
                metadata: [
                    'user_id' => $membership->user_id,
                    'previous_status' => $previousStatus->value,
                    'reason' => $reason,
                    'roles_revoked' => $revokedRoles->map->value->values()->all(),
                ],
                actor: $actor,
            );

            return $membership;
        });
    }

    /**
     * Releases every seat of an organization at once, e.g. when the organization is removed.
     */
    public function releaseAll(Organization $organization, string $reason, ?User $actor = null): int
    {
        $released = $this->memberships->deactivateAll($organization->id, $reason, now());

        if ($released > 0) {
            $this->audit->record(
                action: AuditAction::MEMBERSHIPS_RELEASED,
                organization: $organization,
                target: $organization,
                metadata: ['released' => $released, 'reason' => $reason],
                actor: $actor,
                actorType: $actor ? null : AuditActorType::SYSTEM,
            );
        }

        return $released;
    }

    private function ensureAcceptingMembers(Organization $organization): void
    {
        $now = now();

        $status = match (true) {
            $organization->trashed() => CorporateJoinStatus::ORGANIZATION_NOT_ACTIVE,
            $organization->status === OrganizationStatus::SUSPENDED => CorporateJoinStatus::ORGANIZATION_SUSPENDED,
            $organization->status === OrganizationStatus::EXPIRED,
            $organization->contract_end_at?->lessThanOrEqualTo($now) => CorporateJoinStatus::CONTRACT_EXPIRED,
            $organization->status !== OrganizationStatus::ACTIVE,
            $organization->contract_start_at?->greaterThan($now) => CorporateJoinStatus::ORGANIZATION_NOT_ACTIVE,
            default => null,
        };

        if ($status !== null) {
            throw CorporateJoinException::because($status);
        }
    }

    // A member an admin suspended or offboarded cannot let themselves back in with a work email or code.
    private function ensureNotRemovedByAdmin(?OrganizationMembership $existing): void
    {
        match ($existing?->status) {
            MembershipStatus::SUSPENDED => throw CorporateJoinException::because(CorporateJoinStatus::MEMBERSHIP_SUSPENDED),
            MembershipStatus::DEACTIVATED => $existing->deactivation_reason === OrganizationMembership::REASON_INVITATION_REVOKED
                ? null
                : throw CorporateJoinException::because(CorporateJoinStatus::MEMBERSHIP_DEACTIVATED),
            default => null,
        };
    }

    // One row per organization and user: an existing row is reactivated instead of adding a new one.
    private function activate(
        Organization $organization,
        User $user,
        MembershipJoinMethod $via,
        ?OrganizationMembership $existing,
    ): OrganizationMembership {
        $now = now();
        $attributes = [
            'status' => MembershipStatus::ACTIVE,
            // An admin restore keeps the original join method; the audit row records the restore.
            'joined_via' => $via === MembershipJoinMethod::ADMIN && $existing?->joined_via ? $existing->joined_via : $via,
            'activated_at' => $now,
            'deactivated_at' => null,
            'deactivation_reason' => null,
        ];

        // Members coming back from a suspension or a contract expiry keep their original join date.
        $returning = in_array($existing?->status, [MembershipStatus::SUSPENDED, MembershipStatus::EXPIRED], true);

        try {
            return $existing
                ? $this->memberships->update($existing, $attributes + ['joined_at' => $returning ? $existing->joined_at : $now])
                : $this->memberships->create($attributes + [
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'joined_at' => $now,
                ]);
        } catch (UniqueConstraintViolationException) {
            // The user became active in another organization at the same moment (one active organization per user).
            throw CorporateJoinException::because(CorporateJoinStatus::ALREADY_MEMBER_OF_OTHER_ORGANIZATION);
        }
    }
}
