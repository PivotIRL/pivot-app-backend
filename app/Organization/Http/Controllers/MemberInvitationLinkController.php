<?php

namespace App\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Organization\Enums\CorporateJoinStatus;
use App\Organization\Enums\MembershipStatus;
use App\Organization\Exceptions\CorporateJoinException;
use App\Organization\Http\Requests\SendMemberInvitationLinkRequest;
use App\Organization\Http\Resources\OrganizationMembershipResource;
use App\Organization\Models\Organization;
use App\Organization\Repositories\Contracts\OrganizationMembershipRepositoryInterface;
use App\Organization\Services\MemberInvitationLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Dashboard: send an invitation link. Public web: the page the link opens.
 * Accepting is done by the Mobile App (CorporateJoinController::acceptInvitationLink).
 */
class MemberInvitationLinkController extends Controller
{
    public function __construct(
        protected MemberInvitationLinkService $links,
    ) {}

    public function send(SendMemberInvitationLinkRequest $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manageInvitations', $organization);

        $sent = $this->links->send($organization, $request->invitedEmail(), $request->user(), $request->expiresInDays());

        return response()->json([
            'data' => OrganizationMembershipResource::make($sent['membership'])->resolve($request),
            'invitation_link' => $sent['link'],
            'expires_at' => $sent['expires_at']->toISOString(),
            'email_sent' => $sent['email_sent'],
        ], 201);
    }

    /**
     * GET /{user}/{organization}/{membership}?expires=…&signature=…
     * Never changes anything; the user accepts in the app.
     */
    public function open(Request $request, OrganizationMembershipRepositoryInterface $memberships, string $user, string $organization, string $membership): Response
    {
        try {
            [$userId, $organizationId, $membershipId] = $this->links->verify($request->getRequestUri());
        } catch (CorporateJoinException $e) {
            $expired = $e->joinStatus === CorporateJoinStatus::INVITATION_EXPIRED;

            return $this->page($expired ? 'expired' : 'invalid', status: $expired ? 410 : 404);
        }

        $found = $memberships->findOwned($membershipId, $organizationId, $userId);
        $organizationName = $found?->organization?->name;

        return match (true) {
            $organizationName === null => $this->page('invalid', status: 404),
            $found->status === MembershipStatus::ACTIVE => $this->page('joined', $organizationName),
            $found->status === MembershipStatus::INVITED => $this->page('valid', $organizationName, $this->appLink($request)),
            default => $this->page('invalid', status: 410),
        };
    }

    private function appLink(Request $request): ?string
    {
        $appUrl = config('organization.member_invitation.app_url');

        if (! $appUrl) {
            return null;
        }

        $link = rtrim((string) config('organization.member_invitation.base_url'), '/').$request->getRequestUri();

        return $appUrl.(str_contains($appUrl, '?') ? '&' : '?').http_build_query(['link' => $link]);
    }

    private function page(string $state, ?string $organizationName = null, ?string $appLink = null, int $status = 200): Response
    {
        return response()->view('organization.member-invitation', [
            'state' => $state,
            'organizationName' => $organizationName,
            'appLink' => $appLink,
            'iosStoreUrl' => config('organization.member_invitation.ios_store_url'),
            'androidStoreUrl' => config('organization.member_invitation.android_store_url'),
        ], $status)->header('Referrer-Policy', 'no-referrer');
    }
}
