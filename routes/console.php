<?php

use App\Models\Activity;
use App\Organization\Jobs\ActivateOrganizationContracts;
use App\Organization\Jobs\ExpireOrganizationContracts;
use App\Organization\Jobs\ReconcileOrganizationSeats;
use App\Support\InstructionFormatter;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('activities:normalize-instructions', function () {
    $updated = 0;

    Activity::query()
        ->whereNotNull('instruction')
        ->chunkById(500, function ($activities) use (&$updated) {
            foreach ($activities as $activity) {
                $normalized = InstructionFormatter::normalize($activity->instruction);

                if ($normalized !== $activity->instruction) {
                    $activity->instruction = $normalized;
                    $activity->save();
                    $updated++;
                }
            }
        });

    $this->info("Normalized instructions for {$updated} activities.");
})->purpose('Normalize activities instruction text format and fix common key/format issues.');

// Automatically purge app analytics logs older than 30 days every day
Schedule::command('app:purge-old-usage-logs')->daily();
Schedule::command('notifications:send-engagement')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('notifications:send-global')->everyMinute()->withoutOverlapping()->runInBackground();
Schedule::job(new ExpireOrganizationContracts)->daily();
Schedule::job(new ActivateOrganizationContracts)->daily();
Schedule::job(new ReconcileOrganizationSeats)->daily();

Artisan::command('membership:cancel-lifetime-renewals', function () {
    \Illuminate\Support\Facades\DB::table('lifetime_renewal_cancellations as c')
        ->join('membership_attempts as a', function ($join) {
            $join->on('c.checkout_key', '=', 'a.idempotency_key')->on('c.user_id', '=', 'a.user_id');
        })->where('a.operation', 'change')->where('a.status', 'completed')->where('c.status', '!=', 'completed')
        ->where(fn ($q) => $q->whereNull('c.next_attempt_at')->orWhere('c.next_attempt_at', '<=', now()))
        ->select('c.id')->distinct()->orderBy('c.id')->limit(100)->get()
        ->each(fn ($row) => \App\Jobs\StopRenewalAfterLifetime::dispatch($row->id));
})->purpose('Retry renewal cancellation only after verified Lifetime checkout completion');
Schedule::command('membership:cancel-lifetime-renewals')->everyMinute()->withoutOverlapping();
