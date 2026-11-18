<?php

namespace App\Organization\Services;

use App\Models\User;
use App\Organization\Enums\AuditAction;
use App\Organization\Enums\InvitationStatus;
use App\Organization\Enums\MembershipJoinMethod;
use App\Organization\Enums\MembershipStatus;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Exceptions\OrganizationActionException;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationInvitation;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Repositories\Contracts\OrganizationInvitationRepositoryInterface;
use App\Organization\Repositories\Contracts\OrganizationMembershipRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Employee invitations. Only the token hash is stored; the plaintext token is returned once.
 * Creating an invitation does not take a seat; accepting it does (CorporateOnboardingService).
 */
class OrganizationInvitationService
{
    public const DEFAULT_EXPIRY_DAYS = 7;

    public function __construct(
        protected OrganizationInvitationRepositoryInterface $invitations,
        protected OrganizationMembershipRepositoryInterface $memberships,
        protected AuditLogger $audit,
    ) {}

    public function list(Organization $organization, ?InvitationStatus $status = null, int $perPage = 25): LengthAwarePaginator
    {
        return $this->invitations->paginateFor($organization, $status, $perPage);
    }

    /**
     * Re-inviting the same email replaces its pending invitation (the old token stops working).
     *
     * @return array{invitation: OrganizationInvitation, plaintext: string}
     */
    public function invite(Organization $organization, array $data, User $actor): array
    {
        $email = Str::lower(trim($data['email']));
        $role = $data['role'] ?? OrganizationRole::ORG_MEMBER;
        $invitee = $this->invitations->findUserByEmail($email);

        if ($invitee && $this->memberships->isActiveMember($organization->id, $invitee->id)) {
            throw OrganizationActionException::alreadyActiveMember();
        }

        return DB::transaction(function () use ($organization, $data, $actor, $email, $role, $invitee) {
            $previous = $this->invitations->findPending($organization->id, $email);
            if ($previous) {
                $this->invitations->update($previous, ['status' => InvitationStatus::REVOKED]);
            }

            $plaintext = Str::random(64);
            $invitation = $this->invitations->create($organization, [
                'email' => $email,
                'role' => $role,
                'token_hash' => hash('sha256', $plaintext),
                'status' => InvitationStatus::PENDING,
                'expires_at' => now()->addDays($data['expires_in_days'] ?? self::DEFAULT_EXPIRY_DAYS),
                'invited_by' => $actor->id,
            ]);

            if ($invitee) {
                $this->markInvited($organization, $invitee);
            }

            $this->audit->record(
                action: AuditAction::INVITATION_CREATED,
                organization: $organization,
                target: $invitation,
                metadata: [
                    'email' => $email,
                    'role' => $invitation->role->value,
                    'expires_at' => $invitation->expires_at->toISOString(),
                    'replaced_invitation_id' => $previous?->id,
                ],
                actor: $actor,
            );

            return ['invitation' => $invitation, 'plaintext' => $plaintext];
        });
    }

    public function revoke(OrganizationInvitation $invitation, User $actor): OrganizationInvitation
    {
        if ($invitation->status !== InvitationStatus::PENDING) {
            throw OrganizationActionException::invitationNotPending();
        }

        return DB::transaction(function () use ($invitation, $actor) {
            $this->invitations->update($invitation, ['status' => InvitationStatus::REVOKED]);

            $invitee = $this->invitations->findUserByEmail($invitation->email);
            $membership = $invitee ? $this->memberships->findFor($invitation->organization_id, $invitee->id) : null;

            if ($membership?->status === MembershipStatus::INVITED) {
                $this->memberships->update($membership, [
                    'status' => MembershipStatus::DEACTIVATED,
                    'deactivated_at' => now(),
                    'deactivation_reason' => OrganizationMembership::REASON_INVITATION_REVOKED,
                ]);
            }

            $this->audit->record(
                action: AuditAction::INVITATION_REVOKED,
                organization: $invitation->organization_id,
                target: $invitation,
                metadata: ['email' => $invitation->email],
                actor: $actor,
            );

            return $invitation;
        });
    }

    // An existing Pivot user who has no membership row yet is shown as "invited" (no seat is taken).
    private function markInvited(Organization $organization, User $invitee): void
    {
        $membership = $this->memberships->findFor($organization->id, $invitee->id);

        if ($membership === null) {
            $this->memberships->create([
                'organization_id' => $organization->id,
                'user_id' => $invitee->id,
                'status' => MembershipStatus::INVITED,
                'joined_via' => MembershipJoinMethod::INVITATION,
            ]);
        } elseif ($membership->status === MembershipStatus::DEACTIVATED
            && $membership->deactivation_reason === OrganizationMembership::REASON_INVITATION_REVOKED) {
            $this->memberships->update($membership, [
                'status' => MembershipStatus::INVITED,
                'deactivated_at' => null,
                'deactivation_reason' => null,
            ]);
        }
    }
}
