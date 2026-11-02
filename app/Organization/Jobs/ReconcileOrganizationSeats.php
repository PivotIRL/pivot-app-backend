<?php

namespace App\Organization\Jobs;

use App\Organization\Services\SeatReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileOrganizationSeats implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(SeatReconciliationService $reconciliation): void
    {
        $reconciliation->reconcile();
    }
}
