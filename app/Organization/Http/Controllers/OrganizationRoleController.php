<?php

namespace App\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Organization\Http\Requests\AssignOrganizationRoleRequest;
use App\Organization\Http\Requests\ListOrganizationRolesRequest;
use App\Organization\Http\Requests\RevokeOrganizationRoleRequest;
use App\Organization\Http\Resources\OrganizationRoleAssignmentResource;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationRoleAssignment;
use App\Organization\Services\RoleAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OrganizationRoleController extends Controller
{
    public function __construct(
        protected RoleAssignmentService $roleAssignments
    ) {}

    public function index(ListOrganizationRolesRequest $request, Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [OrganizationRoleAssignment::class, $organization]);

        $assignments = $this->roleAssignments->list($organization, $request->role(), $request->perPage());

        return OrganizationRoleAssignmentResource::collection($assignments);
    }

    public function store(AssignOrganizationRoleRequest $request, Organization $organization): JsonResponse
    {
        $role = $request->role();

        Gate::authorize('assign', [OrganizationRoleAssignment::class, $organization, $role]);

        $assignment = $this->roleAssignments->assign($organization, $request->targetUser(), $role, $request->user());

        return OrganizationRoleAssignmentResource::make($assignment)
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(
        RevokeOrganizationRoleRequest $request,
        Organization $organization,
        OrganizationRoleAssignment $roleAssignment,
    ): OrganizationRoleAssignmentResource {
        $roleAssignment->setRelation('organization', $organization);

        Gate::authorize('revoke', $roleAssignment);

        $assignment = $this->roleAssignments->revoke($roleAssignment, $request->user(), $request->validated('reason'));

        return OrganizationRoleAssignmentResource::make($assignment);
    }
}
