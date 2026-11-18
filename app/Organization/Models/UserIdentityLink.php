<?php

namespace App\Organization\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserIdentityLink extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'organization_id',
        'provider_id',
        'external_subject',
        'external_email',
        'last_login_at',
    ];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(OrganizationIdentityProvider::class, 'provider_id');
    }
}
