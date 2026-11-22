<?php

namespace App\Organization\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Organization\Models\OrganizationMembership
 */
class OrganizationMembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id === null ? null : (int) $this->organization_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            // Highest-ranked active role. Present on the member list.
            'role' => $this->whenLoaded('activeRoles', fn () => $this->activeRoles->first()->value),
            'status' => $this->status->value,
            'joined_via' => $this->joined_via?->value,
            'joined_at' => $this->joined_at,
            'activated_at' => $this->activated_at,
            'deactivated_at' => $this->deactivated_at,
            'deactivation_reason' => $this->deactivation_reason,
            // Set once the member is removed (soft delete); removed members are not in the list.
            'deleted_at' => $this->deleted_at,
        ];
    }
}
