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
use App\Organization\Repositories\Contracts\OrganizationRoleAssignmentRepositoryInterface;
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
        protected OrganizationRoleAssignmentRepositoryInterface $roleAssignments,
    ) {}

    public function list(Organization $organization, ?MembershipStatus $status = null, int $perPage = 25): LengthAwarePaginator
    {
        $page = $this->memberships->paginateFor($organization->id, $status, $perPage);
        $memberships = collect($page->items());

        // One query for the whole page; ORG_MEMBER is implied by the membership itself.
        $rolesByUser = $this->roleAssignments->activeRolesForUsers(
            $organization->id,
            $memberships->pluck('user_id')->filter()->unique()->values()->all(),
        );

        $memberships->each(
            fn (OrganizationMembership $membership) => $membership->setActiveRoles($rolesByUser->get($membership->user_id, collect()))
        );

        return $page;
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

    /**
     * Soft delete: hides an offboarded member from the dashboard. The seat and roles were already released by
     * deactivate(); the row is kept so the audit history and the self-service rejoin block still apply.
     */
    public function remove(OrganizationMembership $membership, User $actor): OrganizationMembership
    {
        if (! $membership->status->canBeRemoved()) {
            throw OrganizationActionException::invalidMembershipTransition($membership->status, 'removed');
        }

        return DB::transaction(function () use ($membership, $actor) {
            $this->memberships->softDelete($membership);

            $this->audit->record(
                action: AuditAction::MEMBERSHIP_REMOVED,
                organization: $membership->organization_id,
                target: $membership,
                metadata: ['user_id' => $membership->user_id],
                actor: $actor,
            );

            return $membership->load('user:id,name,email');
        });
    }

    /**
     * Updates the member's name. It lives on the user's Pivot account, so the change is also visible in the
     * Mobile App. The email is never editable here (it is the personal account's login). Unchanged names are
     * not saved or audited.
     */
    public function updateMember(OrganizationMembership $membership, string $name, User $actor): OrganizationMembership
    {
        if (! $membership->status->canBeEdited()) {
            throw OrganizationActionException::invalidMembershipTransition($membership->status, 'edited');
        }

        return DB::transaction(function () use ($membership, $name, $actor) {
            $user = $membership->user()->lockForUpdate()->firstOrFail();
            $previousName = $user->name;

            if ($previousName !== $name) {
                $user->forceFill(['name' => $name])->save();

                $this->audit->record(
                    action: AuditAction::MEMBER_PROFILE_UPDATED,
                    organization: $membership->organization_id,
                    target: $membership,
                    metadata: ['user_id' => $user->id, 'changes' => ['name' => ['from' => $previousName, 'to' => $name]]],
                    actor: $actor,
                );
            }

            return $membership->load('user:id,name,email');
        });
    }
}
