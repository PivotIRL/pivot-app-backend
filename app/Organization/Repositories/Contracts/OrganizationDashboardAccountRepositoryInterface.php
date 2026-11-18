<?php

namespace App\Organization\Repositories\Contracts;

use App\Models\User;
use App\Organization\Models\OrganizationDashboardAccount;
use Closure;

interface OrganizationDashboardAccountRepositoryInterface
{
    public function findByEmail(string $email): ?OrganizationDashboardAccount;

    public function findByUserId(int $userId): ?OrganizationDashboardAccount;

    /**
     * Creates the account unless one exists. Returns null when the email belongs to another account.
     * Uses ON CONFLICT DO NOTHING, so a conflict cannot abort the caller's transaction.
     */
    public function firstOrCreateForUser(User $user): ?OrganizationDashboardAccount;

    public function setPassword(OrganizationDashboardAccount $account, string $password): OrganizationDashboardAccount;

    public function recordLogin(OrganizationDashboardAccount $account): OrganizationDashboardAccount;

    /**
     * Deletes this dashboard account's tokens. Mobile tokens have a different owner and are not affected.
     */
    public function revokeTokens(OrganizationDashboardAccount $account): void;

    /**
     * Every user with a Pivot staff role or an active administrative organization role.
     */
    public function eachEligibleUser(Closure $callback): void;
}
