<?php

use App\Organization\Http\Controllers\OrganizationJoinPageController;
use Illuminate\Support\Facades\Route;

/*
| Public page opened by an organization invitation link:
|   /organization/join?token=…&code=…
|
| It never changes data; the invited person signs in or registers in the Mobile App and accepts there.
*/

Route::get('organization/join', OrganizationJoinPageController::class)
    ->middleware('throttle:organization-invitation-preview')
    ->name('organization.join-page');
