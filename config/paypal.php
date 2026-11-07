<?php

return [
    // Keys live ONLY in the server .env
    'client_id' => env('PAYPAL_CLIENT_ID'),
    'secret' => env('PAYPAL_SECRET'),
    'webhook_id' => env('PAYPAL_WEBHOOK_ID'),

    // sandbox (testing) or live
    'mode' => env('PAYPAL_MODE', 'sandbox'),

    'brand_name' => env('PAYPAL_BRAND_NAME', 'Synsyma'),
];