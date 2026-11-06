<?php

return [
    // Keys live ONLY in the server .env
    'secret' => env('STRIPE_SECRET'),
    'publishable' => env('STRIPE_PUBLISHABLE'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

    // Customers pay in USD only.
    'currency' => 'usd',
    'minimum_cents' => 50, // Stripe's minimum charge

    // Mobile payment sheet: API version used for the customer's ephemeral key (saved cards).
    'ephemeral_version' => env('STRIPE_EPHEMERAL_VERSION', '2024-06-20'),

    // Web build (hosted Stripe Checkout) sends us where to come back to. Only these hosts are accepted.
    // e.g. STRIPE_RETURN_HOSTS=localhost,app.synsyma.com
    'return_hosts' => array_filter(array_map('trim', explode(',', (string) env('STRIPE_RETURN_HOSTS', 'localhost,127.0.0.1')))),
    // Fallback if the app does not send one, e.g. https://app.synsyma.com/#/bookings
    'return_url' => env('STRIPE_RETURN_URL'),
];