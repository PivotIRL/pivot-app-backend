<?php

namespace App\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationIdentityProvider extends Model
{
    protected $fillable = [
        'organization_id',
        'provider_type',
        'issuer_url',
        'client_id',
        'encrypted_client_secret',
        'metadata_url',
        'tenant_id',
        'is_enforced',
        'is_active',
    ];

    protected $hidden = [
        'encrypted_client_secret',
    ];

    protected function casts(): array
    {
        return [
            'encrypted_client_secret' => 'encrypted',
            'is_enforced' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function identityLinks(): HasMany
    {
        return $this->hasMany(UserIdentityLink::class, 'provider_id');
    }
}
