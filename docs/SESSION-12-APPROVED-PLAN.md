# SESSION-12 APPROVED PLAN — Doc 12: Automation, SLA, Forms & Custom Fields
_APPROVED 2026-09-01 (advisor, owner-witnessed) and committed at approval (rule 12).
Every claim below was read from the repo this session — no row from memory or prompt
summaries. Doc 12 read end-to-end; substrate read as code; the register's own rows
enumerated._

## §0a — ADVISOR RULINGS FOLDED (plan approval, 2026-09-01)
Plan approved; 97 ids family-exact; closing set 12/12. STOP-B: NEIGHBOR — the
connection-tag reopener stays on Doc 05's row. STOP-C: approved, filed as **A-59**
(ANSWERS). STOP-D: outline approved; full R-19b countersign at step 2a BEFORE any
migration. STOP-E: P-A53 shape approved; countersign at landing. STOP-F: P-ESC
approved as an escalate-first packet; countersign before landing. STOP-G: approved,
filed as **A-60** (ANSWERS). STOP-H: confirmed. The v1 strike REFUSED — the frozen
listing holds multiple versions throughout (B12-S01 v1/v2/v3, B12-S02 v1/v2, B5-S02
v1/v2, B6-S14 v1/v2); highest version binds; the v1s stay; the session's
"one-version-per-screen" claim was a defective-regex finding (the pattern missed
`.dc.html`), corrected as honest trail on the [B8] BUILD-STATUS row.

## §0b — BATCH-C ACCEPTANCE RULINGS FOLDED (owner-relayed, grounded at b0365de; folded 2026-09-02 at the batch-D resume)
BATCH C ACCEPTED at b0365de; both CI dispositions accepted (the Stage-4 trio-heal
fix's run as the verdict; the Stage-3 0d tail re-run per 0i). RIDER 3 CONFIRMED
(T-07's DB half; EC-06 version pin by grant; supersede-by-outcome never delete).
RIDER 4 CONFIRMED (the doc is law — item 15's NOT NULL was the oversight; one-
anchor CHECK; closed payloads as filed). P-CHIP COUNTERSIGN GRANTED as filed
(fail-first null pin; poisoned-payload values-from-truth control; Doc 07 suites
green same commit; seeder hand-paint stays until the fidelity session). P-FIELDS
COUNTERSIGN GRANTED as filed (A-53 swap shape; all three controls incl. EC-09
from the real source; seeded trio as real rows at provisioning same commit; Doc
08 suites green same commit). P-SEND COUNTERSIGN GRANTED as filed (one guard,
notes exempt, pinned pre-state flips, Doc 04 suites green same commit).
RbacBudgets re-shape REFUSED for this session — the 4-datapoint count is filed
on 0d's ledger; it is Doc 03's file and a future window's escalate-first call.
Batch D opened at step 13. (END OF RULING — nothing followed that line.)

## §0 — PLAN-TIME STOPS (rulings needed before step 1)

### STOP-A (prompt §5.3 — RESOLVED AT STEP 0, stated not asked) — catalog verification
Catalog count EXECUTED: `php -r "echo count(require 'registries/permissions.php');"`
→ **114**. Every Doc 12-routed key VERIFIED PRESENT by grep at step 0:
`automation.rules.view` / `automation.rules.edit` / `automation.rules.activate`
(FR-07's distinct key) / `macros.personal.manage` / `macros.shared.manage` /
`forms.view` / `forms.edit` / `forms.publish` (the FR-22 door's key, present) /
`fields.manage` (HIGH) / `sla.policies.view` / `sla.policies.edit` (the implied
policy-edit door — present). **NO missing key; no addition-of-record needed; the
catalog stays CLOSED at 114.** Not a stop — the proof the prompt ordered.

### STOP-B (mandatory — §5.5 item 12) — the Doc 05 connection-tag reopener: NEIGHBOR, not subsume
Verified in code: `app/Domain/Routing/Seams/ConnectionRoutingTagSource.php` — the
connection-side tag is **Doc 05 FR-08's per-connection attribute** (no column exists;
production returns null; fixture seam in local/testing/ci), consumed at **pipeline
step 1 (department inference)**. Doc 12's rule-driven tags are **ticket tags** set by
rule actions and consumed at **pipeline step 2** (ticket tag ∩ queue `routing_tags` —
the seam RoutingPipeline already carries, marked [D12] in its own step-5 record).
Different entity (connection vs ticket), different precedence slot (step 1 vs step 2),
different owner (Doc 05 vs Doc 12). **PROPOSAL: Doc 12 closes the STEP-2 seam only
(real `automation_rules` behind `QueueWriter::rulesTargeting` + the rule-matched
routing-tag step); the connection-tag reopener STAYS on Doc 05's FR-08 row, parked,
un-absorbed.** Needs the advisor's ruling to record it.

### STOP-C (mandatory — the EAV-vs-jsonb interpretation, FR-19; filed BEFORE any storage code)
FR-19's own text: "stored per Doc 01 (customer_fields / ticket form_data+custom fields
jsonb per Doc 06 FR-01)". Substrate verified: `customer_field_values` EXISTS (SCHEMA
AMENDMENT 2 ADDITION 9, S08 — EAV row per (tenant, customer, field_key), value text,
audit-history reading, DELETE revoked), and its **Countersign Condition 2 names Doc 12
as the typed-storage reopener**. Doc 06's `tickets` carries `form_data` + custom-fields
jsonb (Doc 06 FR-01). **INTERPRETATION PROPOSED (no rival table, no silent choice):**
1. **Customer entity** → the existing `customer_field_values` EAV table, unchanged
   shape. The typed-storage reopener is answered: `value` stays text (canonical string
   serialization per type; the definition's type drives validation at the ONE write
   door and rendering at read). Choice-type label snapshots (EC-08/FR-18) live in the
   **definition's** option history (option rows keep a label snapshot on removal), not
   per value row — the stored `value` is the stable option value; renders join the
   snapshot. No new column on Doc 08's table; zero core edits.
2. **Ticket entity** → the existing `tickets` custom-fields jsonb (Doc 06 FR-01's own
   storage; the set-custom-field action and form submissions write through Doc 06's
   doors). Form submissions snapshot `{version_id + answers}` into `form_data` (BR-06),
   exactly as Doc 06 FR-01 anticipates.
3. **Definitions** are new Doc 12 tables (`field_definitions` + options — T-01).
4. FR-19's expression indexes on hot keys: ops-managed list, migrator-owned DDL
   (platform note), filed per index — none speculative in v1.

### STOP-D (mandatory — schema countersign) — SCHEMA AMENDMENT 6 outline (full filing at step 2a, BEFORE any migration; R-19b per column; ADR-0003 grant physics; FORCED RLS)
T-01 names ~12 tables. Outline (per-column analysis at the 2a filing):
1. `automation_rules` — rule head {name, description, trigger, condition tree jsonb,
   position, active, run_window, cooldown_s}; live pointer to active revision.
2. `automation_rule_revisions` — the FR-07 unit: immutable revision {definition jsonb,
   author, activated_by, activated_at, **activator capability floor snapshot jsonb
   (SEC-01)**}; INSERT-only by grant (revision history with diff).
3. `automation_rule_executions` — FR-06's log: {rule, revision, event ref, condition
   results jsonb, per-action outcomes jsonb, guard trips}; INSERT-only; 90 d retention
   (platform pruner pattern); SEC-03 elision at read, not at write.
