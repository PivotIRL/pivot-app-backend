<?php

namespace App\Organization\Models;

use App\Organization\Database\Factories\OrganizationEntitlementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationEntitlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'feature_key',
        'value_json',
    ];

    protected function casts(): array
    {
        return [
            'value_json' => 'array',
        ];
    }

    protected static function newFactory(): OrganizationEntitlementFactory
    {
        return OrganizationEntitlementFactory::new();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
