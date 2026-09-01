# SESSION-12 — Doc 12: Automation, SLA, Forms & Custom Fields
_Read from disk at `prompts/SESSION-12-doc12-automation.md`. Integrity facts
about this prompt: the PROTOCOL has exactly **6 steps**; the last line of this
file is exactly `Then STOP.` State both in your first report or stop._

## §1 — ROLE + VERIFICATION CONTRACT

You are a principal engineer with 25+ years building systems of the
Zendesk/Intercom/Stripe class. You are building Doc 12 of Na Live Support —
the workflow layer: rules engine, SLA, business hours, macros, custom fields,
form builder, CSAT. The owner is NOT a programmer; he verifies by pasted
executed output. Nothing is ever claimed working without pasted executed
output. Ambiguity or contradiction = STOP and ask the advisor. A false DONE is
a Sev-1. You may correct the advisor's kit or plan with repo evidence — the
S11 record shows the session was right to hold twice; evidence wins.

## §2 — SOLO-PROCESS GUARD (before ANY work, including reading)

Raw process list; prove your own PID by ancestry; repeat at every resume.
Carry the FULL rival recipes: a detached bg-pty-host session has NO tty and
survives a closed window; `claude daemon run` RESPAWNS what you kill (kill the
daemon itself); the Windows Claude Desktop bridge respawns `--serve`/`--bridge`
transports — `taskkill.exe /IM "Claude.exe" /F` on the Windows side; the
stale-vs-transport discriminator (same-second same-parent = own transport;
earlier/different tty = stale, kill it). Verify every claimed kill against
`ps` before believing it. Two spaced looks. Sweep `/proc` for orphaned
pest/gates processes before relaunching anything. NEVER run Pest and gates
concurrently (three invalid-red episodes on record — a `migrate:fresh` inside
the gates wipes the shared test DB mid-battery).

## §3 — PROMPT INTEGRITY CHECK

Read this file from disk at its exact path; state the PROTOCOL step count (6);
confirm the last line is exactly `Then STOP.` All three or stop.

## §4 — STANDING RULES FROM MINUTE ONE

All EIGHTEEN bind (authoritative list: `docs/PROJECT-HANDOFF-v7.md` §6 —
re-read it in-repo). Restated here:
- **Rule 11**: verdicts only from unfiltered capture summaries + exit codes —
  never the Tests: line alone, never through a pipe on gates.
- **Rule 15**: CI once per checkpoint, never polled; billing annotation read
  FIRST before any triage; recorded-class reds (0i/0k/0l/0m) get their class's
  ruled disposition, not a chased re-run; a red close look = fix first, the
  fix's run is the verdict of record.
- **Rule 17**: evidence as pasted text or screenshots — attachments arrive
  empty (~20 recorded).
- **Rule 18**: grounding both ways — every checkpoint/countersign/close ends
  with its commit SHA and is real only after the owner's plain-terminal
  `git log` paste; captures over prose; register rows over summaries; a
  TRUNCATED ruling is not a ruling — ask for the tail from the truncation
  point exactly, never a re-summary, never a guess. Every advisor ruling block
  ends with "END OF RULING — nothing follows this line"; if that line is
  absent, the ruling is incomplete — hold.

## §5 — STEP 0: RULINGS-FILED PROOF + THE [D12] CLOSING SET

