<?php

namespace App\Organization\Services;

use App\Models\User;
use App\Organization\Data\CorporateJoinResult;
use App\Organization\Enums\AuditAction;
use App\Organization\Enums\CorporateJoinStatus;
use App\Organization\Enums\InvitationStatus;
use App\Organization\Enums\MembershipJoinMethod;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Exceptions\CorporateJoinException;
use App\Organization\Models\OrganizationInvitation;
use App\Organization\Repositories\Contracts\OrganizationDomainRepositoryInterface;
use App\Organization\Repositories\Contracts\OrganizationInvitationRepositoryInterface;
use App\Organization\Repositories\Contracts\OrganizationMembershipRepositoryInterface;
use App\Organization\Repositories\Contracts\OrganizationRedemptionCodeRepositoryInterface;
use App\Organization\Repositories\Contracts\OrganizationRoleAssignmentRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Corporate onboarding: work email, organization code and invitation.
 *
 * Every flow links the user's existing Pivot account; none of them creates a second account.
 * The seat itself is always taken by SeatService::allocate().
 */
class CorporateOnboardingService
{
    public function __construct(
        protected SeatService $seats,
        protected OrganizationDomainRepositoryInterface $domains,
        protected OrganizationRedemptionCodeRepositoryInterface $codes,
        protected OrganizationInvitationRepositoryInterface $invitations,
        protected OrganizationMembershipRepositoryInterface $memberships,
        protected OrganizationRoleAssignmentRepositoryInterface $roleAssignments,
        protected AuditLogger $audit,
    ) {}

    /**
     * Normalize email → verified domain → organization/contract → seat → membership.
     *
     * @throws CorporateJoinException
     */
    public function joinByWorkEmail(User $user): CorporateJoinResult
    {
        $email = Str::lower(trim($user->email));
        $emailDomain = Str::after($email, '@');

        $domain = str_contains($email, '@') && $emailDomain !== ''
            ? $this->domains->findVerifiedForEmailDomain($emailDomain)
            : null;

        if ($domain?->organization === null) {
            throw CorporateJoinException::because(CorporateJoinStatus::DOMAIN_NOT_RECOGNIZED);
        }

        return $this->seats->allocate(
            organization: $domain->organization,
            user: $user,
            via: MembershipJoinMethod::WORK_EMAIL,
            auditContext: ['domain' => $domain->domain],
        );
    }

    /**
     * Hash → code checks → organization/contract → in one transaction: seat + membership + uses_count.
     * The code row stays locked until commit, so max_uses cannot be passed by parallel redemptions.
     *
     * @throws CorporateJoinException
     */
    public function redeemCode(User $user, string $plainCode): CorporateJoinResult
    {
        $codeHash = hash('sha256', Str::upper(trim($plainCode)));

        return DB::transaction(function () use ($user, $codeHash) {
            $code = $this->codes->findByHashForUpdate($codeHash);

            if ($code === null || ! $code->is_active || $code->organization === null) {
                throw CorporateJoinException::because(CorporateJoinStatus::INVALID_CODE);
            }

            if ($code->starts_at?->isFuture()) {
                throw CorporateJoinException::because(CorporateJoinStatus::CODE_NOT_STARTED);
            }

            if ($code->expires_at?->isPast()) {
                throw CorporateJoinException::because(CorporateJoinStatus::CODE_EXPIRED);
            }

            // A retry by someone who already joined must succeed even when the code is now used up.
            $active = $this->memberships->findActiveForUser($user->id);
            if ($active?->organization_id === $code->organization_id) {
                return CorporateJoinResult::alreadyMember($active);
            }

            if ($code->max_uses !== null && $code->uses_count >= $code->max_uses) {
                throw CorporateJoinException::because(CorporateJoinStatus::CODE_LIMIT_REACHED);
            }

            $result = $this->seats->allocate(
                organization: $code->organization,
                user: $user,
                via: MembershipJoinMethod::CODE,
                auditContext: ['code_id' => $code->id, 'code_prefix' => $code->code_prefix],
            );

            if ($result->isNewSeat()) {
                $this->codes->recordUse($code);
            }

            return $result;
        });
    }

