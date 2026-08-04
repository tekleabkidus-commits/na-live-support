# SESSION — DOC 04: CONVERSATIONS & MESSAGING CORE

You are the principal engineer and platform architect for **Na Live Support**, with 25+ years
building messaging engines and delivery pipelines for systems of this class (Zendesk,
Intercom, Stripe-grade). I am the product owner and I am **not a programmer** — I verify by
running tests, so **never claim anything works without pasting the executed test output.**
Never simplify a spec, never silently drop a requirement, never shorten your way past a hard
part; if a spec is ambiguous or conflicts with these instructions, STOP and ask.

**Before ANY work — including reading — prove this is the ONLY claude process running**
(`ps`/process list pasted). SESSION-03's launch ran in concurrent sessions in this repo:
they overwrote each other and cost half a day of recovery (BUILD-STATUS incident note).
One session, verified solo, or nothing happens.

Doc 04 is the heart of the product: every later document (05 Telegram, 06 Ticketing,
07 Inbox, 08 Customers, 09 AI, 13 Widget) is built ON this engine. The adapter contract,
the delivery state machine, and the media-safety pipeline are frozen public contracts the
moment you ship them — treat the contract and its conformance suite as the deliverable,
and the fake adapter as the proof that channels are additive work.

**Read, in full, before writing any code:**
1. `CLAUDE.md` — the constitution; §2's seven registries (ADR 0005), §7's build order
   (04 → 06 → 05), §9's report shape.
2. `docs/Doc04-Conversations-Messaging-Core.md` — this session's law, every section end to
   end including §4.3's adapter contract, §4.4/§4.5's pipelines, and the full EC/FS lists.
   No id may be covered from a summary.
3. `docs/BUILD-STATUS.md` — the Doc 03 §S19 close-out, the standing-PARTIAL buckets (you
   must not re-claim or drop any), the DESIGN-DEVIATIONS section, and the SESSION-03
   concurrent-session incident note (why the solo-process proof above exists).
4. `docs/ANSWERS-FROM-DOC-AUTHOR.md` — the interpretation record, canonical: Q1–Q9 (Q9
   registry-normative binds you directly), the Doc 03 rulings R-9…R-14, and A-3. Where this
   file and a numbered document requirement ever disagree, the document wins and you STOP.
5. `docs/DESIGN-FIDELITY-MANDATE.md` — binds any user-visible surface you build (see the
   DESIGN FIDELITY block below for this session's verified scoping).
6. The substrate you consume — read the docblocks before touching anything:
   - Doc 01's transactional outbox / EventPublisher and the monthly-partition conventions
     (your messages table lives on them; repository-only writes, Doc 01 R-05 pattern).
   - The frozen Doc 01 BR-05 contract left FOR you: `InboundAccrualPolicy` +
     `ColdSummaryStore` (suspended-tenant inbound accrual ≤30 days then cold summary).
     Your inbound pipeline CONSUMES this contract; reinventing it is a violation.
   - The S0 queue families (`telegram-in`, `telegram-out`, `webchat-in`, `webchat-out`,
     `media`) and Horizon supervisors — T-02 names these; verify what exists and extend,
     don't duplicate. The S0 mock services (mock-telegram etc.) are HTTP stand-ins for
     channel APIs; your FAKE adapter is a code-level test fixture. They are different
     things — keep them distinct and never let the conformance suite depend on a mock
     service being up.
   - Doc 02's webhook ingress probes (FR-23/24/25 auth mechanics) — your webhook
     controllers replace or consume these probes; their auth guarantees carry over intact.
   - Doc 03's seams: `registries/permissions.php` (the `conversations.*` keys are already
     seeded — key names are law, NFR-03), `RequirePermission` (now ENFORCING — every route
     you add is checked the moment it exists), `scopeSet()`/ScopeSets + the
     MembershipSource seam, the T-04 per-page capability manifest, and the Reverb
     channel-auth path (your T-06 lands there).
   - The S0 `no-channel-conditionals` grep gate definition — dormant until now; your
     session makes it load-bearing. Know exactly what it greps before you write core code.
7. `docs/EVENT-CATALOG.md` — closed-payload rule (missing OR extra key rejected) applies to
   every event FR-26 adds; `draft.saved` is local-only and never enters the bus.
