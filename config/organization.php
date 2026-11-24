<?php

return [

    'dashboard' => [
        // Base URL of the Organization Dashboard frontend, used in password setup and reset links.
        'url' => env('ORGANIZATION_DASHBOARD_URL'),

        // Dashboard tokens expire; mobile tokens are not affected by this value.
        'token_ttl_minutes' => (int) env('ORGANIZATION_DASHBOARD_TOKEN_TTL_MINUTES', 720),
    ],

    'member_invitation' => [
        // Host of the emailed link: {base_url}/{user_id}/{organization_id}/{membership_id}?expires=…&signature=…
        'base_url' => env('ORGANIZATION_MEMBER_INVITE_BASE_URL', env('APP_URL', 'http://localhost')),

        // Mobile app deep link opened by the "Open in Pivot app" button, e.g. pivot://organization/invitation.
        // The full signed link is passed to it as ?link=…; the app posts it to /api/v1/organization/invitation-link/accept.
        // When empty, the page shows only the app store links.
        'app_url' => env('ORGANIZATION_MEMBER_INVITE_APP_URL'),

        'expiry_days' => (int) env('ORGANIZATION_MEMBER_INVITE_EXPIRY_DAYS', 7),

        'ios_store_url' => 'https://apps.apple.com/app/6760004980',
        'android_store_url' => 'https://play.google.com/store/apps/details?id=com.pivotirl.mobile',
    ],

    'admin_dashboard' => [
        // Admin Dashboard admins allowed to manage organizations (comma-separated emails).
        // The admins table has no roles, so access is denied to every admin unless listed here.
        'manager_emails' => array_values(array_filter(array_map(
            fn (string $email) => strtolower(trim($email)),
            explode(',', (string) env('ORGANIZATION_ADMIN_EMAILS', '')),
        ))),
    ],

];
