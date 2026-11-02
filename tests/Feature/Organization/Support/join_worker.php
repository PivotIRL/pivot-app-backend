<?php

/*
| One join attempt in its own PHP process, used by SeatConcurrencyTest to create real parallel load.
|
| php join_worker.php seat <organization_id> <user_id> <start_at>
| php join_worker.php code <plain_code>      <user_id> <start_at>
|
| Every worker connects first and then waits until <start_at> (unix time, float), so all attempts hit
| the database at the same moment. Prints the resulting CorporateJoinStatus value.
*/

use App\Models\User;
use App\Organization\Enums\MembershipJoinMethod;
use App\Organization\Exceptions\CorporateJoinException;
use App\Organization\Models\Organization;
use App\Organization\Services\CorporateOnboardingService;
use App\Organization\Services\SeatService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../../vendor/autoload.php';

$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $mode, $target, $userId, $startAt] = $argv;

$user = User::findOrFail((int) $userId);
$organization = $mode === 'seat' ? Organization::findOrFail($target) : null;
DB::connection()->getPdo();

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    $result = $mode === 'seat'
        ? app(SeatService::class)->allocate($organization, $user, MembershipJoinMethod::WORK_EMAIL)
        : app(CorporateOnboardingService::class)->redeemCode($user, $target);

    echo $result->status->value;
} catch (CorporateJoinException $e) {
    echo $e->joinStatus->value;
}
