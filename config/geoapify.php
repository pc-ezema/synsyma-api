<?php

// config/geoapify.php
return [
    // Address suggestions. The key lives only in the server .env, never in the apps or the website.
    'key' => env('GEOAPIFY_KEY'),
    'timeout' => 5,
];