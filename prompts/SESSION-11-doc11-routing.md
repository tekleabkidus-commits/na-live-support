# SESSION 11 — DOC 11: DEPARTMENTS, TEAMS, QUEUES & ROUTING
### A fresh, solo Claude Code session reads this file FROM DISK at `prompts/SESSION-11-doc11-routing.md` and follows it completely.

---

## 1 — ROLE AND THE VERIFICATION CONTRACT

You are the principal engineer and platform architect for **Na Live Support** —
25+ years building systems of the Zendesk/Intercom/Stripe class. The owner is
the product manager and is **not a programmer**: he verifies by running the
commands you give him and reading pasted output. Therefore:

- **Never claim anything works without pasting the executed output.** A test
  you did not run is a test that does not pass.
- **Never simplify, drop, shorten, or re-interpret a requirement.** Doc 11 is
  the law for this session; the registries are normative (Q9); the
  interpretation record (`docs/ANSWERS-FROM-DOC-AUTHOR.md`) has precedence
  where it speaks.
- **Ambiguity or contradiction = STOP and ask.** A contradiction found while
  reading is a plan-time STOP, not a judgment call (the S10 shape).
- Honesty about failure is rewarded in this project's record; a false DONE is a
  Sev-1.

## 2 — SOLO-PROCESS GUARD (before ANY work, including reading)

Before reading a single file, prove you are the ONLY Claude session:

1. Raw process list (`ps -ef | grep -i claude`, unfiltered) pasted into your
   report; identify YOUR OWN PID BY ANCESTRY.
2. The stale-vs-transport discriminator: a `--serve`/`--bridge` tree spawned
   the SAME SECOND as you, parented by /init, is your own transport (harmless).
   One started earlier or on a different tty is stale — kill it and VERIFY the
   kill against a fresh `ps` before believing it.
3. The S10 additions, all real on this machine: a **detached bg-pty-host
   session has NO tty and survives a closed window**; **`claude daemon run`
   RESPAWNS what you kill** — the daemon itself must die; the **Windows Claude
   Desktop bridge respawns transports** — killing it needs
   `taskkill.exe /IM "Claude.exe" /F` on the Windows side (tell the owner).
4. Repeat this guard AT EVERY RESUME and takeover. The guard STOPPED four times
   at S10 against real rivals; it is the single most load-bearing rule here.
5. Sweep `/proc` for orphaned pest/gates/probe processes before launching any
   suite; **never run Pest and `make gates` concurrently** (three invalid-red
   episodes at S10 — a `migrate:fresh` inside the gates wipes the shared test
   DB mid-battery).

## 3 — PROMPT INTEGRITY CHECK

1. You read this file FROM DISK at `prompts/SESSION-11-doc11-routing.md`.
2. State the PROTOCOL step count you counted: it must be **6**.
3. Confirm the last line of this file is exactly `Then STOP.`
All three, stated in your first report, or STOP.

## 4 — STANDING RULES FROM MINUTE ONE

All EIGHTEEN standing rules bind (authoritative list:
`docs/PROJECT-HANDOFF-v7.md` §6 — re-read it there, in-repo). Three are
restated because sessions have bled on them:

- **Rule 15 — CI once, never polled.** One look per checkpoint; verdicts owed
  forward; a red verdict = fix first and the fix's run becomes the verdict of
  record. Read the GitHub BILLING annotation before triaging any instant
  Stage-1 death. Reds triage against the 0i/0k thresholds before being believed
  real. The 0i pair's disposition is ruled — see step 0.3.
- **Rule 17 — evidence travels as PLAIN PASTED TEXT or terminal screenshots.**
  File attachments into the advisor chat have arrived empty ~20 recorded
  times. Re-print reports in the terminal for the owner to copy.
- **Rule 18 — the grounding rhythm.** Every checkpoint, countersign, and the
  close END WITH THEIR COMMIT SHA; the advisor accepts only after the owner
  pastes `git log --oneline -N` from his plain terminal. Verify the ADVISOR'S
  SHAs against the tree too — that check has caught the advisor side twice.
  CAPTURES over prose; REGISTER ROWS over summary lines. **A TRUNCATED ruling
  is not a ruling — ask for the tail, never guess it** (the S10 precedent: the
  session that refused to guess was right).

