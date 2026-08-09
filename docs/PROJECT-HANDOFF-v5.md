# PROJECT HANDOFF v5 — Na Live Support
_Written at Doc 06 close (2026-08-09). Supersedes PROJECT-HANDOFF v4 (written at Doc 04 close).
This file + NEXT-ADVISOR-BOOTSTRAP.md + SESSION-PROMPT-SPEC.md + DESIGN-FIDELITY-MANDATE.md
(carried forward unchanged) are the complete four-file kit for resuming the process in a fresh
advisor chat. Commit all four to `docs/` in the repo — and VERIFY the commit landed: this check
has caught a missing kit TWICE (v2's mandate; v3's three kit files at SESSION-04 launch), and at
the v4 handoff a superseded SESSION-PROMPT-SPEC generation was uploaded three times — check the
spec's first line says the CURRENT session number before committing._

---

## 1. What this is and how to use it

The owner (a non-programmer product manager) is building **Na Live Support** module-by-module
with three AI tracks: a **build track** (Claude Code terminal sessions, one per specification
document — now running on a NEW Claude account since mid-Doc 06; the repo, not the account, is
the continuity), a **design track** (Claude Design chats, dormant — program complete), and an
**advisor chat** (this role) that audits both, issues rulings, and writes the strict session
prompts. The advisor chat is replaced at each document boundary; this file is what the
replacement reads.

To resume: open a fresh Claude chat, attach this file, and paste NEXT-ADVISOR-BOOTSTRAP.md.
The new advisor's first real deliverable is SESSION-05 (the Doc 05 Telegram terminal prompt),
written per SESSION-PROMPT-SPEC.md — **but Doc 05 has a hard PREREQUISITE gate instead of an
R-18-style step 0: real Telegram credentials (a BotFather bot token AND the Telegram Business
account phone) must exist in the dev environment before the session launches, and the
session's step 0 proves them live** (see §5 and the spec's §A.0).

**Channel note (practical, learned the hard way):** terminal reports reach the advisor
reliably ONLY as uploaded files or plain pasted text. Inline document attachments from the
owner's setup arrived EMPTY at least eight times during Doc 06. If a report seems missing,
ask for a file upload (the owner can overwrite the same filename each time — content, not
name, is what matters).

## 2. The project and its main concept (the frame every fix must stay inside)

AI-first, white-label customer-support platform for betting companies. Ethiopia first:
English + Amharic, Telegram-dominant (Telegram Business connected-bot; the connection method
is a setting), plus a web chat widget; SMS is notification-only, never a support channel.
Mobile-first for customer surfaces; desktop-primary agent app. Fully operator-configurable
from the Backoffice.