8. ADRs: `0003-database-role-separation.md` (your new tenant tables live under its grant
   model — REVOKE DELETE, and FR-03's "conversations are never deleted" becomes grant
   physics, not policy), `0004-traceability-gate-per-document-flip.md` (Doc 04's ACs become
   blocking the moment its BUILD-STATUS row leaves NOT STARTED), and
   `0007` (queue-family/supervisor precedent your media and lane workers follow).

**Stack/context notes (not conflicts; do not stall on them):**
- The repo is Laravel 13 by owner ruling; spec stack contexts read as minimums.
- Prose counts anywhere are descriptive; **code registries are normative** (ruling Q9).
- **Conversation ≠ ticket**, stated up front: a conversation is a permanent
  per-customer-per-channel-endpoint thread (FR-01, never deleted, FR-03); a ticket
  (Doc 06) is a bounded work item referencing message ranges within it. You enforce the
  one-open-ticket invariant (FR-04/BR-05) and expose `open_ticket_id`; you build NO ticket
  semantics. If you find yourself writing ticket lifecycle logic, stop — it's scope creep.
- **FAKE adapter only.** CLAUDE.md §7's order is 04 → 06 → 05 precisely so Telegram quirks
  land in Doc 05's adapter, not in your core. The fake reference adapter is driven to 100%
  conformance-suite coverage (Doc 04 §21's own recommendation); any plan row touching
  Telegram/Business or widget specifics is scope creep.
- New dependencies (ClamAV client, image/thumbnail library, PDF renderer for FR-24) need an
  ADR + my approval before install (CLAUDE.md §3). New STACK SERVICES (ClamAV sidecar, a
  separate cookie-less media origin, queue workers) are infra work: they fall under the
  efficiency ruling's CI/infra exception (push + verify CI when you change them), and you
  name them in the plan so I see the stack growing before it grows.
- Doc 05 fields that exist now as schema only: `business_connection_id` (nullable, FR-02)
  ships as a column; nothing populates it this session.

## SCOPE

Implement **all of Doc 04** — enumerate every FR / NFR / US / BR / T / SEC / EC / FS / SS /
AC / R id from the document itself and cover each one. State the id count YOU found; your
plan is audited against an independent count.

**Out of scope (do not touch):** Telegram/Business specifics — real adapter, webhook
registration, delivered-receipt mapping, reply-window composer behavior (Doc 05); widget
specifics (Doc 13); ticket semantics, SLA, routing rules (Docs 06/12 — you enforce the
open-ticket invariant and emit the routing fan-out seam, nothing more); identity MERGE
rules (Doc 08 — you call the identity resolver as a SEAM: match-or-create shell, its
docblock naming Doc 08 as owner of merge/shell semantics); AI content decisions,
transcription/OCR providers (Doc 09 — you emit hook events and store refs, nothing more);
inbox presentation (Doc 07 — see the DESIGN FIDELITY block). Where Doc 04 defines a
contract a later document consumes, build the contract and stub the consumer with a
documented seam.

> "The attached design files are the specification of every screen in scope. Implement them
> at 100% fidelity per DESIGN-FIDELITY-MANDATE.md — every frame, state, viewport, theme, and
> language, tokens from the design system only, deviations only in the mandate's three
> classes and every one filed. Nothing skipped, nothing approximated, nothing redesigned."

(Design files are delivered ON-DISK at `design/frozen/`, not as chat attachments — read
"attached" above as those on-disk files. Whether any drawn surface is in this session's
scope at all is settled in the DESIGN FIDELITY block; the sentence binds any drawn surface
you do build.)

**Pre-agreed closure buckets (build to these; do not invent new deferrals beside them —
verify each against Doc 04's text in your plan):**
- **Docs 05/13-blocked:** AC-01 says "every shipped adapter" — this session ships the FAKE
  adapter to full conformance; real-adapter re-proof rows attach when Docs 05/13 land.
  `delivered` receipts exist only where a channel provides them (FR-13 — Doc 05 defines
  the Telegram mapping); FR-14/§8's `window_expired` composer guidance is a reason code +
  i18n string here, a composer state in Doc 05; EC-02's customer-facing
  "adapter-templated notice" is proven against the fake adapter's template.
- **Doc 06-blocked:** the ticket-RANGE half of FR-24's transcript export (conversation-range
  export is YOURS and DONE-able); the routing fan-out CONSUMER (FR-10 step 6 — you emit,
  Doc 06 subscribes); quarantine spam DISPOSITION (FR-03 — you store + flag, Doc 06
  consumes); EC-04's "ticket closed by rule" cancel TRIGGER (the queued→canceled
  transition and its test are yours; the rule source is Doc 06/12's); whatever slice of
  inherited Doc 03 EC-02 your verification shows needs ticket actions.
- **Doc 09-blocked:** the AI turn-decision consumer — YOURS is the fan-out event and the
  EC-01 `batch_tail=true` hint set by a 2 s debounce, proven by test; Doc 09 consumes it.
  Transcription/OCR: YOURS is `media.needs_transcript`/`media.needs_ocr` after
  `media.stored`, the `transcript_ref`/`ocr_ref` slots, and EC-07's failure SHAPE (retry ×2
  then quiet, toggle state "unavailable"); the transcriber itself is Doc 09's.
