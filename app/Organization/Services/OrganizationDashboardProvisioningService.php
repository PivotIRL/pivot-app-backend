<?php

namespace App\Organization\Services;

use App\Models\User;
use App\Organization\Models\OrganizationDashboardAccount;
use App\Organization\Repositories\Contracts\OrganizationDashboardAccountRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Creates dashboard accounts for eligible users and sends their first-time password setup link.
 */
class OrganizationDashboardProvisioningService
{
    public function __construct(
        protected OrganizationDashboardAccountRepositoryInterface $accounts,
        protected OrganizationDashboardPasswordService $passwords,
        protected OrganizationAccessService $access,
    ) {}

    /**
     * Safe to call inside a transaction: the email is only sent after it commits, and a mail
     * failure never fails the role change. The user can request a new link with forgot-password.
     */
    public function provision(User $user): ?OrganizationDashboardAccount
    {
        $account = $this->accounts->firstOrCreateForUser($user);

        if (! $account) {
            Log::warning('Organization Dashboard account not created: the email is used by another account.', [
                'user_id' => $user->id,
            ]);

            return null;
        }

        if (! $account->hasPassword() && ! $account->isDisabled()) {
            DB::afterCommit(fn () => rescue(fn () => $this->passwords->sendLink($account)));
        }

        return $account;
    }

    /**
     * Provisions users who already hold a dashboard role. Users who currently have no dashboard
     * access are skipped, and accounts awaiting setup are only re-sent a link with $resend.
     *
     * @return array{provisioned: int, skipped_no_access: int, skipped_existing: int, failed: int}
     */
    public function provisionExisting(bool $dryRun = false, bool $resend = false): array
    {
        $counts = ['provisioned' => 0, 'skipped_no_access' => 0, 'skipped_existing' => 0, 'failed' => 0];

        $this->accounts->eachEligibleUser(function (User $user) use (&$counts, $dryRun, $resend) {
            if (! $this->access->hasDashboardAccess($user)) {
                $counts['skipped_no_access']++;

                return;
            }

            $existing = $this->accounts->findByUserId($user->id);

            if ($existing && ($existing->hasPassword() || $existing->isDisabled() || ! $resend)) {
                $counts['skipped_existing']++;

                return;
            }

            if ($dryRun) {
                $counts['provisioned']++;

                return;
            }

            $counts[$this->provision($user) ? 'provisioned' : 'failed']++;
        });

        return $counts;
    }
}
