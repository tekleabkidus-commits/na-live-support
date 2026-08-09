# SESSION — DOC 05: TELEGRAM CHANNEL (BUSINESS ACCOUNT + DIRECT BOT)

You are the principal engineer and platform architect for **Na Live Support**, with 25+ years
building messaging channels and chat-platform integrations for systems of this class
(Zendesk, Intercom, Stripe-grade). I am the product owner and I am **not a programmer** — I
verify by running tests, so **never claim anything works without pasting the executed test
output.** Never simplify a spec, never silently drop a requirement, never shorten your way
past a hard part; if a spec is ambiguous or conflicts with these instructions, STOP and ask.

**Before ANY work — including reading — prove this is the ONLY claude process running**
(`ps`/process list pasted, your own PID identified by ancestry). This guard has fired
correctly FOUR times in this project catching stale sessions (SESSION-04's launch, twice
around the S21/S22 boundary, once at the account switch). The recurring cause is the owner
launching before typing `exit` in the old window — if you find another process, tell the
owner to `exit` it first, create NOTHING, and re-run the check when told "Cleared". One
session, verified solo, or nothing happens.

---

## STEP 0 — THE PREREQUISITE PROOF. THIS COMES BEFORE YOUR PLAN.

Doc 05 has no R-18-style engineering gate (R-18 is CLOSED at 3a9d0af), but it has a
**credentials gate** (PROJECT-HANDOFF-v5 §4 row 0), and this session does not start on
promises. Real Telegram credentials must already exist in the dev environment — a BotFather
bot token, and the Telegram Business account on its dedicated phone. The owner has confirmed
their status to the advisor before you launched; your job is to PROVE them live:

**0a — Prove the bot.** A real `getMe` call against the live token, **through the egress
proxy** (ADR 0008 — a direct call to api.telegram.org that bypasses the allowlisted proxy is
a violation, not a convenience; if the proxy's allowlist does not yet carry Telegram's API
domain, adding it BY CONFIG is your first act, and the deny-path test must still pass).
Paste the response **with the token redacted** — in the paste, in any evidence capture, in
any committed file. A bot token in a committed file or artifact is a Sev-1.

**0b — Show the Business connection state.** If the Business account is connected or
connectable, show its state (the `business_connection` update or the current connection
status). If the owner supplied only the bot and NOT the Business account, say so plainly:
your plan must then state which halves of Doc 05 are provable against real Telegram and
plan an honest **credential-blocked bucket** for the rest — you do NOT fake the Business
half against mocks and call it proven.

**0c — Confirm the egress path.** Show the proxy allowlist configuration carrying
Telegram's API domain(s), and that the deny fixture (mailpit) still refuses.

**Then paste all three, redacted, and WAIT for the advisor's written confirmation before
you produce the plan table. Not in parallel with the plan. Before it.**

---

Doc 05 is the first REAL channel. Doc 04's adapter contract was proven with two fake
adapters; this session is where the architecture claim gets tested against a real API:
the conformance suite must pass on the Telegram adapter **with zero core edits** (R-16).
Everything Telegram-peculiar is isolated in the adapter; everything already built
(pipelines, state machine, media chain, dead-letter, dedupe) is CONSUMED through its
existing doors, never rebuilt. Consumers that do not exist yet (Docs 07/08/09/10/12/14/15
surfaces and logic) are SEAMS proven against fixtures.

## Read, in full, before writing any code

1. `CLAUDE.md` — the constitution; §2's registries, the conventions table (C-06 outbox,
   C-09 idempotent consumers, C-11 as narrowed by R-19, C-13 secrets), §9's report shape.
2. `docs/Doc05-Telegram-Channel-Business-Direct.md` — this session's law (**verify the
   exact filename in docs/ before citing it**), every section end to end: §4.1–§4.8's
   FR-01…30 including the FR-01 allowed_updates list and §4.6's window mechanics, the
   complete EC-01…14 / FS-01…05 lists, §18's AC-01…09, and §10's T-01 capability
   declaration. No id may be covered from a summary, from this prompt, or from memory of
   the Bot API.
3. `docs/BUILD-STATUS.md` — the S22/Doc 06 close-out (what the ticketing spine shipped and
   its 12 PARTIAL buckets), the Doc 04 §S20 close-out (the pipelines and media chain you
   consume, incl. the S15 webhook-auth section), RISK 0a / 0b / 0d (all three are known
   intermittents — a CI red on 0d is re-run once and recorded, never "fixed" by
   loosening), numbered row 9 (the `events.created_at` deferral — see inherited row 6),
   the DESIGN-DEVIATIONS section, every standing-PARTIAL bucket (you may neither re-claim
   nor drop any), and the canonical-suites-definition precedent (standing rule 14: you
   define YOUR document's canonical suites command in BUILD-STATUS at plan time and every
   figure you cite uses it).
