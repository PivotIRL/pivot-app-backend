<?php

namespace App\Organization\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Organization\Models\OrganizationRedemptionCode */
class OrganizationRedemptionCodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code_type' => $this->code_type?->value,
            'code_prefix' => $this->code_prefix,
            'max_uses' => $this->max_uses,
            'uses_count' => $this->uses_count,
            'starts_at' => $this->starts_at,
            'expires_at' => $this->expires_at,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
