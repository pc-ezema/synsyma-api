<?php

return [
    'name' => 'Admin',
    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'Super Admin'),
        'email' => env('SUPER_ADMIN_EMAIL'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],
    'access_token_ttl_minutes' => (int) env('ADMIN_ACCESS_TOKEN_TTL', 60),
    'refresh_token_ttl_days' => (int) env('ADMIN_REFRESH_TOKEN_TTL_DAYS', 30),
];
