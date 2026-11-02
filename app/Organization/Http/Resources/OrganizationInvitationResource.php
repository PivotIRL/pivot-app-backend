<?php

namespace App\Organization\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Organization\Models\OrganizationInvitation
 */
class OrganizationInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'email' => $this->email,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'expires_at' => $this->expires_at,
            'is_expired' => $this->isExpired(),
            'invited_by' => $this->invited_by,
            'accepted_by_user_id' => $this->accepted_by_user_id,
            'accepted_at' => $this->accepted_at,
            'created_at' => $this->created_at,
        ];
    }
}
