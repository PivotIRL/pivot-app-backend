<?php

namespace App\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Organization\Services\OrganizationInvitationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /organization/join?token=…&code=…
 */
class OrganizationJoinPageController extends Controller
{
    public function __invoke(Request $request, OrganizationInvitationService $invitations): Response
    {
        // Query values can be arrays; anything but a string is treated as missing.
        $token = is_string($request->query('token')) ? $request->query('token') : '';
        $code = is_string($request->query('code')) ? $request->query('code') : '';

        ['state' => $state, 'invitation' => $invitation] = $invitations->inspect($token, $code);

        $appUrl = config('organization.invitation.app_url');
        $appLink = $state === 'valid' && $appUrl
            ? $appUrl.(str_contains($appUrl, '?') ? '&' : '?').http_build_query(['token' => $token, 'code' => $code])
            : null;

        return response()->view('organization.member-invitation', [
            'state' => $state,
            'organizationName' => $invitation?->organization->name,
            'code' => $state === 'valid' ? $code : null,
            'appLink' => $appLink,
            'iosStoreUrl' => config('organization.invitation.ios_store_url'),
            'androidStoreUrl' => config('organization.invitation.android_store_url'),
        ], match ($state) {
            'expired' => 410,
            'invalid' => 404,
            default => 200,
        })->withHeaders(['Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store']);
    }
}
