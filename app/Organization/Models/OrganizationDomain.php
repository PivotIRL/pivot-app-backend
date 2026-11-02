<?php

namespace App\Organization\Models;

use App\Organization\Database\Factories\OrganizationDomainFactory;
use App\Organization\Enums\OrganizationDomainStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationDomain extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'domain',
        'status',
        'is_primary',
        'verification_method',
        'verification_token',
        'include_subdomains',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrganizationDomainStatus::class,
            'is_primary' => 'boolean',
            'include_subdomains' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    protected static function newFactory(): OrganizationDomainFactory
    {
        return OrganizationDomainFactory::new();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