Cadence: batches → one checkpoint report → one owner grounding → advisor
acceptance. Mandatory stops only (§12). The owner dislikes micro-batching; the
per-unit discipline (commits, proofs, captures) is unchanged underneath.

## 5 — STEP 0: RULINGS-FILED PROOF + THE [D11] CLOSING SET

Doc 11 has **NO external credential gate** — routing is internal. Do not invent
one. STEP 0 is proof and enumeration, executed before the plan and reported
with its evidence:

**0.1 Rulings-filed proof — each cited to its commit, in-repo:**
- `docs/ADVISOR-RULINGS-D11.md` (R-D11-1…4) — commits with this prompt.
- `docs/S10-ACCEPTANCE.md` filed at 052e3e4 AND the BUILD-STATUS top-table
  Doc 10 row reading CLOSED/ACCEPTED citing it (both rode 052e3e4 — verify,
  no flip is owed).
- SCHEMA AMENDMENTS 2, 3 and 4 in `docs/ANSWERS-FROM-DOC-AUTHOR.md`.
- ADRs 0015–0019 present in `docs/adr/` (0018's sidecar is BUILT under a
  compose profile; 0019 remains [OA]).
- The permission catalog count **EXECUTED**:
  `php -r "echo count(require 'registries/permissions.php');"` must print
  **114**. State it. Never read the number from a file or a document.
- **Every permission key Doc 11 routes to must EXIST in the executed catalog
  or you STOP:** at minimum `org.queues.pull_in` (SEC-01),
  `reports.agents.individual` (SEC-02), the `supervision.*` family FR-15 names
  (monitor, whisper, reassign, agent_status.force, broadcast),
  `org.teams.manage`, `org.queues.manage`, `tickets.tags.manage` (EC-05), and
  the assignment keys Doc 06 already binds. **NAMED VERIFICATION:** Doc 11's
  department-administration door (FR-01/FR-18) names no key in the document's
  own text — locate the key the catalog actually holds for department
  management (grep the executed registry). If the catalog holds none, that is
  a STOP and an escalation: the catalog is CLOSED at 114 and no key is ever
  invented (NFR-03 of Doc 03; standing law).

**0.2 The [D11] CLOSING SET.** Enumerate ALL Doc 11-bucketed inheritance from
BUILD-STATUS's OWN ROWS — never a summary line, never this prompt's list. The
advisor's sweep is filed as R-D11-4 and your enumeration is checked against
the rows behind it: the tagged [D11] rows (Doc 09 FR-12, Doc 09 FR-13, Doc 08
FR-15's Doc 11 half), the Doc 03 re-proof bucket (FR-03, T-03, AC-03 + the
top-table annotation), the seam obligations on DONE rows (Doc 03 FR-09 pull-in
grants; Doc 03 EC-04's warning state; Doc 07 FR-05's queue-view fixture seam;
Doc 07 FR-28's `agent.status_changed` first consumer; Doc 06 FR-22's
fail-closed transfer scopes), and **Doc 07's Unassigned×Own-scope pin**, which
lives in the S07 close record as prose — locate the filed text and QUOTE it.
Anchor line numbers drift: RE-LOCATE every row BY ITS TEXT and cite the fresh
line numbers in the flip commits (the S10 law). Each item becomes a plan row
naming the BUILD-STATUS line it flips or annotates at close.

**0.3 Sanctioned side-rows and annotations (R-D11-1/R-D11-2):**
- The **0i wait-hardening packet** (R-D11-1): diagnose
  `tests/Browser/inbox-ticket-panel.spec.ts:333` and
  `tests/Browser/inbox-mobile-interactions.spec.ts:233` at cause; propose the
  diff (wait/setup hardening ONLY, assertions byte-untouched or explicitly
  flagged); STOP for the advisor countersign; land; prove with three
  consecutive first-attempt local full-browser greens. A product defect found
  during diagnosis = separate STOP. Do this EARLY — it unmasks Stage 4 for
  every later push.
- The **[B8]/[B9] named-debt annotations** (R-D11-2): two BUILD-STATUS rows
  citing the ruling and its trigger. Annotation only; no build work.

Report step 0 with its captures and STOP for advisor confirmation before the
plan.

## 6 — READING LIST (each file, and WHY)

No plan row comes from memory or from this prompt's summaries. Read:

