<?php

namespace App\Organization\Repositories;

use App\Models\User;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Models\OrganizationDashboardAccount;
use App\Organization\Repositories\Contracts\OrganizationDashboardAccountRepositoryInterface;
use Closure;
use Illuminate\Support\Str;

class OrganizationDashboardAccountRepository implements OrganizationDashboardAccountRepositoryInterface
{
    public function findByEmail(string $email): ?OrganizationDashboardAccount
    {
        return OrganizationDashboardAccount::query()
            ->with('user')
            ->where('email', Str::lower(trim($email)))
            ->first();
    }

    public function findByUserId(int $userId): ?OrganizationDashboardAccount
    {
        return OrganizationDashboardAccount::query()->where('user_id', $userId)->first();
    }

    public function firstOrCreateForUser(User $user): ?OrganizationDashboardAccount
    {
        OrganizationDashboardAccount::query()->insertOrIgnore([
            'user_id' => $user->id,
            'email' => Str::lower(trim($user->email)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->findByUserId($user->id);
    }

    public function setPassword(OrganizationDashboardAccount $account, string $password): OrganizationDashboardAccount
    {
        $account->forceFill([
            'password' => $password,
            'password_set_at' => now(),
        ])->save();

        return $account;
    }

    public function recordLogin(OrganizationDashboardAccount $account): OrganizationDashboardAccount
    {
        $account->forceFill(['last_login_at' => now()])->save();

        return $account;
    }

    public function revokeTokens(OrganizationDashboardAccount $account): void
    {
        $account->tokens()->delete();
    }

    public function eachEligibleUser(Closure $callback): void
    {
        User::query()
            ->whereNotNull('pivot_role')
            ->orWhereHas('organizationRoleAssignments', fn ($assignments) => $assignments
                ->whereNull('revoked_at')
                ->whereIn('role', OrganizationRole::administrativeRoles()))
            ->lazyById()
            ->each($callback);
    }
}
