<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. These are the first-party frontend origins
    | (SPAs) that are allowed to authenticate against this API using the
    | session cookie. Override with SANCTUM_STATEFUL_DOMAINS when the frontend
    | is served from a different host/port.
    |
    */

    'stateful' => array_values(array_filter(explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,localhost:5173,localhost:5500,localhost:8000,'.
        '127.0.0.1,127.0.0.1:3000,127.0.0.1:5173,127.0.0.1:5500,127.0.0.1:8000,::1',
        env('APP_URL') ? ','.parse_url(env('APP_URL'), PHP_URL_HOST).
            (parse_url(env('APP_URL'), PHP_URL_PORT) ? ':'.parse_url(env('APP_URL'), PHP_URL_PORT) : '') : '',
    ))))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | The "web" guard is checked first so that first-party SPA session cookies
    | authenticate the request. Personal access tokens are used as a fallback.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware applied by EnsureFrontendRequestsAreStateful when handling
    | requests from a stateful SPA origin. These reference the framework
    | middleware classes (the previous App\Http\Middleware\* classes did not
    | exist, which broke stateful requests and CSRF cookie issuance).
    |
    | Note: Sanctum's AuthenticateSession (password-hash session invalidation)
    | is intentionally not enabled here so that a normal login session is not
    | invalidated by hash-comparison edge cases.
    |
    */

    'middleware' => [
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
