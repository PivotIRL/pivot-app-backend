<?php

namespace App\Organization\Http\Resources;

use App\Organization\Enums\AuditActorType;
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
            'organization_id' => $this->organization_id === null ? null : (int) $this->organization_id,
            'action' => $this->action->value,
            'actor_type' => $this->actor_type->value,
            // when() instead of whenLoaded(): whenLoaded() skips the callback when the relation is null.
            'actor' => $this->when($this->resource->relationLoaded('actor'), fn () => match (true) {
                $this->actor !== null => [
                    'id' => $this->actor->id,
                    'name' => $this->actor->name,
                    'email' => $this->actor->email,
                ],
                // Admin Dashboard admins are not Users: same shape, without a user ID or email.
                $this->actor_type === AuditActorType::ADMIN => [
                    'id' => null,
                    'name' => $this->metadata['admin_name'] ?? 'Pivot Admin',
                    'email' => null,
                ],
                default => null,
            }),
            'target_type' => $this->target_type,
            'target_id' => $this->target_type === 'organization' && is_numeric($this->target_id) ? (int) $this->target_id : $this->target_id,
            'metadata' => $this->metadata ?? (object) [],
            'ip_address' => $this->when($isPivotStaff, $this->ip_address),
            'user_agent' => $this->when($isPivotStaff, $this->user_agent),
            'created_at' => $this->created_at,
        ];
    }
}
