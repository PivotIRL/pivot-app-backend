<?php

return [

    'dashboard' => [
        // Base URL of the Organization Dashboard frontend, used in password setup and reset links.
        'url' => env('ORGANIZATION_DASHBOARD_URL'),

        // Dashboard tokens expire; mobile tokens are not affected by this value.
        'token_ttl_minutes' => (int) env('ORGANIZATION_DASHBOARD_TOKEN_TTL_MINUTES', 720),
    ],

];
