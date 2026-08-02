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
        // Owner ruling R-2: recorded in events/audit as its own category, but the
        // PERSON always sees the generic text — a distinct message here would
        // confirm the address exists in more than one workspace (AC-01).
        'ambiguous_tenant' => 'Those details don\'t match an account. Check them and try again.',
        '2fa_failed' => 'That code didn\'t work. Check your authenticator and try again.',
    ],

    'throttle' => [
        // SEC-05: shown when the per-IP limiter refuses before any hashing.
        'too_many_requests' => 'Too many attempts from this connection. Wait a moment and try again.',
    ],

    // FR-06/FR-07: 2FA enrolment, challenge and recovery (§8's wizard copy
    // slots; the full designed wizard arrives with the views step).
    '2fa' => [
        // Challenge-side errors. `voided` is FR-07's fifth failure — back to
        // the password; deliberately does not say how close to lockout the
        // account now is.
        'no_pending' => 'Sign in with your password first.',
        'one_proof_required' => 'Enter either your authenticator code or one recovery code.',
        'voided' => 'Too many incorrect codes. Sign in again from the start.',

        // EC-10's designed loser error — distinct from a wrong code, because
        // the fix is different: rescan, not retype.
        'enrolment_superseded' => 'A newer set-up was started in another tab. Scan the newest QR code and try again.',
        'already_enabled' => 'Two-factor authentication is already on. Turn it off first to set it up again.',

        // FR-08 admin reset.
        'user_not_found' => 'That user does not exist.',
        'confirm_name_mismatch' => 'The name you typed does not match this user. Nothing was changed.',
        'not_enabled_for_user' => 'This user does not have two-factor authentication turned on.',

        // Security-page section (§8/§9).
        'title' => 'Two-factor authentication',
        'status_on' => 'On',
        'status_off' => 'Off',
        'enable' => 'Set up two-factor authentication',
        'scan_instructions' => 'Scan this QR code with your authenticator app, or enter the secret manually.',
        'manual_secret' => 'Manual secret',
        'code_label' => 'Enter the 6-digit code from your app',
        'confirm_action' => 'Activate',
        'codes_warning' => 'Save these recovery codes now. They are shown only once, and each works only once.',
        'codes_saved' => 'I have saved these codes',
        'download_codes' => 'Download codes',
        'regenerate_codes' => 'Generate new recovery codes',
        'disable' => 'Turn off two-factor authentication',
        'disable_code_label' => 'Enter a current code to confirm',
        'low_codes_banner' => 'You have :count recovery codes left. Generate a new set soon.',
    ],

    // FR-12's Security page (§8/§9). `location_unavailable` is FS-04's designed
    // degradation copy — a null city from GeoIP renders as this, never as an
    // error. `only_here` is §9's empty state, verbatim.
    'devices' => [
        'title' => 'Devices',
        'subtitle' => 'Everywhere you are signed in right now. Revoke anything you don\'t recognise.',
        'column_device' => 'Device',
        'column_first_seen' => 'First seen',
        'column_last_active' => 'Last active',
        'column_ip' => 'IP address',
        'column_location' => 'Location',
        'current_badge' => 'This device',
        'location_unavailable' => 'Location unavailable',
        'revoke' => 'Revoke',
        'revoke_confirm' => 'Confirm revoke',
        'revoke_others' => 'Revoke all other sessions',
        'revoke_others_title' => 'Revoke all other sessions?',
        'revoke_others_body' => 'Every session except this one will be signed out immediately, including open tabs on other devices.',
        'cancel' => 'Cancel',
        'only_here' => 'You\'re only signed in here.',
        'load_failed' => 'Your sessions could not be loaded. Refresh to try again.',
        'cannot_revoke_current' => 'This is your current session. Use sign out to end it.',
        'session_not_found' => 'That session no longer exists.',
    ],

];
