<?php

namespace App\Organization\Models;

use App\Organization\Database\Factories\OrganizationRoleAssignmentFactory;
use App\Organization\Enums\OrganizationRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationRoleAssignment extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'user_id',
        'role',
        'assigned_by',
        'assigned_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'assigned_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function newFactory(): OrganizationRoleAssignmentFactory
    {
        return OrganizationRoleAssignmentFactory::new();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
