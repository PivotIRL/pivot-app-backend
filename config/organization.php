<?php

return [

    'dashboard' => [
        // Base URL of the Organization Dashboard frontend, used in password setup and reset links.
        'url' => env('ORGANIZATION_DASHBOARD_URL'),

        // Dashboard tokens expire; mobile tokens are not affected by this value.
        'token_ttl_minutes' => (int) env('ORGANIZATION_DASHBOARD_TOKEN_TTL_MINUTES', 720),
    ],

    'invitation' => [
        // Host of the emailed link: {base_url}/organization/join?token=…&code=…
        // It must reach this application.
        'base_url' => env('ORGANIZATION_INVITE_BASE_URL', 'https://pivotirl.com.au'),

        // Mobile app deep link opened by the "Open in Pivot app" button, e.g. pivot://organization/join.
        // It receives ?token=…&code=…. When empty, the page shows only the code and the app store links.
        'app_url' => env('ORGANIZATION_INVITE_APP_URL'),

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
