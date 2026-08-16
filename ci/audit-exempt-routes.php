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
    'foundation.webhook.telegram' => 'Doc 05 real webhook edge (S23 step 4): ACK-only by design — FR-02/NFR-02 forbid inline processing, so the route itself mutates nothing; every mutation happens in the queued pipeline behind it, each carrying its own audit entry (quarantine, revisions, connection lifecycle). The Doc 01 probe justification this line replaces is deleted per the S15 ruling.',
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

    /*
     | Doc 07 (S07 step 4): personal-configuration mutations, deliberately
     | outside audit coverage. The plan's audit enumeration (protocol step 2)
     | names the ADMIN-RELEVANT inbox mutations — view share/unshare (covered,
     | audit: middleware), selection execution (step 7), supervisor
     | force-status (step 12), tag rename/merge (step 14). A user's OWN saved
     | views (create/edit/tombstone/duplicate of their private definitions)
     | and their OWN UI preferences change nothing another person sees and
     | carry no customer data — auditing them would be keystroke surveillance,
     | not accountability (the Doc 04 drafts precedent). The moment a
     | definition becomes VISIBLE to others (sharing), it IS audited.
     */
    'inbox.views.store' => 'Doc 07 FR-06: a private view definition — owner-only until shared; sharing is the audited act (inbox.view_shared).',
    'inbox.views.update' => 'Doc 07 FR-06: owner-only edit of a private definition; consumers of a shared view cannot reach this door (read_only_view).',
    'inbox.views.destroy' => 'Doc 07 FR-06: tombstone of the caller\'s own definition (deleted_at; DELETE is grant-revoked).',
    'inbox.views.duplicate' => 'Doc 07 AC-02: creates the caller\'s own private copy; no shared state changes.',
    'inbox.prefs.update' => 'Doc 07 FR-08/BR-05: the user\'s own UI preferences (view order, density, sounds) — per-user choices by document design, no admin relevance.',
    'inbox.selections.store' => 'Doc 07 T-05: MINTING a selection token is the act of selecting rows — ephemeral, single-use, user-bound, mutating nothing anyone else sees; the EXECUTION is the wide mutation and carries the audit (inbox.selection_executed).',
    'inbox.read' => 'Doc 07 SCHEMA AMENDMENT 1: the viewer\'s OWN read marker — per-user unread state, invisible to everyone else (BR-06: no metric reads it); the prefs-update rationale verbatim.',
    'inbox.composer.reply' => 'Doc 04/07: an agent reply\'s trail is the EVENT stream (message.queued → the FR-13 delivery machine, every hop evented); a per-message audit row would duplicate the canonical trail. The FR-21 transition half audits through its own door.',
    'inbox.composer.draft' => 'Doc 04 FR-20/T-07: the author\'s OWN draft — private by design (never visible to another principal), tombstoned on send; the prefs-update rationale.',
    'inbox.notifications.read_all' => 'Doc 07 FR-26: the viewer\'s OWN bell read-state (read_at is the only app-updatable column — R-19b); the read-marker rationale verbatim.',
    'inbox.status.set' => 'Doc 07 FR-28: the agent\'s OWN availability — every transition is EVENTED (agent.status_changed, catalogued; Doc 11/15 consume); FORCED transitions audit through their own door.',
    'inbox.status.heartbeat' => 'Doc 07 FR-28: a TTL presence touch in redis-volatile — no durable state mutates; loss materializes as an EVENTED away transition.',
    'customers.merge.preview' => 'Doc 08 FR-11 (S08 step 11): a POST-shaped READ — the confirm checklist computed from the SAME live MergePlan the saga executes (inherited row 6: one brain, never a second engine); nothing mutates, the body carries the pick. The EXECUTION door (customers.merge.execute) audits customer.merged.',
];