No external credential gate (R-D12-3: notify arms are internal registry
contracts; the SMS action is Doc 14's template seam). Prove in-repo, each
cited to its commit:
1. `docs/ADVISOR-RULINGS-D12.md` + `docs/S11-ACCEPTANCE.md` (1dc3620) filed.
2. SCHEMA AMENDMENTS 2–5 present in ANSWERS; ADRs 0003–0019 present.
3. The catalog count EXECUTED:
   `php -r "echo count(require 'registries/permissions.php');"` — expect 114,
   state the number. Then verify EVERY permission key Doc 12 routes to EXISTS
   in the registry: automation.rules.view / automation.rules.edit /
   **automation.rules.activate** (FR-07 names it distinctly),
   macros.personal.manage, macros.shared.manage, forms.view, forms.edit,
   **fields.manage** (FR-17, HIGH), sla.policies.view, and any
   sla-policy-edit key the document's doors imply. **A missing key is a
   MANDATORY PLAN-TIME STOP** — the addition-of-record path (R-D10-1 shape:
   owner sign-off, ONE commit carrying registry entry + CatalogSnapshotTest
   pin moves + route mapping) is the only way the catalog grows. No invented
   keys.
4. Routine lines ordered by the advisor (one commit together): (a) BUILD-
   STATUS's [B8]/[B9] debt rows re-annotated citing R-D12-1's hard trigger;
   (b) the Batch 8 freeze FILING — `git mv design/batch-8/* design/frozen/`
   (the freeze at cfe305b is on record; the files never moved; ls-verify the
   new count and state it).
5. THE [D12] CLOSING SET: enumerate ALL [D12]-bucketed rows from BUILD-
   STATUS's OWN rows yourself (grep, then read each hit in context). Your
   enumeration is checked against the advisor's R-D12-4 sweep — a mismatch is
   investigated, never averaged. Each item becomes a plan row naming the
   BUILD-STATUS line it flips (rows 1–7 of R-D12-4) or annotates (rows 8–12)
   at close. Item 12 (the Doc 05 connection-tag reopener) is a plan-time
   VERIFY: state whether Doc 12's rule-driven tags subsume it or neighbor it,
   and raise the ruling.

## §6 — READING LIST (each with WHY; no plan row from memory or this prompt's summaries)

1. `docs/Doc12-Automation-SLA-Forms-Custom-Fields.md` END TO END — the law.
   Enumerate every id family from it and state your count BY FAMILY in the
   plan (the advisor reconciles privately).
2. `docs/BUILD-STATUS.md` — the S11 close section (verdicts, the six-look
   honest trail, the 0l scene-budget lessons), risks 0a–0m, the platform
   notes, every [D12] hit in context.
3. `docs/ANSWERS-FROM-DOC-AUTHOR.md` — Q1–Q9, A-1…A-58 (A-50 membership
   physics, **A-53 HoursContract** — the seam you make real, A-55 tenant
   default, A-56 pull-in key, A-57 one-live-pull-in, A-58 estimator constant),
   R-1…R-26, SCHEMA AMENDMENTS 2–5.
4. `docs/adr/` 0003–0019 — 0011 (durable consumers: registry + drill pin +
   Doc 06 NFR-06 fixture in the SAME commit) binds every rule trigger and
   timer consumer you add.
5. THE SUBSTRATE AS CODE:
   - Doc 11's routing doors: `RoutingPipeline`, `PlaceInQueue`,
     `AssignTicket::assignBySystem`, `BoostMatcher` (the sla_at_risk seam),
     `Hours/HoursContract` (A-53 — read its fixture shape before replacing
     it), `QueueWriter` (the doc12-rules.json seam), `ReferenceInventory`
     (the sla_policies slice), the routing decision record (FR-17 trigger
     vocabulary already carries "automation").
   - Doc 06: `TicketTransitionService` (FR-03's ONLY status door),
     `TicketTimers`/the ZSET pattern (T-03 — your SLA timers ride the SAME
     infrastructure), `AssignTicket`, FR-24 timestamps (first_response_at —
     your FR-09 clears on it), the escalation door (FR-23).
   - Doc 10 P7: `MenuDesigner::bindTarget` + `telegram_flow_targets` CHECK —
     the committed contract whose ENGINE you build.
   - Doc 09: the classification outputs (FR-02's AI conditions), the decision
     row's CSAT seam (FR-09), the TaskCatalog (run AI task actions name
     EXISTING tasks only — the catalog grew to 15 by A-46; no silent growth).
   - Doc 08: FR-15's suppression contract (your suppress action's consumers),
     EC-09's retired-field render, customer_field_values (SCHEMA AMENDMENT 2
     addition 9 — your FR-19 customer-entity storage EXISTS; verify before
     minting a rival table).
   - Doc 05: the flow state machinery your FR-21 Telegram renderer drives;
     the window events (telegram.window.closing|expired triggers).
   - Doc 01: outbox/EventPublisher, the settings registry (every Doc 12
     setting registered), the webhook-out signing (SEC-02 reuses it),
     ConsumerRegistry + the relay.
   - `registries/` all seven; `EVENT-CATALOG.md` (your event families grow
     only by filed amendment).
