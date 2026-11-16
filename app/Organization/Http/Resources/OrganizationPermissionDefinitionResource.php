<?php

namespace App\Organization\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One permission definition.
 *
 * @mixin \App\Organization\Enums\OrganizationPermission
 */
class OrganizationPermissionDefinitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->value,
            'name' => $this->label(),
            'description' => $this->description(),
            'group' => $this->group()->value,
            'scope' => $this->isDelegated() ? 'organization' : 'pivot',
        ];
    }
}