1. `docs/Doc11-Departments-Teams-Queues-Routing.md` — END TO END, all 21
   sections. The law. You will enumerate every id family from it yourself.
2. `docs/BUILD-STATUS.md` — the Doc 10 close section + `docs/S10-ACCEPTANCE.md`;
   risks 0a–0l (0k: Doc 02 queued-mail, escalate-first; 0l: every browser
   fixture you add uses dedicated fixed-id rows + heal-by-reprojection, never
   a shared canon row); incidents #14–#16; the platform notes (N-20's
   clarified boundary; the `+` array-union trap; the direct-call leg; index
   DDL is migrator-owned).
3. `docs/ANSWERS-FROM-DOC-AUTHOR.md` — Q1–Q9, A-1…A-48, R-1…R-26, SCHEMA
   AMENDMENTS 2/3/4, and specifically the S22 **as-if-assigned FR-15
   interpretation** (Own is satisfied by the assignment itself; Team/Dept must
   match the ticket's department) — your assignment commits ride it.
4. `docs/adr/` — 0003 (grant physics), 0010 (DB-time), 0011 (durable
   consumers), 0015–0019.
5. `docs/DESIGN-FIDELITY-MANDATE.md` (v10) + `docs/ADVISOR-RULINGS-D11.md`
   (R-D11-3) + the frozen files named in §13.
6. **The substrate, read AS CODE — Doc 11 consumes all of it and rebuilds none:**
   - Doc 03: the scope resolver, ScopeSets, the **fail-closed T-03
     MembershipSource seam** (waiting since S19 — YOU bind it), the
     temporary-grant machinery + `rbac:expire-overrides`, RoleTemplates.
   - Doc 06: `TicketTransitionService` (the SOLE status writer and its 13th
     gate), AssignTicket/FR-15 + the claim race, `createFromHandoff`, EC-06's
     hand-back batch, the transfer door's fail-closed scope answers.
   - Doc 07: SystemViews + the queue-view seam
     (`fixtures/inbox/seams/doc11-queue-views.json`), FR-28 agent status +
     `agent.status_changed`, the Rescue view (FR-05), the S07 pin's filed text.
   - Doc 08: the RG flag storage and visibility law (FR-15's routing half is
     yours to close).
   - Doc 09: the FR-11 handoff packet + FR-12 `decision_id` +
     `{{queue_wait}}` seam, FR-13 RG detection (your `is_rg` target).
   - Doc 05: the chip component family (temporary-member chips), connection
     routing tags.
   - Platform: ConsumerRegistry + the live relay, the settings registry
     (every `routing.*` setting Doc 11 names registers), the quota engines.
7. `docs/SESSION-09-APPROVED-PLAN.md` + `docs/SESSION-10-APPROVED-PLAN.md` —
   the plan shapes yours mirrors (plan-time STOPS before step 1; safety tests
   before the features they constrain; the closing set as plan rows).

## 7 — ESCALATE-FIRST (stated before the scope, because it binds the scope)

**Docs 01–10 are ALL core.** Any edit to a file they own follows
escalate → advisor ruling → land. NEVER the reverse. The S09/S10 record is the
exemplar: packets proposed → countersigned with proving obligations → landed
with fail-first controls. Pressure points you WILL hit while reading Doc 11:

- **Doc 03's scope resolver — above all.** Doc 11 is what makes Team/Dept
  scopes non-empty for the first time. Binding the MembershipSource seam is a
  Doc 03 core edit even if the seam was built to receive you: escalate the
  binding diff, countersign, land with the transition proof (§10).
- **Doc 06's TicketTransitionService.** Routing commits assignments ONLY
  through it (T-02, actor=system). Any change to its files is a core edit; the
  sole-status-writer gate stays green throughout.
- **Doc 01's provisioner.** FR-01's seeded department set lands at
  provisioning — the A-45 precedent governs (a DECLARED consumer gets
  implemented, never a quiet hook); touching TenantProvisioner or its steps is
  a Doc 01 core edit.
- **Doc 07's files** — the queue-view seam becoming real, the Rescue view's
  population contract, the routing-record timeline expandable (a slot-fill
  packet in the S09 packet shape), and the R-D11-1 wait-hardening packet.
- **Doc 09's files** — the estimator consumption (`{{queue_wait}}`, FR-12
  decision enrichment) is wiring INTO your endpoint, ideally zero Doc 09 PHP
  lines (the S10 step-9 door precedent); if a Doc 09 line must change,
  escalate.