6. `prompts/SESSION-10-doc10-kb.md` + `prompts/SESSION-11-doc11-routing.md` +
   `docs/SESSION-11-APPROVED-PLAN.md` — structural exemplars; S11 ran 15
   steps / 4 batches to a recount with zero landed-then-escalated core edits.
7. `docs/DESIGN-FIDELITY-MANDATE.md` (v11) + `design/frozen/B12-S03-macro-
   palette-v1.dc.html` + `design/frozen/B3-S05-identity-forms-hours-v1.dc.html`
   — the two BINDING SEAMS (R-D12-2.3); read their contracts before shaping
   macro or form definitions.

## §7 — ESCALATE-FIRST (stated BEFORE scope)

Docs 01–11 are ALL core. Any edit to a file they own: escalate → advisor
countersign → land. NEVER the reverse — S11 ran seven packets and one side-row
at zero landed-then-escalated; that is the standard. Doc 12's named pressure
points, found while reading:
- **Doc 06's TicketTransitionService** — FR-03's set-status action and BR-02's
  no-bypass law. The engine CALLS it; an illegal transition FAILS THAT ACTION
  with a logged reason and the chain continues. Any "just this once" write
  around it is the death of Doc 06's invariants (Doc 12 §21's own words).
- **Doc 11's placement doors** — assign/transfer actions go through
  RoutingPipeline/PlaceInQueue/AssignTicket ONLY. The decision record shows
  trigger=automation distinctly (the vocabulary already exists — consume it).
- **The HoursContract replacement (A-53)** — a Doc 11-owned seam; the
  replacement is a countersigned packet with the fixture's PRE state kept as
  a control (the MembershipTransitionTest precedent).
- **Doc 03's doors for every condition** — any condition the engine evaluates
  that Docs 03/06/11 already enforce (permissions, scope, status legality)
  CALLS the owning door. Automation is where second-implementation pressure
  peaks; a second implementation of an existing check is prohibited.
- **Doc 09's TaskCatalog** — run-AI-task actions bind to existing catalog
  entries; growth is a filed amendment.
- **Doc 08's suppression consumers and customer_field_values** — consume,
  never rebuild.

## §8 — SCOPE

Implement ALL of Doc 12. Enumerate every id family from the document itself
and STATE YOUR COUNT BY FAMILY in the plan.

**The fidelity sentence, verbatim:** "The attached design files are the
specification of every screen in scope. Implement them at 100% fidelity per
DESIGN-FIDELITY-MANDATE.md — every frame, state, viewport, theme, and
language, tokens from the design system only, deviations only in the mandate's
three classes and every one filed. Nothing skipped, nothing approximated,
nothing redesigned." For Doc 12 this binds through R-D12-2: the two SEAM files
bind as contracts; your own surfaces ride [B12-batch] (see §13).

**Out-of-scope handoffs (name each in the plan):** routing algorithms →
Doc 11 (call its actions); SMS template content and delivery → Doc 14 (the
send-SMS action triggers a template by ref — a fixture-proven seam);
AI task internals → Doc 09 (consume outputs, call catalog tasks); report
formulas and the CSAT/SLA dashboards → Doc 15 (you supply golden fixtures);
the widget's rendering engine → Doc 13 (you supply the versioned form JSON
contract — B3-S05 binds ITS render later); notification delivery → Doc 16
(ids-only contract fixtures); audit visibility classes → Doc 17.

**Pre-agreed closure buckets (table in the plan, each with its unblocker):**