    /**
     * Token → pending, not expired, addressed to this user's email → seat → role from the invitation.
     *
     * @throws CorporateJoinException
     */
    public function acceptInvitation(User $user, string $plainToken): CorporateJoinResult
    {
        $tokenHash = hash('sha256', trim($plainToken));

        return DB::transaction(function () use ($user, $tokenHash) {
            $invitation = $this->invitations->findByTokenHashForUpdate($tokenHash);

            if ($invitation === null || $invitation->organization === null) {
                throw CorporateJoinException::because(CorporateJoinStatus::INVALID_INVITATION);
            }

            if ($invitation->status === InvitationStatus::ACCEPTED && $invitation->accepted_by_user_id === $user->id) {
                return $this->repeatedAcceptance($invitation, $user);
            }

            if ($invitation->status !== InvitationStatus::PENDING) {
                throw CorporateJoinException::because(CorporateJoinStatus::INVALID_INVITATION);
            }

            if ($invitation->isExpired()) {
                throw CorporateJoinException::because(CorporateJoinStatus::INVITATION_EXPIRED);
            }

            if (Str::lower(trim($user->email)) !== $invitation->email) {
                throw CorporateJoinException::because(CorporateJoinStatus::INVITATION_EMAIL_MISMATCH);
            }

            $result = $this->seats->allocate(
                organization: $invitation->organization,
                user: $user,
                via: MembershipJoinMethod::INVITATION,
                auditContext: ['invitation_id' => $invitation->id],
            );

            $this->invitations->update($invitation, [
                'status' => InvitationStatus::ACCEPTED,
                'accepted_by_user_id' => $user->id,
                'accepted_at' => now(),
            ]);

            $this->grantInvitedRole($invitation, $user);

            $this->audit->record(
                action: AuditAction::INVITATION_ACCEPTED,
                organization: $invitation->organization,
                target: $invitation,
                metadata: ['user_id' => $user->id, 'role' => $invitation->role->value],
                actor: $user,
            );

            return $result;
        });
    }

    /**
     * Registration hook: try the work-email flow but never fail the personal registration.
     * The returned status tells the app whether corporate access was granted and, if not, why.
     */
    public function joinOnRegistration(int $userId): CorporateJoinResult
    {
        try {
            return $this->joinByWorkEmail(User::findOrFail($userId));
        } catch (CorporateJoinException $e) {
            return CorporateJoinResult::failed($e->joinStatus);
        } catch (Throwable $e) {
            report($e);

            return CorporateJoinResult::failed(CorporateJoinStatus::UNAVAILABLE);
        }
    }

    // Accepting the same invitation twice returns the existing membership instead of an error.
    private function repeatedAcceptance(OrganizationInvitation $invitation, User $user): CorporateJoinResult
    {
        $active = $this->memberships->findActiveForUser($user->id);

        if ($active?->organization_id !== $invitation->organization_id) {
            throw CorporateJoinException::because(CorporateJoinStatus::INVALID_INVITATION);
        }

        return CorporateJoinResult::alreadyMember($active);
    }

    // ORG_MEMBER is the membership itself; administrative roles become a role assignment.
    private function grantInvitedRole(OrganizationInvitation $invitation, User $user): void
    {
        $role = $invitation->role;

        if ($role === OrganizationRole::ORG_MEMBER
            || $this->roleAssignments->findActive($invitation->organization_id, $user->id, $role)) {
            return;
        }

        $assignment = $this->roleAssignments->create([
            'organization_id' => $invitation->organization_id,
            'user_id' => $user->id,
            'role' => $role,
            'assigned_by' => $invitation->invited_by,
            'assigned_at' => now(),
        ]);

        $this->audit->record(
            action: AuditAction::ORGANIZATION_ROLE_ASSIGNED,
            organization: $invitation->organization,
            target: $assignment,
            metadata: [
                'user_id' => $user->id,
                'role' => $role->value,
                'invitation_id' => $invitation->id,
                'invited_by' => $invitation->invited_by,
            ],
            actor: $user,
        );
    }
}
