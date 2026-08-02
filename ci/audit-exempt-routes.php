<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Mutating routes legitimately outside audit coverage — each with a reason.
|------------------------------------------------------------------------------
|
| Key: route URI (as shown by `route:list`) or route name.
| Value: the reason this mutation carries no audit action.
|
| This list should stay very short. "We forgot" is not a reason.
|
*/

return [
    /*
     | Doc 01's three inbound ingress probes (routes/api.php).
     |
     | They are POST because that is the honest verb for a channel webhook, and the
     | audit-coverage gate reasonably treats POST as mutating. These three mutate
     | NOTHING: each resolves a tenant, applies the FR-05 state matrix, and returns
     | what it resolved. There is no state change to audit, so an audit entry here
     | would be a record of nothing having happened.
     |
     | THIS EXEMPTION IS TEMPORARY BY CONSTRUCTION. Doc 05 replaces the telegram
     | route's handler, Doc 13 the widget's, Doc 14 the DLR's — and each of those
     | handlers DOES mutate (it creates a conversation, a message, a delivery
     | receipt). When they land, the handler changes but the route name does not, so
     | the exemption would silently carry over. Each owning document must therefore
     | delete its line here and register real audit coverage; noted in
     | docs/BUILD-STATUS.md as an inherited task rather than left to be noticed.
     */
    'foundation.webhook.telegram' => 'Doc 01 ingress probe: resolves and reports, mutates nothing. Doc 05 must remove this line when it adds a real handler.',
    'foundation.webhook.sms_dlr' => 'Doc 01 ingress probe: resolves and reports, mutates nothing. Doc 14 must remove this line when it adds a real handler.',

    /*
     | Doc 02 step 10: the widget probe's promised replacement (FR-21/FR-22).
     |
     | The session issuer mutates REDIS ONLY — an anonymous visitor credential
     | with no tenant DB write. Doc 02 §4.8 defines the closed auth event set
     | and deliberately contains no visitor-session event: visitors are
     | anonymous (N-06) and high-volume, and an audit row per widget page-load
     | would be a firehose of nothing. Refusals and theft-shaped mismatches DO
     | leave evidence — the widget.origin_blocked / widget.visitor_reissued
     | security metrics, which Doc 13's Health tab and Doc 18's monitoring
     | consume. When Doc 04/13 give a visitor's FIRST MESSAGE a durable effect
     | (conversation, shell customer), those mutations audit under their own
     | documents' actions.
     */
    'auth.widget.session' => 'FR-21 issuance mutates Redis only; §4.8\'s closed event set has no visitor event (step-10 ruling). Refusals emit the widget.origin_blocked security metric instead.',
    'auth.widget.broadcast' => 'Reverb channel authorization: grants a socket subscription, mutates nothing durable; the visitor channel callback is the enforcement point (FR-21).',
    'auth.widget.preflight' => 'CORS preflight catch-all: answers OPTIONS with headers only; no credential is even readable at preflight time (T-06).',

    /*
     | Doc 02's two uniform-response request routes (SEC-01/AC-01). Both answer
     | an identical 200 whether or not any account matched, so a 2xx proves
     | nothing about mutation and the audit MIDDLEWARE (which requires a 2xx to
     | have written its action) cannot apply. The mutations themselves are NOT
     | unaudited: whenever a token is actually minted, PasswordLifecycleRecorder
     | writes auth.password.reset_requested / auth.invite.resent in the matched
     | account's own tenant — PasswordResetFlowTest and InvitationTest assert
     | those entries exist.
     */
    'auth.password.forgot' => 'Uniform SEC-01 response: 200 regardless of matches; per-candidate audit (auth.password.reset_requested) is written by the recorder whenever a token is minted.',
    'auth.invitation.resend' => 'Uniform SEC-01 response: 200 regardless of matches; per-candidate audit (auth.invite.resent) is written by the recorder whenever a token is minted.',
];
