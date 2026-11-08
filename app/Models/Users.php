<?php

namespace App\Models;

use App\Contracts\MobileApiTokenable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;

class Users extends Model implements MobileApiTokenable
{
    use HasApiTokens, Notifiable;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'fcm_token',
        'provider',
        'provider_id',
        'last_login_at',
    ];

    protected $hidden = [
        'password_hash',
        'password',
        'remember_token',
        'fcm_token',
    ];


    public function getAuthPassword()
    {
        return $this->password_hash;
    }
    public function profile()
    {
        return $this->hasOne(UserProfile::class, 'user_id', 'id');
    }

    public function hobbies()
    {
        return $this->belongsToMany(Hobby::class, 'user_hobbies', 'user_id', 'hobby_id');
    }

    public function activities()
    {
        return $this->hasManyThrough(
            Activity::class,
            UserHobby::class,
            'user_id',
            'hobby_id',
            'id',
            'hobby_id'
        );
    }

    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function userProfile()
    {
        return $this->hasOne(UserProfile::class, 'user_id', 'id');
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'user_id', 'id');
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class, 'user_id', 'id')->where('active', true)->latest();
    }

    public function subscriptionLogs()
    {
        return $this->hasMany(SubscriptionLogs::class, 'user_id', 'id');
    }

    public function groupChallengeParticipants()
    {
        return $this->hasMany(GroupChallengeParticipant::class, 'user_id', 'id');
    }

    public function challengeLogs()
    {
        return $this->hasMany(ChallengeLog::class, 'user_id', 'id');
    }

    protected $casts = [
        'last_login_at' => 'datetime',
    ];
}