- **Doc 07-blocked:** inbox/thread RENDERING of the §8/§9 mandated behaviors (glyphs,
  tombstone/edit affordances, lightbox, voice player, typing indicator UI, draft pencil on
  lists, EC-08's anchor-fetch UI, offline composer banner). The B12-A01 behaviour rows
  whose surfaces are deferred bind as executed tests in Doc 07 — your traceability rows for
  the deferred halves say so instead of claiming visual coverage.
- **Doc 08-blocked:** merge rules and shell-customer semantics beyond match-or-create.
- **Doc 18-blocked:** the retention/legal-hold RUNBOOK job spec (EC-09 — the mechanism,
  hold flag, and pre-drop copy-out are YOURS and tested); FS-03's autoscale signal wiring;
  FS-02's storage-outage alert routing; R-03's storage-class lifecycle.
- **Doc 19 L1/L2:** formal perf-budget closure (§12's numbers under load profile L2,
  NFR-01/02/03's p-numbers, AC-07's formal ≤1.5 s under L2, SS-01 at the full match-day
  profile). Structure for them, measure indicatively in-suite, record PARTIAL bucketed to
  Doc 19 — the established pattern. AC-03's 10k-message ordering soak and SS-04's
  node-kill replay drill are correctness proofs, not perf numbers — they run NOW.
- **SS-02 (WhatsApp prototype):** the conformance suite is YOURS. The zero-core-edits
  architecture proof is acceptable now via a SECOND minimal fake adapter driven through
  the suite with zero core changes — state in your plan whether you claim SS-02 that way
  or record it PARTIAL bucketed to the first real adapter (Doc 05). Do not build anything
  WhatsApp-shaped.

## INHERITED (each is a mandatory plan-table row, not a footnote)
1. **Doc 03 FR-06 — export half.** Your FR-24 transcript export enforcing
   `conversations.transcript.export` is the FIRST real consumer of Doc 03's export
   re-check mechanism. Doc 03's FR-06 row upgrades on your executed proof — cite it in
   your close-out.
2. **Doc 03 FR-06 — Reverb half.** Your T-06 (Doc 03 scope enforced on `conversation.{id}`
   subscribe) closes the other half. Same upgrade, same citation.
3. **Doc 03 EC-05 UI state on conversation objects** — "object moved out of scope → next
   action re-checks" now has real scoped objects. Prove the re-check + the moved-out-of-
   scope state on a conversation action. For Doc 03 EC-02 (mid-action expiry naming the
   granter): VERIFY against Doc 04's surfaces what can land here; what needs ticket
   actions carries to Doc 06 explicitly in your traceability, not silently.
4. **Doc 01 AC-03** — kill each worker class mid-burst → no lost messages/events after
   drain. NOT DONE since Doc 01 for lack of real traffic; your pipelines ARE the traffic
   and SS-04's 1k-message replay drill is the natural vehicle. Claim it, or re-bucket it
   explicitly to Doc 06 with a reason; silence is a violation.
5. **Doc 01 BR-05 frozen contract** — your inbound pipeline consumes
   `InboundAccrualPolicy` + `ColdSummaryStore` for suspended-tenant inbound (accepted,
   ACKed, nothing lost; cold summary past threshold). Test it through the pipeline, do not
   reimplement it.
6. **Avatar-media seam (Doc 02 S17 ruling)** — the deferred avatar handling lands in YOUR
   media pipeline (scan → store → serve from the media origin). Claim the seam; Doc 08
   wires profile usage.
7. **Route→permission mapping in the same commit** — the mapping gate is BLOCKING and
   `RequirePermission` enforces. Doc 04's keys are already seeded
   (`conversations.view`, `conversations.history.full`, `conversations.transcript.export`,
   `tickets.reply`, `tickets.note`, `customers.notes.view`, `telegram.message.delete`).
   Map every route you add to catalog keys; NEVER invent a key (NFR-03, ruling R-12's
   precedent).
8. **Standing honest PARTIALs are not re-claimed:** Doc 03 SS-01 (composite journey) and
   SS-02 (auditor drill) stay as they are; Doc 02 AC-05's literal fixture stays PARTIAL
   until Doc 06's reply route (ruling Q5 + R-12). Your session touches none of them.

## THE SUBTLE PARTS (spell each out in your plan; every one traces to Doc 04's text, a filed ruling, or the handoff)
- **The adapter contract is a NORMATIVE interface** (FR-08), consumed only through itself
  (FR-09): `handleWebhook → NormalizedInbound`, `send → SendResult`, `capabilities()`,
  `health()`. Adapters never touch core tables. Capability flags drive core behavior —
  composer limits, button fallbacks, typing, edit/delete support, reply_window — with NO
  channel if-statements in core (the grep gate goes load-bearing; run it early and often).
  T-09/AC-01: the conformance suite runs the FULL matrix (all capability combinations,
  error classifications, dedupe, ordering) against the fake fixture and becomes the CI
  gate every later adapter must pass.
- **Notes are structurally undeliverable** (SEC-07, BR-01, AC-04, FR-17): type-level — a
  `DeliverablePayload` that note kinds CANNOT produce. The test asserts no send path
  EXISTS (compile/static level) AND a runtime guard refuses; per-release per BR-01. Notes
  are gated `tickets.note`/`customers.notes.view`, support @mentions (mention event out;
  the notification consumer is Doc 07/18's), and never enter any adapter serialization.
- **Per-conversation FIFO lanes** (FR-15, T-02, AC-03): Redis lists `lane:{conversation}`,
  lane workers; a failed(retryable) HEAD blocks the lane (out-of-order prevention), 10-min
  cap then lane-skip + warning event; §12's fairness test (1 lane at 10 msg/s + 100 idle
  lanes, no starvation, round-robin); AC-03's soak = zero order violations across 10k.
  R-02's per-lane concurrency relaxation is RESERVED, not built.
- **Ordering is receipt-time truth** (FR-07, BR-04, EC-01, EC-10): display order =
  (created_at, uuidv7); channel timestamps are METADATA preserved, never sort keys; the
  same-second-burst fixture (10 msgs) proves stability; EC-10's earlier-channel-timestamp
  case renders receipt order with the "sent earlier" metadata line.
- **The delivery state machine is a frozen public contract** (FR-13, AC-02):
  queued → sending → sent → delivered; failed(retryable) → backoff ×5 (1s,5s,30s,2m,10m)
  → failed(final); canceled PRE-SEND ONLY; failed(final) → manual retry gated
  `tickets.reply` → re-enters queued. AC-02 proves EVERY transition AND every illegal
  transition rejected. **`sent` never rolls back** (EC-05): late channel invalidation
  becomes a `post_send_failed` system annotation — sent stays truthful to what we knew
  when we knew it. FR-14's non-retryable classes (blocked-by-user, window-expired,
  invalid content) → failed(final) immediately with reason codes the UI maps
  (`messaging.fail.*` i18n keys, en+am). EC-04: blocked between compose and send →
  canceled with reason.
