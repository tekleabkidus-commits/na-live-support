<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

/*
|------------------------------------------------------------------------------
| Fortify (Doc 02 T-01)
|------------------------------------------------------------------------------
|
| Routes are NEVER auto-registered (FortifyServiceProvider::register calls
| ignoreRoutes; see its docblock and BUILD-STATUS inherited task 1). The
| `paths`/`redirects` blocks are therefore absent — routes/auth.php is the
| single place the auth surface is declared.
|
| FEATURES DELIBERATELY OFF, with the spec line that forbids them:
|   registration        FR-17: users are admin-created and arrive invited; no
|                       self-registration surface exists in v1.
|   emailVerification   No Doc 02 requirement asks for it; staff accounts are
|                       created by admins from known addresses.
|   passkeys            Doc 02 §19 lists WebAuthn as FUTURE expansion. The guard
|                       design stays method-pluggable; the feature does not ship.
|
| Two-factor `window` note: 1 means ±1 TOTP time-step (FR-07 / AC-03 / EC-01).
| The AC-03 suite proves ±1 accepted and ±2 rejected against this exact value.
|
*/

return [

    'guard' => 'staff',

    'passwords' => 'users',

    'username' => 'email',

    'email' => 'email',

    'lowercase_usernames' => true,

    'views' => true,

    'home' => '/',

    'features' => [
        Features::resetPasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
            'window' => 1,
        ]),
    ],

];
