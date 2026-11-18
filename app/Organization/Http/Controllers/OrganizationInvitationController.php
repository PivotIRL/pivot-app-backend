<?php

namespace App\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Organization\Http\Requests\ListOrganizationInvitationsRequest;
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
        ], 201);
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