4. `automation_keyword_sets` — managed reusable lists (FR-02).
5. `sla_policies` — {name, order, match conditions jsonb, targets per priority jsonb,
   clock_mode, calendar ref, warning_pct, pause condition toggles, retarget_mode,
   breach escalation hook}.
6. `sla_instances` — armed per ticket: **policy snapshot jsonb + resolved calendar
   snapshot jsonb (BR-03/T-05 — never refs)**; INSERT + narrow UPDATE grants.
7. `sla_instance_stages` — stage rows {stage, target_at, warned_at, breached_at,
   paused ranges jsonb}; DB-time columns per R-19b (metric-bearing: warned/breached
   instants feed Doc 15).
8. `business_calendars` — weekday intervals (split shifts), timezone, dept-override
   deltas (the Doc 11 hours-override REF becomes readable).
9. `business_calendar_holidays` — {date, name, recurring_yearly}.
10. `macros` — {name, scope personal|team|tenant, kind macro|saved_reply, body,
    variables, bundled actions jsonb, usage_count}.
11. `field_definitions` — {entity, key immutable snake_case, label i18n, type (13),
    validation jsonb, agent_editable, customer_visible, position, active}; key
    immutability + type-change prohibition BY GRANT (no UPDATE on key/type/entity).
12. `field_definition_options` — {value stable, label i18n, label_snapshot on
    removal, active} (EC-08).
13. `forms` — head {name, entity bindings, active}.
14. `form_versions` — immutable {schema jsonb, published_by/at, per-language labels};
    INSERT-only by grant (BR-06 physics).
15. `surveys` + `survey_responses` — CSAT: survey rows armed per resolution event
    {token unguessable, expires_at, resolver_type}; single-response server-side
    (partial unique), edit window per FR-25.
Timers ride Doc 06's T-03 ZSET infrastructure (redis-durable; column/row truth in
`sla_instance_stages.target_at`; sweep re-verifies; rebuild from truth — FS-02).
No new tickets/customers column. Chain-depth (T-03) = `automation_depth` in event
payload **metadata** — the Doc 01 event-schema extension is additive payload metadata,
raised inside the amendment for the countersign (no catalog version bump — verified
against the catalog's closed-payload rule at filing).

### STOP-E (mandatory — core-edit packet shape, A-53) — the HoursContract replacement
`app/Domain/Routing/Hours/HoursContract.php` is Doc 11-owned. Public surface verified:
`windowFor / timezone / now / shiftEnd / isOpen / nextOpen`. **PACKET P-A53 (proposed,
countersign before landing):** the SOURCE swaps from the fixture JSON to Doc 12's
`business_calendars` (tenant default + department override), the six-method surface
and every caller byte-untouched; the fixture's PRE state kept as a control
(MembershipTransitionTest precedent) — `HoursTest`'s existing legs re-proven on the
real calendar with the fixture window reproduced as seeded calendar rows; A-53's
pull-in expiry default reads the real calendar (dual-proof: drawn close ahead → close;
else 23:59:59). Production stops being "always open" the moment a tenant paints a
calendar; NO calendar painted = always open (the honest default, stated on the edit
screen). Lands with Doc 11's suites green in the same commit.

### STOP-F (anticipated core-edit packet — named now, filed when reached) — the escalate action's system actor
`EscalateTicket::escalate(Ticket, User $actor, path, reason)` takes a staff User;
Doc 12 FR-03's escalate action is rule-authored (system actor, policy/rule-authored
reason). `TicketTransitionService` already speaks system/automation actors (verified,
its own §398 branch); `AssignTicket` has `assignBySystem`. **PACKET P-ESC (shape):**
an `escalateBySystem(Ticket, EscalationPath, string $reason, string $ruleRef)` door on
Doc 06's file, delegating to the same internals, audited with the rule ref — proposed
→ countersigned → landed with a fail-first control, at the step that builds the
escalate action arm. Same anticipated shape if the SLA breach-escalation hook (FR-08)
needs it — one packet, both consumers.

### STOP-G (filed interpretation — raised now, filed before its step) — webhook-out machinery lives in Doc 12, consuming Doc 01's conventions
Verified: `tenant.outbound_webhook_secret` exists in `registries/settings.php` (Doc 01);
NO outbound-webhook sender exists anywhere in `app/`. Doc 12 FR-03/SEC-02/EC-13 say
"webhook out (Doc 01 signed)" and "Doc 01 webhook semantics reused" — the semantics are
specified but never built. **INTERPRETATION:** Doc 12 builds the platform's outbound
webhook arm (`Automation/Actions/WebhookOut` + delivery job): HMAC signing with the
Doc 01 secret convention, per-tenant admin-managed destination allowlist, SSRF egress
through the egress proxy (Doc 04 SEC-03 family), retry ×8 backoff → auto-disable +
alert (EC-13). Filed as the A-mechanism interpretation; future docs consume it.

### STOP-H (design conflict/none — R-D12-2 confirmed by fresh ls) — the two seams verified
`design/frozen/` re-verified this session (189 entries after the step-0 filing; the
count moved by the ordered batch-8 move — see the step-0 report's FLAG on the two
superseded v1 files). Both seam files read end-to-end: `B12-S03-macro-palette-v1`
(pill variables, personal-before-shared, live per-ticket preview resolution,
per-variable ✓/missing chips, insert-never-sends, "Insert and fill" with placeholder
selected + send blocked while unfilled, saved replies same list with type tag, usage
counts; "macro language variants are Doc 12's territory" — its own text) and
`B3-S05-identity-forms-hours-v1` (two-field seeded pre-chat, required-blocks/optional-
skip, offline form → real offline-tagged ticket + receipt, hours consumption, Amharic
end-to-end). Doc 12's own surfaces confirmed UNDESIGNED both directions (program
report's list names Doc 12; no B-batch frozen file draws a rule builder / SLA editor /
form builder / fields manager). Option A per R-D12-2 stands: backend-first, own
surfaces on [B12-batch], the two seams proven by contract fixtures.

