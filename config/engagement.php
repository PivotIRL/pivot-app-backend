<?php

return [
    'inactive_days' => (int) env('ENGAGEMENT_INACTIVE_DAYS', 3),
    'daily_hour' => (int) env('ENGAGEMENT_DAILY_HOUR', 14),
];
