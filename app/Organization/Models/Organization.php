<?php

namespace App\Organization\Models;

use App\Organization\Database\Factories\OrganizationFactory;
use App\Organization\Enums\OrganizationStatus;
use App\Organization\Enums\OrganizationType;
use App\Organization\Enums\Package;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'organization_type',
        'account_type',
        'status',
        'package_code',
        'seat_limit',
        'contract_start_at',
        'contract_end_at',
        'timezone',
        'country_code',
        'pricing_config',
        'reporting_config',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'organization_type' => OrganizationType::class,
            'status' => OrganizationStatus::class,
            'package_code' => Package::class,
            'seat_limit' => 'integer',
            'contract_start_at' => 'datetime',
            'contract_end_at' => 'datetime',
            'pricing_config' => 'array',
            'reporting_config' => 'array',
        ];
    }

    protected static function newFactory(): OrganizationFactory
    {
        return OrganizationFactory::new();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function domains(): HasMany
    {
        return $this->hasMany(OrganizationDomain::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(OrganizationContract::class);
    }

    public function currentContract(): HasOne
    {
        return $this->hasOne(OrganizationContract::class)->latestOfMany();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(OrganizationEntitlement::class);
    }

    public function redemptionCodes(): HasMany
    {
        return $this->hasMany(OrganizationRedemptionCode::class);
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(OrganizationRoleAssignment::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(OrganizationAuditLog::class);
    }

    public function identityProviders(): HasMany
    {
        return $this->hasMany(OrganizationIdentityProvider::class);
    }

    public function identityLinks(): HasMany
    {
        return $this->hasMany(UserIdentityLink::class);
    }

    public function scimCredentials(): HasMany
    {
        return $this->hasMany(OrganizationScimCredential::class);
    }

    public function dailyMetrics(): HasMany
    {
        return $this->hasMany(OrganizationDailyMetric::class);
    }

    public function baselines(): HasMany
    {
        return $this->hasMany(OrganizationBaseline::class);
    }

    public function pulseMetrics(): HasMany
    {
        return $this->hasMany(OrganizationPulseMetric::class);
    }

    public function hrMetrics(): HasMany
    {
        return $this->hasMany(OrganizationHrMetric::class);
    }
}
