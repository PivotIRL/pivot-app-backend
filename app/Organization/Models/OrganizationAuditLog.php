<?php

namespace App\Organization\Models;

use App\Models\User;
use App\Organization\Enums\AuditAction;
use App\Organization\Enums\AuditActorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class OrganizationAuditLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'actor_user_id',
        'actor_type',
        'action',
        'target_type',
        'target_id',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'actor_type' => AuditActorType::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    // Audit history is insert-only.
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Organization audit logs are immutable.'));
        static::deleting(fn () => throw new LogicException('Organization audit logs are immutable.'));
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
