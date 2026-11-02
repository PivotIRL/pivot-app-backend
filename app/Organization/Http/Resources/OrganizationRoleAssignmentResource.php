<?php

namespace App\Organization\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Organization\Models\OrganizationRoleAssignment
 */
class OrganizationRoleAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'role' => $this->role->value,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'assigned_by' => $this->assigned_by,
            'assigned_at' => $this->assigned_at,
            'revoked_at' => $this->revoked_at,
            'is_active' => $this->isActive(),
        ];
    }
}
