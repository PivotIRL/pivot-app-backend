<?php

namespace App\Repositories;

use App\Models\Users;
use Illuminate\Support\Facades\Hash;
use App\Repositories\Auth\AuthRepositoryInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Subscription;
use App\Support\GroupChallengeStatus;

class AuthRepository implements AuthRepositoryInterface
{
    public function createUser(array $data): Users
    {
        return DB::transaction(function () use ($data) {

            $user = Users::create([
                'name'           => $data['name'] ?? null,
                'email'          => $data['email'],
                'password'       => Hash::make($data['password']),
                'provider'       => 'email',
                'status'         => GroupChallengeStatus::GROUP_CHALLENGE_STATUS_NOT_READY,
                'last_login_at'  => now(),
            ]);

            // Create subscription
            Subscription::create([
                'user_id' => $user->id,
                'tier_id' => 1,
                'active' => true,
            ]);

            return $user;
        });
    }

    public function findByEmail(string $email): ?Users
    {
        return Users::where('email', $email)->first();
    }

    public function updateLastLogin(Users $user): void
    {
        $user->update(['last_login_at' => now()]);
    }

    public function updateStatusByUserId(int $userId, string $status): Users
    {
        $user = Users::findOrFail($userId);
        $user->status = $status;
        $user->save();

        return $user->fresh(['subscription']);
    }

    public function updateFcmTokenByUserId(int $userId, string $fcmToken): Users
    {
        // A device token must belong only to the account currently using it.
        Users::where('fcm_token', $fcmToken)->where('id', '!=', $userId)->update(['fcm_token' => null]);
        $user = Users::findOrFail($userId);
        $user->fcm_token = $fcmToken;
        $user->save();

        return $user;
    }

    public function updatePassword(string $email, string $password): void
    {
        Users::where('email', $email)->update([
            'password' => Hash::make($password),
        ]);
    }

    public function getCurrentUser()
    {
        return Auth::user();
    }
}
