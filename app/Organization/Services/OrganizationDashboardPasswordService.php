<?php

namespace App\Organization\Services;

use App\Organization\Enums\AuditAction;
use App\Organization\Exceptions\OrganizationDashboardAuthException;
use App\Organization\Mail\OrganizationDashboardPasswordMail;
use App\Organization\Models\OrganizationDashboardAccount;
use App\Organization\Repositories\Contracts\OrganizationDashboardAccountRepositoryInterface;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

use function Illuminate\Support\defer;

/**
 * First-time setup and password reset for dashboard accounts. Uses its own brokers and table,
 * so it cannot touch mobile OTPs, users.password or mobile tokens.
 */
class OrganizationDashboardPasswordService
{
    private const RESET_BROKER = 'organization_dashboard';

    private const SETUP_BROKER = 'organization_dashboard_setup';

    public function __construct(
        protected OrganizationDashboardAccountRepositoryInterface $accounts,
        protected AuditLogger $audit,
    ) {}

    /**
     * Runs after the response, so the response looks and takes the same whether or not the email exists.
     */
    public function requestLink(string $email): void
    {
        defer(fn () => rescue(function () use ($email) {
            $account = $this->accounts->findByEmail($email);

            if ($account && ! $account->isDisabled()) {
                $this->sendLink($account);
            }
        }));
    }

    /**
     * @return string The broker status, such as Password::RESET_LINK_SENT or Password::RESET_THROTTLED.
     */
    public function sendLink(OrganizationDashboardAccount $account): string
    {
        $isSetup = ! $account->hasPassword();

        return $this->brokerFor($isSetup)->sendResetLink(
            ['email' => $account->email],
            function (OrganizationDashboardAccount $account, string $token) use ($isSetup) {
                Mail::to($account->email)->send(new OrganizationDashboardPasswordMail($account, $token, $isSetup));
            },
        );
    }

    /**
     * The account decides which broker applies, so a client cannot extend a reset link's lifetime.
     *
     * @throws OrganizationDashboardAuthException
     */
    public function resetPassword(string $email, string $token, string $password): void
    {
        $account = $this->accounts->findByEmail($email);

        if (! $account || $account->isDisabled()) {
            // Takes as long as checking a real token, so timing does not reveal the account.
            Hash::check($token, OrganizationDashboardAuthService::TIMING_GUARD_HASH);

            throw OrganizationDashboardAuthException::invalidPasswordLink();
        }

        $isSetup = ! $account->hasPassword();

        $status = $this->brokerFor($isSetup)->reset(
            ['email' => $account->email, 'token' => $token, 'password' => $password],
            function (OrganizationDashboardAccount $account, string $password) use ($isSetup) {
                DB::transaction(function () use ($account, $password, $isSetup) {
                    $this->accounts->setPassword($account, $password);
                    $this->accounts->revokeTokens($account);

                    $this->audit->record(
                        action: $isSetup ? AuditAction::DASHBOARD_PASSWORD_SET : AuditAction::DASHBOARD_PASSWORD_RESET,
                        target: $account,
                        actor: $account->user,
                    );
                });
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw OrganizationDashboardAuthException::invalidPasswordLink();
        }
    }

    private function brokerFor(bool $isSetup): PasswordBroker
    {
        return Password::broker($isSetup ? self::SETUP_BROKER : self::RESET_BROKER);
    }
}
