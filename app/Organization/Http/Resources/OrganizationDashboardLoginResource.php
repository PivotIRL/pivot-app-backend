<?php

namespace App\Organization\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Organization\Data\OrganizationDashboardLoginResult
 */
class OrganizationDashboardLoginResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'token_type' => 'Bearer',
            'access_token' => $this->plainTextToken,
            'expires_at' => $this->expiresAt,
            ...CorporateAccessSummaryResource::make($this->summary)->resolve($request),
        ];
    }
}
