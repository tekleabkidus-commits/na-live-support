<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Routes legitimately outside the permission system — each with a reason.
|------------------------------------------------------------------------------
|
| Key: route URI (as shown by `route:list`) or route name.
| Value: the reason it needs no permission key.
|
| Public ≠ unprotected: webhook/widget routes still authenticate via their
| channel keys (Doc 01 FR-03); they are exempt from STAFF permissions only.
|
*/

return [
    '/' => 'Session 0 placeholder page; replaced by Doc 07 agent app (login-gated then)',

    /*
     | Doc 01's ingress probes. The three inbound ones authenticate via their
     | channel keys (telegram bot key, widget key, SMS gateway key — Doc 01
     | FR-03c/d/e), not via a staff principal, so no staff permission key can
     | apply. The two context probes report only the requester's OWN resolved
     | context — there is no object to authorize beyond what authentication
     | already established. Docs 05/13/14 replace the inbound handlers and must
     | revisit these lines with their real surfaces, exactly as they must the
     | audit exemptions.
     */
    /*
     | Doc 02's authentication surface. Requiring a permission to log in is
     | circular: authentication is what ESTABLISHES the principal whose
     | permissions could be checked. Logout is the same — a session's owner may
     | always end it, and a permission that could be revoked mid-session would
     | leave someone unable to sign out.
     */
    'auth.login' => 'Authentication establishes the principal; there is no principal yet to hold a permission.',
    'auth.logout' => 'A session owner may always end their own session; no permission gates it.',

    /*
     | Doc 02 FR-12's Security page: self-service over the caller's OWN sessions
     | only — no parameter can name another user, so there is no object to
     | authorize beyond authentication. Doc 03's catalog is closed (BR-03) and
     | deliberately contains no profile.* key; inventing one here would violate
     | the catalog's authority the same way a free-text permission would.
     */
    'auth.security.page' => 'Self-service Security page shell; the caller sees only their own sessions.',
    'auth.sessions.index' => 'Self-service: lists only the authenticated caller\'s own sessions (FR-12).',
    'auth.sessions.revoke' => 'Self-service: a session owner may always end their own session, as with logout.',
    'auth.sessions.revoke_others' => 'Self-service: bulk form of the same right; scoped to the caller by construction.',

    'foundation.staff.context' => 'Resolution probe: reports the authenticated requester\'s own context only; Doc 07 replaces the staff surface.',
    'foundation.api.context' => 'Resolution probe on the machine-key surface: no staff principal exists to hold a permission.',
    'foundation.webhook.telegram' => 'Channel-key-authenticated ingress (Doc 01 FR-03c); staff permissions do not apply. Doc 05 revisits.',
    'foundation.widget.session' => 'Channel-key-authenticated ingress (Doc 01 FR-03d); staff permissions do not apply. Doc 13 revisits.',
    'foundation.webhook.sms_dlr' => 'Channel-key-authenticated ingress (Doc 01 FR-03e); staff permissions do not apply. Doc 14 revisits.',
];