- **Doc 12 does not exist yet.** Business-hours calendars, SLA-at-risk inputs,
  automation rule triggers and rule-driven tag matching are SEAMS with
  fixtures (§8 buckets) — never stubs of Doc 12's logic.

## 8 — SCOPE

Implement **all of Doc 11**. Your plan enumerates EVERY id family from the
document itself and STATES ITS COUNT BY FAMILY
(FR/NFR/US/BR/T/SEC/EC/FS/SS/AC/R) — the advisor reconciles privately against
an independent enumeration; a mismatch is investigated, never averaged. Beware
the cross-document citation trap: Doc 11 cites Doc 03/05/06/07/08/09/12/15 ids
inline constantly — family boundaries come from the section text, never a
regex.

The mandate's fidelity sentence, verbatim, binds this scope:

> "The attached design files are the specification of every screen in scope.
> Implement them at 100% fidelity per DESIGN-FIDELITY-MANDATE.md — every
> frame, state, viewport, theme, and language, tokens from the design system
> only, deviations only in the mandate's three classes and every one filed.
> Nothing skipped, nothing approximated, nothing redesigned."

**Out-of-scope handoffs, named per document:** automation rule conditions and
the rule engine that CALLS routing actions → Doc 12; SLA policy math → Doc 12
(you only order by an SLA-urgency input); business-hours calendar definition →
Doc 12 (you consume a fixture-shaped contract); supervisor dashboard and queue
table RENDERING → Doc 15; monitor/whisper conversation UX → Doc 07/15
(permissions and events only here); notification delivery → Doc 16/18; widget
estimator rendering → Doc 13; L2 load p-values → Doc 19.

**Pre-agreed closure buckets — a table in your plan, each with its unblocker:**

| Bucket | What rides it | Unblocker |
|---|---|---|
| [D12] | FR-01 hours-override ref + FR-14 calendar consumption + pull-in shift-end default (fixture-shaped hours contract); FR-12 SLA-at-risk boost INPUT; FR-07's automation-route trigger; rule-driven tag matching | Doc 12 builds calendars/SLA/rules |
| [D15] | Queue-table/dashboard rendering of your data contracts; team reports; monitor/whisper surfaces | Doc 15's session |
| [D13] | Widget consumption of the estimator bands | Doc 13's session |
| [D19] | NFR-01/02 p-values under L2 (50 queues / 500 agents / 200-ticket bursts); §12 load legs | Doc 19's load environment |
| [D16/D18] | Broadcast-note DELIVERY (FR-15 emits the event; delivery is Doc 16's); Doc 18 alert wiring (FS-01/FS-04) | Their sessions |

Dev-scale indicative figures for every §12 row regardless; misses flagged
plainly (the TTI precedent). PARTIAL outside a named bucket does not exist.

**The [D11] closing set from step 0 restated as plan rows** — each naming the
BUILD-STATUS line (re-located by text) it flips or annotates at close.

## 9 — INHERITED ROWS (handoff v10 §4 — every one a plan row with a disposition)

| Row | Disposition |
|---|---|
| 0a — the 0i browser pair | DISCHARGE early per R-D11-1 (step 0.3 side-row) |
| 0b — key rotation before any production key | OWNER-ACTION, standing at every checkpoint |
| 0c — Addis AI DPA-or-switch | OWNER-ACTION before launch; not this build |
| 0d — [B8]/[B9] passes | DISCHARGE as named-debt annotations per R-D11-2 |
| 1 — no credential gate; step 0 = rulings + [D11] set | DISCHARGE at step 0 |
| 2 — [OA] Doc 05 rows parked | CARRY untouched |
| 3 — risks 0a–0l | CARRY; 0l binds every browser fixture you add; 0k reds escalate-first |
| 4 — R-14 queue | DISCHARGE per step-commit: Doc 11's `routing.*` en+am strings join it, destructive tier flagged (delete wizards, force status, RG replacement choice) |
| 5–11 — per-door `shouldRenderJsonWhen`; bounded deferral wording; Doc 18/19 buckets; Doc 12/16 seams; owner hygiene; parked items (Doc 01 QuotaTest order-dependence; partition-maintenance fold-in; the Doc 07 channel-health ↔ AI-provider cross-link) | CARRY, stated in the plan |

## 10 — THE SUBTLE PARTS (verified against Doc 11's text; cite the id when you build it)

- **THE SCOPE-WIDENING MOMENT (FR-04, T-01; Doc 03 T-03).** Today every
  Team/Dept-scoped permission resolves to an EMPTY set, fail-closed, and has
  since S19. The moment you bind the membership source, every one of them
  starts resolving to real rows platform-wide. Prove the transition
  DELIBERATELY, before any routing path exists: a before/after test pair —
  fail-closed empty pre-binding; EXACTLY the expected member sets
  post-binding (direct ∪ via-team ∪ unexpired temporary, ∩ department
  access); a foreign or unexpected row appearing anywhere = red. A scope that
  silently widens is a security event, not a bug.
- **The starvation cap (FR-12, NFR-06, BR-01).** Its VALUE is a setting
  (`routing.starvation.minutes`, default 30); its EXISTENCE is absolute. The
  property test proves: it cannot be set null/zero/absent (validation
  physics), it is exempt from EVERY other rule, no boost stack can bury a
  ticket past it, and EC-04's interaction holds (the language requirement
  drops at the cap and the record shows BOTH facts). The §8/§9 locked row
  ("Always on.") is the drawn law of the same fact.
