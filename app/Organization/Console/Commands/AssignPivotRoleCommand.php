<?php

namespace App\Organization\Console\Commands;

use App\Models\User;
use App\Organization\Enums\PivotRole;
use App\Organization\Services\PivotRoleService;
use Illuminate\Console\Command;

/**
 * Global Pivot staff roles are granted from the server only, never through the API.
 *
 *   php artisan pivot:role staff@pivot.app PIVOT_SUPER_ADMIN
 *   php artisan pivot:role staff@pivot.app --revoke
 */
class AssignPivotRoleCommand extends Command
{
    protected $signature = 'pivot:role
        {email : Email of the staff user}
        {role? : PIVOT_SUPER_ADMIN or PIVOT_SUPPORT}
        {--revoke : Remove the global Pivot role from the user}';

    protected $description = 'Grant or revoke a global Pivot staff role';

    public function handle(PivotRoleService $pivotRoles): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user found with that email.');

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $pivotRoles->revoke($user);
            $this->info("Pivot role removed from {$user->email}.");

            return self::SUCCESS;
        }

        $role = PivotRole::tryFrom((string) $this->argument('role'));

        if (! $role) {
            $this->error('Role must be one of: '.implode(', ', array_column(PivotRole::cases(), 'value')).'.');

            return self::FAILURE;
        }

        $pivotRoles->grant($user, $role);
        $this->info("{$role->value} granted to {$user->email}.");

        return self::SUCCESS;
    }
}
