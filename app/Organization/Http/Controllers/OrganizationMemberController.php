<?php

namespace App\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Organization\Http\Requests\ListOrganizationMembersRequest;
use App\Organization\Http\Requests\MembershipActionRequest;
use App\Organization\Http\Resources\OrganizationMembershipResource;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Services\MembershipService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OrganizationMemberController extends Controller
{
    public function __construct(
        protected MembershipService $memberships,
    ) {}

    public function index(ListOrganizationMembersRequest $request, Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('viewMembers', $organization);

        return OrganizationMembershipResource::collection(
            $this->memberships->list($organization, $request->status(), $request->perPage())
        );
    }

    public function suspend(
        MembershipActionRequest $request,
        Organization $organization,
        OrganizationMembership $membership,
    ): OrganizationMembershipResource {
        $this->authorizeManage($organization, $membership);

        return OrganizationMembershipResource::make(
            $this->memberships->suspend($membership, $request->reason(), $request->user())
        );
    }

    public function restore(
        Request $request,
        Organization $organization,
        OrganizationMembership $membership,
    ): OrganizationMembershipResource {
        $this->authorizeManage($organization, $membership);

        return OrganizationMembershipResource::make(
            $this->memberships->restore($membership, $request->user())
        );
    }

    public function destroy(
        MembershipActionRequest $request,
        Organization $organization,
        OrganizationMembership $membership,
    ): OrganizationMembershipResource {
        $this->authorizeManage($organization, $membership);

        return OrganizationMembershipResource::make(
            $this->memberships->deactivate($membership, $request->reason(), $request->user())
        );
    }

    private function authorizeManage(Organization $organization, OrganizationMembership $membership): void
    {
        $membership->setRelation('organization', $organization);

        Gate::authorize('manage', $membership);
    }
}
