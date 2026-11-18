<?php

namespace App\Organization\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Organization\Models\OrganizationDomain */
class OrganizationDomainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'status' => $this->status?->value,
            'is_primary' => $this->is_primary,
            'include_subdomains' => $this->include_subdomains,
            'verification_method' => $this->verification_method,
            'verification_token' => $this->verification_token,
            'verified_at' => $this->verified_at,
            'created_at' => $this->created_at,
        ];
    }
}
