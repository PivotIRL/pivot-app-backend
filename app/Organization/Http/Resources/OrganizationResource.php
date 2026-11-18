<?php

namespace App\Organization\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Organization\Models\Organization
 */
class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'organization_type' => $this->organization_type?->value,
            'status' => $this->status?->value,
            'package_code' => $this->package_code?->value,
            'seat_limit' => $this->seat_limit,
            'contract_start_at' => $this->contract_start_at,
            'contract_end_at' => $this->contract_end_at,
            'timezone' => $this->timezone,
            'country_code' => $this->country_code,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
            'current_contract' => $this->whenLoaded(
                'currentContract',
                fn () => OrganizationContractResource::make($this->currentContract),
            ),
        ];
    }
}
