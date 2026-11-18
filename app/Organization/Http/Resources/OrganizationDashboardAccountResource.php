<?php

namespace App\Organization\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Organization\Models\OrganizationDashboardAccount
 */
class OrganizationDashboardAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'status' => $this->status()->value,
            'password_set_at' => $this->password_set_at,
            'last_login_at' => $this->last_login_at,
        ];
    }
}
