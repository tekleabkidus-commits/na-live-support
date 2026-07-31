<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Authentication copy (Doc 02 §8, §14; C-08, N-03)
|------------------------------------------------------------------------------
|
| Doc 02 §8: "Every error state has a designed i18n copy slot under the auth.*
| namespace." This file is that namespace's English half; lang/am mirrors it.
|
| THE MOST IMPORTANT LINE IN THIS FILE IS `failed.generic`, AND IT IS
| DELIBERATELY VAGUE. FR-02 and AC-01 require one identical message for every
| credential failure — wrong password, no such address, an address that exists
| in two workspaces. Any attempt to be more helpful here ("we don't recognise
| that email") reintroduces the enumeration oracle the whole login path is built
| to avoid. If someone asks for friendlier copy, the answer is the reset flow,
| not a more specific error.
|
| The two `failed.*` messages that ARE specific — disabled_user and
| suspended_tenant — are only reachable AFTER a correct password (FR-05), so
| they tell an attacker nothing they did not already prove.
|
*/

return [

    'failed' => [
        // FR-02 / AC-01: the one generic credential failure.
        'generic' => 'Those details don\'t match an account. Check them and try again.',

        // FR-03: a locked account gets the generic-shaped message with no
        // remaining time — "try again later", never "try again in 12 minutes",
        // which would tell an attacker exactly when to resume.
        'locked' => 'Too many attempts. Please try again later, or reset your password.',

        // FR-05, post-verification and non-technical.
        'disabled_user' => 'This account has been turned off. Contact your administrator.',
        'suspended_tenant' => 'This workspace is temporarily unavailable. Contact your administrator.',

        'ip_blocked' => 'Those details don\'t match an account. Check them and try again.',
        'bad_credentials' => 'Those details don\'t match an account. Check them and try again.',
        '2fa_failed' => 'That code didn\'t work. Check your authenticator and try again.',
    ],

    'throttle' => [
        // SEC-05: shown when the per-IP limiter refuses before any hashing.
        'too_many_requests' => 'Too many attempts from this connection. Wait a moment and try again.',
    ],

];
