<?php
return [
    'auto_cancel_enabled' => (bool) env('MEMBERSHIP_AUTO_CANCEL_RENEWAL', false),
    'credentials_file' => env('GOOGLE_PLAY_SERVICE_ACCOUNT_FILE'),
    'package_name' => env('GOOGLE_PLAY_PACKAGE_NAME', 'com.pivotirl.mobile'),
];
