<?php

namespace App\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Organization\Enums\OrganizationPermission;
use App\Organization\Http\Resources\OrganizationPermissionDefinitionResource;
use App\Organization\Services\RolePermissionCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
<<<<<<< HEAD
 * GET /corporate/permissions: the permissions the signed-in dashboard user may see, flat and grouped.
=======
 * GET /organization/permissions: the permissions the signed-in dashboard user may see, flat and grouped.
>>>>>>> origin/corporate-backend
 * Pivot-controlled permissions are listed only for Pivot staff.
 */
class PermissionCatalogController extends Controller
{
    public function __construct(
        protected RolePermissionCatalogService $catalog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $permissions = $this->catalog->permissionsVisibleTo($request->user());

        return response()->json([
            'data' => OrganizationPermissionDefinitionResource::collection($permissions)->resolve($request),
            'groups' => array_map(fn (array $entry) => [
                'key' => $entry['group']->value,
                'name' => $entry['group']->label(),
                'permissions' => array_map(
                    fn (OrganizationPermission $permission) => $permission->value,
                    $entry['permissions'],
                ),
            ], $this->catalog->grouped($permissions)),
        ]);
    }
}
