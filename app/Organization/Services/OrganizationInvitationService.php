<?php

namespace App\Organization\Services;

use App\Models\User;
use App\Organization\Enums\AuditAction;
use App\Organization\Enums\InvitationStatus;
use App\Organization\Enums\MembershipJoinMethod;
use App\Organization\Enums\MembershipStatus;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Exceptions\OrganizationActionException;
use App\Organization\Mail\OrganizationInvitationMail;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationInvitation;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Repositories\Contracts\OrganizationInvitationRepositoryInterface;
use App\Organization\Repositories\Contracts\OrganizationMembershipRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Individual employee invitations. Each one is emailed as a link:
 *
 *   {base_url}/organization/join?token=…&code=…
 *
 * The token and the 6-character code are looked up by hash; encrypted copies let the list show the link again.
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
     * @return array{invitation: OrganizationInvitation, plaintext: string, code: string, link: string, email_sent: bool}
     */
    public function invite(Organization $organization, array $data, User $actor): array
    {
        $email = Str::lower(trim($data['email']));
        $role = $data['role'] ?? OrganizationRole::ORG_MEMBER;
        $invitee = $this->invitations->findUserByEmail($email);

        match ($invitee ? $this->memberships->findFor($organization->id, $invitee->id)?->status : null) {
            MembershipStatus::ACTIVE => throw OrganizationActionException::alreadyActiveMember(),
            MembershipStatus::SUSPENDED => throw OrganizationActionException::memberSuspendedUseRestore(),
            default => null,
        };

        $created = DB::transaction(function () use ($organization, $data, $actor, $email, $role, $invitee) {
            $previous = $this->invitations->findPending($organization->id, $email);
            if ($previous) {
                $this->invitations->update($previous, ['status' => InvitationStatus::REVOKED]);
            }

            $plaintext = Str::random(64);
            $code = OrganizationInvitation::generateCode();
            $invitation = $this->invitations->create($organization, [
                'email' => $email,
                'role' => $role,
                'token_hash' => hash('sha256', $plaintext),
                'code_hash' => OrganizationInvitation::hashCode($code),
                'token_encrypted' => $plaintext,
                'code_encrypted' => $code,
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

            return ['invitation' => $invitation, 'plaintext' => $plaintext, 'code' => $code];
        });

        $link = $this->linkFor($created['plaintext'], $created['code']);

        // Sent after the commit, so a link is never emailed for an invitation that was rolled back.
        return $created + [
            'link' => $link,
            'email_sent' => $this->email($organization, $created['invitation'], $link, $created['code']),
        ];
    }

    // Null once the link can no longer be used, and for invitations created before links were stored.
    public function activeLink(OrganizationInvitation $invitation): ?string
    {
        if ($invitation->status !== InvitationStatus::PENDING || $invitation->isExpired()
            || $invitation->token_encrypted === null || $invitation->code_encrypted === null) {
            return null;
        }

        return $this->linkFor($invitation->token_encrypted, $invitation->code_encrypted);
    }

    public function linkFor(string $token, string $code): string
    {
        return rtrim((string) config('organization.invitation.base_url'), '/')
            .'/organization/join?'.http_build_query(['token' => $token, 'code' => $code]);
    }

    /**
     * What a link currently stands for, without changing anything (join page and preview endpoint).
     *
     * @return array{state: 'valid'|'joined'|'expired'|'invalid', invitation: ?OrganizationInvitation}
     */
    public function inspect(string $token, ?string $code = null): array
    {
        $invitation = $token === '' ? null : $this->invitations->findByTokenHash(hash('sha256', trim($token)));

        // A removed organization is not loaded by the relation.
        if ($invitation === null || $invitation->organization === null
            || ($code !== null && ! $invitation->matchesCode($code))) {
            return ['state' => 'invalid', 'invitation' => null];
        }

        $state = match (true) {
            $invitation->status === InvitationStatus::ACCEPTED => 'joined',
            $invitation->status !== InvitationStatus::PENDING => 'invalid',
            $invitation->isExpired() => 'expired',
            default => 'valid',
        };

        return ['state' => $state, 'invitation' => $invitation];
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

    // The link and code are also returned to the admin, so a mail failure is reported, not fatal.
    private function email(Organization $organization, OrganizationInvitation $invitation, string $link, string $code): bool
    {
        try {
            Mail::to($invitation->email)->send(
                new OrganizationInvitationMail($organization, $link, $code, $invitation->expires_at)
            );

            return true;
        } catch (Throwable $e) {
            Log::warning('Organization invitation email could not be sent.', [
                'organization_id' => $organization->id,
                'invitation_id' => $invitation->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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
