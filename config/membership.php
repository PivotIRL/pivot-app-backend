<?php

return [
    // Compatibility for older clients without a paid package. Plan edits must not grant free access.
    'legacy_free_limits' => [
        'daily_challenges' => 3, 'category_count' => 5, 'blocked_apps' => 10,
        'scheduled_blocking_hours' => 12, 'blocking_intervals' => 8, 'blocked_app_usage_minutes' => 15,
        'hardcore' => false, 'team_connections' => 5, 'full_research' => false,
    ],
    'full_trial_premium' => (bool) env('MEMBERSHIP_FULL_TRIAL_PREMIUM', true),
    'announcement_version' => 'standard-premium-v1',
    'store_environment' => env('REVENUECAT_STORE_ENVIRONMENT', 'production'),
];
