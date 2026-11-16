<?php

namespace App\Organization\Http\Resources;

use App\Organization\Enums\OrganizationPermission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One organization role definition. Roles are enum-defined, so the code is the identifier.
 *
 * @mixin \App\Organization\Enums\OrganizationRole
 */
class OrganizationRoleDefinitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->value,
            'name' => $this->label(),
            'description' => $this->description(),
            'rank' => $this->rank(),
            'is_system' => true,
            'can_access_dashboard' => $this->isAdministrative(),
            'permissions' => array_map(
                fn (OrganizationPermission $permission) => $permission->value,
                $this->permissions(),
            ),
        ];
    }
}
