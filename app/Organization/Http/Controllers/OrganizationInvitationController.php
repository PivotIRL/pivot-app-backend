<?php

namespace App\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Organization\Enums\CorporateJoinStatus;
use App\Organization\Exceptions\CorporateJoinException;
use App\Organization\Http\Requests\ListOrganizationInvitationsRequest;
use App\Organization\Http\Requests\PreviewOrganizationInvitationRequest;
use App\Organization\Http\Requests\StoreOrganizationInvitationRequest;
use App\Organization\Http\Resources\OrganizationInvitationResource;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationInvitation;
use App\Organization\Models\OrganizationRoleAssignment;
use App\Organization\Services\OrganizationInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OrganizationInvitationController extends Controller
{
    public function __construct(
        protected OrganizationInvitationService $invitations,
    ) {}

    public function index(ListOrganizationInvitationsRequest $request, Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('manageInvitations', $organization);

        return OrganizationInvitationResource::collection(
            $this->invitations->list($organization, $request->status(), $request->perPage())
        );
    }

    public function store(StoreOrganizationInvitationRequest $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manageInvitations', $organization);

        // Inviting someone as admin/analyst is a role grant, so the role-assignment rank rule applies too.
        if ($request->role()->isAdministrative()) {
            Gate::authorize('assign', [OrganizationRoleAssignment::class, $organization, $request->role()]);
        }

        $created = $this->invitations->invite($organization, $request->invitationData(), $request->user());

        return response()->json([
            'data' => OrganizationInvitationResource::make($created['invitation'])->resolve($request),
            'one_time_token' => $created['plaintext'],
            'invitation_code' => $created['code'],
            'invitation_link' => $created['link'],
            'email_sent' => $created['email_sent'],
        ], 201);
    }

    /**
     * Mobile App, before sign-in or registration: what the link is for. Needs the secret token; changes nothing.
     */
    public function preview(PreviewOrganizationInvitationRequest $request): JsonResponse
    {
        ['state' => $state, 'invitation' => $invitation] = $this->invitations->inspect($request->token(), $request->code());

        if ($state !== 'valid') {
            throw CorporateJoinException::because($state === 'expired'
                ? CorporateJoinStatus::INVITATION_EXPIRED
                : CorporateJoinStatus::INVALID_INVITATION);
        }

        return response()->json([
            'data' => [
                'organization_name' => $invitation->organization->name,
                'email' => $invitation->email,
                'expires_at' => $invitation->expires_at,
            ],
        ]);
    }

    public function destroy(
        Request $request,
        Organization $organization,
        OrganizationInvitation $invitation,
    ): OrganizationInvitationResource {
        Gate::authorize('manageInvitations', $organization);

        return OrganizationInvitationResource::make(
            $this->invitations->revoke($invitation, $request->user())
        );
    }
}