Non-negotiables (violating any is a concept change; only the owner rules on one): no customer
identity verification ("staff-collected", never "verified"); AI grounding-or-handoff, never
states transaction facts, always discloses it's AI, RG responses template-only; Copilot never
auto-sends; **internal notes structurally undeliverable** (Doc 04 SEC-07 — three layers,
sabotage-proven); tickets never deleted (erasure workflow only — Doc 06 BR-01 is now BUILT as
grant physics: REVOKE DELETE on the ticket tables per ADR 0003, `tickets.delete` in the
catalog but platform-disabled, sabotage #10 proven twice); **conversation ≠ ticket**
(conversations are permanent threads, tickets are bounded ranges over them — the boundary
held through Doc 06: merge and split move LINKS, never message rows); **ordering and metric
timestamps are DATABASE time** (R-19/R-19a/R-19b + ADR 0010 — the app is structurally unable
to write them; every new table inherits this from its first migration); starvation cap and
RG-routing finality; spam/test traffic excluded from metrics (Doc 06 AC-06's one-predicate
MetricExclusion is now the built mechanism); append-only audit; opt-out absolute; **no
impersonation feature, ever** (structural sweep green through Doc 06); tenancy isolation as
physics (FORCE RLS + scope).

**Source of truth:** the 19-document series (docx in `docs/_source/`, verified md in `docs/`)
+ `CLAUDE.md` are the ONLY law. The master design document is permanently superseded (Q1).
Prose counts are descriptive; **code registries are normative** (Q9) — held in Doc 04 (the
"16 events" figure vs the 20 landed) and again in Doc 06 (the handoff's "21-event family"
reconciled with nothing; the document required 23, the catalog now carries 24 with one
awaiting its Doc 12 producer).

## 3. State of the build (all CI-proven; run IDs are the evidence)

| Phase | Status | Evidence |
|---|---|---|
| S0 bootstrap | DONE | Laravel 13.23, Docker stack (incl. egress-proxy per ADR 0008), 7-stage CI, **13 blocking gates** (ticket-status-writer joined as the 13th in S22). Runs 30393265519 / 30492124123 |
| Doc 01 Platform Foundation | CLOSED | 69-id audit: 37/30/2. Run 30613246335. AC-03 now FULLY claimed (messages+events in S20; tickets count in S22's node-kill extension) |
| Doc 02 Authentication | CLOSED — COMPLETE | 85-id audit: 61/22/2. Run 30850307901. **AC-05's literal fixture CLOSED in S22** against the real `tickets.reply` route (Q5/R-12) — nothing of Doc 02 remains anywhere |
| Doc 03 RBAC | CLOSED | 75-id audit: 59/15/1. Run 30912681250; wrap-up 4ea891f. **EC-02's granter-naming remainder CLOSED in S22** on a real ticket transition |
| Doc 04 Conversations & Messaging Core | CLOSED | Historical close figure 79/17/0 (run 31053343339 on e0655a0); **standing figure now 80/16/0** — FR-24 upgraded to DONE by S22 step 8's ticket-range export, and **AC-03's soak half is now PROVEN** (see the S21 row). Both figures recorded in BUILD-STATUS deliberately — history unfalsified |
| S21 step-0 arc (R-18) | CLOSED | The AC-03 soak root-caused to TWO defects (0a NULL-external-id artefact; RISK 0c: the ordering key read an app-server clock — a C-11 violation). Fixed under owner ruling R-19 + ADR 0010. R-18 declared closed at 3a9d0af: inverted forced-backstep controls green, soaks 19–21 green at µs precision, CI 31188058863 Stage 3b green. Stage 3b is now a permanent blocking CI job — every push yields a free soak sample |
| Doc 06 Ticketing System | **CLOSED** | Final 96-id audit: **84 DONE / 12 PARTIAL / 0 NOT DONE**, mechanically recounted by the advisor at e49f5e2 (family counts matched the advisor's independent enumeration exactly; a 13-vs-12 prose discrepancy was resolved mechanically — FR-26 PARTIAL, NFR-06 DONE, the gap booked once). Close-out CI run 31292481214 on 333c4cb, all stages green. Suites 186 passed / 2043 assertions under the canonical definition; 13/13 gates; all 12 plan sabotages executed and reconciled item-by-item, two invalid attempts filed under honest names |
| Docs 05, 07–19 | NOT STARTED | Build order: 06 → **05** → 07 → rest |

**The workflow spine is complete.** Doc 06 highlights, all falsification-proven:
`TicketTransitionService` as the SOLE status writer (FR-04's 15-edge matrix as data, all 64
cells table-tested, NFR-03 enforced by grep + encapsulation + the 13th gate); T-02's partial
unique index behind the service check with the direct-SQL bypass proof, reconciled with Doc
04's shipped `open_ticket_id` layer (fast layer = claim, index = physics, `linked_work`
bypasses both by design); the R-20 metric-timestamp trigger (migrator-owned BEFORE UPDATE,
no SECURITY DEFINER, fill-only signal columns for first-response-without-transition, golden
lifecycle fixtures shared with Doc 15 including the A-13-rider supersede-no-close golden);
per-tenant sequential numbering with backpressure-never-duplicates; the immutable insert-only
handoff packet proven at the DB layer against a Doc 09 fixture; merge/unmerge to §21's
standard (same-customer hard rule SEC-06, links-not-rows, 24 h byte-identical snapshot
restore with snapshot-diff-∅, FS-04 presence-is-authority, EC-03 transitive chains, A-16
per-link ordering semantics); split with the cap-3 `linked_work` sanction; transfer and
escalate with target-scope re-checks and the named-target fallback; ZSET+column-truth timers
with the in-transaction sweep re-check, EC-01 cancel-wins, FS-01 rebuild-delayed-never-
premature, DST fixtures; the FR-08 reopen consumer as ADR 0011's first durable subscriber
(idempotent on event_id, FS-02 catch-up drilled by killing it mid-flight); bulk with NO
verdicts held in the job — per-item live permission checks make averaging structurally
impossible (the mid-bulk revocation drill is the discriminating proof), per-tenant
concurrency cap 1, 200-cap as a DB CHECK; spam disposition with the one-predicate metric
exclusion, ≤7 d restore from event history, day-8 terminality, and the conversation block as
a genuinely separate act calling Doc 04's mechanism; **24 events catalogued (23 producing —
`ticket.fields_changed` awaits Doc 12's custom fields), consumer contracts across ALL SEVEN
consuming documents (07/11/12/14/15/16/17) with completeness enforcement** — an uncontracted
consumer relationship cannot ship, and a schema_version bump goes red per consumer until
reconciled; the ticket-range transcript export on Doc 04's exact gate through ONE shared
render path (drift structurally impossible); §12 budgets enforced in both indicative and
strict modes with real numbers all WITHIN; the FS-05 nightly invariant auditor with its
seeded-violation positive control. Interpretations A-7…A-18 filed and countersigned; rulings
R-19…R-23 in force; ADRs 0010 and 0011 accepted.

**Two platform-wide upgrades nobody planned, both from R-18's step 0:** the ordering-clock
physics (ADR 0010) and the outbox consumer dispatch (ADR 0011). Both now bind every future
document.

**The owner has been told ~40% toward MVP** as of Doc 06 close (5 of 19 documents + the
security core + the clock work).

## 4. Inherited into Doc 05 (its plan table MUST claim every one of these as a row)

0. **THE PREREQUISITE GATE (blocking, before the session even launches):** Doc 05 needs a
   real BotFather bot token and the Telegram Business account phone, live in the dev
   environment. The advisor does not deliver SESSION-05 for launch until the owner confirms
   both exist; the session's STEP 0 proves them (a real `getMe` call pasted, the Business
   connection state shown). Unlike R-18 this is owner-side setup, not engineering — but the
   session must not start on promises. If credentials are partial (bot but no Business
   account), the plan states which halves of Doc 05 are provable and which get an honest
   credential-blocked bucket.
1. **Doc 04's AC-01 re-proof bucket fires NOW**: the ChannelAdapter conformance suite (32
   capability combinations, the 12th gate) was proven with two FAKE adapters; Doc 05's
   Telegram adapter is the FIRST REAL one. The R-16 architecture proof — zero core edits for
   a new adapter — must hold or be escalated as an architecture failure, never quietly
   patched around. The conformance suite passing on the real adapter is a mandatory row.