4. `docs/ANSWERS-FROM-DOC-AUTHOR.md` — the interpretation record, and it is **canonical**:
   Q1–Q9 (Q9's registry-normative rule binds your event names, permission keys, and
   settings directly), the Doc 02 webhook rulings (A-1 replay-cache fails CLOSED;
   `webhook_rejected` single byte-identical 403; hash_equals + functional sabotage;
   volatile nonce cache; `hmac_secret` encrypted cast + the C-13 vault handoff), A-4
   (`channel.health.changed`, TRANSITION-only — read it before you touch FR-29), A-5 (the
   `messaging.rate.*` settings precedent), R-15…R-23, ADRs 0010/0011 context, A-7…A-18.
   **Where this record and a numbered document requirement disagree, the document wins and
   you STOP.** A ruling that lives only in a chat or a code comment is not filed and does
   not bind.
5. `docs/DESIGN-FIDELITY-MANDATE.md` — read in full; it binds the user-visible surfaces
   this session builds. See the DESIGN FIDELITY block at the end — **Doc 05 is NOT the
   thin case**: six frozen Batch 6 files draw its admin surfaces.
6. The substrate you consume. Read the code and its docblocks before you touch anything —
   **verify each of these against the repo; where this prompt and the repo disagree, the
   repo is the fact and you STOP and tell me:**
   - **The ChannelAdapter contract and the 32-combination conformance suite** (the 12th
     gate) plus the two fake adapters that prove it. Your Telegram adapter is the first
     real implementation; the suite must pass on its DECLARED capability set, unmodified
     (T-09/SS-04). **Needing a core edit to make it pass is an ARCHITECTURE FAILURE you
     escalate to the owner — never a quiet patch.**
   - **The inbound discipline and its exact door**: the webhook edge (quota-gated,
     never-inline ACK), claimant-aware SETNX dedupe, the Doc 08 identity seam, the
     one-transaction persist+outbox, FR-11 quarantine. Your webhook enters HERE. State
     which door each Doc 05 flow enters, in the plan.
   - **The Doc 02 webhook auth machinery** (S15): `webhook_credentials`,
     `VerifyWebhookSecret`'s `secret_header` mechanism (Telegram's
     `X-Telegram-Bot-Api-Secret-Token` maps onto it — reconcile, never re-invent), the
     throttle and its `auth.webhook.rate_per_minute` setting, and the S15 note that the
     telegram probe audit-exemption line **is Doc 05's to delete** when the real route
     lands.
   - **The delivery state machine and dead-letter/re-drive machinery** (9 legal edges,
     `sent` never rolls back, R-17/A-6's ops re-drive view). FR-18's error classes map
     into ITS states and ITS retry path — you never build a parallel retry stack.
   - **The FULL media chain** end to end: egress-proxy allowlist config (tinyproxy; the
     mailpit deny fixture), streamed size abort, clamd INSTREAM, EXIF strip,
     tenant-prefixed store, signed URLs, EC-11 hold-then-quarantine. Telegram media rides
     this SAME chain (getFile via the proxy) — no second path.
   - **The ConsumerRegistry** (ADR 0011): if Doc 05 subscribes to any event durably, it is
     a registry entry with an idempotency proof (C-09, keyed on event_id) and an
     FS-02-style catch-up drill — the reopen-consumer pattern is established; follow it.
     You add entries through the registry, never by editing relay logic.
   - **`registries/permissions.php`** — the catalog is CLOSED at 111 keys + the two
     additions of record (`views.share`, `ai.providers.manage`). **Verify every key Doc 05
     names inline actually exists** — `telegram.bots.manage` (FR-05) and
     `telegram.health.view` (FR-30/SEC-03) at minimum, plus the already-load-bearing
     `telegram.message.delete` (Doc 04 FR-22 uses it). **If any key Doc 05 names is absent
     from the registry, STOP and ask — never invent a key** (NFR-03 discipline).
   - **The settings registry conventions** — the connection-method mode (FR-05) IS a
     setting; `telegram.business.window_hours` (FR-21: min 1, max 24, default 24) and the
     FR-20 bucket tunables and FR-29 health thresholds need registered keys named per the
     conventions (A-5's `messaging.rate.*` is the naming precedent — do not collide with
     it; the platform-maximum validation clamps live in the registry entry).
   - **`docs/EVENT-CATALOG.md`** — closed payloads (a missing OR extra key is rejected);
     Doc 06 left the catalog at 24 events; yours are additive, versioned, and the S22
     contract machinery will DEMAND consumer contracts the moment an event gains a
     consumer.
   - **The Doc 02 SEC-08 log-scrubber** — its seeded sentinels and header patterns
     already cover `X-Telegram-Bot-Api-Secret-Token`; NFR-06 extends the patterns with
     Telegram token and file-URL formats (T-07: Telegram file URLs CONTAIN the token and
     are secrets — never persisted, never logged; only our storage keys are).
   - **Doc 06's `channel_unreachable` column and its orthogonality tests** — the flag
     changes ANSWERS, never STATE; no ticket transition ever emits from a flag flip.
     Those tests must stay green while FR-10 supplies the set/clear semantics.
7. ADRs: **0003** (grants for any new tenant tables — and R-19b rides with it), **0007**
   (queue families — your sweep/health jobs ride the established families), **0008** (the
   egress proxy Doc 05 now truly exercises against a real external API), **0010** (R-19
   clock physics — Telegram's `date` is metadata, never ordering), **0011** (durable
   consumers via the registry).

## Stack / context notes (not conflicts; do not stall on them)

- The repo is **Laravel 13** by owner ruling; spec stack contexts read as minimums.
  Doc 05 names **Nutgram** — T-02 requires it bound per-bot at runtime from the bot
  registry (no per-bot deploys). Adding it is expected stack growth: name it in the plan
  so the owner approves it WITH the plan (the R-15 pattern), and if you conclude you can
  meet T-02 more safely without it, say so with reasoning instead of silently choosing.
- Prose counts anywhere are descriptive; **code registries are normative** (Q9). This
  applies to your event names, your permission keys, and your settings.
- **The adapter-consumes-core boundary, stated up front**: the Telegram adapter implements
  the ChannelAdapter contract and touches ZERO core files. R-16's zero-core-edits proof
  was made with a fake; you now make it real. If the real API breaks the contract's
  assumptions, that is an escalation with evidence, not a workaround.
- **Channel timestamps are METADATA** (EC-13 + R-19/ADR 0010): ordering and every metric
  timestamp are DATABASE time the app cannot write. Telegram's `date`/`edit_date` are
  stored in `channel_meta` and never participate in ordering. The reply window (FR-21)
  computes from the inbound message's DB-time `created_at`, never from `telegram_date`.
  **Any new Doc 05 table with an ordering- or metric-bearing timestamp gets the full
  R-19b treatment from its FIRST migration** (clock_timestamp() default + both-revoked
  column grant + schema-derived guard — Doc 06's tables were the first application; state
  your R-19b analysis per new column in the plan, including the columns you judge NOT
  ordering/metric-bearing and why).
- **Business vs Direct is a SETTING, not a fork** (FR-05, and a §2 non-negotiable): one
  adapter, capability-declared differences, never two code paths that drift. Hybrid is
  the same adapter with both inbound lines active.
- **Consumers that do not exist are SEAMS with fixtures, never their logic**: Doc 08
  (identity — the Telegram-user→customer mapping goes through the EXISTING Doc 04 seam;
  hybrid's one-customer resolution is Doc 08's, fixture-proven here), Doc 07 (composer
  states, rescue view, inbox banner, dual-channel badge — you ship the CONTRACT and the
  data), Doc 09 (AI first turn, /human handoff, AI flow targets), Doc 10 (KB flows),
  Doc 12 (forms, window-event automations), Doc 14 (SMS re-engagement send — you gate on
  phone presence and emit; the send is theirs), Doc 15 (per-connection report feed —
  events and dimensions are yours, formulas theirs). Doc 06 IS built: FR-25's `/status`
  reads real ticket data through Doc 06's doors.
- **Statuses and sources remain platform-fixed**; nothing in Doc 05 adds a ticket status
  or invents a source value.

## SCOPE

Implement **all of Doc 05** — enumerate every FR / NFR / US / BR / T / SEC / EC / FS / SS /
AC / R id from the document itself and cover each one. **State the id count YOU found**, by
family; your plan is audited against an independent count made from the same text.

> "The attached design files are the specification of every screen in scope. Implement them
> at 100% fidelity per DESIGN-FIDELITY-MANDATE.md — every frame, state, viewport, theme,
> and language, tokens from the design system only, deviations only in the mandate's three
> classes and every one filed. Nothing skipped, nothing approximated, nothing redesigned."

**Out of scope (do not touch):**
- Core pipelines, the delivery state machine, media chain internals → **Doc 04** (you
  consume their doors; §3 says so explicitly).
- Identity resolution and customer merge → **Doc 08** (the mapping seam is yours,
  fixture-proven; resolution is theirs).
- AI conversation logic → **Doc 09** (Doc 05 defines only where flows attach: /human,
  deep-link context on the first turn, AI free-chat menu targets, callback routing).
- Routing and ticket rules → **Doc 06** consumes your connection dimension (FR-08) and
  your `channel_unreachable` semantics; you build no routing.
- SMS template content and sending → **Doc 14**.
- Inbox/thread rendering, composer rendering, rescue view, inbox banner → **Doc 07**
  (§8's composer states are explicitly "contract for Doc 07").
- The widget → **Doc 13**. Groups/channels support → **deferred, §19**.

**Pre-agreed closure buckets** — the ONLY sanctioned deferrals; verify each against Doc
05's own text and name its unblocker in the plan. Do not invent a deferral beside them; if
you believe one is missing, STOP and ask before planning around it.

| Bucket | What defers | Unblocker |
|---|---|---|
| §8 composer contract | RENDERING of normal/closing/expired/paused/revoked/blocked composer states, the rescue view, the FS-01 inbox banner, EC-04's dual-channel badge and grouping | Doc 07 — the states, data, events and per-state contract are THIS session's; §8 says "contract for Doc 07" |
| AC-07's DOM half | The DOM assertion that no delivered state ever renders | Doc 07 — the capability declaration (`delivered unsupported`), the state-machine truth mapping and its tests are THIS session's |
| Doc 08 identity | Hybrid's one-customer resolution (FR-05/EC-04) | Doc 08 — the mapping through the existing seam + fixtures is yours |
| SMS re-engagement | The actual SMS send (FR-23a, SS-03) | Doc 14 — phone-presence gating, the action seam and its events are yours |
| Window automations | Doc 12 rules acting on `closing`/`expired` (FR-22) | Doc 12 — the events are yours |
| AI/KB/form flow targets | Executing KB flows, forms, AI free-chat behind menu buttons and callbacks (FR-14/FR-27) | Docs 09/10/12 — the designer, the target indirection, callback routing and fixtures are yours |
| Per-connection reporting | Doc 15 dashboards/report lines (FR-29, SS-01) | Doc 15 — events, dimensions and any golden fixtures are yours |
| §12 / NFR p-values | Formal performance budget closure | Doc 19 — structure the budgets, measure indicatively, never fake numbers |
| CREDENTIAL-BLOCKED (conditional) | Only if step 0 proved the bot but not the Business account: AC-03's staged lifecycle walk and every business-mode-only proof | The Business account credential — stated plainly, never mocked into a claim |

Anything Doc 05 requires that is NOT in a bucket is built and proven in this session.

## INHERITED (PROJECT-HANDOFF-v5 §4 — each is a mandatory plan-table row)

0. **The prerequisite proof** — satisfied before this plan exists; its row cites the
   pasted step-0 evidence and the advisor's confirmation.
1. **The conformance suite passes on the REAL adapter with ZERO core edits** (Doc 04
   AC-01's re-proof bucket firing; R-16 made real; SS-04 is this claim in Doc 05's own
   text). The zero-core-edits proof is a diff assertion, not a sentence.
2. **`channel_unreachable` set/clear semantics** (FR-10 + Doc 06 BR-07/EC-10 inheritance):
   the flag never transitions a ticket; Doc 06's orthogonality tests stay green and your
   plan says where you re-ran them.
3. **The messaging pipeline consumed through its existing doors** — the plan states, flow
   by flow (inbound message, business inbound, edits, deletions, media, outbound send,
   typing), WHICH existing door each enters.
4. **R-19b on every new table** — per-column analysis in the plan; default + grant +
   guard from the first migration for every ordering/metric-bearing timestamp.
5. **`channel.health.changed` produced per A-4** — TRANSITION-only, never heartbeats,
   closed versioned payload. **Doc 05 FR-29's text writes `channel.health_changed`; A-4
   filed `channel.health.changed` and the catalog is normative (Q9) — state the
   reconciliation as a filed interpretation, never a silent choice.**
6. **The `events.created_at` deferral check** (BUILD-STATUS row 9): if ANY Doc 05 work
   orders a read over the events table, the deferral FIRES and the R-19 treatment lands
   first. State the check's outcome in the plan even if the answer is "no ordered read".
7. **Route→permission mapping same-commit** from the existing catalog only; every key
   verified in the registry; never invent.
8. **Standing honest PARTIALs untouched** — Doc 06's twelve, Doc 04's sixteen, Doc 03's
   SS-01/SS-02: each Doc 05-relevant one gets a stated disposition; none silently
   dropped, none re-claimed without executed evidence.
9. **The three known intermittents acknowledged** (RISK 0a/0b/0d) — a CI red on 0d is
   re-run once and recorded; capture-first on any recurrence of 0b.
10. **The consumer-fixture tightening note**: if Doc 05 consumes any `ticket.*` event
    durably, that consumer's FIRST act is tightening its own fixture file (the note in
    each of the seven contract-fixture files binds you).

## THE SUBTLE PARTS (verified against Doc 05's text — read them there, not here)

- **The webhook is Doc 02's machinery wearing Telegram's header** (FR-01/FR-02/SEC-02 +
  the S15 substrate): unguessable route key `/hooks/telegram/{bot_key}` resolving the bot
  BEFORE secret verification (T-02's order), Telegram's `secret_token` carried by the
  existing `secret_header` mechanism, constant-time compare, one byte-identical
  `webhook_rejected` 403, per-key throttle, A-1's replay posture, 256-bit secret ROTATED
  on every re-register (FR-03 — idempotent, safe anytime, drops nothing because Telegram
  redelivers). setWebhook carries FR-01's allowed_updates list EXACTLY — enumerate it from
  the document; a missing update type is a silent feature amputation. The S15
  audit-exemption probe line for telegram dies in your first webhook commit. FR-04's bot
  deletion: zero active connections OR explicit cascade acknowledgment; deleteWebhook;
  conversations stay readable (endpoint archived, never rows dropped).
- **Dedupe key is `{bot}:{update_id}`** (FR-11) through the EXISTING claimant-aware SETNX
  — verify the key-shape composition against the built dedupe's contract, and prove the
  duplicate-update fixture (EC-11 for `business_connection` upserts is SEPARATE:
  idempotent by connection_id, not by update dedupe).
- **Mode semantics are handling, not topology** (FR-05/FR-06): `direct` stores stray
  business updates flagged `unconfigured_source` + admin alert; `business` auto-replies
  direct messages with the per-language redirect template — BR-06: ALWAYS a tappable
  account link, dead ends prohibited — and still stores the conversation; `hybrid` runs
  both lines with one customer, two conversations (Doc 08 seam). Mode changes are
  immediate; leaving `business` with active connections takes a typed confirmation and
  does NOT disconnect — disconnect is its own explicit action.
- **The connection lifecycle is update-driven truth** (FR-07…10, EC-01/02/03/05/06/11):
  states active|paused|revoked change ONLY from `business_connection` updates; each
  transition emits `telegram.connection.{state}`, updates health, notifies on
  paused|revoked. SEC-04's confirm step is a security control: an UNCONFIRMED connection
  stays pending, NEVER routes, and alerts after 10 min — prove the never-routes with a
  sabotage. `can_reply=false` saves as paused with the redo-step-2 explainer (EC-03);
  wrong-account rejection deletes and restarts (EC-01); two admins converge on ONE
  registry row (EC-02); FS-04's flap damping collapses notifications >3/hour into a
  digest while states stay truthful. FR-10 on revoked: open tickets flagged
  `channel_unreachable` (inherited row 2), rescue-view DATA prepared (rendering Doc 07),
  outbound lane frozen via the EXISTING non-retryable classification.
- **Owner-phone replies are first-class** (FR-11/T-03/BR-03/US-03): `from = owner` on a
  business message maps to `author_type=agent` under a lazily-created reserved staff
  identity "{account_name} (phone)", reported as agent type `owner_phone`, never hidden.
  Username changes update the row forward; historical attribution keeps the old display
  (EC-05, the Doc 08 pattern).
- **Edits, deletions, media ride Doc 04**: `edited_*` → the FR-21 revision path (the
  at-least-once-redelivery-vs-edit defect is already fixed there — do not regress it);
  `deleted_business_messages` → tombstones per id. Media: largest photo size + Telegram's
  thumb, voice ogg/opus + duration, video/video_note, documents with pre-download
  `file_size` respect, stickers stored with static thumb v1 (EC-10's placeholder when
  thumbnailing fails — never blocks the message). getFile via the proxy; the 20 MB
  bot-download ceiling maps to a `media_rejected` note + template shortcut with the
  caption still stored (EC-07). **Telegram file URLs contain the token: secrets, never
  persisted, never logged** (T-07).
- **Callbacks and deep links are untrusted input** (FR-14/FR-26/T-05/SEC-05/SEC-06):
  callback ACK ≤1 s via answerCallbackQuery; nonce = HMAC(bot_key, flow_id, ts) inside
  Telegram's 64-byte callback-data cap, 15-min validity, single-use via Redis; replay →
  neutral toast + logged event. Deep-link payloads `src-{source}[_ctx-{context}]` are
  schema-validated, length-capped, resolved through an indirection table — never executed
  as flow ids; unknown payloads ignored gracefully; the payload lands as acquisition
  metadata and feeds the Doc 09 first-turn seam.
- **Outbound maps kinds, splits honestly, and never lies about delivery**
  (FR-16/17/18, BR-04, EC-08/09): kind-mapped send methods with `business_connection_id`
  on business sends; >4096 chars splits at word boundaries into sequential lane messages
  annotated part i/n, and a mid-split failure holds the lane with retry resuming from the
  failed part (EC-09 on Doc 04's FR-15 behavior). Capability declares `delivered`
  UNSUPPORTED: terminal success is single-check "Sent", and BR-04 makes faking richer
  receipts a prohibited roadmap item — the capability + state mapping is proven here,
  the DOM assertion buckets to Doc 07. FR-18's classification table lands in Doc 04's
  states verbatim: 403 → `blocked_by_user`; window/paused → `window_expired` /
  `connection_paused` non-retryable; 429 honors `retry_after` OVER the backoff step;
  400 → `invalid_content` with detail surfaced; 5xx/network retryable. EC-08's
  doomed-retry rule: `retry_after` ≥ remaining window → immediate `window_expired`, no
  doomed retry.
- **Typing is best-effort and capability-gated** (FR-19): the EXISTING 1-per-4s adapter
  signal drives sendChatAction with `business_connection_id` where applicable; failures
  silently ignored.
- **Rate limits are Redis-Lua physics** (FR-20/T-08/R-05): per-bot bucket (default
  25 msg/s) and per-chat (1/s sustained, burst 3), tunable in settings WITHIN
  platform-maximum validation; 429s feed adaptive slowdown (refill −20% for 60 s);
  per-chat FIFO order preserved under the buckets (AC-06). All atomic in Lua.
- **The window is column truth + ZSET index, and calibration WINS** (FR-21…24, T-04,
  BR-05, EC-14, FS-01/FS-05, NFR-03): `reply_window_expires_at` on conversations is
  truth; `windows:{tenant}` ZSET is the O(log n) sweep index, rebuilt from columns on
  loss (FS-05) while event-driven updates keep working. The 5-min sweep emits
  `telegram.window.closing|expired`; every inbound/outbound updates state immediately,
  bypassing cadence. The window computes from the inbound message's DB-time receipt.
  **Calibration**: a `window_expired`-classified send error BEFORE computed expiry
  tightens the stored expiry to now + logs telemetry per connection (EC-14); BR-05 —
  operators may tighten, NEVER extend beyond observed behavior; configuration loses to
  calibration. FS-01: during a VERIFIED outage (health=down) the sweep pauses expiry
  transitions — no false "expired" — and resumes with recompute. FR-24's reopen on
  inbound: → open, recompute, clear badges, notify the assignee if a ticket is open.
- **Direct mode is the fixed v1 surface** (FR-25…28): the five commands registered per
  language via setMyCommands, set fixed in v1, labels localized; `/status` reads REAL
  Doc 06 ticket data; `/human` emits the handoff-request event (Doc 09/06 seam);
  `/language` lists only languages with COMPLETE template sets (§14). The menu designer:
  ≤12 buttons, rows ≤3, five target types through the indirection table, versioned
  publish with instant rollback, ≤10 s propagation via config-cache invalidation
  (T-06/NFR-05), and the dual preview — button render vs the numbered-text business
  fallback (Doc 04's capability fallback doing real work). FR-28's templates are
  per-bot-per-language operator content with variables ({{first_name}}, {{queue_wait}} —
  where a variable's data source is unbuilt, it resolves through a seam fixture, stated).
- **Health is polled + event-driven, and its event is A-4's** (FR-29/30, FS-02): 5-min
  getWebhookInfo poll + event-driven updates; healthy|degraded|down thresholds from
  registered settings; rollup ≤1 s; **the health event fires on TRANSITIONS only** (A-4)
  and the FR-29-vs-A-4 name reconciliation is filed (inherited row 5). FS-02's
  silent-broken detection: pending_update_count growth + a no-inbound heartbeat on
  normally-active bots; one-click re-register recovers; Telegram redelivers. Admin test
  tools: send-test to an admin-verified sandbox chat; simulate-window-expiry STAGING
  ONLY; last-50 raw-update viewer with SEC-03 masking (phones, contact payloads,
  forwarded-author fields — EC-12's forwarded-origin metadata stored but masked),
  gated by `telegram.health.view`.
- **The token is a secret with no vault to live in — surface this, don't solve it
  silently** (FR-01/SEC-01/FS-03 vs the substrate): Doc 05 says vault-only per Doc 01
  SEC-04, but SEC-04 is NOT DONE — no vault exists; Doc 18 owns it (BUILD-STATUS is
  explicit). The established interim precedent is Doc 02's `hmac_secret`: encrypted cast
  at rest, scrubber sentinel, secrets-never-serialize pin, C-13 vault-migration handoff
  filed. **Propose your storage shape against that precedent as a plan-time question for
  the advisor — a filed interpretation, never a silent choice.** Whatever is ruled:
  SEC-01's UI behavior stands (never display a stored token; re-entry to change), NFR-06
  extends the scrubber with Telegram token/file-URL formats with positive controls, and
  FS-03's vault-unavailable path maps to retryable `credentials_unavailable` with no
  fallback storage by design.
- **The event family is enumerated by YOU** (FR-09/15/22/29 + anything else the text
  requires): state the number in your plan; do not adopt a count from any prose,
  including this prompt. Resolve rather than paper over: (i) FR-09's
  `telegram.connection.{state}` — enumerate the concrete states and say whether that is
  two events or three; (ii) `telegram.bot_blocked|unblocked` — one event or two, filed;
  (iii) the FR-29/A-4 health-event name (inherited row 5). Every schema versioned,
  closed-payload, through the outbox; consumer contracts per the S22 machinery for any
  event with a consuming document.
- **NFR budgets are enforced, not admired**: ≤30 ms p95 normalization overhead, ≤80 ms
  p95 send overhead (our half only; Telegram RTT monitored separately), 100 updates/s
  burst with the ACK budget intact (NFR-02/SS-02/§12), sweep drift ≤5 min with
  event-driven bypass, connection-state reflection ≤5 s, publish propagation ≤10 s,
  50k-window sweep ≤10 s — measured in the §12 pattern Doc 06 set (both modes where the
  harness exists; honest Doc 19 buckets where it does not).
- **§13/§14 bind the surfaces you build**: role="status" checklist announcements,
  periodic (not per-second) countdown SR announcements, icon+text pills, labeled
  preview frames, keyboard drag equivalents, labeled typed-name confirmations; all
  operator strings under `telegram.*` i18n keys en+am; customer-facing content is
  per-language operator content with completeness indicators and the Doc 01 fallback
  chain; window guidance copy jargon-free in every language; numbered menus respect
  locale digits; RTL-ready composition. New machine-authored Amharic joins the standing
  native-review queue and destructive-tier strings are flagged per R-14's rider.

## PROTOCOL (unchanged, in full)

1. **PLAN FIRST**: a table of every Doc 05 id → file(s) → proving test, plus rows for
   every INHERITED item (0–10 above) and the DESIGN FIDELITY scoping row (below). **State
   the id count you found**, by family, and your event-family enumeration with its three
   named resolutions. Sequence safety tests BEFORE the features they constrain and justify
   any counter-intuitive ordering — at minimum: the conformance suite runs against the
   adapter skeleton BEFORE feature flows are added to it; the webhook secret/replay tests
   BEFORE the webhook processes real updates; SEC-04's never-routes proof BEFORE any
   connection can route; the calibration and outage-pause tests BEFORE the sweep is
   trusted; the scrubber's Telegram-format positive controls BEFORE any evidence capture
   that could contain a token. Define this document's **canonical suites command in
   BUILD-STATUS at plan time** (standing rule 14) — every figure you cite at checkpoints
   and close-out uses it. Name any new stack service or dependency (Nutgram included) so
   the owner approves stack growth with the plan. **WAIT for approval — and the approved
   plan table is COMMITTED to the repo at approval, before step 1's first commit**
   (governance #9 / standing rule 12). Step 0's evidence is already pasted and confirmed
   before this table exists.
2. One commit per numbered step; registries updated as you go — permission mappings for
   every new route (**keys from the seeded catalog only**); settings named per the
   conventions with platform-maximum validation; audit-coverage for every new event and
   every admin action (connection confirm/disconnect, mode change, re-register, publish/
   rollback, test tools — with actor + old/new); traceability rows; the EVENT-CATALOG
   regenerated with closed payloads.
3. **Falsify every safety mechanism you build, with a positive control for every
   assertion, sabotage BEFORE counting anything proven.** A sabotage that stays green
   condemns the TEST, not the guard — strengthen the test and keep the stronger one
   (S19 #7); and the inverse stands: a by-design green is EXPLAINED with its mechanism,
   never "strengthened" into a worse test (S22's FS-04 precedent). A live-DB sabotage
   wiped by `migrate:fresh` is INVALID and is redone at the migration level (the S22
   row-7/row-10 precedent) — file invalid attempts under honest names. At minimum,
   verified against Doc 05's text: a capability lie → the conformance suite goes red;
   dedupe dropped → the duplicate-inbound fixture goes red; ACK made inline → the latency
   proof goes red; the media chain bypassed → the infected-fixture proof goes red;
   ordering keyed on a channel timestamp → the inverted R-19 controls go red; webhook
   secret validation removed → the webhook-ruling tests go red; the callback nonce made
   reusable → SEC-06's replay proof goes red; an unconfirmed connection allowed to
   route → SEC-04's never-routes proof goes red; a seeded fake token planted in a log
   path → the NFR-06 scrubber gate goes red; the sweep expiring windows while
   health=down → FS-01's outage proof goes red. Your plan enumerates ITS OWN complete
   sabotage list (these are the floor); the ledger is reconciled against that list at
   close, item by item, never against a count (governance #5). Paste fail AND
   restored-pass, every time; backup before sabotage; nothing commits until
   `git status`/`git diff` proves the tree clean of falsification damage. **Every
   diagnostic run is captured FIRST** — full output to a file under `docs/evidence/`
   before any filtering or rerun (governance #6) — **and every verdict you state comes
   from the unfiltered capture's summary and exit code, never from a filtered pipe**
   (standing rule 11). **Token-redaction discipline on every capture: scrub-check each
   evidence file before it is committed; a real token in `docs/evidence/` is a Sev-1.**
4. Every user-visible string is an i18n key with `en` AND `am` entries (`telegram.*` per
   §14); `string-lint` stays green; new machine-authored Amharic joins the standing
   native-review queue and destructive-tier strings (disconnect, cascade delete,
   mode-change confirmation) are flagged in your close-out per R-14's rider.
   Customer-facing template/menu/command content is operator-managed per-language data
   with completeness indicators — not hardcoded strings.
5. Targeted tests while iterating; the FULL suite via the canonical command, plus
   `make gates`, `make lint`, once per step-commit — pasted real output. Push and record
   the CI run ID per the efficiency ruling (exceptions: CI/infra-file changes — your
   proxy-allowlist config qualifies — and rulings that name CI as a gate). A CI red on
   RISK 0d is re-run once and recorded, never loosened.
6. Finish with the full Doc 05 self-audit table (every id, DONE / PARTIAL / NOT DONE +
   one-line reason; PARTIALs cite their pre-agreed bucket — including the
   credential-blocked bucket if step 0 left one; a false DONE is a Sev-1), the **sabotage
   ledger reconciled against this plan's enumerated list, item by item, never against a
   count**, the DESIGN-DEVIATIONS section updated in BUILD-STATUS (an unfiled deviation
   is a fidelity violation), the inherited-row outcomes (0–10) cited explicitly, and the
   CLAUDE.md §9 report: BUILT / PROVEN / NOT DONE / BLOCKERS / RISKS / NEXT SESSION.

## DESIGN FIDELITY (Doc 05 is NOT the thin case — verify the scoping and state it)

Per DESIGN-FIDELITY-MANDATE.md §5, which is in your reading list:

- **The drawn inventory.** PROGRAM-REPORT-v1's Batch 6 froze six files squarely over Doc
  05's §8/§9 admin surfaces — **verify each against the report and the frozen directory,
  never assume**: `B6-S01-telegram-bots-connection-v1` (bot registry + webhook lifecycle —
  FR-01…04, the health-panel actions), `B6-S02-telegram-connections-v1` (the connection
  lifecycle — FR-07…10 and their edge states), `B6-S03-reply-window-settings-v1` (FR-21's
  administration), `B6-S04-menu-designer-v2` (FR-27), `B6-S05-bot-templates-commands-v1`
  (FR-25/FR-28), `B6-S06-channel-health-v2` (FR-29/30). Plus — always — the design
  system: `B1-DS-design-system-v2` (token source, §2 both colourways),
  `B1-DS-addendum-B5-v1`, `B1-DS-v3-addendum-B6-v1` (the seven Batch 6 components live
  here), `B1-DS-v4-addendum-B7-v1`. The Batch 6 rulings live in `B6-S00-checklist-v2`,
  `B6-A01-closing-audit-v3` and `B6-X01-crosswalks-v2` — read them for the rows that bind
  these surfaces. **Access wrapper: read ONLY the named files at `design/frozen/`;
  screen/audit files — the highest version is the frozen one; the design system is v2
  base plus all three addenda together.**
- **The scoping verification is a plan row.** Doc 05's §8 Connection tab, Health panel and
  Menu designer are THIS document's Backoffice surfaces — there is no later document that
  renders them. Doc 05's §8 composer states are the opposite: explicitly a "contract for
  Doc 07". Your plan STATES this split in your own reading of §8/§9, maps every built
  surface's frames like ids (frame → component/file → proving test), and if you read the
  split differently, you STOP and ask before planning UI work.
- **One exclusion is already owner-accepted, on the record**: the deep-link builder —
  FR-26's `src-{source}_ctx-{context}` payload schema stays a bot-side contract with NO
  admin surface (`B6-S00-checklist-v2`, Silence 2 closed). Build the contract; do not
  invent the screen; cite the record in your plan.
- **Fixed tokens are law you encode, not values you choose**: connection-state pills
  (active=green, paused=amber, revoked=red per §9), the countdown/reply-window chip
  (mm:ss under 10 min, h:mm above — Doc 07 consumes the SAME component, so build it
  shared), and the channel-health rollup are among the mandate's fixed tokens — exact
  values from the design system in BOTH colourways, never re-mixed, never colour-only
  (icon+text per §13). Dark values from v2 §2's dark column, never mixed by eye.
  Reduced-motion: countdowns never pulse — static color shift (§9).
- **AC-08 is a real-Telegram design proof**: menu-designer publish/rollback round-trip
  with dual previews matching ACTUAL renders on the staging bot (screenshot comparison),
  ≤10 s propagation. It needs the staging bot from step 0; if only direct mode exists,
  the business-text-preview half states its evidence honestly.
- Deviations only in the mandate's three classes, every one filed in BUILD-STATUS's
  DESIGN-DEVIATIONS **and** named in your close-out. Design-vs-document conflict → STOP
  and ask. Design gap → class-2 fill with design-system components, filed. Design
  defect → class-3 flag, wait.

Then STOP.