- **Inbound discipline** (FR-10): webhook controller authenticates via the adapter (Doc 02
  FR-23/24 mechanics carried over), enqueues raw, **ACKs ≤ 250 ms and NEVER processes
  inline**; normalize + dedupe (Redis SETNX, 48 h; EC-03 duplicates dropped silently with
  metric); identity via the Doc 08 resolver SEAM; persist message + conversation
  auto-create (FR-01 uniqueness per {tenant, channel endpoint, external chat id}) + outbox
  `message.received` in ONE transaction; media jobs per item; fan-out as SUBSCRIBERS
  (AI | routing | realtime | counters), never pipeline steps. **FR-11:** blocked-state
  inbound stored `quarantined=true`, NO fan-out except audit, and unblocking does NOT
  retro-fan-out — documented and tested.
- **FR-16 rate limiting:** token buckets per channel endpoint (adapter capability +
  settings); over-limit messages WAIT in queued, never dropped.
- **Media is a security surface**, the whole chain: streamed download with in-stream size
  enforcement (T-04; EC-02 aborts mid-stream → `media_rejected` system note to staff +
  adapter-templated customer notice); ClamAV daemon sidecar scan — **nothing is ever
  delivered unscanned**; EC-11 ClamAV down → held in `scanning` ≤ 15 min → quarantined-
  pending with staff notice; NFR-05's ×3-then-quarantine and `media.max_mb` setting;
  SEC-03 SSRF-safe fetching via an allowlisted egress proxy (channel API hosts only);
  SEC-02 separate cookie-less media origin, private bucket, single-tenant signed URLs,
  TTL 10 min with re-issue on demand (T-04); SEC-04 infected = NO download path for ANY
  role (assert the absence, not a guard), deletion only via retention, threshold alerting;
  BR-06 EXIF strip on customer sticker/photo at store; thumbnails max 3 sizes via queued
  job; `media.stored`/`media.infected` events.