| Bucket | Content | Unblocker |
|---|---|---|
| [D13] | Widget form render + widget CSAT star row + widget survey states | Doc 13's session renders the contract |
| [D14] | send-SMS action's live delivery; quiet-hours consumption | Doc 14 gateway + templates |
| [D15] | SLA/CSAT/macro-usage report formulas + dashboards | Doc 15 (goldens are the shared contract) |
| [D16] | notify-action delivery + rubric content | Doc 16 delivery machinery |
| [D17] | High-risk audit visibility classes (SEC-06) | Doc 17 |
| [D19] | NFR-01…05 at L2 load | Doc 19 (dev-scale figures stated plainly per row, misses flagged — the NFR-05/TTI precedent) |
| [B12-batch] | Pixel fidelity of Doc 12's own surfaces | The R-D12-1 design track + fidelity session |
| [OA] | Anything owner-blocked, named plainly | The named owner action |

The [D12] closing set (§5.5) restates as plan rows. Every §12 budget row
carries a dev-scale figure with misses flagged plainly.

## §9 — INHERITED ROWS (handoff v11 §4, dispositions in the plan)

| Row | Disposition |
|---|---|
| 0a [B8]/[B9] debt | DEFERRED per R-D12-1 — step-0 annotation only; the hard trigger fires post-S12 |
| 0b staging-key rotation | OWNER action, restated at every checkpoint |
| 0c Addis AI DPA-or-switch | OWNER action [OA], restated |
| 0d staging Telegram Business account | Parked [OA]; do not unpark |
| 1 [D12] closing set | THE HEART — §5.5's plan rows |
| 2 Risks 0a–0m | Standing; 0l binds every browser fixture AND scene budget (S11's lessons: dedicated customers per scene ticket, no census-fragile depth pins, no timeline-sensitive exact-head pins — assert what holds on ANY census) |
| 3 R-14 queue | Doc 12's am strings join it; destructive tier flagged (rule activation/deactivation confirms, field deactivation, form publish, the rules-pause switch) |
| 4 A-57/A-58 | Filed interpretations with reopeners — your SLA/estimator work TOUCHES A-58's constant: verify, don't disturb without a ruling |
| 5 Platform NNRR | per-door shouldRenderJsonWhen evidence continues; partition fold-in and QuotaTest order-dependence stay parked |
| 6 WSL recipes | Windows-gateway nameserver; MTU 1350 on TLS hangs; the wsl.conf permanent fix still owed (owner) |

## §10 — THE SUBTLE PARTS (each verified against Doc 12's text; cite ids)

1. **The SLA clock is a PURE, PROPERTY-TESTED LIBRARY built FIRST** (§21
   recommendation 1; T-04; AC-03). Interval math: elapsed = Σ active ∩
   business-hours calendar − paused-interval UNION (EC-04 — overlapping pause
   conditions union, never double-subtract). The retarget formula is
   documented physics: remaining_new = remaining_pct × target_new (EC-03).
   Property tests + golden timelines shared with Doc 15. This is the
   starvation-cap precedent: physics, not configuration.
2. **Snapshot semantics are contracts** (BR-03, FR-09, T-05, EC-05): armed
   instances embed the RESOLVED calendar and policy — never refs. Policy and
   calendar edits are prospective-only; the falsification (edit mutates an
   armed instance) must go red.
3. **Guard caps are absolute platform physics** (FR-04, BR-05): ≤10 rules
   per event, ≤20 actions, chain depth ≤3 (automation_depth in event payload
   metadata, T-03), per-rule cooldown keyed on (rule, ticket) (EC-12).
   Property-test the caps; a cap configurable above the platform limit is not
   a cap. Loop-guard trips log + alert.
4. **BR-02, no bypass powers**: every action through the owning door;
   per-action isolation (a failed action logs and the chain CONTINUES —
   AC-01); EC-01's superseded_state honesty when position order acts on
   already-changed state.
5. **Dry-run parity** (FR-05, AC-02): dry-run verdicts EQUAL live evaluation
   over a replayed corpus — the Doc 09 AC-03 simulation-vs-replay pattern;
   zero side effects proven, not assumed.
6. **SEC-01 activator-capability floor**: rule actions execute as a system
   actor bounded by what the ACTIVATOR held at activation, captured on the
   revision; drift surfaces as warnings; the falsification (demoted author's
   rule out-privileges) must go red.
7. **No blank variables ever reach a customer** (FR-15, BR-04, T-06): ONE
   server-side resolver shared by preview and send; enforcement at the send
   API, not only UI. No free-text SMS from rules (template-only).
8. **FR-07 edit-vs-activate split**: distinct permission keys; edits to an
   active rule create a DRAFT revision; activation swaps atomically; revision
   history with diff.
9. **Field lifecycle physics** (FR-17/18, EC-08, EC-14): keys immutable and
   never reused; type changes PROHIBITED; deactivation → read-only with
   retired tag (closes Doc 08 EC-09); option removal keeps stored label
   snapshots; where-used completeness (EC-14) before deactivate. fields.manage
   is HIGH. Verify Doc 08's customer_field_values before creating storage —
   FR-19 says per-Doc-01 conventions and the table may already exist.
10. **Forms are versioned contracts** (FR-20/22, BR-06, EC-06/07): immutable
    versions; submissions snapshot {version_id + answers}; publish validation
    blocks (conditional-references-retired, required-when-visible EC-07,
    language completeness, upload-capability); a mid-form Telegram flow
    completes on ITS version (checkpointed ref); FS-04's Redis-loss resume
    from the DB checkpoint is a DRILL, not a comment.
11. **The Telegram flow engine closes Doc 10 FR-19**: one field per message,
    inline buttons (direct) / numbered text (business), 3-attempt-then-human,
    mid-form handoff preserves partial data flagged incomplete into the
    packet (SS-03, Doc 09 FR-12's form_data), native photo/document for file
    fields. It mounts on MenuDesigner's committed bindTarget contract.
12. **CSAT is measurement, not marketing** (FR-24/25, BR-07, SEC-05, EC-11):
    one survey per resolution event; cooldown- AND RG-suppressed (the Doc 08
    FR-15 contract); token-bound unguessable endpoints, single-response
    server-side with the 1 h edit window; 72 h expiry with csat.expired;
    scores ≤2 raise the Doc 09 FR-20 review item.
13. **SLA timers ride Doc 06's T-03 ZSET infrastructure** — next-fire-at
    scheduling, recompute on every state-affecting event, ±30 s accuracy
    (NFR-02 measured); FS-02: fires late-not-early, honest backdating flagged
    late_fired. Every timer/trigger consumer is a DURABLE consumer (ADR 0011:
    registry + drill pin + Doc 06 NFR-06 fixture, SAME commit).
14. **The rules-pause switch ships in v1** (R-04) under features.toggle —
    tenant-wide, instant, in the incident runbook beside Doc 09's kill
    switches.
15. **Schema**: T-01 names ~12 tables → expect SCHEMA AMENDMENT 6 EARLY, with
    R-19b per-column analysis + grant physics, countersigned BEFORE the
    migration. EAV-vs-jsonb for field values is a FILED interpretation
    (FR-19's own text + existing substrate), never a silent choice.

## §11 — PROTOCOL (exactly 6 steps)

1. **PLAN-FIRST.** Read everything in §6; enumerate Doc 12's ids by family;
   produce the plan table (one row per id; counts by family; the [D12]
   closing set as rows; inherited rows dispositioned; buckets as §8's table;
   frame/seam-mapping rows for the two binding seams). PLAN-TIME STOPS raised
   BEFORE step 1 — at minimum: the catalog-key verification (§5.3), the
   connection-tag ruling (§5.5 item 12), the EAV-vs-jsonb interpretation, the
   SCHEMA AMENDMENT 6 shape, and any contradiction found while reading.
   Safety tests SEQUENCED BEFORE the features they constrain: the SLA clock
   property suite and the guard-cap property tests BEFORE any engine line;
   the BR-02 no-bypass sweep and SEC-01 floor test BEFORE any action
   executes; the snapshot-immutability falsification BEFORE policy editing
   ships. Define the canonical DOC 12 SUITES command at plan time. WAIT for
   advisor approval; the approved plan commits at approval (rule 12).
2. **BUILD.** One commit per step; registries as you go; gates every commit;
   enumerated lists grow only by filed amendment; schema = filed amendment +
   R-19b + grant physics countersigned BEFORE the migration; every
   escalate-first packet proposed → countersigned → landed with a fail-first
   control.
3. **SABOTAGE.** Floor of TEN, grown only by filed amendment, the document's
   mandated falsifications named — at minimum: (a) a guard cap removed →
   loop/blast test red; (b) a rule writing status AROUND the transition
   service → BR-02 sweep red; (c) a blank variable reaching the send API →
   T-06 red; (d) an armed instance mutated by a policy edit → BR-03 red;
   (e) the activator-capability floor dropped → SEC-01 red; (f) the webhook
   allowlist/SSRF egress dropped → SEC-02 red; (g) a second CSAT response
   accepted → SEC-05 red; (h) a field type change permitted → FR-18 red;
   (i) required-when-hidden publish permitted → EC-07 red; (j) pause-interval
   double-subtraction → EC-04 property red. Positive control per assertion;
   captures first; no pipes on gates; invalid greens filed under honest names
   and re-executed hardened.
4. **i18n.** en+am throughout (workflow.* admin strings; form labels per
   language with completeness gates; Telegram prompts localized per the
   FR-20 resolution chain; CSAT button labels; ICU-templated SLA example
   sentences; locale-formatted variable resolution). Machine-authored am
   joins the R-14 queue; destructive tier flagged.
5. **BATTERY + CI.** Full correctness battery every step-commit regardless of
   BAT1; timing legs wall-power only ("timing not taken" said plainly; a
   second re-run chasing green is verdict-shopping); CI once per checkpoint,
   billing annotation first; browser fixtures under the 0l law INCLUDING
   scene budgets (dedicated customers per scene ticket; assert what holds on
   ANY census — S11's close is the reading list for this); draft in a
   SCRATCHPAD while a battery runs — never write the tree under a live chain;
   killed/exit-2 batteries prove nothing — relaunch, never salvage;
   KEEP-AND-VERIFY for any inherited dirty tree.
6. **CLOSE-OUT.** Full self-audit table, EVERY id itemized; ledger
   item-by-item; closing-set flips + annotations; deviations; inherited
   outcomes; core-edit enumeration; honest trail; the close-out ends with its
   SHA, GROUNDED BY THE OWNER before the advisor recount; acceptance
   conditional on a green verdict of record (green on Doc 12's own terms; a
   red = fix-first, the fix's run is the verdict).

## §12 — CADENCE

Batches; one report + one owner grounding per checkpoint; the SEVEN mandatory
stops only (ruling needed; guard conflict; red battery; core-edit boundary;
enumeration amendment; schema countersign; design conflict/gap). The owner
dislikes micro-batching. One command block + one sentence per owner message.

## §13 — DESIGN FIDELITY

Mandate v11 binds. R-D12-2 is the design ruling of record: backend-first;
Doc 12's own surfaces functional/themed/i18n-keyed from design-system
components riding [B12-batch]; the TWO BINDING SEAMS proven by contract
fixtures (B12-S03 palette contract: live variable resolution + missing-
variable warnings + personal-before-shared + preview + insert-never-sends;
B3-S05 pre-chat contract for form definitions); tokens only — re-run the
committed hex-extraction script before any screen (S11's step-12 six-token
slip is the recorded failure shape: undefined token names are defects even
when the render looks right); deviations in exactly three classes, all filed;
an unlinked screen is a worse deviation than adding one nav row (the P8
palette-action precedent is the shell's nav pattern).

## §14 — HARD PROHIBITIONS

No secrets/PII in prompts, commits, or captures. No real customer text to any
provider outside production. No invented permission keys (catalog CLOSED at
114; growth only by the addition-of-record path). No document edits without
escalate-first. No second implementation of an existing check — conditions
call the owning doors. No status write outside TicketTransitionService; no
placement outside Doc 11's doors; no free-text SMS; no blank variable past
the send API. No green without pasted output. No CI polling. No second Claude
process. No Pest beside gates. No PARTIAL outside a named bucket. A false
DONE is a Sev-1.

Then STOP.