2. **BR-07/EC-10's `channel_unreachable` semantics**: Doc 06 shipped the column and the
   orthogonality rules (the flag changes ANSWERS — e.g. refuses the automated resolve —
   never STATE; no transition ever emits from a flag flip; humans decide). Doc 05 supplies
   the setting/clearing semantics. The Doc 06 tests that pin orthogonality must stay green.
3. **The messaging pipeline is CONSUMED, never rebuilt**: inbound discipline (ACK ≤250 ms
   never-inline, SETNX dedupe 48 h, identity SEAM to Doc 08, one-transaction persist+outbox,
   quarantine with no retro-fan-out), the delivery state machine (9 legal edges; `sent`
   never rolls back), FIFO lanes, the FULL media security chain (streamed size abort, ClamAV
   sidecar, EC-11 hold-then-quarantine, SSRF-allowlisted egress proxy, signed URLs, EXIF
   strip) — Telegram media flows through the SAME chain. Rebuilding any of it is a
   violation; the plan states which existing door each Doc 05 flow enters.
4. **R-19 physics on channel timestamps**: Telegram's `date` fields are METADATA (Doc 04's
   receipt-time ordering ruling). Ordering NEVER uses them; `created_at` is DB time and the
   app cannot write it. Any new Doc 05 table with an ordering- or metric-bearing timestamp
   ships with the `clock_timestamp()` default + the R-19a column grant (INSERT and UPDATE
   both revoked) + the schema-derived guard FROM ITS FIRST MIGRATION (R-19b — Doc 06's
   tables were the first application; Doc 05's are the second).
5. **`channel.health.changed` (A-4, transition-only)**: Doc 04 filed the event; Doc 05 is
   its natural producer. Closed payloads; catalog + contracts per the S22 machinery (the
   completeness test will DEMAND a contract the moment the event gains a consumer).
6. **The `events.created_at` deferral (BUILD-STATUS numbered row 9)**: the events table's
   own `created_at` is still app-clock (write-only stream, relay cursors on outbox.id — safe
   while nothing ORDERS reads on it). Unblocker: "the first ordered read or Doc 14/15". If
   any Doc 05 work orders a read over events, the deferral FIRES and the R-19 treatment
   lands first. State the check in the plan.
7. **Route→permission mapping same-commit** (gate blocking): keys from the seeded catalog
   only — the catalog is CLOSED at 111 + the two additions of record; never invent (NFR-03).
   Channel-management keys exist in the registry; verify every key Doc 05 names inline.
8. **Standing honest PARTIALs are not re-claimed and not dropped**: Doc 06's twelve (Doc 07
   ×6: T-08 read models, SEC-05's property-export surface, EC-08's prompt dialog, EC-11's
   retry affordance, AC-08's rendering, R-02's preview friction; Doc 09: FR-03's AI subject
   generation; Doc 12 ×2: FR-26's `fields_changed` producer, US-05's escalation clock; Doc
   13: FR-14's widget caller; API wave: FR-13's HTTP route; Doc 15: AC-09's consuming
   dictionary). Doc 04's remaining sixteen and Doc 03's SS-01/SS-02 as v4 recorded them
   (minus the ones S22 closed: Doc 04 FR-24, Doc 02 AC-05, Doc 03 EC-02, Doc 01 AC-03's
   tickets count). The full bucket map lives in BUILD-STATUS; each Doc 05-relevant one gets
   a stated disposition, none silently dropped.
