<?php

namespace App\Organization\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The corporate join status returned by the join endpoints and by registration.
 * The full access object (package, entitlements) is added by the access resolver in B5.
 *
 * @mixin \App\Organization\Data\CorporateJoinResult
 */
class CorporateJoinResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $membership = $this->membership;
        $organization = $membership?->organization;

        return [
            'join_status' => $this->status->value,
            'message' => $this->status->message(),
            'organization' => $organization ? [
                'id' => $organization->id,
                'name' => $organization->name,
                'package_code' => $organization->package_code->value,
                'contract_end_at' => $organization->contract_end_at,
            ] : null,
            'membership' => $membership ? OrganizationMembershipResource::make($membership)->resolve($request) : null,
        ];
    }
}
