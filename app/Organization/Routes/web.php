<?php

use App\Organization\Http\Controllers\MemberInvitationLinkController;
use App\Organization\Services\MemberInvitationLinkService;
use Illuminate\Support\Facades\Route;

/*
| Public page opened by an organization invitation link:
|   /{user_id}/{organization_id}/{membership_id}?expires=…&signature=…
|
| Digits only, so it cannot catch any other page. The controller checks the signature and never changes data;
| the invited user accepts in the Mobile App.
*/

Route::get('{user}/{organization}/{membership}', [MemberInvitationLinkController::class, 'open'])
    ->where(['user' => '[0-9]{1,19}', 'organization' => '[0-9]{1,19}', 'membership' => '[0-9]{1,19}'])
    ->middleware('throttle:30,1')
    ->name(MemberInvitationLinkService::ROUTE);
