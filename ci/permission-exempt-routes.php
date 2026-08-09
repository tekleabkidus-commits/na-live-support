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
    'login' => 'The login card (GET): renders the form that establishes a principal; no principal exists yet to hold a permission.',
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
    'auth.session.deadline' => 'Self-service heartbeat for the §8 timeout toast (owner-ruled): reports the caller\'s OWN session clocks; rolls idle exactly as any authenticated request does, never the absolute cap.',
    'auth.sessions.index' => 'Self-service: lists only the authenticated caller\'s own sessions (FR-12).',
    'auth.sessions.revoke' => 'Self-service: a session owner may always end their own session, as with logout.',
    'auth.sessions.revoke_others' => 'Self-service: bulk form of the same right; scoped to the caller by construction.',

    /*
     | Doc 02 FR-06/FR-07: 2FA on the caller's OWN account. Same self-service
     | reasoning as the sessions routes; every mutation demands a current code
     | (or, for begin, is only reachable while 2FA is off). The one 2FA route
     | that touches ANOTHER user — FR-08's admin reset — is NOT here: it
     | carries admin.2fa.reset.
     */
    /*
     | Doc 02 FR-16/FR-17: the password-lifecycle guest surface. Same circularity
     | as /login — every route here exists to (re)establish a credential for
     | someone who cannot authenticate, so no staff principal exists to hold a
     | permission. The token IS the authorisation on the redemption routes,
     | validated by ActionTokenRedeemer against SEC-02's bindings.
     */
    'auth.password.forgot.form' => 'Guest form: requesting a reset is what someone who cannot log in does; no principal exists yet.',
    'auth.password.forgot' => 'Guest request: uniform SEC-01 response; the token it may mint is the eventual authorisation.',
    'auth.password.reset.form' => 'Guest form: the presented token is the authorisation, checked by the redeemer.',
    'auth.password.reset' => 'Guest redemption: single-use token authorises exactly this action (SEC-02).',
    'auth.invitation.form' => 'Guest form: the presented invite token is the authorisation, checked by the redeemer.',
    'auth.invitation.accept' => 'Guest redemption: the invited person\'s first credential; no principal exists until it is set (FR-17).',
    'auth.invitation.resend' => 'Guest request: EC-04 self-service resend, uniform SEC-01 response.',
    'auth.password.change' => 'Self-service: changes the caller\'s OWN password after re-proving the current one (FR-14).',

    'auth.2fa.begin' => 'Self-service: starts enrolment on the caller\'s own account (FR-06).',
    'auth.2fa.confirm' => 'Self-service: activates the caller\'s own enrolment with a valid code (FR-06).',
    'auth.2fa.regenerate_codes' => 'Self-service: reissues the caller\'s own recovery codes, code-proven (FR-07).',
    'auth.2fa.disable' => 'Self-service: turns the caller\'s own 2FA off, code-proven (§4.8).',
    'auth.2fa.challenge' => 'The login\'s second half: no principal exists until the code verifies (FR-07, SS-02).',

    /*
     | Doc 02 FR-18: personal tokens, self-service. Doc 03 §4.1's catalog was
     | checked end to end per the step-9 ruling: it contains NO personal-token
     | key — apikeys.machine.manage governs machine tokens only and is mapped
     | on those routes. Every query here starts from the caller's own tokens()
     | relation, so there is no object to authorize beyond authentication —
     | the sessions-routes shape exactly.
     */
    'auth.tokens.index' => 'Self-service: lists only the caller\'s own tokens; Doc 03 §4.1 has no personal-token key (step-9 ruling).',
    'auth.tokens.store' => 'Self-service: mints a token on the caller\'s own account; scope ceiling enforced by T-05 at use, not by a management permission the catalog does not define.',
    'auth.tokens.revoke' => 'Self-service: a token owner may always revoke their own token, as with sessions and logout.',

    'auth.token.context' => 'Resolution probe on the bearer surface: reports the token\'s own resolved context; the first product routes here arrive with Docs 04/06 and will carry permission keys.',

    'foundation.staff.context' => 'Resolution probe: reports the authenticated requester\'s own context only; Doc 07 replaces the staff surface.',
    'foundation.api.context' => 'Resolution probe on the machine-key surface: no staff principal exists to hold a permission.',
    'foundation.webhook.telegram' => 'Channel-key-authenticated machine ingress (Doc 01 FR-03c; Doc 05 S23 step 4 revisited): Telegram authenticates with the URL key + secret_token header, staff permissions structurally do not apply. Admin surfaces are separate routes mapped to telegram.* keys.',
    'foundation.webhook.sms_dlr' => 'Channel-key-authenticated ingress (Doc 01 FR-03e); staff permissions do not apply. Doc 14 revisits.',

    /*
     | Doc 02 step 10: the visitor surface (FR-21/FR-22, BR-06).
     |
     | Staff permission keys CANNOT apply here as a matter of structure, not
     | convenience: the principal is a WidgetVisitor, which holds no roles and
     | no permissions, and BR-06 forbids any path from this surface into the
     | staff permission model. Authentication is the widget key (tenant
     | resolution) plus the visitor token+cookie pair (the widget guard);
     | authorization is "your own session, your own channels", enforced by
     | construction in the guard and the channel callbacks. Doc 13's real
     | endpoints inherit these exemptions' SHAPE (visitor-token scoped, per
     | its T-03), not staff keys.
     */
    'auth.widget.session' => 'Widget-key-authenticated issuance (FR-21); the caller is an anonymous visitor — no staff principal exists to hold a permission.',
    'auth.widget.context' => 'Resolution probe on the visitor surface: reports the visitor\'s own resolved context; visitor-token + cookie authenticated (widget guard).',
    'auth.widget.broadcast' => 'Reverb auth for the visitor\'s OWN channels only (FR-21); enforced by the channel callback comparing principal to channel name, not by a permission key.',
    'auth.widget.preflight' => 'CORS preflight catch-all (T-06): no credential, no principal, headers-only response.',
];
