<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Contracts\MobileApiTokenable;
use App\Organization\Concerns\HasOrganizationRoles;
use DevKandil\NotiFire\Traits\HasFcm;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\Sanctum;

class User extends Authenticatable implements MobileApiTokenable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasFcm, HasOrganizationRoles;

    protected $table = 'users';

    // The legacy Users subclass must use the same inferred relationship keys.
    public function getForeignKey()
    {
        return 'user_id';
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'status',
        'fcm_token',
        'password',
        'provider',
        'provider_id',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
        'password',
        'remember_token',
        'fcm_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    public function hobbies(): BelongsToMany
    {
        return $this->belongsToMany(Hobby::class, 'user_hobbies', 'user_id', 'hobby_id');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class, 'user_id', 'id');
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class, 'user_id')->where('active', true)->latest();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'user_id');
    }

    /** Include inactive records for subscription administration and history. */
    public function latestSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class, 'user_id')->latest();
    }

    public function userProfile(): HasOne
    {
        return $this->profile();
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function activities(): HasManyThrough
    {
        return $this->hasManyThrough(Activity::class, UserHobby::class, 'user_id', 'hobby_id', 'id', 'hobby_id');
    }

    public function subscriptionLogs(): HasMany
    {
        return $this->hasMany(SubscriptionLogs::class, 'user_id');
    }

    public function groupChallengeParticipants(): HasMany
    {
        return $this->hasMany(GroupChallengeParticipant::class, 'user_id');
    }

    public function challengeLogs(): HasMany
    {
        return $this->hasMany(ChallengeLog::class, 'user_id');
    }

    /** Revoke all mobile sessions, including tokens issued before model consolidation. */
    public function revokeMobileTokens(): void
    {
        $tokenModel = Sanctum::$personalAccessTokenModel;
        $tokenModel::query()
            ->where('tokenable_id', $this->getKey())
            ->whereIn('tokenable_type', array_unique([
                self::class,
                Users::class,
                (new self)->getMorphClass(),
                (new Users)->getMorphClass(),
            ]))
            ->delete();
    }
}
