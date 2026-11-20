<?php
namespace App\Jobs;
use App\Services\Membership\LifetimeRenewalCancellation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
class StopRenewalAfterLifetime implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public int $timeout = 90;
    public function __construct(public int $cancellationId) {}
    public function handle(LifetimeRenewalCancellation $service): void { $service->process($this->cancellationId); }
}
