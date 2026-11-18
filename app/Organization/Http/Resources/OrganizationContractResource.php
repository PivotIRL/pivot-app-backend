<?php

namespace App\Organization\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Organization\Models\OrganizationContract */
class OrganizationContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status?->value,
            'package_code' => $this->package_code?->value,
            'seat_limit' => $this->seat_limit,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'pricing_config' => $this->pricing_config,
            'created_at' => $this->created_at,
        ];
    }
}