9. **Known intermittents (all in BUILD-STATUS):** RISK 0a — the soak's shared-suite
   corruption mechanism (open; the soak runs ALONE in Stage 3b for this reason). RISK 0b —
   `PasswordResetFlowTest` (recurred twice, undiagnosed, capture-first on any recurrence).
   RISK 0d — `Performance/RbacBudgetsTest` (three datums incl. one on a GitHub runner —
   NOT host-specific; reds full CI runs occasionally; ceiling untouched; harness question
   is Doc 19's). A CI red on 0d is re-run once and recorded, never "fixed" by loosening.
10. **The consumer-contract fixtures are pre-consumer curation**: each of the seven fixture
   files says in its own note that the consuming session's FIRST act is tightening its own
   file. Doc 05 produces more than it consumes, but if it consumes any `ticket.*` event,
   that note binds it.

## 5. Decision log (rulings in force; re-litigating any needs a new owner ruling + ADR)

**Interpretation record**: `docs/ANSWERS-FROM-DOC-AUTHOR.md` is canonical — Q1–Q9, A-1…A-18,
R-1…R-23. Where it and a numbered requirement disagree, the document wins and the session
STOPs. Everything v4 recorded stands (Q1–Q9; the Doc 02 rulings incl. the four SECURITY
DEFINER doors ceiling — held at four through Doc 06, the R-20 trigger deliberately needing
none; the efficiency ruling; webhook rulings 1–7 — about to matter again for Telegram; R-9…
R-14 incl. the catalog closure and `tickets.read` ≙ `tickets.view`; R-15…R-17; R-18 now
CLOSED, see §3).

New since v4 (all filed, all countersigned where applicable):
- **R-19 (owner, option A) + ADR 0010**: FR-07's ordering key vs C-11 — C-11 governs. The
  ORDER expression stays `(created_at, id)`; the SOURCE moves to PostgreSQL
  `clock_timestamp()` (never `now()` — transaction-start), full µs precision, UUIDv7 stays
  the tiebreak, no backfill. C-11 NARROWED in the same ruling: UUIDv7 monotonicity is
  app-clock too — tiebreak only, never a primary ordering source. **R-19a**: the app is
  STRUCTURALLY unable to write the column — INSERT and UPDATE both revoked via column-list
  grants, schema-derived guard asserting the denied set for both privileges. **R-19b**:
  every new ordering/metric-bearing timestamp ships with default + grant + guard from its
  FIRST migration. The forced-backstep controls are INVERTED as the permanent regression
  proof — deleting or relaxing them is a gate-weakening change.
- **R-20**: the FR-24 metric-timestamp trigger — migrator-owned plain BEFORE UPDATE, no
  status writes, fill-only signal columns, note-type and pre-ticket refusals in-trigger.
- **R-21**: all four `tickets.*` settings `tenant_editable: true`.
- **R-22 (OPEN owner choice, due BEFORE Doc 07)**: the tag rename/merge SURFACE — engine
  built headless in Doc 06; owner picks design-track reopen vs class-2 fill before Doc 07.
- **R-23 (owner)**: integer-microsecond ZSET scores for session-cap eviction (IEEE-double
  rounding closed RISK 0e); proven at both levels with a mixed-index no-migration test.
- **ADR 0011**: the OutboxRelay dispatches durable consumers via a registry map (event →
  queued job); idempotent on event_id (C-09); backlog catch-up = queue durability +
  re-drive; inline ephemeral consumers (RealtimePusher, bulk.progress frames) remain
  legitimate for ephemeral work. Doc 06's reopen consumer is the first entry; Doc 05 adds
  entries through the registry, never by editing relay logic.
- **A-7…A-18** (one-liners; full text in the record): A-7 ticket base-field list (+the two
  signal columns); A-8 the three event readings incl. `follower_added`/`removed` as two and
  the `ticket.*` prefix; A-9 route→key mappings; A-10 unmark-targets {new, open}; A-11
  FR-15 `visibleOnceAssignedTo` (present-tense circularity resolved); A-12 successor
  tickets `source=automation` (`previous_ticket_id` disambiguates); A-13 merge-close/
  unmerge-restore as operation doors of the sole writer, no `ticket.closed` on supersede
  (carried into the Doc 15 goldens); A-14 merged-away sources frozen out of FR-08's
  ladders; A-15 `ticket.escalation_rerouted` + payload carries `by`; A-16 per-link unmerge
  ordering semantics stand (advisor-ruled); A-17 terminal-spam fresh ticket unlinked +
  the per-tenant bulk concurrency cap=1 rider; A-18 export ranges (anchored = BR-02
  boundary authority; unanchored = lifetime; audit +ticket_id+range_basis).

**Governance record (do not repeat these — now NINE incidents plus two hardened rules):**
(1) v2's kit never committed; (2) SESSION-03's concurrent launch — the solo-process guard
has now FIRED CORRECTLY FOUR TIMES catching stale sessions (SESSION-04, twice around the
S21/S22 boundary, once at the account switch — the owner's habit of launching before
exiting is the recurring cause; the fix is one word, `exit`, typed first); (3) Doc 03's
close-out arithmetic error — the advisor recounts every table (the Doc 06 recount resolved
a 13-vs-12 prose discrepancy mechanically and found the committed table correct); (4) a
ruling not in the record is not filed; (5) sabotage-ledger drift — reconcile against the
plan's LIST, never a count (S22's ledger caught its OWN row-7 trap repeating at row 10 —
the precedent taught forward); (6) evidence destroyed by pipes — CAPTURE FIRST, and now
**hardened: verdicts come only from unfiltered capture summaries and exit codes** (two
filtered-pipe misreads occurred even after the rule existed: `tail -4` in S20, `grep|head`
in S22); (7) parallel advisor chats — ONE advisor chat per document; (8) **NEW — the #7
pattern RECURRED in Doc 06**: steps 2–4 were checkpoint-audited from a second advisor chat;
the work was sound and was ratified after consolidation, but the rule is restated harder:
when a document's advisor exists, every audit, ruling, and paste-ready block comes from
that chat only, a second chat's instructions are invalid until re-issued, and the advisor
of record flags any owner paste referencing an instruction it never gave IMMEDIATELY; (9)
**NEW — the approved plan was never committed**: Doc 06's plan table lived only in a chat
that died, forcing an advisor-anchored reconstruction mid-document. **STANDING RULE: every
approved plan table is committed to the repo AT APPROVAL, before step 1's first commit. A
plan that exists only in a chat is not approved yet.**

