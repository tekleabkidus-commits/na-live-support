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
    // Doc 10 FR-18 (S10 step 11): reads mutate nothing; the two visitor writes have their OWN records —
    // feedback lands in kb_feedback + kb.feedback.received on the bus (Q7 shape; a visitor is no staff
    // actor for the audit trail), and views land as kb.article.viewed events (FR-12's analytics record).
    'kb.widget.categories' => 'Read-only browse of the cached public tree; mutates nothing.',
    'kb.widget.search' => 'Read-only keyword search; SEC-06 — the query is deliberately stored NOWHERE, an audit row would violate that.',
    'kb.widget.article' => 'Read; the kb.article.viewed EVENT is the record (FR-12) — an audit row per customer read would be noise, and no staff actor exists.',
    'kb.widget.feedback' => 'The kb_feedback row + kb.feedback.received event ARE the durable record (EC-10 dedupe arbiter); no staff actor exists for an audit row.',
    'kb.widget.media' => 'Read-only redirect to a signed URL for public published article media; mutates nothing.',

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
    // ── Doc 09 FR-16 (S09 step 10): the Copilot's artifact doors. EVERY
    //    artifact and EVERY outcome is a row/column of ai_decisions — FR-20's
    //    decision log IS the canonical trail (surface=copilot, requester in
    //    meta, outcome enriched through T-08's one pen); a per-artifact audit
    //    row would duplicate it, the inbox.composer.reply rationale verbatim.
    //    Generation doors mint the row; insert/sent/discard enrich the
    //    agent's OWN row (invisible to any other principal — the drafts
    //    precedent). The agent's SEND stays the composer's evented door.
    'inbox.ai.copilot.draft' => 'Doc 09 FR-16/FR-20: mints the agent\'s own artifact row in ai_decisions — the decision log is the trail.',
    'inbox.ai.copilot.action' => 'Doc 09 FR-16/FR-20: mints the agent\'s own artifact row in ai_decisions — the decision log is the trail.',
    'inbox.ai.copilot.insert' => 'Doc 09 FR-16: outcome enrichment (inserted_unedited) on the agent\'s OWN artifact row — T-08\'s column, not a new mutation class.',
    'inbox.ai.copilot.sent' => 'Doc 09 FR-16: outcome enrichment (inserted_edited{diff_ref}) on the agent\'s OWN artifact row; the SEND itself is the composer\'s evented door.',
    'inbox.ai.copilot.discard' => 'Doc 09 FR-16: outcome enrichment (discarded) on the agent\'s OWN artifact row.',
    // ── Doc 09 FR-18 (S09 step 12): the Backoffice doors — every mutation is
    //    a DOMAIN door that audits itself (SEC-05 high-visibility rows named
    //    in registries/audit-coverage.php): ConfigVersions::publish ->
    //    ai.config.published, ::rollbackTo -> ai.config.rolled_back,
    //    KillSwitches::flip -> ai.killswitch.changed. Drafts and simulations
    //    mutate nothing live (a draft is inert until published; a simulation
    //    is a read); the persona preview is a cached read of a queued job.
    'ai.backoffice.persona.draft' => 'Doc 09 FR-04: an INERT draft row (status=draft) — the audited act is the publish (ai.config.published, written by ConfigVersions itself).',
    'ai.backoffice.persona.preview' => 'Doc 09 FR-18: queues a PREVIEW over fixture dialogs — nothing published, no decision row, a cached read.',
    'ai.backoffice.guardrails.draft' => 'Doc 09 FR-04: an INERT draft row — the publish audits (ai.config.published).',
    'ai.backoffice.publish' => 'Doc 09 FR-04/SEC-05: ConfigVersions::publish writes ai.config.published itself (actor + old/new) — no second row.',
    'ai.backoffice.rollback' => 'Doc 09 FR-04/SEC-05: ConfigVersions::rollbackTo writes ai.config.rolled_back itself.',
    'ai.backoffice.thresholds.simulate' => 'Doc 09 FR-11/BR-07: a READ (yesterday recomputed); the token it returns is consumed by the publish door.',
    'ai.backoffice.thresholds.flips' => 'SESSION-F1 (B8-S05 E03): the simulate door\'s drill-down — the SAME rows and RoutePolicy call recomputed for two threshold sets (SimulationEngine::flips, read-only); a POST because both value sets ride the body. Nothing mutates; publish stays the audited door.',
    'ai.backoffice.thresholds.publish' => 'Doc 09 BR-07/SEC-05: ThresholdPublisher -> ConfigVersions::publish writes ai.config.published itself.',
    'ai.backoffice.killswitches.flip' => 'Doc 09 FR-18/SEC-05: KillSwitches::flip writes ai.killswitch.changed itself (actor + old/new, flipped_at = the audit stamp).',
    // Doc 10 (S10 step 3): the editor's presence + working-copy doors — inert by design.
    'kb.editor.autosave' => 'Doc 10 FR-05: the WORKING COPY is not history (versions are minted only by publish, which audits); an autosave every 5 s is inert state, not an accountable act.',
    'kb.editor.lock.acquire' => 'Doc 10 T-07: a TTL presence key in redis-volatile (the inbox.status.heartbeat precedent); the TAKEOVER door audits both parties.',
    'kb.editor.lock.heartbeat' => 'Doc 10 T-07: a TTL refresh in redis-volatile — no durable state mutates.',
    'kb.editor.lock.release' => 'Doc 10 T-07: releasing one\'s own presence key — no durable state mutates.',
    'kb.insert_link' => 'Doc 10 FR-12 (S10 step 7b): the act is EVENTED (kb.article.inserted_by_agent through the outbox — Doc 15 counts it); an audit row per composer insert would duplicate the analytics stream for a non-privileged, non-destructive act.',
    'kb.workflow.publish' => 'Doc 10 FR-07 (S10 step 4): ONE door, TWO audit names — Transitions::publish writes kb.variant.published (approve) OR kb.variant.self_published (the collapsed review) itself, plus kb.sanitizer.overridden when BR-06 applies; a single declared key cannot name both (the ConfigVersions::publish precedent).',
    'inbox.status.heartbeat' => 'Doc 07 FR-28: a TTL presence touch in redis-volatile — no durable state mutates; loss materializes as an EVENTED away transition.',
    'customers.merge.preview' => 'Doc 08 FR-11 (S08 step 11): a POST-shaped READ — the confirm checklist computed from the SAME live MergePlan the saga executes (inherited row 6: one brain, never a second engine); nothing mutates, the body carries the pick. The EXECUTION door (customers.merge.execute) audits customer.merged.',
    // Doc 12 (S12 step 12): the CSAT capture door — a CUSTOMER act, evented not audited.
    'csat.widget.submit' => 'Doc 12 FR-25 (the kb.insert_link precedent): a customer\'s 1-tap score is EVENTED (csat.received through the outbox — Doc 15\'s source of truth); no staff principal acts, so an audit row would duplicate the analytics stream for a non-privileged act. Refusals (unknown/expired/locked/rate_limited) mutate nothing.',

    // Doc 12 §8 (S12 step 14): four POST-shaped READS on the Workflow surfaces — each computes an
    // answer from the SAME engine the live path uses and mutates nothing (the customers.merge.preview
    // precedent). The execution doors beside them (create/update/activate/publish) audit themselves.
    'workflow.rules.dry_run' => 'Doc 12 FR-05: the dry-run is ZERO side effects BY CONSTRUCTION (DryRunner holds no writer; AC-02 parity is shared code) — a POST because the definition under test rides the body.',
    'workflow.sla.example' => 'Doc 12 §8: the live example sentence is the clock library\'s arithmetic over a calendar snapshot — a read; the POST carries the draft policy\'s target and calendar.',
    'workflow.macros.preview' => 'Doc 12 FR-16/T-06: PaletteReader::preview resolves variables against a sample ticket — a read (T-06 parity: preview == send resolution); nothing is inserted or sent.',
    'workflow.forms.preview' => 'Doc 12 FR-22: FormPublisher::preview runs the publish validations over a draft schema and returns the blocks + usage — a read; publish is the audited door.',
    'widget.config' => 'Read-only: the live config version + capabilities; mutates nothing.',
    'widget.messages.index' => 'Read-only cursor page of the visitor\'s own thread; mutates nothing (the thread rows are the record).',
    'widget.messages.store' => 'Doc 13 T-03 / Doc 04 FR-10: the inbound door enqueues; the message row + message.received EVENT are the durable record (the telegram webhook precedent) — an audit row per visitor message would be a second ledger.',
    'widget.typing' => 'A TTL-bound presence signal in redis-volatile (EC-12); nothing durable is mutated.',
    'widget.receipts' => 'Doc 13 T-02: the delivery transition is DeliveryStateMachine\'s and publishes message.delivered — the event IS the record (Doc 04 FR-13).',
    'widget.context' => 'Doc 13 FR-24: the widget_visitor_contexts row (latest wins) IS the record; visitor-device facts, no staff actor.',
    'widget.beacon' => 'Doc 13 T-08: health COUNTERS (monotone increments) and sampled scrubbed stacks are the record; anonymous, high-volume, no actor.',
];
