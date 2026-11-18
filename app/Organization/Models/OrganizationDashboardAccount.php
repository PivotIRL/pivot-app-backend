<?php

namespace App\Organization\Models;

use App\Models\User;
use App\Organization\Enums\OrganizationDashboardAccountStatus;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Organization Dashboard credentials for an existing user.
 *
 * This model owns the dashboard's Sanctum tokens. Token isolation relies on that ownership:
 * the dashboard guard accepts only this model, and the mobile guard does not accept it.
 */
class OrganizationDashboardAccount extends Authenticatable implements CanResetPasswordContract
{
    use CanResetPassword, HasApiTokens;

    public const GUARD = 'organization-dashboard';

    public const TOKEN_NAME = 'organization-dashboard';

    public const TOKEN_ABILITY = 'organization-dashboard';

    // Credential fields are set explicitly through forceFill() in the repository.
    protected $fillable = [
        'user_id',
        'email',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'password_set_at' => 'datetime',
            'disabled_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    public function status(): OrganizationDashboardAccountStatus
    {
        return match (true) {
            $this->isDisabled() => OrganizationDashboardAccountStatus::DISABLED,
            $this->hasPassword() => OrganizationDashboardAccountStatus::ACTIVE,
            default => OrganizationDashboardAccountStatus::PENDING_SETUP,
        };
    }
}
