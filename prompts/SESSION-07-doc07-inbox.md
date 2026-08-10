# SESSION — DOC 07: UNIFIED INBOX — AGENT WORKSPACE

You are the principal engineer and platform architect for **Na Live Support**, with 25+
years building agent workspaces, realtime read models, and frontend systems of this class
(Zendesk, Intercom, Stripe-grade). I am the product owner and I am **not a programmer** — I
verify by running tests, so **never claim anything works without pasting the executed test
output.** Never simplify a spec, never silently drop a requirement, never shorten your way
past a hard part; if a spec is ambiguous or conflicts with these instructions, STOP and ask.

**Before ANY work — including reading — prove this is the ONLY claude process running**
(`ps`/process list pasted, your own PID identified by ancestry). This guard has fired
correctly FIVE+ times in this project catching stale sessions (SESSION-04's launch, twice
around the S21/S22 boundary, the account switch, and SESSION-23's launch). The recurring
cause is the owner launching before typing `exit` in the old window — if you find another
process, tell the owner to `exit` it first, create NOTHING, and re-run the check when told
"Cleared". One session, verified solo, or nothing happens.

**Two standing rules you carry from the first minute (they bind every step below):**

- **CI is checked ONCE per checkpoint, never polled** (standing rule 15). `gh run view`
  once; if the run is still in progress, the verdict is OWED FORWARD and reported at the
  next checkpoint. A poll loop killed the S23 step-10 session at 100% context after 3.5
  hours. There are no exceptions.
- **Evidence to the advisor travels as UPLOADED FILES or plain pasted text only**
  (standing rule 17). Inline document attachments have arrived empty NINE recorded times.
  Close-out recount material is files, full stop.

---

## STEP 0 — THE OWNER-RULINGS GATE. THIS COMES BEFORE YOUR PLAN.

Doc 07 has no credentials gate, but it has a **rulings gate** (PROJECT-HANDOFF-v6 §4
row 0), and this session does not start on promises. Three owner rulings of 2026-08-10
must be verified FILED in `docs/ANSWERS-FROM-DOC-AUTHOR.md` — filed means committed text
in the record, not a chat memory:

- **R-22 RESOLVED** — the tag rename/merge surface is an implementation-side **class-2
  fill** from existing design-system components (settings-row + confirmation patterns),
  no design-track reopen; Doc 07 BUILDS it, frame-mapped.
- **R-24** — untranslated menu/button content renders **fallback-language text** per the
  Doc 01 fallback chain — never blank, never a raw key — with the §14 completeness
  indicator surfacing the gap.
- **R-25** — health outage bars use the **neutral ink ramp**; status hues stay reserved
  to their semantic components per design-system law R-07/R-08.

**0a — Prove the filing.** `grep` the three rulings in `docs/ANSWERS-FROM-DOC-AUTHOR.md`
and paste the matched block headers. Then run
`git log --oneline -3 -- docs/ANSWERS-FROM-DOC-AUTHOR.md` and paste the output — the
filing commit's SHA is what your inherited row 0 cites. If the rulings text is absent
from the committed record, STOP: report exactly what the file contains, create nothing —
the filing block comes from the advisor, not from you.

**Then paste the evidence and WAIT for the advisor's written confirmation before you
produce the plan table. Not in parallel with the plan. Before it.**

---

Doc 07 is the screen agents live in for their whole shift, and it is **the heaviest
designed document of the series**: the Batch 1+2 design stack (twelve frozen screen
files, two audits, a nine-row acceptance file, and the drift-report ledger) draws it
frame by frame, and R-01's own words call the read models "the hardest engineering in
the series". Nothing semantic is invented here: tickets are Doc 06's, messages Doc 04's,
window/composer states Doc 05's — Doc 07 RENDERS and READS through their existing doors
and builds the read-model + realtime layer that keeps 500 agents' lists correct without
stampeding the database. Panels that belong to other documents (Customer — Doc 08;
Copilot — Doc 09) are named SLOTS with fixture-proven seams and zero panel logic here.

## Read, in full, before writing any code

1. `CLAUDE.md` — the constitution; §2's registries, the conventions table (C-04 stable
   refusal reasons, C-06 outbox, C-09 idempotent consumers, C-11 as narrowed by R-19),
   §9's report shape.
2. `docs/Doc07-Unified-Inbox-Agent-Workspace.md` — this session's law (**verify the exact
   filename in docs/ before citing it**), every section end to end: §4.1–§4.9's FR-01…31
   including the complete FR-31 shortcut map, §5's NFR-01…07 (NFR-07's consistency
   checker is a deliverable, not an aspiration), §7's BR-01…06, §8/§9's drawn-state
   obligations (empty/loading/error for ALL regions, density, print), §10's T-01…08 and
   the data-flow diagram, §11's SEC-01…06, §12's L2 budgets, §13/§14 in full, and the
   complete EC-01…12 / FS-01…05 / SS-01…04 / AC-01…10 / R-01…05 lists. No id may be
   covered from a summary, from this prompt, or from memory.
3. `docs/BUILD-STATUS.md` — the S23/Doc 05 close-out (acceptance `e62f204`, what the
   Telegram channel shipped and its 17 PARTIAL buckets — the [CO] rows are YOURS to
   close), the S22/Doc 06 close-out (the ticketing spine and its 12 PARTIALs — six are
   YOURS), the S20/Doc 04 close-out (the pipelines, presence, drafts, virtualization,
   RealtimePusher you consume), **RISK 0a / 0d / 0f** (all open intermittents — a CI red
   on 0d is re-run once and recorded, never loosened; 0f's ratified disposition ladder
   binds: failure-time limiter-key snapshot FIRST, harness-isolation extension only if
   bleed is confirmed, a Doc 02 session if it proves a product write), numbered row 9
   (the `events.created_at` deferral — see inherited row 7: your T-07 sync endpoint is
   the likely trigger), the DESIGN-DEVIATIONS section (eight rows including the four S23
   additions; rows 7 and 8 are CLOSED by R-24/R-25), every standing-PARTIAL bucket (you
   may neither re-claim nor drop any except the rows your closing sets legitimately
   close), and the canonical-suites-definition precedent (standing rule 14: you define
   YOUR document's canonical suites command in BUILD-STATUS at plan time and every figure
   you cite uses it).
