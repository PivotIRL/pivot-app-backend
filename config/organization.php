<?php

return [

    'dashboard' => [
        // Base URL of the Organization Dashboard frontend, used in password setup and reset links.
        'url' => env('ORGANIZATION_DASHBOARD_URL'),

        // Dashboard tokens expire; mobile tokens are not affected by this value.
        'token_ttl_minutes' => (int) env('ORGANIZATION_DASHBOARD_TOKEN_TTL_MINUTES', 720),
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
