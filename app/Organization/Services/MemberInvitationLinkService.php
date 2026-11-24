<?php

namespace App\Organization\Services;

use App\Models\User;
use App\Organization\Data\CorporateJoinResult;
use App\Organization\Enums\AuditAction;
use App\Organization\Enums\CorporateJoinStatus;
use App\Organization\Enums\MembershipJoinMethod;
use App\Organization\Enums\MembershipStatus;
use App\Organization\Exceptions\CorporateJoinException;
use App\Organization\Exceptions\OrganizationActionException;
use App\Organization\Mail\OrganizationMemberInvitationMail;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Repositories\Contracts\OrganizationInvitationRepositoryInterface;
use App\Organization\Repositories\Contracts\OrganizationMembershipRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Invitation links for people who already have a Pivot account:
 *
 *   {base_url}/{user_id}/{organization_id}/{membership_id}?expires=…&signature=…
 *
 * The signature (Laravel signed URL, relative so it does not depend on the host) makes the IDs tamper-proof and
 * the link expire. Nothing is stored for the link itself: it stays usable until it expires, or until the
 * membership stops being "invited" (deactivated, removed or already joined).
 *
 * Sending creates (or reuses) an "invited" membership, which takes no seat. Accepting happens in the mobile app,
 * signed in as the invited user, and takes a seat through SeatService like every other join.
 */
class MemberInvitationLinkService
{
    public const ROUTE = 'organization.member-invitation.open';

    private const PATH_PATTERN = '#^/(\d{1,19})/(\d{1,19})/(\d{1,19})$#';

    public function __construct(
        protected OrganizationMembershipRepositoryInterface $memberships,
        protected OrganizationInvitationRepositoryInterface $invitations,
        protected SeatService $seats,
        protected AuditLogger $audit,
    ) {}

    /**
     * @return array{membership: OrganizationMembership, link: string, expires_at: Carbon, email_sent: bool}
     *
     * @throws ValidationException when no Pivot account uses the email
     * @throws OrganizationActionException when the person is already active or is suspended
     */
    public function send(Organization $organization, string $email, User $actor, ?int $expiresInDays = null): array
    {
        $email = Str::lower(trim($email));
        $user = $this->invitations->findUserByEmail($email)
            ?? throw ValidationException::withMessages([
                'email' => 'No Pivot account uses this email address. The person must create a Pivot account first.',
            ]);

        $expiresAt = now()->addDays($expiresInDays ?? config('organization.member_invitation.expiry_days', 7));

        $membership = DB::transaction(function () use ($organization, $user, $actor, $expiresAt) {
            $existing = $this->memberships->findFor($organization->id, $user->id);
            $previousStatus = $existing?->status;

            $membership = match ($previousStatus) {
                null => $this->memberships->create([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'status' => MembershipStatus::INVITED,
                    'joined_via' => MembershipJoinMethod::INVITATION,
                ]),
                // Resending keeps the same row.
                MembershipStatus::INVITED => $existing,
                // A former member can be invited back; no seat is taken until they accept.
                MembershipStatus::DEACTIVATED, MembershipStatus::EXPIRED => $this->memberships->update($existing, [
                    'status' => MembershipStatus::INVITED,
                    'deactivated_at' => null,
                    'deactivation_reason' => null,
                ]),
                MembershipStatus::ACTIVE => throw OrganizationActionException::alreadyActiveMember(),
                MembershipStatus::SUSPENDED => throw OrganizationActionException::memberSuspendedUseRestore(),
            };

            $this->audit->record(
                action: AuditAction::MEMBER_INVITATION_LINK_SENT,
                organization: $organization,
                target: $membership,
                metadata: [
                    'user_id' => $user->id,
                    'previous_status' => $previousStatus?->value,
                    'expires_at' => $expiresAt->toISOString(),
                ],
                actor: $actor,
            );

            return $membership;
        });

        $link = $this->linkFor($membership, $expiresAt);

        return [
            'membership' => $membership->load('user:id,name,email'),
            'link' => $link,
            'expires_at' => $expiresAt,
            'email_sent' => $this->email($user, $organization, $link, $expiresAt),
        ];
    }

    /**
     * Mobile App: the signed-in user accepts the link they received.
     *
     * @throws CorporateJoinException
     */
    public function accept(User $user, string $link): CorporateJoinResult
    {
        [$userId, $organizationId, $membershipId] = $this->verify($link);

        if ($userId !== $user->id) {
            throw CorporateJoinException::because(CorporateJoinStatus::INVITATION_EMAIL_MISMATCH);
        }

        $membership = $this->memberships->findOwned($membershipId, $organizationId, $userId);

        // A trashed (removed) organization is not loaded by the relation.
        if ($membership === null || $membership->organization === null) {
            throw CorporateJoinException::because(CorporateJoinStatus::INVALID_INVITATION);
        }

        if ($membership->status === MembershipStatus::ACTIVE) {
            return CorporateJoinResult::alreadyMember($membership);
        }

        // Deactivating or removing the member after sending is how an admin cancels a link.
        if ($membership->status !== MembershipStatus::INVITED) {
            throw CorporateJoinException::because(CorporateJoinStatus::INVALID_INVITATION);
        }

        return $this->seats->allocate(
            organization: $membership->organization,
            user: $user,
            via: MembershipJoinMethod::INVITATION,
            membership: $membership,
            auditContext: ['via_invitation_link' => true],
        );
    }

    /**
     * Checks the signature and expiry of a link (full URL, or path plus query).
     *
     * @return array{0: int, 1: int, 2: int} user ID, organization ID, membership ID
     *
     * @throws CorporateJoinException INVALID_INVITATION or INVITATION_EXPIRED
     */
    public function verify(string $link): array
    {
        $parts = parse_url(trim($link));
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';

        if (! preg_match(self::PATH_PATTERN, $path, $ids)) {
            throw CorporateJoinException::because(CorporateJoinStatus::INVALID_INVITATION);
        }

        $request = Request::create($path.(isset($parts['query']) ? '?'.$parts['query'] : ''));

        if (! URL::hasCorrectSignature($request, absolute: false)) {
            throw CorporateJoinException::because(CorporateJoinStatus::INVALID_INVITATION);
        }

        if (! URL::signatureHasNotExpired($request)) {
            throw CorporateJoinException::because(CorporateJoinStatus::INVITATION_EXPIRED);
        }

        return [(int) $ids[1], (int) $ids[2], (int) $ids[3]];
    }

    public function linkFor(OrganizationMembership $membership, Carbon $expiresAt): string
    {
        $path = URL::temporarySignedRoute(self::ROUTE, $expiresAt, [
            'user' => $membership->user_id,
            'organization' => $membership->organization_id,
            'membership' => $membership->id,
        ], absolute: false);

        return rtrim((string) config('organization.member_invitation.base_url'), '/').$path;
    }

    // The link is also returned to the admin, so a mail failure is reported, not fatal.
    private function email(User $user, Organization $organization, string $link, Carbon $expiresAt): bool
    {
        try {
            Mail::to($user->email)->send(new OrganizationMemberInvitationMail($user, $organization, $link, $expiresAt));

            return true;
        } catch (Throwable $e) {
            Log::warning('Organization member invitation email could not be sent.', [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