**Mid-document session replacement (learned in Doc 06, now procedure):** sessions may die
(context exhaustion, reboots, account switches). The replacement gets a RESUME brief: prompt
integrity check, solo-process proof, HEAD + clean-tree verification, a truncation sweep
(`php -l` every touched file) if a crash interrupted writes, then verify-by-running before
trusting anything on disk. The old session is `exit`ed FIRST. `claude --continue` is run
ONCE, in ONE window.

## 6. Standing rules (bind the advisor, the design track, and every terminal session)

v4's ten, restated in short, plus four new: (1) documents are law; silence-fills filed in
the record; (2) fixes stay inside the main concept; (3) nothing skipped/shortened/
re-litigated; (4) one fresh session per document, solo-process proven before work; plan
table first, WAIT; one commit per step; (5) closure = pasted executed output + green CI run
ID; false DONE = Sev-1; sabotage-before-counting with positive controls; a green sabotage
condemns the test; ledger reconciled against the plan's enumerated list; (6) RULING UPDATEs
reach the terminal before its next work message; (7) prompt delivery is read-from-committed-
file (`prompts/`), integrity check = last line "Then STOP." + protocol step count; (8) the
advisor recounts every self-audit table; old claims read against today's registry; (9)
design fidelity is law — frozen program at `design/frozen/` (219 files), deviations only in
the three classes, filed; (10) evidence capture first; **(11) NEW: verdicts only from
unfiltered capture summaries and exit codes — never from a filtered pipe; (12) NEW: the
approved plan table is committed at approval, before step 1 (governance #9); (13) NEW:
R-19b — every new ordering/metric-bearing timestamp ships with DB-time default + both-
revoked column grant + schema-derived guard from its first migration; (14) NEW: each
document defines its canonical suites command in BUILD-STATUS at plan time, and every
figure cited at checkpoints and close-out uses that definition** (Doc 06's precedent — it
ended a labeling hazard where different subsets quietly produced different totals).

## 7. Design track state (dormant; owner routes everything)

The frozen program (`design/frozen/`, PROGRAM-REPORT-v1 alongside) is the map. **Doc 06
built ZERO drawn surfaces** — the §8/§9 scoping verification held (every visual is Doc 07's
rendering over the keys, pointers and tokens Doc 06 shipped; §9's fixed status/priority
tokens verified against the design system in both colourways; no hue re-mixed) and the
DESIGN-DEVIATIONS statement says so explicitly. B12-A01's nine rows carry filed
dispositions.

Both Doc 06-era design gaps are now resolved or routed: the **bulk-bar disabled reason** was
CONFIRMED already resolved in the frozen `B1-S02-inbox-states-v2` (visible, focusable text
under the bar — B2-S06's hover-only note was a stale open-item row, now a settled fact); the
**tag rename/merge surface** is R-22 — an OPEN owner choice (design-track reopen vs class-2
fill) due BEFORE Doc 07, with the T-07 engine already built headless and fully tested.

For Doc 05 specifically: the drawn inventory is expected to be THIN (Doc 05 is
adapter/server work; candidate surfaces would be Backoffice channel-setup screens if the
program drew any) — but the next advisor VERIFIES against PROGRAM-REPORT-v1's inventory and
Doc 05's own §8/§9 rather than assuming the trivial case; Doc 06 taught that the scoping
verification is cheap and the assumption is not.

Other open design items (owner's): AC-09 + Batch 6 pixel exports; the Amharic native-review
queue — now FOUR documents deep, with Doc 06's priority tier named in its close-out
(`channel_unreachable_hold`, `open_ticket_exists`, `merged_superseded`,
`unmerge_window_passed`, `spam_terminal`, `not_spam`, the eight `consequence.*` strings);
label-gray re-issue; B6-S10 approver chip; Batches 5–7 acceptance files; Batches 8+ scope.

## 8. Known traps for Doc 05 (bake these into SESSION-05 — VERIFY each against Doc 05's
text; the next advisor reads Doc 05 end to end and this list is the floor, not the ceiling)

- **The conformance suite is the gate, and zero-core-edits is the claim under test.** Doc
  04's ChannelAdapter contract was proven with two fakes; Telegram is the first real
  adapter. If building it requires editing core, that is an ARCHITECTURE FAILURE escalated
  to the owner, never a quiet patch. The 32-combination suite must pass on the real
  adapter's declared capability set.
- **Business connected-bot vs Direct bot is a SETTING, not a fork.** The connection method
  is operator-configurable (a non-negotiable from §2). One adapter, capability-declared
  differences, never two code paths that drift.
- **Channel timestamps are metadata** (receipt-time ordering + R-19 physics). Telegram's
  `date`/`edit_date` never participate in ordering; they are stored as metadata. Any
  ordering- or metric-bearing column in a new Doc 05 table gets the full R-19b treatment
  from its first migration.
- **Webhook rulings 1–7 stand** (filed in the Doc 02 era, waiting for this document):
  verify them against Doc 05's webhook text before writing the prompt — they cover
  verification, replay, and error posture. Telegram's secret-token webhook validation must
  be reconciled with them, not re-invented.
- **The inbound discipline is consumed as-is**: ACK ≤250 ms never-inline; SETNX dedupe
  48 h keyed on Telegram's update_id semantics (verify the key shape against Doc 05's
  text); quarantine with no retro-fan-out; one-transaction persist+outbox. The identity
  mapping (Telegram user → customer) is a SEAM to Doc 08 — Doc 08 is not built; fixtures.
- **Telegram media rides the EXISTING chain end to end** — streamed size abort, ClamAV,
  hold-then-quarantine, egress proxy (Telegram file API calls go THROUGH the
  SSRF-allowlisted proxy — its allowlist gains Telegram's domains by config, not by
  bypass), EXIF strip, signed URLs. No second media path.
- **Bot token is a SECRET**: storage per the settings/secrets conventions in force (the
  vault question is Doc 18's; until then the env/config discipline in CLAUDE.md governs).
  The token never lands in logs, evidence captures, or test fixtures — capture-first
  discipline plus a scrub check.
- **`channel_unreachable` semantics land here** (inherited row 2): set/clear rules from
  Doc 05's text; the flag never transitions a ticket (Doc 06's orthogonality tests pin
  this and must stay green); humans decide.
- **`channel.health.changed` is transition-only** (A-4): emitted on state CHANGES, never
  heartbeats. Closed payload, versioned, contract machinery will demand its consumers.
- **Rate limits and retries**: Telegram's per-chat and global limits vs the delivery state
  machine's retry/dead-letter path — the dead-letter machinery and the re-drive view
  (R-17/A-6) already exist; Doc 05 wires into them, never builds a parallel retry stack.
- **ADR 0011 consumers**: if Doc 05 subscribes to any event durably, it's a registry entry
  with an idempotency proof and an FS-02-style catch-up drill — the pattern is established;
  follow it.
- **Sabotage candidates to verify and name in the prompt** (the plan enumerates its own
  list; these are the expected floor): a capability lie → conformance suite red; dedupe
  dropped → duplicate inbound persisted red; ACK made inline → latency proof red; media
  chain bypassed → infected-fixture red; ordering keyed on a channel timestamp → the
  inverted R-19 controls red; webhook secret validation removed → the webhook-ruling test
  red.

## 9. Open items on the owner's desk

**BLOCKING for Doc 05: the BotFather bot + Telegram Business account phone must exist in
the dev environment BEFORE SESSION-05 launches** (inherited row 0 — the advisor holds the
launch until confirmed). Then: **R-22** (tag rename/merge surface: reopen vs class-2 fill)
due before Doc 07; the Amharic native-review queue (four documents deep, Doc 06's priority
tier named); pixel exports; label-gray; B6-S10 chip; Batches 5–7 acceptance files.
Approaching: SMS gateway sandbox (Doc 14), AI provider keys (Doc 09), MaxMind key (staging
GeoIP). CI note: the GitHub Actions budget was the silent CI killer once ($0 stop-usage
budget — runs are created but never scheduled, with no error); it is now $15 at repo AND
account level; if CI ever "goes quiet" again, check the budget FIRST, and remember run
31127499107: a run with no runner is infrastructure, not a red test.

## 10. Forward plan

Doc 05 (Telegram Channel) next, per the build order 06 → 05 → 07 → rest. The loop is
unchanged: SESSION-05 per SESSION-PROMPT-SPEC.md (prerequisite gate first — see §4.0);
committed to `prompts/`; fresh solo-verified terminal; plan table independently audited (id
recount from the advisor's OWN enumeration; sabotage list; inherited rows §4.0–4.10;
fidelity scoping) **and committed to the repo at approval per governance #9**; APPROVED
with rulings; build under the efficiency ruling with checkpoint audits FROM THE ONE ADVISOR
CHAT; close-out audited with a full mechanical recount before acceptance; then the v6 kit.
First usable milestone (agents serving players on Telegram) needs Docs 05 and 07 plus a
careful Doc 09 first pass on the finished core — the owner has been told **~40% toward
MVP** as of Doc 06 close.
