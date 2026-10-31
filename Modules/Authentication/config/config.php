<?php

return [
    'name' => 'Authentication',
    'access_token_ttl_minutes' => (int) env('AUTH_ACCESS_TOKEN_TTL', 60),
    'refresh_token_ttl_days' => (int) env('AUTH_REFRESH_TOKEN_TTL_DAYS', 30),
    'otp' => [
        'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 10),
        'max_attempts' => 5,
        'resend_seconds' => 60,
    ],
    'terms_version' => env('TERMS_VERSION', '1.0'),
];
