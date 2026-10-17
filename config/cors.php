<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Here you may configure your CORS options. You can allow various origins,
    | middleware, and headers to be used by your API as well as other options.
    |
    */

    // Include Sanctum's CSRF endpoint and the current-user endpoint so a
    // first-party SPA on another origin can call them with credentials.
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'user'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(explode(
        ',',
        env('CORS_ALLOW_ORIGINS', implode(',', [
            'http://localhost:3000',
            'http://127.0.0.1:3000',
            'http://localhost:5173',
            'http://127.0.0.1:5173',
            'http://localhost:5500',
            'http://127.0.0.1:5500',
            'http://localhost:8000',
            'http://127.0.0.1:8000',
        ]))
    ))),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];