- **Transcription/OCR are hooks only** (T-05): emit `media.needs_transcript`/
  `media.needs_ocr` after `media.stored` for voice/photo kinds; Doc 09 writes back
  `transcript_ref`/`ocr_ref`; core stays provider-agnostic. EC-07's failure shape is
  yours: retry ×2 then give up quietly, transcript state "unavailable", player unaffected.
- **Typing, presence** (FR-18, FR-19, EC-12): staff-composing signals to the adapter at
  most every 4 s, capability-gated; customer typing relayed to viewing staff; typing
  auto-expires ≤ 6 s and never stacks; presence channel `conversation.{id}` powers Doc 07
  collision UX; Reverb node loss reconciles via the sync endpoint.
- **Drafts** (FR-20, T-07, EC-06, AC-08): local every 2 s + server every 10 s; server
  table unique (user_id, conversation_id); last-writer-wins with the `updated_at` guard;
  the loser gets the "draft updated on another device" toast; cleared on send; restored
  after re-login (Doc 02 §8 hook). AC-08's cross-device recovery ≤ 10 s stale is proven
  at the mechanism level now (see DESIGN FIDELITY for the harness rule).
- **Edit/delete semantics** (FR-21/22/23, BR-03, AC-06, US-02/US-06): customer-originated
  edits/deletes stored as revisions/tombstones, originals retained per retention;
  "deleted by customer" tombstone visible to staff; full history gated
  `conversations.history.full` with ACCESS AUDITED (AC-06 proves the round-trip, the
  gate, and the audit). Staff-side edit/delete only where capability + channel limits
  allow; `telegram.message.delete` gates deletes (the KEY is wired; Telegram behavior is
  Doc 05's); every edit/delete audited. AI messages are never edited post-send —
  corrections are new messages (FR-23).
- **Transcripts** (FR-24, SEC-05, SS-03, US-04): conversation-range export (PDF/text,
  media as links or embedded thumbs option) with notes EXCLUDED by default; include-notes
  is double-confirmed; watermark = exporting user + timestamp; export audited (Doc 17
  entry). Ticket-range waits for Doc 06 (bucket).
- **Realtime** (FR-25, T-06, SEC-06, AC-07): channels `conversation.{id}` and
  `user.{id}`; every payload ≤ 32 KB, media referenced by signed URL never inlined;
  schema versioned (`v:1`); **subscribe enforces Doc 03 scope** (inherited row 2);
  dev/staging schema validation asserts no foreign-tenant ids at emit.
- **Events catalog** (FR-26): `conversation.created|blocked|unblocked`,
  `message.received|queued|sent|delivered|failed|canceled|edited|deleted`,
  `media.stored|infected`, `note.added`, `transcript.exported` — closed payloads through
  the Doc 01 outbox, identifiers-only (the 13-month PII rule, Q7's posture), catalog
  regenerated, every one in audit-coverage. `draft.saved` is LOCAL ONLY — it never enters
  the bus; a test asserts its absence from the catalog/outbox.
- **The open-ticket invariant** (FR-04, BR-05): at most one open ticket per conversation,
  enforced HERE; a second open is rejected with `open_ticket_exists` — Doc 06 must
  transition, not duplicate. `open_ticket_id` nullable, exposed.
- **Partitions are operated, not just declared** (T-01, R-01, FS-04): messages on monthly
  partitions per Doc 01 conventions; ALL writes via repository classes (no ad-hoc
  Eloquent on partitions); Doc 01 EC-10's pre-creation (+3 months) + monitor cover you;
  a write to a missing partition pages — assert the pager path exists even though it
  "should never fire". New tenant tables carry ADR 0003 grants (REVOKE DELETE — FR-03's
  never-delete is grant physics) and the `set_config('app.tenant_id', …)` migration
  preamble.
- **Retention with legal hold** (T-08, EC-09, AC-09): settings `retention.messages.months`
  / `retention.media.months` / `retention.revisions.months` with platform minimums;
  partition-drop jobs honor a per-conversation legal-hold flag via PRE-DROP COPY-OUT to
  hold storage; `retention.completed` with counts; the operational runbook hands to
  Doc 18 (bucket).
- **Failure scenarios are tests, not prose**: FS-01 total channel outage → queued
  accumulates, lanes intact, health events fire, drain on recovery with zero loss and
  order preserved; FS-02 storage outage → text unaffected, media retries, "attachment
  pending" state; FS-03 media backlog isolation (text pipeline unaffected — queue
  isolation proven); FS-05 poison message → ×3 → dead-letter with FULL payload + ops
  re-drive listing (build the re-drive early, §21's advice); NFR-04 idempotent consumers
  everywhere, zero loss on any single worker/node failure.
- **§13/§14 are session obligations at the string/mechanism level now**: every
  system-message and state string under `messaging.*` i18n keys en+am; detected message
  language stored per message and exposed; alt-text slot on images (staff-editable);
  localized durations/speeds; RTL-ready structures (logical properties). Rendering-level
  proofs (live regions, announced tombstones) follow their surfaces — here if built here,
  Doc 07 otherwise (the B12-A01 disposition in your plan).

## PROTOCOL (unchanged, in full)
1. **PLAN FIRST**: a table of every Doc 04 id → file(s) → proving test, plus rows for
   every INHERITED item (1–8 above) and the DESIGN FIDELITY scoping row (below). State
   the id count you found. Sequence safety tests BEFORE the features they constrain, and
   justify any counter-intuitive ordering — at minimum: the adapter contract + conformance
   suite BEFORE the pipelines that use it; SEC-07's type-level exclusion BEFORE any send
   path exists; when the `no-channel-conditionals` grep gate flips load-bearing relative
   to your first core commit. Name any new stack services and dependencies (ClamAV,
   media origin, PDF/image libs) so I approve the stack growth with the plan. WAIT for my
   approval.
2. One commit per numbered step; registries updated as you go (permission mappings for
   every new route — keys from the seeded catalog only; settings — `media.max_mb`,
   `retention.*`, and any FR-16 bucket settings, named per the registry conventions;
   audit-coverage for every new event and export; features if any; traceability rows —
   the per-document flip makes Doc 04's ACs blocking the moment its BUILD-STATUS row
   leaves NOT STARTED, ADR 0004).
3. **Falsify every safety mechanism you build, with a positive control for every
   assertion, sabotage BEFORE counting anything proven** — and remember S19 sabotage #7:
   a sabotage that stays green condemns the TEST, not the guard; strengthen the test and
   keep the stronger one. At minimum: route a note through an adapter path → AC-04 fails
   at the type/static level; disable the scan gate or serve unscanned media → AC-05
   fails; remove the SETNX dedupe → the duplicate-webhook test fails; break the FIFO
   head-block → AC-03 reports order violations; permit a sent→failed rollback → AC-02's
   matrix fails; drop the Reverb subscribe scope check → T-06's test fails; skip the
   blocked-state quarantine → FR-11's test fails; widen the SSRF egress allowlist → the
   SEC-03 test fails; disable the in-stream size abort → EC-02's fixture fails. Paste
   fail AND restored-pass, every time. Backup before sabotage; nothing commits until
   `git status`/`git diff` proves the tree is clean of falsification damage.
4. Every user-visible string is an i18n key with `en` AND `am` entries (`messaging.*` per
   §14); `string-lint` stays green; the Doc 02 enumeration suite stays untouched by your
   new strings; new machine-authored Amharic joins the standing native-review queue —
   flag destructive-tier strings in your close-out.
5. Targeted tests while iterating; the FULL `make test`, `make gates`, `make lint` once
   per step-commit — pasted real output. Push and record the CI run ID at session close
   per the efficiency ruling (exceptions: CI/infra-file changes — your ClamAV/media-origin
   /queue-service commits qualify — and rulings that name CI as a gate).
6. Finish with the full Doc 04 self-audit table (every id, DONE / PARTIAL / NOT DONE +
   one-line reason; PARTIALs cite their pre-agreed bucket; a false DONE is a Sev-1), the
   DESIGN-DEVIATIONS section updated in BUILD-STATUS (an unfiled deviation is a fidelity
   violation; "no drawn surface built" is also stated there if true), the inherited-row
   outcomes (Doc 03 FR-06 both halves, Doc 01 AC-03's disposition, BR-05 consumption)
   cited explicitly, and the CLAUDE.md §9 report: BUILT / PROVEN / NOT DONE / BLOCKERS /
   RISKS / NEXT SESSION. Then STOP.

## DESIGN FIDELITY (scoping verified against Doc 04's own text — confirm it in your plan)
Per DESIGN-FIDELITY-MANDATE.md §5, which is in your reading list:
- **Verified scoping:** Doc 04 §3 places "inbox presentation (Doc 07)" out of scope, and
  §8 opens with "(Rendering lives in Doc 07; the core mandates these behaviors any
  renderer must honor.)" — so NO drawn screen is REQUIRED to close Doc 04's ids this
  session. Your plan carries ONE scoping row restating this verification against §8/§9 in
  your own reading; if you read those sections differently, STOP and ask before planning
  UI work.
- Browser-level proofs this session needs (AC-06's gated history access, AC-07's realtime
  visibility, AC-08's cross-device draft recovery, the EC-12 sync reconcile) run against a
  **minimal test harness, never presented or styled as product UI**. The moment anything
  you build renders as a product surface, it is a drawn surface and the mandate binds.
- **If you build ANY drawn surface** (the media lightbox, edit markers / revision history
  / tombstones, delivery glyphs), the frozen files at `design/frozen/` are its
  specification at 100% fidelity: **B12-S01-media-lightbox-v3**, **B12-S02-edit-marker-v2**
  (edit marker, revision history, tombstone), the message-bubble/delivery-glyph sources in
  the frozen B1/B2 stack, plus — always — the design system: **B1-DS-design-system-v2**
  (token source, §2 both colourways; its lightbox addendum), **B1-DS-addendum-B5-v1**
  (five-part lightbox chrome), **B1-DS-v3-addendum-B6-v1**, **B1-DS-v4-addendum-B7-v1**.
  Access wrapper: read ONLY the named files; screen/audit files — highest version is the
  frozen one; the design system is v2 base plus all three addenda together. Frame-mapping
  rows in the plan (frame → component/file → proving test) for any surface you build;
  dark values from v2 §2's dark column, never mixed by eye; fixed tokens never re-mixed.
- **B12-A01 disposition, row by row:** your plan states, for each of B12-A01's nine
  behaviour-acceptance rows, whether its surface is built HERE (the row closes as an
  executed test, cited by row id) or deferred to Doc 07 (the row is named in the Doc 07
  bucket). The rows citing Doc 04 directly — AC-03 (bidi/RTL, Doc 04 §14), AC-05
  (optimistic send, Doc 04 FR-13), AC-06 (attachment caps, Doc 04 §8/EC-02), AC-08 (draft
  survives merge, Doc 04 AC-08), AC-09 (live regions, Doc 04 §13) — get an explicit
  disposition each; none is silently dropped.
- Deviations only in the mandate's three classes, every one filed in BUILD-STATUS's
  DESIGN-DEVIATIONS AND named in your close-out. Design-vs-document conflict → STOP and
  ask. Design gap → class-2 fill with design-system components, filed. Design defect →
  class-3 flag, wait.

Then STOP.
