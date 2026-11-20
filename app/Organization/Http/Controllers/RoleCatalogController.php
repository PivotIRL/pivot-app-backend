<?php

namespace App\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Organization\Http\Resources\OrganizationRoleDefinitionResource;
use App\Organization\Services\RolePermissionCatalogService;
use Illuminate\Http\JsonResponse;

/**
<<<<<<< HEAD
 * GET /corporate/roles: the organization roles that exist. Not tenant data, so open to every dashboard user.
=======
 * GET /organization/roles: the organization roles that exist. Not tenant data, so open to every dashboard user.
>>>>>>> origin/corporate-backend
 * Who holds which role in an organization is GET /organizations/{organization}/roles (needs roles.view).
 */
class RoleCatalogController extends Controller
{
    public function __construct(
        protected RolePermissionCatalogService $catalog,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => OrganizationRoleDefinitionResource::collection($this->catalog->roles())->resolve(),
        ]);
    }
}