- **RG routing is FINAL (FR-08 step 2, BR-02; FR-01, FR-03, SEC-06).** No
  rule, boost, strategy, or manual BULK action diverts RG-flagged intake from
  the `is_rg` queue — with exactly ONE exception, built exactly as narrow as
  the document draws it: individual manual reassign by RG-permitted staff,
  audited HIGH-VISIBILITY. `is_rg` is at most one per tenant. Deleting the RG
  department warns about the Doc 08/09 contracts and REQUIRES a replacement
  RG target queue (FR-01).
- **The routing decision record (FR-17, BR-06, AC-08) — with the FIRST line of
  engine code (§21).** Every automatic routing writes
  {trigger, steps[{step, input, outcome}], strategy, eligible_count,
  boosts_applied, assigned_to|waiting} to the ticket timeline as a collapsed,
  expandable system event. Replaying a record's snapshot through the T-08
  harness yields the same outcome. SEC-02: agent-level facts elide for
  non-supervision viewers (`reports.agents.individual` gates the per-agent
  details; names elided otherwise) — absence-not-redaction is the house law.
- **Routing NEVER writes ticket rows (T-02, §21).** Assignment commits through
  Doc 06's TicketTransitionService, actor=system, under the as-if-assigned
  FR-15 interpretation. The 13th gate stays green every commit. The moment
  routing writes tickets directly, two sources of transition truth exist and
  Doc 06's guarantees quietly die — the document says so in its own words.
- **Event-driven, no polling (FR-08 step 5, NFR-02, T-03).** Availability
  events → per-queue dispatcher pops the ZSET head → optimistic assign → on
  race, next candidate. Pop is READ-THEN-COMMIT; removal only on successful
  assignment (FS-02 at-least-once with Doc 06 idempotency). ZSETs mirror DB
  truth and rebuild from it (FS-01, the proven Doc 05/06 pattern) — Redis is
  never the truth.
- **Temporary membership (FR-03, FR-15, T-07, AC-05, BR-07; Doc 03 FR-09).**
  ONE source: `queue_members` kind=temporary linked to Doc 03's audited grant
  rows — the router holds NO grant authority of its own (SEC-03). Expiry is
  dual-path and each path proven INDEPENDENTLY (disable one in test; security
  holds): the 1-min ZSET sweep AND the assignment-time resolver check.
  Assignments already made persist past expiry (EC-07); no new ones. The 3×-
  in-a-week recurrence hint (BR-07) is a nudge, never an auto-conversion.
- **Determinism (NFR-03, T-08, AC-10).** The property harness — generated org
  snapshots + ticket streams replayed twice, identical assignments — runs in
  CI on the routing package. Every tiebreak documented (FR-10: least
  open-assigned → least-recently-assigned → user id).
- **The estimator (FR-13, T-06, NFR-04, FS-03, SEC-05).** BANDS only, with
  hysteresis — never counts, never names, in any customer-facing payload
  (assert the absence). Degraded flag on corrupted counters, same honest band
  contract. Consumed by Doc 09's `{{queue_wait}}` and later Doc 13.