## §1 — Id count by family (from Doc 12's own sections; cross-document citations excluded — Doc 12 cites Doc 05 FR-22/FR-27, Doc 06 FR-06/FR-22/FR-23/FR-24/FR-26/BR-07/T-03/SS-03/AC-09/FS-01, Doc 08 FR-15/EC-09, Doc 09 FR-09/FR-12/FR-20/AC-03, Doc 10 BR-07, Doc 11 FR-01, Doc 13/14/15/17 seams — each checked against the section text, none counted)

| Family | Count | Ids |
|---|---|---|
| FR | 25 | FR-01…FR-25 |
| NFR | 6 | NFR-01…NFR-06 |
| US | 7 | US-01…US-07 |
| BR | 7 | BR-01…BR-07 |
| T | 9 | T-01…T-09 |
| SEC | 6 | SEC-01…SEC-06 |
| EC | 14 | EC-01…EC-14 |
| FS | 5 | FS-01…FS-05 |
| SS | 4 | SS-01…SS-04 |
| AC | 9 | AC-01…AC-09 |
| R | 5 | R-01…R-05 |
| **Total** | **97** | |

## §2 — Out-of-scope handoffs (named per prompt §8)
Routing algorithms → Doc 11 (assign/transfer actions CALL RoutingPipeline /
PlaceInQueue / AssignTicket::assignBySystem; decision record trigger=automation —
vocabulary exists, consumed not invented). SMS template content + delivery → Doc 14
(send-SMS action triggers a template by ref; fixture-proven seam; quiet-hours source
contract only). AI task internals → Doc 09 (run-AI-task actions bind to the CLOSED
15-task catalog — verified: `summarize_thread`, `classify_message`, `translate` all
exist; NO catalog growth). Report formulas + SLA/CSAT dashboards → Doc 15 (golden
timelines are the shared contract). Widget rendering engine → Doc 13 (the versioned
form JSON contract + widget CSAT star row are contracts here; B3-S05 binds ITS render
later). Notification delivery → Doc 16 (notify action = ids-only contract fixtures on
the registered categories). Audit visibility classes → Doc 17 (SEC-06 events named
high-risk now, classed there).

## §3 — Steps (FIFTEEN, four batches; one commit per step; registries as we go; gates every commit; DOC 12 SUITES + full battery + `make gates` + `make lint` per step-commit, no pipes; safety tests SEQUENCED BEFORE the features they constrain)

**Batch A — physics first**
- **0** (DONE, ee84f04): rulings-filed proof; catalog 114 EXECUTED + keys verified;
  [B8]/[B9] re-annotation citing R-D12-1; Batch 8 freeze filing (finding FLAGGED:
  cfe305b had already copied the 13-file freeze set; two superseded v1s moved per the
  ordered git mv, strike ruling offered); the [D12] closing set enumerated 12/12
  against R-D12-4, zero mismatch.
- **1**: **THE SLA CLOCK LIBRARY, PURE AND FIRST** (§21 rec 1; T-04; AC-03; EC-03/04).
  `app/Domain/Sla/Clock/` — interval sets, calendar intersection, paused-interval
  UNION subtraction (EC-04 — overlap never double-subtracts), retarget formula
  `remaining_new = remaining_pct × target_new` (EC-03), warning-at-%/breach-at-100
  instants, next-fire-at computation. Property tests (`tests/Property/SlaClockTest`)
  + golden timelines `fixtures/sla/golden-timelines.json` (shared with Doc 15, the
  Doc 06 AC-09 pattern). No engine line before this is green.
- **2a**: SCHEMA AMENDMENT 6 FILED in full (STOP-D shape; R-19b per column; grant
  physics; the T-03 payload-metadata extension inside it). **MANDATORY STOP —
  countersign before any migration.**
- **2b**: migrations on the countersigned amendment (migrator connection; FORCED RLS;
  REVOKE per the grant table) + `SlaGrantPhysicsTest`/`AutomationGrantPhysicsTest`
  (denied-set loops, 42501 controls); registries: settings (`csat.cooldown.hours`,
  `csat.window.hours`, cooldown defaults, `automation.*` tunables WITHIN caps),
  features (`automation.rules_pause` under features.toggle — R-04), audit actions,
  notification categories for notify arms; traceability rows seeded PLANNED.
- **3**: business hours + holidays (FR-12/13, AC-04): calendar writer (one door,
  audited), week-grid painter BACKEND (interval-list model — §13's alternative editor
  is the data shape), holiday recurrence, department override deltas; **PACKET P-A53
  LANDED under countersign** (STOP-E; fixture PRE state kept as control; Doc 11
  HoursTest re-proven); the §8 SLA example-sentence computation from the SAME clock
  library (AC-04's one-implementation clause); EC-05/EC-10 (prospective-only banner
  fact + schedule recompute on timezone change).

**Batch B — the engine under its guards**
- **4**: **guard-cap property tests + BR-02 no-bypass sweep FIRST**, then the engine
  core (T-02): trigger index over the 12 v1 triggers (FR-01 — incl. schedule with
  min-interval-5-min floor + next-run preview), compiled condition trees (cached,
  invalidated on activate; FS-03 interpretive fallback), materialized event context
  (ticket+customer+message+AI facts assembled once), condition vocabulary (FR-02:
  every operator per type; conditions that Docs 03/06/11 already enforce CALL the
  owning door — the sweep pins it), keyword sets, chain-depth via `automation_depth`
  payload metadata (T-03), per-(rule,ticket) cooldown (EC-12), guards ≤10 rules /
  ≤20 actions / depth ≤3 as PLATFORM constants — no config path above them (BR-05
  property test), loop_guard log + alert. Durable consumers per ADR 0011 (registry +
  drill pin + Doc 06 NFR-06 fixture, SAME commit).
- **5**: actions, first family (BR-02, EC-01, EC-09-adjacent honesty): **SEC-01
  activator-capability floor test BEFORE any action executes** — system actor bounded
  by the revision's captured floor, drift warnings, re-activation refresh; then:
  assign (Doc 11 doors), transfer department (TransferTicket), set priority/status
  (TicketTransitionService — illegal transition fails THE ACTION, logged, chain
  continues: AC-01), add/remove tags, set custom field (definition-validated), add
  internal note (NotesWriter — N-04 respected by construction), superseded_state
  honesty (EC-01). Per-action try/log/continue job chain on the `automation` family.
