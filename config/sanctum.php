<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains — deliberately NONE (Doc 02 T-02, T-06)
    |--------------------------------------------------------------------------
    |
    | Sanctum's "SPA mode" lets listed first-party domains authenticate API
    | calls with the session cookie. This platform's partition is the opposite
    | of that convenience: the agent app is same-origin Inertia on the STAFF
    | guard, and the bearer surface is stateless by construction. An empty list
    | means Sanctum never treats any request as stateful.
    |
    */

    'stateful' => [],

    /*
    |--------------------------------------------------------------------------
    | Fallback Session Guards — deliberately NONE (T-02's partition)
    |--------------------------------------------------------------------------
    |
    | The package default is ['web']: before checking the bearer token, the
    | Sanctum guard asks the session guard "is someone already signed in?" and
    | authenticates THEM if so. Here that would let a staff session cookie
    | satisfy `auth:api`, collapsing the two surfaces T-02 strictly partitions
    | (and, since the bearer surface is CSRF-exempt per T-06, it would do so
    | without CSRF protection). Empty means: a bearer token or nothing.
    | tests/Feature/Auth/ApiTokenLifecycleTest.php proves both directions.
    |
    */

    'guard' => [],

    /*
    |--------------------------------------------------------------------------
    | Expiration
    |--------------------------------------------------------------------------
    |
    | Null: Sanctum's global created_at-based expiry is a blunter tool than
    | FR-18's per-token `expires_at` (30/90/365/none per policy), which both
    | the resolve_api_token door and the guard enforce per token.
    |
    */

    'expiration' => null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];
