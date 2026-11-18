<?php

namespace App\Organization\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Organization\Models\OrganizationAuditLog
 */
class OrganizationAuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Network details are only shown to Pivot staff, not to corporate admins.
        $isPivotStaff = (bool) $request->user()?->isPivotStaff();

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'action' => $this->action->value,
            'actor_type' => $this->actor_type->value,
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'email' => $this->actor->email,
            ] : null),
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'metadata' => $this->metadata ?? (object) [],
            'ip_address' => $this->when($isPivotStaff, $this->ip_address),
            'user_agent' => $this->when($isPivotStaff, $this->user_agent),
            'created_at' => $this->created_at,
        ];
    }
}