- **6**: SLA policies + instances + timers (FR-08/09/10/11, BR-03, T-05, EC-02/03/05,
  FS-02, NFR-02): **snapshot-immutability falsification BEFORE policy editing
  ships**; first-match policy arming on ticket creation (policy + resolved-calendar
  snapshots embedded); stage lifecycle (first_response clears on Doc 06 FR-24's
  `first_response_at` via `recordPublicReply`'s event; next_response re-arm idempotent
  while armed, clock from first unanswered message — EC-02 fixture-pinned; resolution
  clears on resolved); pause conditions incl. channel_unreachable (Doc 06 BR-07's
  column, read never written) with pause-union; retarget keep|retarget_remaining
  (EC-03; Doc 06 FR-22 badge contract); timers on the T-03 ZSET pattern (row truth,
  sweep re-verifies, next-fire-at, recompute on every state-affecting event, ±30 s
  NFR-02 measured, FS-02 late-not-early + `late_fired`); `sla.warned|breached {stage}`
  events; the SLA chip contract fixture (FR-11 — Doc 07 renders); `sla_at_risk`
  BoostMatcher seam made REAL (closing-set row 11). Breach escalation hook (P-ESC if
  needed — STOP-F).
- **7**: dry-run + execution logs (FR-05/06, AC-02, NFR-03, SEC-03, FS-05, R-01):
  dry-run against sample/pasted/historical events (7-day picker) with per-condition
  truth, zero side effects PROVEN (spy on every door); **AC-02 parity: dry-run
  verdicts ≡ live evaluation over a replayed corpus** (Doc 09 AC-03 simulation-vs-
  replay pattern); execution log detail + retention 90 d + ticket-timeline "what
  touched this" (Doc 06 FR-26 integration — system events with rule links);
  SEC-03 log elision (matched-keyword markers only for non-privileged); the
  rules-pause switch WIRED (R-04: features.toggle, instant, evaluation short-circuit)
  + runbook page line. **CHECKPOINT (CI, one look).**

