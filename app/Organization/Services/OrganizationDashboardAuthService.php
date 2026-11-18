<?php

namespace App\Organization\Services;

use App\Models\User;
use App\Organization\Data\OrganizationDashboardLoginResult;
use App\Organization\Enums\AuditAction;
use App\Organization\Exceptions\OrganizationDashboardAuthException;
use App\Organization\Models\OrganizationDashboardAccount;
use App\Organization\Repositories\Contracts\OrganizationDashboardAccountRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Organization Dashboard login and logout. Never reads users.password.
 */
class OrganizationDashboardAuthService
{
    // Checked when there is no real hash, so unknown emails take as long as known ones.
    public const TIMING_GUARD_HASH = '$2y$12$coT/0FiW7IhlFuBpBzqwOOI0CYY9iE1sqwXKzQmcqBgPHGC2NtZQm';

    public function __construct(
        protected OrganizationDashboardAccountRepositoryInterface $accounts,
        protected OrganizationAccessService $access,
        protected CorporateAccessService $corporateAccess,
        protected AuditLogger $audit,
    ) {}

    /**
     * Credentials are checked first, so only the password holder can learn the account's status.
     *
     * @throws OrganizationDashboardAuthException
     */
    public function login(string $email, string $password): OrganizationDashboardLoginResult
    {
        $account = $this->accounts->findByEmail($email);
        $passwordMatches = Hash::check($password, $account?->password ?? self::TIMING_GUARD_HASH);

        if (! $account?->hasPassword() || ! $passwordMatches) {
            throw OrganizationDashboardAuthException::invalidCredentials();
        }

        $user = $account->user;

        if ($account->isDisabled()) {
            $this->recordDenied($user, 'account_disabled');

            throw OrganizationDashboardAuthException::accountDisabled();
        }

        if (! $this->access->hasDashboardAccess($user)) {
            $this->recordDenied($user, 'no_dashboard_access');

            throw OrganizationDashboardAuthException::noDashboardAccess();
        }

        $expiresAt = now()->addMinutes(config('organization.dashboard.token_ttl_minutes'));

        $plainTextToken = DB::transaction(function () use ($account, $user, $expiresAt) {
            $this->accounts->recordLogin($account);

            $this->audit->record(
                action: AuditAction::DASHBOARD_LOGIN,
                target: $account,
                actor: $user,
            );

            return $account->createToken(
                OrganizationDashboardAccount::TOKEN_NAME,
                [OrganizationDashboardAccount::TOKEN_ABILITY],
                $expiresAt,
            )->plainTextToken;
        });

        return new OrganizationDashboardLoginResult(
            plainTextToken: $plainTextToken,
            expiresAt: $expiresAt,
            summary: $this->corporateAccess->summaryFor($user, $account),
        );
    }

    /**
     * Revokes only the token used for this request.
     */
    public function logout(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->currentAccessToken()->delete();

            $this->audit->record(
                action: AuditAction::DASHBOARD_LOGOUT,
                target: $user->organizationDashboardAccount,
                actor: $user,
            );
        });
    }

    private function recordDenied(User $user, string $reason): void
    {
        $this->audit->record(
            action: AuditAction::DASHBOARD_LOGIN_DENIED,
            target: $user->organizationDashboardAccount,
            metadata: ['reason' => $reason],
            actor: $user,
        );
    }
}
