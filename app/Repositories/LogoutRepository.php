<?php

namespace App\Repositories;

use App\Models\Users;
use App\Models\User;
use App\Repositories\Auth\LogoutRepositoryInterface;
use Illuminate\Support\Facades\Auth;

class LogoutRepository implements LogoutRepositoryInterface
{
    public function logoutCurrentUser(): void
    {
        $user = Auth::user();
        if ($user instanceof Users || $user instanceof User) {
            $this->logout($user);
        }
    }

    public function logout(Users|User $user): void
    {
        $user->forceFill(['fcm_token' => null])->save();
        $user->revokeMobileTokens();
    }
}
