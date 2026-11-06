<?php

namespace App\Console\Commands;

use App\Services\EngagementNotificationService;
use Illuminate\Console\Command;

class SendEngagementNotifications extends Command
{
    protected $signature = 'notifications:send-engagement';
    protected $description = 'Send due trial, inactivity and daily challenge push reminders';

    public function handle(EngagementNotificationService $service): int
    {
        $this->info('FCM accepted '.$service->sendDue().' engagement notifications.');
        return self::SUCCESS;
    }
}
