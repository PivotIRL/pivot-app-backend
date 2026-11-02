<?php

namespace App\Organization\Models;

use App\Organization\Enums\OrganizationStatus;
use App\Organization\Enums\Package;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationContract extends Model
{
    protected $fillable = [
        'organization_id',
        'status',
        'package_code',
        'seat_limit',
        'starts_at',
        'ends_at',
        'pricing_config',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'package_code' => Package::class,
            'seat_limit' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'pricing_config' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
