<?php

namespace App\Organization\Http\Resources;

use App\Organization\Models\OrganizationRoleAssignment;
use App\Organization\Services\OrganizationInvitationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * @mixin \App\Organization\Models\OrganizationInvitation
 */
class OrganizationInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id === null ? null : (int) $this->organization_id,
            'email' => $this->email,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'invitation_link' => $this->invitationLink($request),
            'expires_at' => $this->expires_at,
            'is_expired' => $this->isExpired(),
            'invited_by' => $this->invited_by,
            'accepted_by_user_id' => $this->accepted_by_user_id,
            'accepted_at' => $this->accepted_at,
            'created_at' => $this->created_at,
        ];
    }

    // An admin invitation's link is shown only to someone who could create it, so it cannot be used to gain a role.
    private function invitationLink(Request $request): ?string
    {
        $organization = $request->route('organization');

        if ($this->role->isAdministrative()
            && ! Gate::allows('assign', [OrganizationRoleAssignment::class, $organization, $this->role])) {
            return null;
        }

        return app(OrganizationInvitationService::class)->activeLink($this->resource);
    }
}