**Batch C — fields, macros, forms, flows**
- **8**: custom fields (FR-17/18/19, EC-08/14, AC-06, STOP-C as filed): definitions
  writer under `fields.manage` (HIGH — confirm dialog per R-14 destructive tier);
  13-type matrix + per-type validation; lifecycle physics (deactivate → read-only +
  retired tag CLOSING Doc 08 EC-09; reactivate; keys never reused; type change
  PROHIBITED by grant + door; option removal keeps label snapshots); where-used
  completeness (EC-14: forms, rules, views — deactivation proceeds, rule flagged
  warning evaluates is-empty, form's next publish blocked); value storage per STOP-C
  (customer → existing `customer_field_values`; ticket → Doc 06 jsonb); FR-02
  condition + Doc 07 view-filter indexing contract.
- **9**: macros + THE ONE RESOLVER (FR-14/15/16, T-06, BR-04, EC-09, AC-05, NFR-05):
  macro/saved-reply storage (scopes, kinds, bundled actions); ONE server-side
  `VariableResolver` shared by preview and send (parity test); no-blank enforcement
  AT THE SEND API (T-06 — send refused while a placeholder is unfilled, the B12-S03
  drawn law); bundled-action per-capability honesty (EC-09 — text inserts, action
  skipped with notice); locale-formatted resolution (§14); usage counts (FR-16);
  palette budget NFR-05 (dev-scale, flagged). **B12-S03 CONTRACT FIXTURE**
  (`MacroPaletteContractTest`): personal-before-shared, live resolution, missing-
  variable warnings, insert-never-sends, per-variable state chips — the palette
  itself is Doc 07's (built, FR-19); Doc 12 supplies the contract it consumes.
- **10**: forms + versioning + publish (FR-20/22/23, BR-06, EC-06/07, AC-07's
  publish/version halves): versioned definitions (steps, field refs + inline fields,
  conditional logic — no cross-step forward refs, R-03's scope wall); publish mints
  an immutable version (grant physics); publish validation blocks (retired-field
  conditionals, **required-when-visible EC-07 with the auto-suggest fix**, language
  completeness per enabled language, upload-capability per Doc 04 caps); usage
  banner (bot menus, rules, widget shortcuts); submissions snapshot
  {version_id + answers}; seeded forms ×3 as editable content (FR-23, Doc 10 BR-07
  stance) + 3–5 seeded example rules DEACTIVATED (§21 rec 3); **B3-S05 CONTRACT
  FIXTURE** (`FormPreChatContractTest`): the versioned JSON contract renders the
  drawn pre-chat/offline shapes ([D13] renders later); widget payload ≤30 KB +
  infinite-TTL version cache (NFR-04).
- **11**: THE TELEGRAM FLOW ENGINE (FR-21, T-07, EC-06, SS-03, FS-04, AC-07's flow
  half; **CLOSES Doc 10 FR-19 — closing-set row 1**): mounts on
  `MenuDesigner::bindTarget`'s committed contract (`telegram_flow_targets`); one
  field per message; inline buttons (direct) / numbered text (business); friendly
  retry ×3-then-offer-human; native photo/document for file fields; per-conversation
  flow state in Redis 24 h TTL + DB checkpoint at step boundaries; mid-form version
  publish → flow completes on ITS checkpointed version (EC-06); mid-form /human or
  handoff → partial data flagged incomplete into the packet (SS-03; Doc 09 FR-12
  form_data); **FS-04 = a DRILL** (Redis state killed mid-flow → resume from DB
  checkpoint with the recap prompt); localized prompts per the FR-20 resolution
  chain.
- **12**: CSAT (FR-24/25, BR-07, SEC-05, EC-11, T-08, AC-08; closing-set rows 2–5):
  survey arming on `ticket.resolved` (Doc 06 FR-06) + AI-resolved (Doc 09 FR-09 —
  the ChatbotTurn `csat_prompt` seam made real); one per resolution event; cooldown-
  AND RG-suppressed (Doc 08 FR-15's [D12] suppression consumer contract — the
  suppress ACTION lands step 5, its CSAT/marketing consumers here); 1-tap 1–5 both
  channels (Telegram inline/numbered per mode; widget star-row CONTRACT [D13]);
  token-bound unguessable endpoints, rate-limited, single-response server-side with
  the 1 h edit window; 72 h expiry + `csat.expired` (EC-11's polite window-closed);
  `csat.sent|received|expired` events (Doc 15's source of truth); score ≤2 → Doc 09
  FR-20 review item (the OutcomeEnrichment `csat_scored` join — closing row 4);
  comment prompt after scoring. **CHECKPOINT (CI, one look).**

**Batch D — arms, surfaces, close**
- **13**: the external action arms (FR-03's remainder; SEC-02; EC-13; STOP-G):
  webhook-out (signed, allowlisted, egress-proxied, retry ×8 → auto-disable+alert);
  notify users/roles (ids-only [D16] contract fixtures on registered categories);
  send-SMS template-by-ref ONLY ([D14] fixture seam; no free-text path EXISTS —
  BR-04); suppress (the Doc 08 FR-15 flag + consumer contract — closing row 6);
  run-AI-task (catalog-bound: summarize→note, classify-refresh, translate→note;
  refusal on any other name); escalate (P-ESC packet if countersigned — STOP-F).
- **14**: Doc 12's own surfaces, functional/themed/i18n-keyed from DS components,
  riding **[B12-batch]** (R-D12-2.2; hex-extraction script re-run FIRST): rules
  list/editor (drag order, truth badges, guard badges, dry-run drawer, draft-vs-
  activate flows), SLA policies + live example sentence (the clock library's own
  answer), hours painter (+ interval-list a11y editor), macros manager, fields
  manager (where-used panel), form builder (dual live preview from the CONTRACT
  fixtures' renderers), CSAT settings; nav via the P8 palette-action precedent (one
  entry per surface group — an unlinked screen is the worse deviation); i18n sweep
  en+am (`workflow.*`; ICU SLA sentences; CSAT button labels; form-language
  completeness gates; Telegram prompts) — machine-authored am → R-14, destructive
  tier FLAGGED (rule activate/deactivate confirms, field deactivation, form publish,
  rules-pause switch).
- **15**: CLOSE-OUT (protocol step 6): 97-id self-audit itemized; sabotage ledger
  item-by-item; closing-set flips (rows 1–7) + annotations (rows 8–12); §12 budget
  rows dev-scale with misses flagged; deviations; inherited outcomes; core-edit
  enumeration; honest trail; SHA grounded by the OWNER before the advisor recount;
  acceptance conditional on a green verdict of record.

## §4 — Inherited rows (prompt §9), dispositions
| Row | Disposition |
|---|---|
| 0a [B8]/[B9] | DEFERRED per R-D12-1 — step-0 annotation LANDED (ee84f04); hard trigger post-S12, v12 kit row 0 |
| 0b staging-key rotation | OWNER action — restated at every checkpoint |
| 0c Addis AI DPA-or-switch | OWNER action [OA] — restated |
| 0d staging Telegram Business | [OA] parked, untouched |
| 1 [D12] closing set | THE HEART — §5 below, 12 rows |
| 2 risks 0a–0m | STANDING; 0l binds every browser fixture AND scene budget (dedicated customers per scene ticket; assert what holds on ANY census; no depth/exact-head pins); 0d isolated-rerun-once law; 0i triage law per red; 0k/0m letters stand RESOLVED-BY-ISOLATION |
| 3 R-14 queue | Doc 12's am strings join cumulatively; destructive tier flagged (step 14's list) |
| 4 A-57/A-58 | Filed interpretations honored; the estimator's A-58 constant TOUCHED ONLY BY READING — the SLA/estimator work changes no constant without a ruling (verified: A-58 is `BandMapper`'s, not the SLA clock's) |
| 5 Platform NNRR | per-door `shouldRenderJsonWhen` evidence continues on every new Doc 12 Inertia door; partition fold-in + QuotaTest order-dependence stay parked |
| 6 WSL recipes | Windows-gateway nameserver; MTU 1350 on TLS hangs; wsl.conf permanent fix still owed (owner) |

## §5 — The [D12] closing set (enumerated at step 0 from the register's own rows; 21 grep hits read in context; RECONCILED 12/12 against R-D12-4, zero mismatch; rows re-located BY TEXT at flip time)
**Flips (rows 1–7):**
1. Doc 10 FR-19 PARTIAL [D12] → step 11 (the tap-flow ENGINE on bindTarget).
2. Doc 09 FR-09 PARTIAL [D12] → step 12 (1-tap CSAT on the decision-row seam).
3. Doc 09 FR-17 [D12] HALF (QA rubric content) → step 12/14; [D15]/[D16] halves stay worded.
4. Doc 09 T-08 PARTIAL [D12] → step 12 (the `csat_scored` outcome-enrichment join).
5. Doc 09 AC-01 [D12] half (run_form execution + CSAT join) → steps 11–12; [D19] stays.
6. Doc 08 FR-15 [D12] HALF (automation suppression) → steps 5+12; [D14] SMS half stays worded.
7. Doc 08 EC-09 PARTIAL [D12] → step 8 (FR-18 IS the retired-field rule; Doc 08's render already built).
**Annotations/implementations on DONE rows (rows 8–12):**
8. Doc 11 FR-14 HoursContract → step 3 (P-A53; PRE-state control). Named sub-touchpoints
   found in my sweep, stated so nothing is averaged: the DepartmentWriter hours-override
   REF ("stored never read", S11 step 3b) becomes READ here, and J03's drawn REF display
   gains its live calendar — both inside P-A53's annotation.
9. `doc12-rules.json` seam → steps 4–5 (QueueWriter::rulesTargeting + the
   RoutingPipeline rule-matched tag step REAL against `automation_rules`).
10. ReferenceInventory `sla_policies` slice → step 6 (real rows; DeleteQueueWizard
    per-rule choices land on real tables).
11. `sla_at_risk` boost input → step 6 (BoostMatcher reads real instance state — FR-10 warned events).
12. Doc 05 connection-tag reopener → STOP-B verdict: NEIGHBOR; stays parked on Doc 05's row.

## §6 — Pre-agreed closure buckets (the ONLY lawful PARTIAL homes)
| Bucket | Content | Unblocker |
|---|---|---|
| [D13] | Widget form render + widget CSAT star row + widget survey states | Doc 13 renders the contract |
| [D14] | send-SMS live delivery; quiet-hours consumption | Doc 14 gateway + templates |
| [D15] | SLA/CSAT/macro-usage report formulas + dashboards | Doc 15 (goldens shared) |
| [D16] | notify-action delivery + rubric content | Doc 16 delivery machinery |
| [D17] | High-risk audit visibility classes (SEC-06) | Doc 17 |
| [D19] | NFR-01…05 at L2 load (dev-scale figures stated plainly, misses flagged) | Doc 19 |
| [B12-batch] | Pixel fidelity of Doc 12's own surfaces | R-D12-1 design track + fidelity session |
| [OA] | Anything owner-blocked, named plainly | The named owner action |

## §7 — Sabotage enumeration (floor TEN — the prompt's mandated set; grown only by filed amendment; positive control per assertion; captures first; no pipes)
(a) guard cap removed → loop/blast test red; (b) rule writes status AROUND
TicketTransitionService → BR-02 static sweep red; (c) blank variable reaches the send
API → T-06 red; (d) armed instance mutated by policy edit → BR-03 red; (e) activator
floor dropped → SEC-01 red; (f) webhook allowlist/SSRF egress dropped → SEC-02 red;
(g) second CSAT response accepted → SEC-05 red; (h) field type change permitted →
FR-18 red; (i) required-when-hidden publish permitted → EC-07 red; (j) pause-interval
double-subtraction → EC-04 property red.

## §8 — "DOC 12 SUITES" (canonical command, defined at plan time; files later steps create are named now; grows only by filed note as files land)
```
docker compose exec -T app php artisan test \
  tests/Property/SlaClockTest.php \
  tests/Property/AutomationGuardCapsTest.php \
  tests/Feature/Sla tests/Feature/Automation tests/Feature/Hours \
  tests/Feature/Macros tests/Feature/Fields tests/Feature/Forms \
  tests/Feature/Csat \
  tests/Contract/Automation tests/Contract/Sla \
  tests/Security/AutomationNoBypassTest.php \
  tests/Security/ActivatorFloorTest.php \
  tests/Security/AutomationGrantPhysicsTest.php \
  tests/Security/SlaGrantPhysicsTest.php \
  tests/Security/MacroNoBlankSendTest.php \
  tests/Security/CsatSingleResponseTest.php
```
Exact file list amended in-plan as files land (the S11 §8 rule). Never beside gates.

_§8 amendments (filed as files land):_ the grant-physics pair lives in
`tests/Isolation/` (the KbGrantPhysicsTest house shape; the isolation-suite gate
re-runs them) — `tests/Isolation/AutomationGrantPhysicsTest.php` +
`tests/Isolation/SlaGrantPhysicsTest.php` replace the two `tests/Security/*GrantPhysics*`
lines. Landed and added: `tests/Feature/Hours/CalendarTest.php`,
`tests/Feature/Sla/ExampleSentenceTest.php`, `tests/Feature/Foundation/EnvelopeMetadataTest.php`
(packet #3), `tests/Feature/Automation/{EngineOrderTest,RuleDefinitionTest,ConditionTreeTest,AutomationConsumerDrillTest}.php`,
`tests/Property/AutomationGuardCapsTest.php`, `tests/Security/AutomationNoBypassTest.php`,
`tests/Security/ActivatorFloorTest.php`. Step 9: `tests/Contract/Automation/MacroPaletteContractTest.php`
(in the listed `tests/Contract/Automation` dir), `tests/Feature/Macros/*` (listed dir).
Step 10: `tests/Contract/Forms/FormPreChatContractTest.php` (NEW dir — add
`tests/Contract/Forms` to the run list), `tests/Feature/Forms/*` (listed dir).
Step 11: `tests/Feature/Forms/TelegramFlowTest.php` (listed dir),
`tests/Contract/Forms/DualRenderConformanceTest.php` (added dir); RIDER 3's
grant legs ride the already-listed AutomationGrantPhysicsTest.
Step 12: `tests/Feature/Csat/{TriggerMatrixTest,CaptureTest}.php` (listed dir)
+ the already-listed CsatSingleResponseTest; doc12Columns/doc12DeniedSet
promoted to tests/Pest.php (the per-file-helper law, 6th promotion).
Step 13 (batch D): P-CHIP's proof `tests/Feature/Inbox/SlaProjectionTest.php`
(a Doc 07 dir — add the file to the run list; Doc 07's own suite runs it too);
P-FIELDS' proof `tests/Feature/Fields/CustomerDefinitionsSourceTest.php` (listed
dir); the external arms: `tests/Security/WebhookEgressTest.php` (add the file —
AC-09's automated suite), `tests/Feature/Automation/ExternalArmsTest.php` and
`tests/Contract/Automation/ExternalContractsTest.php` (listed dirs); RIDER 5's
physics legs ride the already-listed AutomationGrantPhysicsTest.

## §9 — Plan table of record (ONE row per id: id → file(s) → proving test; paths under `app/Domain/{Automation,Sla,Hours,Macros,Fields,Forms,Csat}/` unless noted; tests under `tests/`)

| Id | File(s) | Proving test |
|---|---|---|
| FR-01 | Automation/Rules/RuleWriter + Engine/TriggerIndex (12 triggers; schedule min-5-min + next-run preview) | Feature/Automation/RuleDefinitionTest + ScheduleTriggerTest |
| FR-02 | Engine/ConditionTree + Engine/EventContext + Rules/KeywordSets | Feature/Automation/ConditionTreeTest (operator×type matrix; owning-door calls) |
| FR-03 | Actions/* (assign, transfer, setPriority, setStatus, tags, setField, macroReply, note, notify, smsTemplate, webhookOut, runAiTask, escalate, suppress — each through its owning door) | Feature/Automation/ActionsTest + Security/AutomationNoBypassTest |
| FR-04 | Engine/Guards (caps as platform constants; cooldown; loop_guard) | Property/AutomationGuardCapsTest + Feature/Automation/EngineOrderTest |
| FR-05 | DryRun/DryRunner (+ 7-day historical picker) | Feature/Automation/DryRunParityTest (AC-02) |
| FR-06 | ExecutionLog/{Writer,Reader} + timeline system events (Doc 06 FR-26 consumption) | Feature/Automation/ExecutionLogTest |
| FR-07 | Rules/Revisions (draft-on-edit; atomic activation swap; diff) | Feature/Automation/RevisionsTest (both permission keys distinct) |
| FR-08 | Sla/Policies/PolicyWriter (+ first-match ordering; targets; pause toggles; retarget mode; breach hook) | Feature/Sla/PolicyTest |
| FR-09 | Sla/Instances/InstanceArming + StageLifecycle (snapshots embedded; first_response/next_response/resolution stages) | Feature/Sla/InstanceLifecycleTest |
| FR-10 | Sla/Clock/* + Sla/Timers/SlaTimers (ZSET; warned/breached events) | Property/SlaClockTest + Feature/Sla/TimerTest |
| FR-11 | Contract fixture fixtures/sla/doc07-chip-contract.json + reader | Contract/Sla/SlaChipContractTest |
| FR-12 | Hours/CalendarWriter (+week grid model; holidays; dept deltas; prospective-only) | Feature/Hours/CalendarTest |
| FR-13 | Hours consumers: HoursContract source swap (P-A53), estimator/off-hours contracts | Feature/Hours/ConsumersTest + Doc 11 HoursTest re-proven |
| FR-14 | Macros/MacroWriter (scopes, kinds, bundled actions) | Feature/Macros/MacroTest |
| FR-15 | Macros/VariableResolver (ONE resolver; {{queue.wait_band}} via Doc 11's provider) | Feature/Macros/ResolverParityTest + Security/MacroNoBlankSendTest |
| FR-16 | Contract fixture per B12-S03 + usage counts | Contract/Automation/MacroPaletteContractTest |
| FR-17 | Fields/DefinitionWriter (13 types; validation; fields.manage HIGH) | Feature/Fields/DefinitionMatrixTest |
| FR-18 | Fields/Lifecycle (retire/reactivate/no-reuse/no-type-change BY GRANT; option snapshots) | Feature/Fields/LifecycleTest (closes Doc 08 EC-09's ownership) |
| FR-19 | STOP-C storage: CustomerFieldValues (existing door) + tickets jsonb + index contract | Feature/Fields/ValueStorageTest |
| FR-20 | Forms/FormWriter + Versions (steps, conditionals, per-language completeness) | Feature/Forms/DefinitionTest |
| FR-21 | Forms/Runtime/{WidgetContract,TelegramFlowEngine} (dual render) | Contract/Forms/DualRenderConformanceTest + Feature/Forms/TelegramFlowTest |
| FR-22 | Forms/Publish (immutable version; validation blocks; usage banner) | Feature/Forms/PublishValidationTest |
| FR-23 | database/seeders forms ×3 (editable content) | Feature/Forms/SeededFormsTest |
| FR-24 | Csat/SurveyArming (both resolution paths; cooldown + RG suppression) | Feature/Csat/TriggerMatrixTest |
| FR-25 | Csat/{Capture,Endpoints} (1-tap both channels; edit window; expiry; events) | Feature/Csat/CaptureTest + Security/CsatSingleResponseTest |
| NFR-01 | compiled trees + async actions | Perf leg in EngineOrderTest — dev-scale stated, [D19] |
| NFR-02 | SlaTimers ±30 s measured | Feature/Sla/TimerAccuracyTest — dev-scale stated, [D19] if missed |
| NFR-03 | DryRunner progressive results | DryRun perf leg — dev-scale, [D19] |
| NFR-04 | version cache infinite TTL + ≤30 KB payload | Feature/Forms/PayloadBudgetTest |
| NFR-05 | macro search budget | Feature/Macros perf leg — dev-scale, [D19] |
| NFR-06 | every write evented + audited | registry gates + Feature/Automation/EventedWritesTest |
| US-01 | rule build + dry-run + activate journey | DryRunParityTest + RevisionsTest legs (stated composition) |
| US-02 | VIP SLA + pause proof | InstanceLifecycleTest pause legs + SS-02 fixture |
| US-03 | macro fill + blank warning | MacroPaletteContractTest + MacroNoBlankSendTest |
| US-04 | midnight Telegram deposit form | TelegramFlowTest end-to-end leg |
| US-05 | field once → everywhere | DefinitionMatrixTest + ValueStorageTest + ConditionTreeTest custom-field legs |
| US-06 | timeline rule links | ExecutionLogTest timeline leg |
| US-07 | one-tap CSAT + no re-survey | TriggerMatrixTest cooldown leg |
| BR-01 | deterministic order everywhere | EngineOrderTest + PolicyTest first-match leg |
| BR-02 | rules act through the doors | Security/AutomationNoBypassTest (static + runtime, positive control) |
| BR-03 | snapshot semantics | Feature/Sla/SnapshotImmutabilityTest (falsification red) |
| BR-04 | no blanks + no free-text SMS | MacroNoBlankSendTest + ActionsTest sms-template-only leg |
| BR-05 | caps non-negotiable | AutomationGuardCapsTest (no config above platform limit) |
| BR-06 | versioned form contracts | PublishValidationTest + snapshot legs |
| BR-07 | CSAT is measurement | TriggerMatrixTest (one per resolution; suppressions; no incentive path) |
| T-01 | migrations per AMENDMENT 6 | GrantPhysicsTests (both) + migration-safety gate |
| T-02 | Engine compiler + context + job chain | EngineOrderTest + ActionsTest isolation legs |
| T-03 | automation_depth payload metadata | Feature/Automation/ChainDepthTest |
| T-04 | Sla/Clock pure library | Property/SlaClockTest + golden timelines |
| T-05 | resolved-calendar snapshots | SnapshotImmutabilityTest calendar leg (EC-05) |
| T-06 | resolver shared preview/send; API enforcement | ResolverParityTest + MacroNoBlankSendTest |
| T-07 | flow state Redis TTL + DB checkpoint | TelegramFlowTest + the FS-04 drill |
| T-08 | survey arming + callback/widget ingestion upsert | CaptureTest + CsatSingleResponseTest |
| T-09 | the contract-test set | the §8 suites themselves (rule-order fixtures; goldens; dual-render; no-blank; CSAT matrix) |
| SEC-01 | Engine/SystemActor floor (captured on revision) | Security/ActivatorFloorTest (demoted-author falsification red) |
| SEC-02 | Actions/WebhookOut (allowlist, signing, egress proxy) | Security/WebhookEgressTest |
| SEC-03 | ExecutionLog elision + automation.rules.view gate | Feature/Automation/LogElisionTest (role fixtures) |
| SEC-04 | form file fields on Doc 04 media; type-closed schema | Feature/Forms/FileFieldTest + schema-inertness leg |
| SEC-05 | Csat token-bound endpoints, rate-limited, single-response | CsatSingleResponseTest |
| SEC-06 | high-risk audit events registered (rule activate/deactivate, policy change, field deactivation, form publish) | audit-coverage gate + [D17] classes bucket |
| EC-01 | superseded_state honesty | EngineOrderTest EC-01 leg (dry-run visibility) |
| EC-02 | one armed stage; clock from first unanswered | InstanceLifecycleTest EC-02 fixture-pinned leg |
| EC-03 | retarget formula | SlaClockTest property + InstanceLifecycleTest badge leg |
| EC-04 | pause union | SlaClockTest property (sabotage j) |
| EC-05 | holiday added while armed | SnapshotImmutabilityTest + CalendarTest banner fact |
| EC-06 | mid-form version publish | TelegramFlowTest checkpointed-version leg |
| EC-07 | required-when-visible | PublishValidationTest (+ auto-suggest fix) |
| EC-08 | option removal snapshots | LifecycleTest + rule-condition warning leg |
| EC-09 | bundled action w/o capability | Feature/Macros/BundledActionHonestyTest |
| EC-10 | schedule timezone recompute | ScheduleTriggerTest tz-change leg |
| EC-11 | hour-73 tap | CaptureTest expiry leg |
| EC-12 | cooldown keys (rule,ticket) | EngineOrderTest cooldown legs (second ticket fresh) |
| EC-13 | webhook retry ×8 → auto-disable | WebhookEgressTest retry/disable leg |
| EC-14 | where-used completeness | Feature/Fields/WhereUsedTest (rule flagged; publish blocked; live version renders) |
| FS-01 | queue backlog isolation (clocks independent) | Feature/Sla/TimerTest independence leg |
| FS-02 | timer loss → rebuild, late-not-early, late_fired | Feature/Sla/TimerRebuildDrillTest |
| FS-03 | compilation cache corrupt → interpretive fallback | Feature/Automation/CompilerFallbackTest |
| FS-04 | flow Redis loss → DB checkpoint resume | TelegramFlowTest FS-04 DRILL leg |
| FS-05 | mass-fire containment (caps + toggle + log scoping) | Feature/Automation/BlastRadiusTest |
| SS-01 | J5 composed end-to-end (form v2 + rule + article + menu) | stated composition over landed suites (fixture journey) |
| SS-02 | SLA dispute settled from the instance record | Feature/Sla/DisputeRecordTest (golden) |
| SS-03 | mid-form handoff partial data | TelegramFlowTest SS-03 fixture |
| SS-04 | rules list as living ops manual | RevisionsTest + list ordering legs (stated composition) |
| AC-01 | rule semantics + guards | EngineOrderTest + GuardCaps + ActionsTest (EC-01/EC-12 incl.) |
| AC-02 | dry-run parity | DryRunParityTest (replayed corpus) |
| AC-03 | clock properties + goldens + ±30 s + snapshots | SlaClockTest + TimerAccuracyTest + SnapshotImmutabilityTest |
| AC-04 | hours: split shifts, recurring holidays, overrides, example sentence | CalendarTest + ExampleSentenceTest (one implementation) |
| AC-05 | macro parity + no-blank + EC-09 + budget | ResolverParityTest + MacroNoBlankSendTest + BundledActionHonestyTest |
| AC-06 | field matrix + lifecycle + snapshots + where-used | DefinitionMatrixTest + LifecycleTest + WhereUsedTest |
| AC-07 | forms: dual render + EC-07 + versions + partial + FS-04 drill | DualRenderConformanceTest + PublishValidationTest + TelegramFlowTest |
| AC-08 | CSAT matrix + suppressions + single-response + expiry + events | TriggerMatrixTest + CaptureTest + CsatSingleResponseTest |
| AC-09 | SEC-01 floor + webhook rules + log elision | ActivatorFloorTest + WebhookEgressTest + LogElisionTest |
| R-01 | matched-count visibility + revisions + ordering | rules-list legs (step 14) |
| R-02 | property library + snapshots + human-readable record | AC-03's set + DisputeRecordTest |
| R-03 | v1 logic walls (no cross-step refs/calculations) | DefinitionTest refusal legs |
| R-04 | rules-pause switch v1 (features.toggle) + runbook | Feature/Automation/RulesPauseTest |
| R-05 | cooldown tunable to zero within caps; trips visible | GuardCaps legs + ExecutionLogTest guard-trip leg |

## §10 — Design binding (mandate v11; R-D12-2)
Backend-first; own surfaces [B12-batch] (STOP-H verified undesigned both directions);
the TWO BINDING SEAMS proven by contract fixtures at build time (steps 9/10); tokens
only — hex-extraction script re-run before step 14's first screen; deviations in
exactly three classes, all filed; nearest drawn idioms for class-2 fills: B5-S07
builder chrome, B5-S10 wizard/confirmation, B12 timeline idiom, B2-S06 inline
refusals; nav per the P8 palette-action precedent.

## §11 — Core-edit enumeration (escalate → countersign → land; NEVER the reverse; S11's 7-for-7 standard)
#1 P-A53 — HoursContract source swap (Doc 11; STOP-E; PRE-state control) · #2 P-ESC —
escalateBySystem door (Doc 06; STOP-F; filed when reached) · #3 the T-03 payload-
metadata extension (Doc 01 event schema; inside AMENDMENT 6's countersign) · #4 Doc 11
seam realizations (QueueWriter::rulesTargeting source, ReferenceInventory sla slice,
BoostMatcher sla_at_risk input — each a Doc 11-owned file: packet per file, or one
combined packet at the advisor's preference, countersigned before landing) · #5 Doc 07
consumption slots (SLA chips, macro palette source) — CONTRACT FIXTURES ONLY this
session; any Doc 07 file edit is its own packet · #6 anticipated NONE for Docs
02/03/04/08/09/10 (conditions/actions consume doors; Doc 08's customer_field_values
written through its existing service; any surprise = stop first).
_Batch-D addenda (filed as reached):_ #5 became TWO packets — P-CHIP (Doc 07's
TicketListItems/ConsumerRegistry/fixture; countersigned at the batch-C acceptance,
landed 2e448f5) and P-SEND (Doc 04's ComposeOutbound; countersigned there, landed
9dd9f49); #6's "any surprise" fired twice — P-FIELDS (Doc 08's CustomerFieldValues
source swap; countersigned, landed d53a76e) and **P-CLS (Doc 09's
InboundClassification: a classify-ONLY door, because classify() also executes the
route — filed docs/evidence/S12-step13/packet-P-CLS.md, PROPOSED; the
classify_refresh sub-arm answers unbuilt_ai_task until countersigned).** #7 =
P-CLS.

## §12 — Step 0 record
Solo guard: rival PID 84745 killed, verified gone twice; no daemon/bridge; no orphaned
pest/gates. Integrity: 6 steps; last line `Then STOP.` Proofs: rulings filed (a240e06,
1dc3620); amendments 2–5 present; ADRs 0003–0019 present; catalog 114 EXECUTED, all
Doc 12 keys present; routine commit **ee84f04** ([B8]/[B9] re-annotation + Batch 8
freeze filing; frozen 187 → **189**; batch-8 directory GONE; finding: cfe305b had
already copied the 13-file freeze set — duplicates removed; the two superseded v1
files (S01/S07) moved per the ordered git mv; the strike offer was REFUSED at plan
approval — see §0a's honest-trail correction). The [D12]
closing set: 21 grep hits, each read in context, reduced to the 12 register rows —
RECONCILED 12/12 against R-D12-4, zero mismatch, two named sub-touchpoints of row 8
stated (§5).
