<?php

namespace App\Organization\Models;

use App\Organization\Database\Factories\OrganizationRedemptionCodeFactory;
use App\Organization\Enums\RedemptionCodeType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationRedemptionCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'code_type',
        'code_hash',
        'code_prefix',
        'max_uses',
        'uses_count',
        'starts_at',
        'expires_at',
        'is_active',
        'created_by',
        'last_used_at',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'max_uses' => 'integer',
            'code_type' => RedemptionCodeType::class,
            'uses_count' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    protected static function newFactory(): OrganizationRedemptionCodeFactory
    {
        return OrganizationRedemptionCodeFactory::new();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
