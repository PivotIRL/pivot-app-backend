<?php

namespace App\Repositories\Auth;

use App\Models\User;

interface AuthRepositoryInterface
{
    public function createUser(array $data): User;
    public function findByEmail(string $email): ?User;
    public function updateLastLogin(User $user): void;
    public function updateStatusByUserId(int $userId, string $status): User;
    public function updateFcmTokenByUserId(int $userId, string $fcmToken): User;
    public function updatePassword(string $email, string $password): void;
    public function getCurrentUser();
}