4. `docs/ANSWERS-FROM-DOC-AUTHOR.md` — the interpretation record, and it is **canonical**:
   Q1–Q9 (Q9's registry-normative rule binds your event names, permission keys, and
   settings directly), A-1…A-29 (A-22's `channel.health.changed` schema_version 2 —
   healthy|degraded|down, TRANSITION-ONLY — matters most: your FR-03 header indicator is
   its FIRST consumer), R-1…R-25 — **including the three 2026-08-10 rulings your STEP 0
   just proved filed** — and ADRs 0010/0011/0012 context. **Where this record and a
   numbered document requirement disagree, the document wins and you STOP.** A ruling
   that lives only in a chat or a code comment is not filed and does not bind.
5. `docs/DESIGN-FIDELITY-MANDATE.md` — read in full; **Doc 07 is the heaviest fidelity
   block of the series** — the DESIGN FIDELITY block at the end of this prompt is the
   main event, not a footnote.
6. `docs/EVENT-CATALOG.md` — closed payloads (a missing OR extra key is rejected); Doc 05
   left the catalog at 83 events; yours are additive, versioned, filed-before-shipped
   (standing rule 16), and the S22 contract machinery will DEMAND consumer contracts the
   moment an event gains a consuming document.
7. The substrate you consume. Read the code and its docblocks before you touch anything —
   **verify each of these against the repo; where this prompt and the repo disagree, the
   repo is the fact and you STOP and tell me:**
   - **The shared Doc 05 UI components, built at design fidelity in S23 steps 10–11:
     `WindowChip` (the countdown chip), `ConnectionStatePill`, `HealthRollupPill`,
     `StepChecklist`.** Doc 07 CONSUMES them, never re-implements. A second
     implementation of any shared component (these, or Doc 04's delivery
     glyphs/tombstone/edit-marker patterns) is a fidelity violation AND an architecture
     escalation.
   - **Doc 04's thread substrate**: the §8/§9 message contracts (bubbles, states, media,
     tombstones, revision markers), presence (FR-19), drafts (FR-20: LWW updated_at
     guard, EC-06 other-device conflict toast, IndexedDB body-only per its T-07),
     delivery glyphs, the virtualization budget, `RealtimePusher` (v:1, ≤32 KB ceiling,
     SEC-06 emit fence), presence/user channels with T-06 scope-on-subscribe, and EC-08's
     reply-to context fetch. Your thread RENDERS these contracts fully (FR-14); it
     re-implements nothing.
   - **Doc 05's channel substrate**: the §8 composer-state contract JSON
     (normal/closing/expired/paused/revoked/blocked + Doc 04 reasons —
     `blocked_by_user`, `connection_paused`, `window_expired`), the
     `reply_window_expires_at` state + `telegram.window.closing|expired|reopened` events,
     `channel_unreachable` rescue data, owner-phone attribution (`author_type=agent`,
     agent type `owner_phone`), and `channel.health.changed` v2 — **no consumer contract
     fixture exists for it yet; yours is the first.**
   - **Doc 06's ticket substrate**: the FR-04 matrix + `TicketTransitionService` as the
     SOLE status writer (your send-and-set-status split button goes through it, never
     beside it), the property contract, the bulk machinery (`TicketBulkJob`, per-item
     live checks, per-tenant caps, `ticket.bulk_completed`), the handoff-packet card
     data (FR-11/AC-08), the merge machinery whose banners/dialogs/preview YOU render,
     and **T-08's own words: "List reads go through Doc 07's read models; this module
     owns writes and the canonical row" — `ticket_list_items` does NOT exist yet; you
     build it, and R-19b rides its first migration.**
   - **The ConsumerRegistry** (ADR 0011): every durable subscription — your
     list-projector on ticket.*/message events, the health-v2 header consumer — is a
     registry entry with an idempotency proof (C-09, keyed on event_id) and an
     FS-02-style kill/re-drive catch-up drill. You add entries through the registry,
     never by editing relay logic. **The consumer-fixture tightening note binds you**:
     the note in each contract-fixture file (Doc 06 step 8) says a consuming document's
     first act is tightening its OWN fixture file — your projector consumes ticket.*
     durably, so the Doc 07 fixture file gets tightened in your first consumer commit.
   - **Doc 03's substrate**: ScopeSets and the resolver, `RequirePermission`, the T-04
     page-capability manifest, Reverb channel-auth with Doc 03 scope, and
     `registries/permissions.php` — the catalog is CLOSED at 111 keys + the two
     additions of record (`views.share`, `ai.providers.manage`). **Verify every key
     Doc 07 names inline actually exists** — `views.share` (FR-06's shared views),
     `tickets.export` (Doc 06 SEC-05's property/timeline surface mounts with YOUR
     views), `conversations.history.full` (the §9 print path's note gate),
     `supervision.agent_status.force` (FR-28's supervisor force-status) at minimum. **If
     any key Doc 07 names is absent from the registry, STOP and ask — never invent a
     key** (NFR-03 discipline).
   - **Meilisearch** — RUNNING substrate since S0, and **nothing has ever indexed into
     it**: your T-04 search is its first real consumer. Expect index-definition +
     tenancy-scoping work early. **RLS does not protect a search index — scoping is code
     you prove**: the search proxy is the ONLY path (SEC-02), it injects Doc 03 scope
     filters server-side, the client never sends scope, and the scope-injection fixtures
     (Doc 03 AC-03 reuse per AC-09) prove no out-of-scope hit ever returns.
   - **The settings and notifications registries** — FR-26/27's sounds and desktop
     notifications need REGISTERED categories; `inbox.away.idle_minutes` (FR-28, default
     10) and any FR-11/density tunables are registered settings named per the
     conventions with platform-maximum validation; per-category toggles live in Doc 16's
     preferences surface — you register categories and ship the bell's quick mute only.
8. ADRs: **0003** (grants for any new tenant tables — R-19b rides with it), **0007**
   (queue families — your projector/rebuild jobs ride the established families),
   **0010** (R-19 clock physics — every ordering/metric timestamp is DB time), **0011**
   (durable consumers via the registry).

## Stack / context notes (not conflicts; do not stall on them)

- The stack is **Laravel 13 + Inertia + Vue 3 + TypeScript + Reverb/Echo + Redis read
  models + Meilisearch** — ALL running substrate; Doc 07's stack context reads as the
  repo you have. Any NEW frontend dependency (virtualization library, state library,
  soak-harness tooling for T-08) is stack growth: name it in the plan so the owner
  approves it WITH the plan (the R-15 pattern); if you can meet the budget more safely
  without one, say so with reasoning instead of silently choosing.
- Prose counts anywhere are descriptive; **code registries are normative** (Q9). This
  applies to your event names, your permission keys, and your settings.
- **Panels are SLOTS, stated up front**: the Context panel's Customer tab (Doc 08) and
  Copilot tab (Doc 09) are named slots with fixture-proven seams and ZERO panel logic in
  this session. The tab order is fixed (Ticket, Customer, Copilot); the badge-dot
  contracts (Copilot: new suggestion; Customer: RG banner presence) are seam fixtures.
  The inbox team owning panel internals is how workspace codebases rot — Doc 07 §21's
  own words.
- **Doc 07 RENDERS and READS; it re-implements nothing**: ticket semantics stay
  Doc 06's (every transition through `TicketTransitionService`), message semantics stay
  Doc 04's, window/composer-state semantics stay Doc 05's. Needing to edit a core file
  of those documents is an ESCALATION with evidence (the four ruled core edits of S23
  are precedent for HOW — escalate → rule → enumerate → sabotage — never precedent that
  core edits are casual).
- **Consumers that do not exist are SEAMS with fixtures, never their logic**: Doc 11
  (queue views — the view TYPE renders here against fixtures; queue membership/config is
  theirs; `agent.status_changed` routing consumption is theirs), Doc 12 (SLA chips and
  macro/saved-reply definitions — the chip slots, the `/` palette UX, and variable
  resolution seams are yours; policies and definitions theirs), Doc 15 (supervisor
  surfaces). Docs 04/05/06 ARE built: you consume real data through their doors.
- **Statuses and sources remain platform-fixed**; nothing in Doc 07 adds a ticket
  status, a message state, or a source value.
- **Channel timestamps are METADATA and every ordering/metric timestamp is DATABASE
  time** (R-19/ADR 0010): list ordering keys, `updated_at` sort scores, and any
  timestamp your read models persist obey R-19b from their FIRST migration. ZSET scores
  obey R-23's physics: INTEGER microseconds, no fractional ties.

## SCOPE

Implement **all of Doc 07** — enumerate every FR / NFR / US / BR / T / SEC / EC / FS /
SS / AC / R id from the document itself and cover each one. **State the id count YOU
found**, by family; your plan is audited against an independent count made from the same
text.

> "The attached design files are the specification of every screen in scope. Implement them
> at 100% fidelity per DESIGN-FIDELITY-MANDATE.md — every frame, state, viewport, theme,
> and language, tokens from the design system only, deviations only in the mandate's three
> classes and every one filed. Nothing skipped, nothing approximated, nothing redesigned."

**Out of scope (do not touch):**
- Ticket semantics, transitions, bulk execution internals → **Doc 06** (you consume its
  doors; §3 says so explicitly).
- Message semantics, pipelines, media internals, delivery states → **Doc 04**.
- Customer panel CONTENTS and identity resolution → **Doc 08** (the slot + fixtures are
  yours).
- Copilot/AI panel CONTENTS, AI actions execution → **Doc 09** (the slot, the composer's
  AI-actions button cluster + inline-results region, and fixtures are yours).
- Queue configuration, routing rules, membership sources → **Doc 11**.
- Macro/SLA/saved-reply DEFINITIONS and policies → **Doc 12**.
- Supervisor dashboard → **Doc 15**. Per-category notification preference surfaces →
  **Doc 16** (you register categories).

**Pre-agreed closure buckets** — the ONLY sanctioned deferrals; verify each against
Doc 07's own text and name its unblocker in the plan. Do not invent a deferral beside
them; if you believe one is missing, STOP and ask before planning around it.

| Bucket | What defers | Unblocker |
|---|---|---|
| Doc 08 panel contents | Customer tab internals, hybrid one-customer resolution surfaces, RG banner logic | Doc 08 — the named slot, badge-dot seam and fixtures are THIS session's |
| Doc 09 panel contents | Copilot tab internals, AI action execution (draft/improve/translate/summarize), sentiment production | Doc 09 — the slot, the FR-19 button-cluster + inline-results contract, the FR-09 sentiment-dot render from fixtures are yours |
| Doc 11 queue views | Queue-view GENERATION per accessible queue, membership sources, routing consumption of agent status | Doc 11 — the queue-view TYPE renders here against fixtures; `agent.status_changed` is emitted here, consumed there |
| Doc 12 definitions | SLA policy evaluation (warned/breached production), macro/saved-reply definitions and variable sources | Doc 12 — the SLA-chip slot in rows + the "SLA at risk" view against fixtures, the `/` palette UX, missing-variable warnings are yours |
| Doc 15 supervisor | Supervisor dashboard surfaces, active-time metric formulas (BR-06) | Doc 15 — activity events and dimensions are yours |
| §12 / NFR p-values | Formal L2 closure: 500 agents, 200 ev/s, the 8 h soak p-values, 10 M-message search index scale | Doc 19 — structure the budgets, enforce in CI at dev scale, measure indicatively, never fake numbers |

**The CLOSING sets — rows that close STANDING PARTIALs.** These are the opposite of
buckets: work other sessions honestly deferred TO you. Each is a mandatory plan row, and
each names the BUILD-STATUS row it updates at close:

*From Doc 05 (the [CO]/[PB] halves — handoff §4 row 1):*
- §8 composer-state RENDERING — normal / closing-countdown / expired options card /
  connection_paused / blocked_by_user / conversation-blocked, the composer as the SINGLE
  renderer (FR-20) → closes the rendering halves of Doc 05 FR-23, AC-04, US-02.
- The rescue view (Doc 05 US-04) rendered from `channel_unreachable` data — the Rescue
  system view (FR-05) plus its states.
- The FS-01 outage inbox banner (Doc 05 FS-01's render half) on the FR-03 health
  indicator's banner.
- EC-04's dual-channel badge + grouping + older-activity warning (also Doc 05 R-04's
  grouping half) in the row anatomy (FR-09).
- US-03's clearly-marked owner-phone reply render in the thread (FR-14 on Doc 05's
  attribution data).
- **AC-07's DOM half — the EXECUTED assertion that no delivered state ever renders for a
  Telegram message** (capability truth was proven at Doc 05 step 3; the DOM assertion is
  yours, and it is also a named sabotage below).

*From Doc 06 (the Doc 07-bucketed PARTIALs — handoff §4 row 2):*
- T-08 — the read models themselves (`ticket_list_items` + view ZSETs + projector).
- SEC-05 — the tickets.export property/timeline surface mounts with your views (key
  already registered; the export path is Doc 04's gate/watermark machinery, consumed).
- EC-08 — the resolved-state prompt dialog (your FR-21 send-and-set-status + EC-06
  composer re-evaluation render it).
- EC-11 — the subject-fallback snippet + retry affordance (your FR-09 row anatomy cites
  it by name).
- AC-08 — the handoff-packet card RENDERING (FR-14's pinned collapsible card).
- The merge banners/dialogs/preview (+ the merge-preview READ that Doc 06 §12 bucketed
  here).

Each closing row updates the named Doc 05/Doc 06 BUILD-STATUS row at YOUR close-out with
the executed evidence cited. Anything Doc 07 requires that is NOT in a bucket is built
and proven in this session.

## INHERITED (PROJECT-HANDOFF-v6 §4 — each is a mandatory plan-table row)

0. **The owner-rulings gate** — satisfied before this plan exists; its row cites the
   STEP 0 evidence (the three filed rulings + the filing commit SHA) and the advisor's
   confirmation. **The R-22 fill appears in this plan as a frame-mapped class-2 row**:
   tag rename/merge UI from design-system components (settings-row + confirmation
   patterns), built here, filed as a class-2 deviation per the mandate.
1. **The Doc 05 closing set** (the [CO]/[PB] halves listed in SCOPE) — one row each,
   naming the Doc 05 BUILD-STATUS row it closes.
2. **The Doc 06 closing set** (T-08, SEC-05, EC-08, EC-11, AC-08, merge surfaces) — one
   row each, naming the Doc 06 BUILD-STATUS row it closes.
3. **B12-A01's nine behaviour-acceptance rows** — the plan enumerates ALL NINE with a
   disposition each: executed-here (as a test cited by row id) / already-executed-at
   (naming the Doc 04/05 build that claimed it — its status stands) / deferred-with-
   bucket. The report maps every row into Doc 07's own sections (AC-01/02/09 → §13,
   AC-03 → §14, AC-04 → FR-11, AC-05 → FR-21, AC-06 → FR-18, AC-07 → FS-05,
   AC-08 → EC-12) — expect most to execute HERE.
4. **Shared components are CONSUMED, never re-built**: `WindowChip`,
   `ConnectionStatePill`, `HealthRollupPill`, `StepChecklist`, and Doc 04's delivery
   glyphs / tombstone / edit-marker patterns. A second implementation of any of these is
   a fidelity violation and an architecture escalation. The plan states, component by
   component, where each is consumed.
5. **`channel.health.changed` v2 gets its FIRST consumer**: the FR-03 header indicator
   subscribes → a ConsumerRegistry entry with the C-09 idempotency proof, an FS-02-style
   catch-up drill, AND the consumer contract fixture the S22 machinery demands — its
   first for this event. Transition-only semantics (A-22) are consumed, never
   re-derived: the indicator renders state, it never infers transitions from heartbeats.
6. **Read-model projectors are DURABLE consumers**: every projector feeding the
   lists/counts is a ConsumerRegistry entry with the C-09 idempotency proof and a
   kill/re-drive catch-up drill; **the T-03 counting contract's correctness is proven
   against EVENT REPLAY, not assumed** — replay a recorded event stream into a cold
   projector and assert ZCARD equals the direct scoped query (NFR-07's checker is the
   productized form). The Doc 07 consumer-contract fixture file is tightened in your
   first consumer commit (the note in the fixture binds you).
7. **The `events.created_at` deferral check (standing)**: if ANY Doc 07 read model or
   endpoint orders a read over the events table, the deferral FIRES and the R-19
   treatment lands first. **Your T-07 sync endpoint returns "ordered events since
   cursor" — this is the likely trigger. State the check's outcome in the plan
   explicitly**: either the sync cursor orders over the events table (deferral fires,
   R-19 treatment is a plan row BEFORE the endpoint), or you show what it orders over
   instead and why the deferral does not fire. "No ordered read" is a claim you prove,
   not a default.
8. **R-19b on every new table and column** — `ticket_list_items`, saved-view tables,
   selection tokens, notification rows, anything else the plan creates: per-column
   analysis in the plan (including the columns you judge NOT ordering/metric-bearing and
   why); clock_timestamp() default + both-revoked grant + schema-derived guard from the
   FIRST migration for every ordering/metric-bearing timestamp; **ZSET scores per R-23:
   integer microseconds, no fractional ties** — your view ZSETs' sort_key physics are
   stated in the plan.
9. **Route→permission mapping same-commit** from the CLOSED catalog (111 +
   `views.share` + `ai.providers.manage`). `views.share` exists for FR-06's shared
   views — **verify inline, never invent** (NFR-03). Every new inbox route lands with
   its key in the same commit.
10. **Standing PARTIALs untouched** except the rows your closing sets legitimately
    close: the [OA] rows (Doc 05 AC-03, AC-08-live, US-01-live) stay blocked on the
    dedicated staging Business account — if the owner lands it mid-document, that work
    is a SEPARATE owner-assisted addendum session (token rotation FIRST per the recorded
    boundary), never folded into this one; the Doc 19 / Doc 08 / Doc 12 / Doc 14 /
    Doc 15 buckets stay; Doc 03 SS-01/SS-02 stay. Each Doc 07-relevant standing PARTIAL
    gets a stated disposition; none silently dropped, none re-claimed without executed
    evidence.
11. **Open intermittents acknowledged**: RISK 0a (soak — isolated CI job, stays), RISK
    0d (RbacBudgets p99 — a CI red is re-run once and recorded, never loosened), RISK 0f
    (OPEN — PasswordReset throttle recurrences post-fix; the ratified disposition ladder
    binds: failure-time limiter-key snapshot FIRST, harness-isolation extension only if
    bleed confirmed, a Doc 02 session if it proves a product write; the ladder is filed,
    not yet applied — if 0f fires during your build, the ladder's first rung executes
    and is recorded, nothing more). The two single-sighting timing notes
    (EnumerationTest; TicketsBudgets linearity) become lettered risks on recurrence.
12. **Design-evidence honesty**: NO pixel baselines exist for Batches 1/2 (Batch 7's
    44-frame manifest is the only wired set); your traceability rows claim
    functional-state + a11y coverage only, per the Doc 03/Doc 05 precedent, until the
    owner fires an export. Stating this in the close-out is mandatory.

## THE SUBTLE PARTS (verified against Doc 07's text — read them there, not here)

- **The counting contract is server truth, proven by replay** (T-01/T-03/FR-07/NFR-07):
  view badge = server ZCARD pushed on delta (debounced 2 s); the client NEVER derives
  counts from its partial window; counts display-cap at "999+"; view counts show an
  em-dash until first value — never 0-then-jump (§8). EC-04's per-viewer counts (a
  shared view whose consumer lacks scope for some rows sees only their subset, with NO
  indication rows are hidden — BR-03) force the per-scope ZSET-variant-or-filtered-count
  decision T-01 flags: make it, document it in the plan, prove it in the EC-04 fixture.
- **FR-11's live-list behavior is a product commitment, not a tuning knob** (R-02's own
  words): patches in place; re-sorts BATCHED every 5 s with the "list updated — resort"
  pill when the pointer is inside the list; auto-resort only when unhovered ≥ 3 s; the
  pill appears max once/5 s; enter = slide-in + 300 ms highlight; leave = fade +
  collapse 150 ms; reduced-motion → instant with outline highlight (§9). B12-A01 AC-04
  binds this as an executed test: rows never move under a pointer.
- **FR-12/FR-13 make the list survive being alive**: cursor pagination 50/page with
  scroll-position restoration on back-navigation (B2-S01-v3 is the drawn source);
  selection survives live re-sorts BY ID (EC-02: a selected row leaving the view moves
  to a "no longer in view" tray on the bulk bar); "select all in view" mints a
  SERVER-SIDE selection token (T-05: view-hash + filter snapshot + count), user-bound
  and single-use per bulk execution (SEC-06), capped by Doc 06's bulk limits, resolving
  ids at EXECUTION with the drift report ("3 items left the view and were skipped");
  EC-05's 1k-row intent chunks per Doc 06 caps with the queued-batches drawer; mobile
  bulk caps at 25 (§8, Doc 06 §9).
- **The thread is composition, not invention** (FR-14/FR-15): Doc 04 §8/§9 rendered
  FULLY, plus date separators, ticket-boundary markers ("Ticket #4811 closed · Ticket
  #4812 opened"), the pinned collapsible handoff-packet card at its ticket start
  (Doc 06 AC-08's rendering half), bulk-collapsible muted system events ("show 12
  events"); opens at first unread else bottom; jump-to-first-unread / jump-to-latest;
  deep links `/inbox/t/{ticket}` and `/inbox/c/{conversation}` resolve with correct
  anchor (EC-01: out-of-scope deep link → the Doc 03 SEC-02 404-pattern page, NEVER
  confirming existence); reply-to click scrolls to anchor with Doc 04 EC-08's context
  fetch; EC-10's 16 KB message clamps at 40 lines with "show more" and search anchors
  still resolving.
- **Collision is warned, never blocked** (FR-16/US-07/AC-06): viewer avatars from
  Doc 04 FR-19; a colleague typing a reply in the same ticket shows the live warning
  strip (their draft is NOT shown); a send race gives the LATER sender a non-blocking
  confirm ("Sara just replied — send anyway?"); the strip is dismissible per occurrence
  and never covers composer text (§8); exactly one visible outcome ordering, proven by
  dual-session scripts.
- **The composer is the single renderer of channel state** (FR-17…22): Reply/Note modes
  sticky per ticket per user with HARD visual differentiation (BR-02: the contrast
  delta is not themeable below a minimum; the send button is labeled "Send reply" vs
  "Add note"; AC-05's wrong-mode heuristic — 50 scripted reply-intent sequences into
  note mode — plus the Doc 04 AC-04 integration proof that zero note payloads reach
  adapters); rich-lite content downgraded per channel capability at send with the
  per-channel preview hint; attachment caps from Doc 04/05 capabilities shown LIVE
  (B12-A01 AC-06: refusal names the cap, before upload); the AI-actions slot renders a
  button cluster + inline results with Doc 09 fixtures; ALL Doc 05 §8 states render
  per contract (the closing set); FR-21's send-and-set-status split button executes
  reply THEN the Doc 06 transition atomically-in-order — on transition rejection the
  reply STAYS SENT and the error toast offers one-tap retry of just the transition
  (§8's exact UX, Doc 06 EC-08's prompt style); optimistic bubble per Doc 04 events
  (B12-A01 AC-05: sending → sent → failed-with-reason, no duplicate on retry); drafts
  per Doc 04 FR-20 with the per-ticket draft chip and the >20-char unsent-draft guard;
  EC-12: a draft survives its ticket's merge (drafts key by CONVERSATION, which
  persists) with an info chip — B12-A01 AC-08's executed form.
- **Search is ONE search, scope enforced invisibly** (FR-23…25/BR-03/T-04/SEC-02/
  AC-09): the ⌘K palette's three sections (Actions / Views / Search across tickets,
  customers, messages); NO scope toggle exists anywhere — the B2-S02 ruling removed it
  and it does not reappear; the search proxy is the ONLY path, injects Doc 03 scope
  filters server-side (filterable attributes: department_id, assignee_id, team ids),
  the client never sends scope; message hits deep-link anchored + highlighted; the
  in-view filter bar composes WITHIN the current view's base filters; recent searches
  per user; FS-03's Meilisearch-down state keeps Actions/Views working with inline
  "search unavailable". Tenancy scoping of the INDEX is code you prove with a
  sabotage-backed test — RLS does not reach Meilisearch.
- **Views are definitions, not queries** (FR-05…08/T-01): the seven system views with
  their EXACT membership definitions (My Open, Unassigned, Mentions, Following, SLA at
  risk, Expiring windows — Doc 05 FR-22's closing/expired with open tickets — and
  Rescue on `channel_unreachable`); queue views auto-generate per accessible queue
  (Doc 11 fixture); saved views = filter set + sort + column config on `tickets.view`;
  SHARING requires `views.share` (verified in the catalog); shared views are read-only
  with "duplicate to edit" (AC-02's round-trip); view ordering user-arrangeable within
  sections, persisted; system-view membership fixtures must match direct queries
  EXACTLY (AC-02); saved-view recompute for 50k candidates ≤ 5 s with progress (§12).
- **Multi-tab is a convergence contract** (FR-04/FR-27/EC-03/R-04): realtime state
  converges across tabs; sounds/desktop notifications deduplicate via BroadcastChannel
  tab-leader election — leader plays ONCE; OS-focus respected (no sound when the thread
  is visible & focused); dual-tab composer drafts sync last-writer with Doc 04 EC-06's
  toast; typing indicators never self-trigger; the soak includes dual-tab runs.
- **Agent status is routing truth, not surveillance** (FR-28/BR-06): Online = routable
  (`agent.status_changed` emitted for Doc 11's consumption — seam fixture); Away keeps
  assignments, not routable, auto-set after `inbox.away.idle_minutes` (default 10, a
  registered setting) with the 60 s cancelable countdown toast; Offline = manual or
  logout; heartbeat loss > 90 s → system-away; supervisor force-status
  (`supervision.agent_status.force`) reflects WITH attribution ("set by Dawit");
  active-time metrics derive from activity events (Doc 15's), never from status.
- **Offline is truthful, reconnect is one path** (FR-29/FR-30/T-06/T-07/NFR-06/EC-07/
  EC-08/AC-07/US-06/SS-03): amber reconnecting pill within 2 s; read-only from cache;
  queued sends as visible chips flushing IN ORDER with idempotency keys on reconnect;
  `/v1/sync?cursor` is the SINGLE reconciliation path (bounded 5k, overflow → full
  refetch instruction) — subject to inherited row 7's deferral check; every optimistic
  mutation is a command object pairing optimistic-apply with a rollback handler — BR-01:
  the inbox never lies, reconcile or roll back VISIBLY; version skew → the passive
  refresh toast, never forced mid-composition; client clock skew → server-now offset
  from handshake (EC-07); reconnect storms jitter 0–10 s and honor 429-on-connect
  (EC-08); FS-05's store-corruption recovery preserves composer text ABOVE ALL — drafts
  are the last thing ever dropped (B12-A01 AC-07's executed form).
- **The shortcut map is an enumerable spec** (FR-31/SS-01/AC-08): every binding in the
  map is an automated end-to-end test; the ? overlay matches LIVE bindings; no conflict
  with browser defaults on the support matrix; localized labels with layout-aware
  physical keys (§14); US-01's keyboard-only shift is the design intent every choice
  serves.
- **Notifications are registered, deep-linked, and quiet by default** (FR-26/FR-27/
  BR-05/FS-04): the bell's event set enumerated from the text (assignment, mention,
  follower events per Doc 06 FR-18, escalation-to-me, SLA warning on mine,
  window-closing on mine per Doc 05 FR-22 opt-in, bulk-completed); each row deep-links;
  mark-all-read; the quick mute (1 h/today) on the bell; sound defaults exactly as
  FR-27 states; permission-denied → silent downgrade with the one-time hint, NEVER a
  re-prompt loop (FS-04).
- **Failure scenarios are drills, not prose** (FS-01…05): projector down → lists freeze
  correct-as-of with the 30 s staleness pill, recovery from backlog, NO agent action
  blocked; ZSET loss → prioritized rebuild (system views first) with direct-query
  fallback + perf-degraded flag; Meilisearch down per FS-03; each proven by killing the
  real thing, not by mocking its absence.
- **§13 is executed tests, not intentions**: landmarks + skip-links for the four
  regions; the list as a listbox with roving tabindex (J/K mirror arrows); live-region
  announcements (batched list changes, incoming message, collision strip role="status",
  explicit "Internal note mode" announcement); the split-button exposes all three
  actions in its menu; badge counts with accessible labels; keyboard equivalents for
  every drag; focus NEVER stolen by live updates (a documented, tested focus policy);
  bulk progress announcements at 25% increments (B12-A01 AC-09 + Doc 06 §13's
  interactive obligations, recorded there as Doc 07's browser suite).
- **§14 binds every string**: `inbox.*` en + am; ICU relative times switching to
  absolute >24 h; bidi isolation on mixed-direction snippets; locale digits in counts;
  the 🎉 empty-state token replaceable per tenant via Doc 16; new machine-authored
  Amharic joins the standing R-14 native-review queue (~180 strings already sit there;
  you will add substantially more — say so in the close-out) with destructive-tier
  strings flagged. **R-24 applies wherever operator content renders**: untranslated
  content shows fallback-language text per the Doc 01 chain, never blank, never a raw
  key, with the completeness indicator.
- **R-25 applies to the FR-03 health surfaces**: outage bars use the neutral ink ramp;
  status hues stay reserved (R-07/R-08). The channel-health dot mirrors the Doc 05
  rollup; degraded/down shows the colored dot + the banner (the Doc 05 FS-01 closing
  row).
- **The R-22 fill is in scope**: tag rename/merge UI from design-system components
  (settings-row + confirmation patterns), wired to Doc 06's T-07 chunked resumable
  rewrite (the engine is BUILT and headless — you give it its surface), frame-mapped as
  a filed class-2 row.
- **The event family is enumerated by YOU** (rule 16): expect at minimum
  `agent.status_changed` (FR-28) — enumerate from the text, state the number in the
  plan, and any in-flight addition is a FILED amendment with its document citation
  BEFORE it ships. Reverb view-delta payloads (T-02, protocol v:1) are realtime
  protocol, not catalog events — state that distinction in the plan the way Doc 04's
  RealtimePusher did; anything durable goes through the outbox + catalog as always.

## PROTOCOL (unchanged, in full)

1. **PLAN FIRST**: a table of every Doc 07 id → file(s) → proving test, plus rows for
   every INHERITED item (0–12 above), FRAME-MAPPING rows for every designed surface
   (below), and the DESIGN FIDELITY scoping row. **State the id count you found**, by
   family, and your event-family enumeration. Sequence safety tests BEFORE the features
   they constrain and justify any counter-intuitive ordering — at minimum: the
   scope-injection proofs (list AND search) BEFORE any list or search serves real data;
   the projector's C-09 idempotency + replay-counting proof BEFORE any count is
   trusted; the selection-token cap + single-use proofs BEFORE bulk wiring; the
   tab-leader dedup proof BEFORE sounds ship; AC-07's no-delivered-DOM assertion
   BEFORE the thread renders Telegram fixtures as done; the tokens-first encoding
   verification (hex-extraction re-run) BEFORE the first screen. Define this document's
   **canonical suites command in BUILD-STATUS at plan time** (standing rule 14) — every
   figure you cite at checkpoints and close-out uses it. Name any new stack dependency
   so the owner approves stack growth with the plan (R-15 pattern). **WAIT for
   approval — and the approved plan table is COMMITTED to the repo at approval, before
   step 1's first commit** (governance #9 / standing rule 12). Step 0's evidence is
   already pasted and confirmed before this table exists.
2. One commit per numbered step; registries updated as you go — permission mappings for
   every new route (**keys from the seeded catalog only**); settings named per the
   conventions with platform-maximum validation; notification categories registered;
   audit-coverage for every new admin-relevant action (view share/unshare, selection
   execution, supervisor force-status render path, tag rename/merge — with actor +
   old/new); traceability rows; the EVENT-CATALOG regenerated with closed payloads.
   **Enumerated lists grow only by FILED amendment** (standing rule 16): your event
   family and your sabotage list are amended in the committed record BEFORE any
   addition ships, cited to its document requirement.
3. **Falsify every safety mechanism you build, with a positive control for every
   assertion, sabotage BEFORE counting anything proven.** A sabotage that stays green
   condemns the TEST, not the guard — strengthen the test and keep the stronger one
   (S19 #7); and the inverse stands: a by-design green is EXPLAINED with its mechanism,
   never "strengthened" into a worse test (S22's FS-04 precedent). A live-DB sabotage
   wiped by `migrate:fresh` is INVALID and is redone at the migration level (the S22
   row-7/row-10 precedent) — file invalid attempts under honest names. At minimum,
   verified against Doc 07's text: the scope filter dropped from a read model → a
   cross-tenant/cross-scope list row appears → the scoped-list proof goes red; the
   search index fed without the tenancy scope → the scoped-search test goes red; the
   selection-token cap removed → the Doc 06 bulk-limit test goes red; the token's
   single-use guard removed → the SEC-06 reuse proof goes red; tab-leader election
   disabled → the duplicate-notification test goes red; the resort batcher bypassed
   (instant rug-pull) → the FR-11 affordance test goes red; a delivered glyph rendered
   for a Telegram message → AC-07's DOM assertion goes red (this sabotage IS the
   Doc 05 AC-07 closing proof); the collision warning suppressed → the FR-16 dual-
   session test goes red; a projector made non-idempotent → the C-09 replay proof goes
   red; the sync endpoint's ordering broken → the reconnect golden goes red. Your plan
   enumerates ITS OWN complete sabotage list (these are the floor); the ledger is
   reconciled against that list at close, item by item, never against a count
   (governance #5). Paste fail AND restored-pass, every time; backup before sabotage;
   nothing commits until `git status`/`git diff` proves the tree clean of falsification
   damage. **Every diagnostic run is captured FIRST** — full output to a file under
   `docs/evidence/` before any filtering or rerun (governance #6) — **and every verdict
   you state comes from the unfiltered capture's summary and exit code, never from a
   filtered pipe** (standing rule 11).
4. Every user-visible string is an i18n key with `en` AND `am` entries (`inbox.*` per
   §14); `string-lint` stays green; new machine-authored Amharic joins the standing
   native-review queue and destructive-tier strings (bulk execution confirms,
   tag-merge/rename confirmations, discard-draft) are flagged in your close-out per
   R-14's rider. R-24's fallback-language rendering applies wherever operator content
   renders — never blank, never a raw key.
5. Targeted tests while iterating; the FULL suite via the canonical command, plus
   `make gates`, `make lint`, once per step-commit — pasted real output; browser proofs
   under `make browser` as the Doc 05 precedent set. Push and record the CI run ID per
   the efficiency ruling — **checked ONCE, never polled (standing rule 15); a verdict
   still running is owed forward and reported at the next checkpoint.** A CI red on
   RISK 0d is re-run once and recorded, never loosened; a 0f firing executes the
   ladder's first rung (failure-time limiter-key snapshot) and is recorded, nothing
   more.
6. Finish with the full Doc 07 self-audit table (every id, DONE / PARTIAL / NOT DONE +
   one-line reason; PARTIALs cite their pre-agreed bucket; a false DONE is a Sev-1),
   the **sabotage ledger reconciled against this plan's enumerated list, item by item,
   never against a count**, the CLOSING-set outcomes (every Doc 05/Doc 06 row your
   build closed, with the BUILD-STATUS row updated), the B12-A01 nine-row disposition
   outcomes, the DESIGN-DEVIATIONS section updated in BUILD-STATUS (an unfiled
   deviation is a fidelity violation; the R-22 fill is a filed class-2 row), the
   inherited-row outcomes (0–12) cited explicitly, and the CLAUDE.md §9 report: BUILT /
   PROVEN / NOT DONE / BLOCKERS / RISKS / NEXT SESSION. **Close-out recount material
   reaches the advisor as UPLOADED FILES or plain pasted text only (standing rule 17).**

## DESIGN FIDELITY (the heaviest block of the series — this is the main event)

Per DESIGN-FIDELITY-MANDATE.md §5, which is in your reading list:

- **The drawn inventory.** PROGRAM-REPORT-v1's Batch 1+2 stack draws Doc 07's surfaces —
  **verify each against the report and the frozen directory, never assume**:
  `B1-S01-inbox-shell-v2` (the four-region shell; its reopen chip carries the assignee
  inline refusal, tag autocomplete, and KB hover preview rulings),
  `B1-S02-inbox-states-v2` (the state matrix; its reopen made the bulk-bar disabled
  reason VISIBLE AND FOCUSABLE — the program report's §7 "open item" note on this is
  STALE; the frozen v2 file governs and resolves it, per the Doc 06 verification),
  `B1-S03-views-search-notify-v2` (views, search, notifications, shortcuts; the
  four-section views sheet per FR-08), `B1-S04-ticket-operations-v1` (the operations
  surfaces — merge banners/dialogs/preview render from here),
  `B2-S01-mobile-interactions-v3` (mobile two-level nav; scroll restoration, FR-12),
  `B2-S02-mobile-search-status-v3` (mobile search/status/density; the scope toggle was
  REMOVED here per BR-03 — it never reappears), `B2-S03-ticket-properties-v2`,
  `B2-S04-state-affordances-v2`, `B2-S05-states-v2` (panels, races, mobile, sheets),
  `B12-S01-media-lightbox-v3`, `B12-S02-edit-marker-v2`,
  `B12-S03-macro-palette-v1` (FR-19's macro/saved-reply palette — drawn, frozen, in
  scope). Audits and acceptance: `B12-S00-combined-checklist-audit-v3` (the 221
  reconciled rows), `B2-S06-closing-audit-v2` — **the seven Batch 2 owner rulings live
  in its "What your rulings changed" section and BIND these surfaces** (search scope
  invisible; views sheet; assignee inline refusal; tags with NO rename/merge designed —
  now R-22's fill, YOUR class-2 row; KB hover preview; bulk bar; scroll restoration) —
  `B12-A01-behaviour-acceptance-v1` (all nine rows dispositioned, inherited row 3), and
  `B12-R01-drift-report-v1` (the 79-correction ledger — the law that components come
  from B1-DS VERBATIM, never matched by eye). Plus — always — the design system:
  `B1-DS-design-system-v2` (token source, §2 both colourways),
  `B1-DS-addendum-B5-v1`, `B1-DS-v3-addendum-B6-v1`, `B1-DS-v4-addendum-B7-v1`.
  **Access wrapper: read ONLY the named files at `design/frozen/`; screen/audit files —
  the highest version is the frozen one; the design system is v2 base plus all three
  addenda together.**
- **The scoping verification is a plan row.** State in your own reading of Doc 07
  §8/§9 + the inventory what this session builds versus what stays a slot (the Customer
  and Copilot tab INTERNALS are drawn in Batches 4 and nothing respectively — the
  Batch 4 files are Doc 08's build, not yours; your slots render fixtures). If you read
  the split differently, STOP and ask before planning UI work.
- **The plan table maps frames like ids**: for every designed surface, enumerate the
  design file's frames/rows and map frame → component/file → proving test, exactly as
  you map FR/AC ids. A designed surface with no frame rows in the plan is an incomplete
  plan. This is the largest frame-map of the series — plan the table, not a summary of
  it.
- **Tokens first, and the extraction script is re-run**: before the first screen,
  verify the existing token encoding against the design-system files by RE-RUNNING the
  committed hex-extraction script (S23's precedent — it refuted a prose claim and
  caught two invented values); all styling consumes tokens, never literals; dark values
  from v2 §2's dark column, never mixed by eye (the program's worst recorded error,
  twice).
- **Fixed tokens are law you encode, not values you choose**: status pills and priority
  dots from Doc 06 §9, the SLA chip and reply-window chip (`WindowChip` — CONSUMED),
  connection state, channel-health rollup, note tokens, and the violet AI accent (every
  AI-origin element in the composer's actions slot and results carries it — platform
  rule, §9) keep their exact values in BOTH colourways; **status hues stay reserved to
  their semantic components (R-07/R-08); generic pass/fail UI uses the neutral tokens;
  R-25's ink-ramp rule governs the outage bars.**
- **Every frame, state, viewport, theme, language**: idle/loading/empty/error/edge for
  ALL regions (§8's empty states verbatim — including "Inbox zero 🎉 — check Unassigned
  (12)" with its action), 1440 and 375 with the two-level mobile contract, light and
  dark complete, English and Amharic, density both settings, reduced-motion variants.
  Drawn states are enumerable like ids.
- **No pixel baselines exist for Batches 1/2** (inherited row 12): traceability rows
  claim functional-state + a11y coverage only and SAY SO; visual fidelity is proven by
  frame-mapped implementation + the browser suite until the owner fires an export.
- Deviations only in the mandate's three classes, every one filed in BUILD-STATUS's
  DESIGN-DEVIATIONS **and** named in your close-out. The R-22 fill is a class-2 row by
  owner ruling. Design-vs-document conflict → STOP and ask. Design gap → class-2 fill
  with design-system components, filed. Design defect → class-3 flag, wait.

Then STOP.