- **Hours never touch eligibility (FR-14, EC-09).** An Online agent at 18:01
  gets assigned; hours shape estimates and templates only. No cliff.
- **Config physics (EC-10, FR-16c, FS-04).** Validation at save prevents
  defaultless departments; the orphan path is the terminal net with an alert;
  an engine exception parks the ticket in the department default with an error
  record — never lost, never looping, queryable.
- **Capacity (FR-05, FR-06, EC-01, EC-03, AC-03).** pending_customer exclusion
  toggle; per-user override (`routing.capacity.user`); the commit-time
  capacity check serializes races — ZERO over-capacity assignments under the
  concurrent-load test.
- **FR-04's config-error exclusion**: an agent in a queue of a department they
  cannot see is EXCLUDED from routing and badged — the warning state Doc 03
  EC-04 has been holding data for since S19.
- **Events (FR-19).** The org.*/ticket.routed/routing.*/rescue.* family enters
  the catalog by filed amendment; EVERY routing consumer is a durable consumer
  (ADR 0011): registry entry + drill pin + the Doc 06 NFR-06 fixture in the
  SAME commit. N-20: no channel conditionals anywhere in this engine — routing
  tags are data.

## 11 — PROTOCOL (exactly 6 steps)

**Step 1 — PLAN FIRST.** Read everything in §6. Enumerate Doc 11's ids by
family and state your counts. Raise your PLAN-TIME STOPS before step 1 of the
build — a contradiction or silence found while reading is a stop, not a guess.
Known stop-candidates you must address (with evidence, not from this prompt's
authority): the department-administration permission key (step 0.1's named
verification); the FR-01 seeded-department provisioning path (Doc 01 core-edit
shape, A-45 precedent); the Unassigned×Own-scope pin's revisit (quote the S07
filing; present options for the OWNER ruling); the routing-record timeline
expandable's slot-fill packet (Doc 07 shape). Sequence safety tests BEFORE the
features they constrain — at minimum: the scope-resolution transition proof
and the starvation-cap property test BEFORE any routing path exists. Define
the canonical DOC 11 SUITES command at plan time. One row per id; buckets per
§8; the [D11] set as rows; the sabotage list enumerated (§ step 3). Deliver
the plan, WAIT for the advisor's audit and the owner's APPROVED, then COMMIT
the approved plan as the plan of record before the first build commit.

**Step 2 — BUILD, one commit per step.** Registries as you go (every
`routing.*` setting, every event, every audit action, every AC traceability
row). Gates every commit (`make gates` bare — no pipes, no `;`). Enumerated
lists grow ONLY by filed amendment, filed BEFORE the run. Schema changes:
R-19b per-column analysis + ADR-0003 grant physics + a filed SCHEMA AMENDMENT
countersigned BEFORE the migration ships — Doc 11's tables (departments,
teams+team_members, queues+queue_members, user_skills, routing_decisions) are
certain; expect the amendment early. DB-time only (C-11); pinned-provenance
copies for anything ordering-relevant.

**Step 3 — SABOTAGE, floor of TEN, grown only by filed amendment.** Verified
against Doc 11's text; the ledger reconciles item-by-item at close against the
plan's enumerated list, never a count. The floor MUST include: at least one
**scope-widening falsification** (a poisoned membership source yields a
foreign row → the scope suite goes red at the named line) and at least one
**starvation-cap falsification** (cap nulled/disabled/boost-buried → the
property test goes red). Expected siblings, verify each: RG demotion attempt →
BR-02 fixtures red; commit-time capacity check dropped → AC-03 over-capacity
red; routing writes a ticket row directly → the 13th gate red; a trigger path
skips the decision record → AC-08 completeness red; a polling loop introduced
→ the structural no-polling assertion red; one temporary-expiry path disabled
→ AC-05's independence proof red (both directions); estimator payload carries
a count or name → SEC-05 red; RR pointer resets on join/leave → T-04 fairness
red; ZSET pop removes before commit → FS-02 red; record elision dropped →
SEC-02 red. POSITIVE CONTROL per assertion; captures FIRST; fail AND
restored-pass pasted; an invalid green is filed under its honest name and
re-executed hardened.

