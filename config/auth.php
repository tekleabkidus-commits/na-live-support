<?php

declare(strict_types=1);

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | The default guard is `staff` — the browser-session staff surface. Named
    | for what it authenticates, not for the transport, per Doc 02 T-02.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'staff'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards (Doc 02 T-02, BR-06)
    |--------------------------------------------------------------------------
    |
    | Three strictly partitioned guards, and the partition is STRUCTURAL:
    |
    |   staff   session-backed browser guard for staff users. Fortify drives its
    |           flows; routes/auth.php owns its routes.
    |   api     Sanctum bearer tokens (personal + machine), stateless. Scope
    |           enforcement is the T-05 intersection, but the guard boundary
    |           itself never depends on scopes.
    |   widget  registered by the widget step (FR-21) with its OWN driver whose
    |           principal is a visitor record, NOT App\Models\User — so a widget
    |           token can never satisfy `auth:staff`/`auth:api` regardless of any
    |           scope misconfiguration (BR-06). It is absent here until that
    |           driver exists: a guard entry pointing at an unregistered driver
    |           would be dead config that LOOKS like a partition.
    |
    */

    'guards' => [
        'staff' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'api' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        // NOT 'eloquent' (Doc 02 FR-01, Doc 01's inherited constraint #2).
        //
        // The stock Eloquent provider looks a user up by email through the
        // model's tenant global scope — and at login no tenant is bound, because
        // finding the account is HOW the tenant is discovered. `staff` resolves
        // through the SECURITY DEFINER door instead and performs the timing
        // equalisation AC-01 requires. See StaffUserProvider's docblock; the
        // driver is registered in FortifyServiceProvider.
        'users' => [
            'driver' => 'staff',
            'model' => env('AUTH_MODEL', User::class),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
