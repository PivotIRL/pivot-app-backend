<?php

namespace App\Organization\Console\Commands;

use App\Organization\Services\OrganizationDashboardProvisioningService;
use Illuminate\Console\Command;

/**
 * Gives users who already hold a dashboard role an account and a first-time setup link.
 *
 *   php artisan organization:dashboard-provision --dry-run
 *   php artisan organization:dashboard-provision
 *   php artisan organization:dashboard-provision --resend
 */
class ProvisionDashboardAccountsCommand extends Command
{
    protected $signature = 'organization:dashboard-provision
        {--dry-run : Report what would happen without creating accounts or sending email}
        {--resend : Also send a new setup link to accounts that have not set a password}';

    protected $description = 'Provision Organization Dashboard accounts for users who already hold a dashboard role';

    public function handle(OrganizationDashboardProvisioningService $provisioning): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! config('organization.dashboard.url')) {
            $this->error('Set ORGANIZATION_DASHBOARD_URL first: setup emails need it for their link.');

            return self::FAILURE;
        }

        $counts = $provisioning->provisionExisting($dryRun, (bool) $this->option('resend'));

        $this->table(['Result', 'Users'], [
            [$dryRun ? 'Would be provisioned' : 'Provisioned', $counts['provisioned']],
            ['Skipped: no current dashboard access', $counts['skipped_no_access']],
            ['Skipped: account already exists', $counts['skipped_existing']],
            ['Failed: email used by another account', $counts['failed']],
        ]);

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