**Step 4 — i18n en+am on every user-visible string**, through the R-14 queue,
destructive tier flagged (delete/restructure confirmations, force-status, the
RG replacement choice). Estimator bands localized with culturally sane
phrasing; routing-record sentences from templated ICU keys; real Ethiopic in
browser fixtures with the 375 no-overflow leg (the S10 lesson: the Amharic leg
catches what every English test passes).

**Step 5 — FULL correctness battery at every step-commit regardless of BAT1.**
Timing legs on WALL POWER only; "timing not taken" said plainly; a second
re-run to chase a green is verdict-shopping — refuse it. CI once per
checkpoint (rule 15). Browser fixtures under the 0l heal+reproject law:
dedicated fixed-id rows, heal-by-reprojection, never a shared canon row;
`octane:reload` before every browser run. **Never Pest and gates
concurrently; sweep /proc before relaunching anything.**

**Step 6 — THE CLOSE-OUT.** Full self-audit table, EVERY id itemized (no
grouped rows); ledger item-by-item; the [D11] closing-set flips with fresh
line numbers; DESIGN-DEVIATIONS final; inherited-row outcomes; the core-edit
enumeration with countersign citations; the honest trail; R-14 totals with
the destructive tier; final battery + the CI look once. The close-out ends
with its SHA and the OWNER GROUNDS IT before the advisor recount issues.
Nothing is CLOSED until the advisor's recount passes and the owner accepts on
a green verdict of record.

## 12 — CADENCE

Batches; ONE checkpoint report per batch ending with its SHA; one owner
grounding per checkpoint. The SEVEN mandatory mid-batch stops — and only
these: (1) a ruling is needed; (2) a guard conflict; (3) a red battery; (4) a
core-edit boundary; (5) an enumeration amendment; (6) a schema countersign;
(7) a design conflict or gap. Everything else proceeds without asking.

## 13 — DESIGN FIDELITY

`docs/DESIGN-FIDELITY-MANDATE.md` (v10) binds; **R-D11-3 is the design ruling
of record — Doc 11 is a SPLIT, not an Option A document:**

- Build **backend-first** (Doc 11 §21's own order).
- Doc 11's own UI steps bind at FULL fidelity against
  **`design/frozen/B5-S09-organization-v1.dc.html`** (structure admin,
  J01–J15) and **`design/frozen/B5-S10-restructure-wizards-v1.dc.html`**
  (wizards, J16–J21), plus always the system:
  `B1-DS-design-system-v2` §2 (both colourways) + addenda `B5`, `B6` (v3),
  `B7` (v4). Re-run the committed hex-extraction script before the first
  screen; tokens only, never literals. FRAME-MAPPING ROWS are mandatory for
  every built surface — enumerate the frames from the frozen files
  themselves, ls-verified, never from this prompt.
- The recorded Batch-5 mobile deviation (wizard execution, the v3 ruling) is
  ALREADY FILED — cite it; do not re-file it.
- Gaps (the routing-record timeline expandable; any queue-detail state no
  frame draws) are CLASS-2 fills from the nearest drawn pattern (the B12
  timeline system-event idiom; B5-S02's drawn rows where applicable) — each
  is a MANDATORY batch stop for the owner's ruling naming the fill. A
  conflict with Doc 11's text is class 1: the document wins, both citations
  filed. Stale frame references are STRUCK. Drawn laws that bind here: legal
  next states ABSENT not greyed; identical controls at 375; refusals inline;
  server-computed stamps; proofs assert VISIBILITY; absence not redaction
  (SEC-02's elision); labeled degrade never a silent empty (FS-03/FS-04
  states); confirmation text computed from the SAME query that acts (the
  wizard's typed confirmations).
- `B5-S02-interventions-v2` surfaces whose rendering Doc 11 §3 assigns to
  Doc 15/07 are SEAMS: you supply data contracts; the frames bind those
  sessions.

## 14 — HARD PROHIBITIONS

No secrets or PII in prompts, commits, or captures. No invented permission
keys — the catalog is CLOSED at 114. No document-file edits without
escalate-first. No routing write to ticket rows outside TicketTransitionService.
No second implementation of an existing check. No green claimed without pasted
output. No CI polling. No second Claude process. No Pest beside gates. No
PARTIAL outside a named bucket. A false DONE is a Sev-1.

Then STOP.
