Table of Contents

# Document 12 — Automation, SLA, Forms & Custom Fields

**Product:** AI-First Support Platform for Betting Companies **Document series:** 12 of 19 · Standalone enterprise specification **Audience:** Backend engineers, frontend engineers, QA, product, operator admins **Depends on:** Doc 01 (events, settings, outbox), Doc 03 (permissions automation.*, macros.*, forms.*, fields.manage, sla.*), Doc 04 (messages), Doc 05 (channel renderings, window events), Doc 06 (ticket events & transition service, timers pattern), Doc 09 (AI conditions/actions, CSAT trigger), Doc 11 (routing actions, business-hours consumption). Consumed by: Doc 07 (macros palette, SLA chips), Doc 13 (form renders, surveys), Doc 14 (SMS actions), Doc 15 (SLA & CSAT metrics), Doc 17 (audit). **Stack context:** Laravel, PostgreSQL, Redis (timers), Horizon automation queue

## 1. Executive Summary

This document specifies the workflow layer — five tightly related subsystems: (1) the **Automation Rules engine** (if-trigger-then-actions with condition trees, deterministic ordering, dry-run testing, execution logs, and loop protection); (2) **SLA management** (policies with per-priority targets for first response, next response, and resolution, business-hours or calendar time, pause conditions including the channel-unreachable flag, warning thresholds, instances armed per ticket, and the timers that drive Doc 07’s chips and Doc 11’s boosts); (3) **Business hours ****&**** holidays** (tenant calendars with department overrides, consumed by SLA, routing estimates, and off-hours bot behavior); (4) **Macros ****&**** saved replies** (personal/team/tenant scopes, variables, the Doc 07 palette contract); and (5) the **Form builder and Custom Fields** (13 field types, drag-and-drop multi-step forms with conditional logic, dual rendering — web widget forms and Telegram conversational one-field-per-message flows — versioned publishing with submission snapshots) plus **CSAT surveys** (1-tap post-resolution and post-AI-resolution capture on both channels). Everything here is Backoffice-configurable with zero code, and everything emits events for audit and reporting.

## 2. Purpose

- Give operators the no-code workflow toolkit the master requirements demand: rules, SLAs, forms, fields, macros, hours — each safe to change on a live system.

- Define exact evaluation semantics (rule ordering, SLA clock math, condition truth tables, form versioning) so behavior is testable and disputes are decidable.

- Contract the integration points every consumer document renders: SLA chips, macro palette, form renders, CSAT prompts.

## 3. Scope

**In scope:** rules engine (triggers, conditions, actions, ordering, dry-run, logs, loop protection, rate/blast guards); SLA policies, instances, clocks, pauses, warnings, breaches, re-targeting semantics; business hours & holiday calendars; macros & saved replies; custom field definitions (ticket + customer entities); form builder, versioning, dual rendering, validation, submissions; CSAT surveys (both channels, both resolution paths); events for all subsystems. **Out of scope:** routing algorithms (Doc 11 — rules call its actions); SMS template content (Doc 14 — rules trigger sends); AI task internals (Doc 09 — rules consume its outputs and call its actions); report formulas (Doc 15); the widget’s rendering engine (Doc 13 renders this doc’s form contract).

## 4. Functional Requirements

### 4.1 Automation rules

FR-01 Rule = {name, description, trigger, condition tree, ordered actions[], position, active, run-window (optional schedule constraint)}. Triggers (v1): ticket.created, ticket.status_changed, ticket.priority_changed, ticket.transferred, message.received (customer), form.submitted, sla.warned, sla.breached, csat.received, telegram.window.closing|expired (Doc 05 FR-22), customer.rg_flag.set, schedule (cron-like, tenant timezone, min interval 5 min). FR-02 Conditions: nested all/any groups over: ticket fields (status, priority, department, queue, channel, tags, age, custom fields), customer fields (tags, language, country, custom fields, VIP-source field, blocked), message facts (contains/keyword sets, language), AI outputs (intent, sentiment range, confidence range, urgency — Doc 09 classification), time facts (within/outside business hours, day-of-week). Operators per type (equals, in, contains, gt/lt, between, is-empty). Keyword sets are managed lists (reusable across rules). FR-03 Actions (each independently audited): assign (agent/queue via Doc 11), transfer department, set priority/status (through Doc 06’s transition service — illegal transitions fail that action with a logged reason, remaining actions continue), add/remove tags, set custom field, send macro as reply (channel-capability aware), add internal note, notify (users/roles — Doc 16 delivery), send SMS template (Doc 14, template-based only), webhook out (Doc 01 signed), run AI task (summarize→note, classify-refresh, translate-last-message→note), escalate (Doc 06 FR-23 with rule-authored reason), suppress (marketing-suppression flag used by Doc 08 FR-15 consumers). FR-04 Evaluation semantics: per event, active rules whose trigger matches evaluate **in position order**; each matching rule’s actions run in listed order; per-event guards: max 10 rules fire, max 20 total actions, rule-chain depth ≤ 3 (an action-caused event carries a chain-depth counter; exceeding it stops evaluation with a loop_guard log entry + alert). Same-rule-same-ticket cooldown (default 5 min, per-rule setting) prevents thrash. FR-05 Dry-run tester: pick/paste a sample ticket or event → shows which rules would match (with per-condition truth), which actions would run, in order, and which guards would trip — zero side effects; runnable against historical events (last 7 days picker). FR-06 Execution log per rule: matched count, last run, per-execution detail (event ref, condition results, action outcomes incl. failures), retention 90 days; a global “what touched this ticket” view exists on the ticket timeline (rule actions appear as system events with rule links — Doc 06 FR-26 integration). FR-07 Activation is separate from editing (automation.rules.activate vs .edit, Doc 03): edits to an active rule create a draft revision; activating the revision swaps atomically; revision history kept with diff (who changed what).

### 4.2 SLA

FR-08 Policy = {name, order (first match wins), match conditions (subset of FR-02: department, priority, channel, tags, VIP source), targets per priority: first_response, next_response (each subsequent customer message → staff reply), resolution; clock mode: business_hours (with calendar ref) | calendar; warning threshold % (default 80); pause conditions: pending_customer (default on), pending_internal (default off), channel_unreachable (default on — Doc 06 BR-07 realized), outside business hours (implied by clock mode); re-target on priority change: keep | retarget_remaining (default keep, Doc 06 FR-22 badge); breach escalation hook (optional: auto-escalate per Doc 06 FR-23 with policy-authored reason).} FR-09 Instance arming: on ticket creation, first matching policy arms an instance (policy snapshot embedded — later policy edits never mutate armed instances, Doc 06 SS-03 semantics); stages arm/clear per lifecycle: first_response clears on Doc 06 FR-24’s first_response_at; next_response re-arms on each customer message while open-family, clears on staff public reply; resolution clears on resolved. FR-10 Clock math (normative): elapsed = Σ active intervals ∩ (business-hours calendar if mode=business) minus paused intervals; warning fires at threshold% of target; breach at 100%; both emit events (sla.warned|breached {stage}) consumed by Doc 07 chips, Doc 11 boosts, FR-01 triggers, Doc 15. Timers via the platform ZSET pattern (Doc 06 T-03) with recompute-on-pause/resume (no per-second ticking; next-fire-at scheduling). FR-11 SLA chip contract (Doc 07/06 render): stage label + countdown (green → amber at warning → red at breach, pulsing prohibited per reduced-motion rules), pause state shows a pause glyph with reason tooltip; multi-stage display shows the most urgent stage.

### 4.3 Business hours & holidays

FR-12 Tenant calendar: per-weekday intervals (multiple per day supported — split shifts), timezone (tenant), holiday list (date + name, recurring-yearly flag); department overrides (Doc 11 FR-01) reference their own calendar or deltas; editable visually (week grid painter); changes apply prospectively (armed SLA instances keep their snapshot calendar — documented on the edit screen). FR-13 Consumers (contracts): SLA clock (FR-08), Doc 11 estimator off-hours bands, Doc 05/13 off-hours bot templates, FR-01 time conditions, Doc 14 quiet-hours default source.

### 4.4 Macros & saved replies

FR-14 Macro = {name, scope: personal|team|tenant, body (rich-lite per Doc 07 FR-18), variables, optional bundled actions (set status/priority/tags applied on insert-and-send — shown as chips in the palette so agents know a macro does more than text)}; saved reply = body-only macro (same storage, kind). Permissions: macros.personal.manage (own), macros.shared.manage (team/tenant). FR-15 Variables: {{customer.name}}, {{ticket.number}}, {{agent.first_name}}, {{queue.wait_band}} (Doc 11 estimator), custom fields by key; unresolved variables render highlighted in the palette preview and block send until filled or removed (no {{blank}} ever reaches a customer — hard rule). FR-16 Palette contract (Doc 07 FR-19): / search across scopes (personal first), preview pane with live variable resolution, insert; usage counts recorded per macro (Doc 15 macro report; also drives palette ranking).

### 4.5 Custom fields

FR-17 Definition = {entity: ticket|customer, key (immutable snake_case), label (i18n), type: text|number|email|phone|date|time|checkbox|radio|dropdown|multi_select|url|file|rich_text, options (for choice types, each with i18n label + stable value), validation (per type: min/max, regex, required-contexts), agent_editable, customer_visible (forms only), position, active}. Managed under fields.manage (high-risk — field changes ripple everywhere). FR-18 Lifecycle: deactivate hides from new input, values persist read-only with a “retired field” tag (Doc 08 EC-09); reactivation restores; keys never reused; type changes prohibited (create a new field — migration guidance shown); option removal keeps historic values rendered with the stored label snapshot. FR-19 Field values: stored per Doc 01 (customer_fields / ticket form_data+custom fields jsonb per Doc 06 FR-01); indexed for the FR-02 conditions and Doc 07 view filters (expression indexes on hot keys, ops-managed list).

### 4.6 Form builder

FR-20 Form = versioned definition {name, entity bindings (creates/updates ticket with department + optional priority), steps[] each with fields[] (refs to custom fields or inline form-only fields), conditional logic (show/hide field or step when {field op value}, no cross-step forward references), completion actions (route per Doc 11 tag, tag ticket, priority), per-language labels/help texts with completeness gates for enabled languages}. FR-21 Dual rendering (normative): **Widget** (Doc 13 renders): stepped form UI with inline validation, progress, file upload per Doc 04 caps. **Telegram conversational** (Doc 05 flows): one field per message; choice fields → inline buttons (direct mode) / numbered text (business mode); validation errors as friendly retry prompts with 3-attempt-then-offer-human; file field accepts photo/document natively; a mid-form /human or handoff trigger preserves partial data into the packet (Doc 09 FR-12 form_data may be partial, flagged incomplete). FR-22 Publishing: draft → publish creates an immutable version; submissions snapshot {version_id + answers} (Doc 06 form_data — schema-drift-proof); publish validation blocks: conditional referencing missing/retired fields, required file on a capability lacking upload (Doc 04 capability check), enabled language with incomplete labels; usage banner shows bindings (bot menus, rules, widget shortcuts) before publish. FR-23 Seeded forms (provisioning): Deposit Issue, Withdrawal Issue, Verification Request — full schemas (amount+currency, method choice, transaction reference with per-method regex hints, timestamp, screenshot file optional-but-requested, free-text) as editable content (Doc 10 BR-07 stance).

### 4.7 CSAT surveys

FR-24 Trigger points: ticket resolved (Doc 06 FR-06) and AI-resolved (Doc 09 FR-09); one survey per resolution event, suppressed if the same customer received one within csat.cooldown.hours (default 24) or is RG-flagged (suppression contract Doc 08 FR-15). FR-25 Capture: 1-tap score 1–5 (Telegram inline buttons / numbered text per mode; widget star row) + optional comment prompt after scoring; response window 72 h then expires; exactly one response per survey (edits within 1 h replace); events csat.sent|received|expired with {score, comment?, ticket, resolver_type: human|ai} — Doc 15’s CSAT source of truth; low scores (≤2) raise a review item (Doc 09 FR-20 loop + supervisor feed).

## 5. Non-functional Requirements

NFR-01 Rule evaluation per event ≤ 20 ms p99 at 200 active rules (compiled condition trees, indexed guards); action execution async on the automation queue ≤ 2 s p95 to completion. NFR-02 SLA timer accuracy: warning/breach fire within ±30 s of the computed instant (next-fire-at scheduling; recompute on every pause/resume/priority event). NFR-03 Dry-run over a 7-day event window (≤ 50k events) ≤ 10 s with progressive results. NFR-04 Form render definitions cached per version (immutable → infinite TTL); widget form payload ≤ 30 KB. NFR-05 Macro palette search ≤ 50 ms p95 at 1k macros. NFR-06 All five subsystems’ writes are evented (Doc 01 outbox) and audited; zero configuration change is silent.

## 6. User Stories

US-01 As an operator admin, I build “if Bonus Issue form + promo code MEGA10 → tag, route to Bonuses, normal priority” in five minutes and dry-run it against yesterday before activating. US-02 As an operator admin, VIP withdrawal tickets get a 10-minute first-response SLA in business hours, and I can prove exactly when a breach clock paused because we were waiting on the customer. US-03 As an agent, my /refund-timeline macro fills the player’s name and the live queue wait, and warns me before I could ever send a blank variable. US-04 As a player on Telegram at midnight, the deposit form asks me one clear question at a time, accepts my screenshot, and hands me to the morning queue with everything attached. US-05 As a product owner, I add a “payment method” dropdown once and it’s usable in forms, rule conditions, view filters, and reports without engineering. US-06 As a supervisor, every ticket a rule touched says so on its timeline, with a link to the rule and that execution’s detail. US-07 As a player, after my issue is resolved I tap one star rating and I’m done — and nobody surveys me three times in a day.

## 7. Business Rules

BR-01 Deterministic order everywhere: rules by position, policies first-match, actions as listed — no parallel/priority magic; what the admin sees is the execution order. BR-02 Rules act through the same doors as humans: status via the transition service, routing via Doc 11, SMS via templates — automation gains no bypass powers (illegal transitions fail per-action, honestly logged). BR-03 Armed SLA instances are contracts (snapshot semantics): policy and calendar edits are prospective; retroactive SLA rewriting is prohibited (dispute integrity). BR-04 No blank variables to customers (FR-15) and no free-text SMS from rules (FR-03 template-only) — the two classic automation embarrassments are structurally impossible. BR-05 Guards are non-negotiable platform limits (FR-04 caps); operators tune within them, never above them. BR-06 Forms are versioned contracts (FR-22): a submission is forever interpretable against its exact version. BR-07 CSAT is measurement, not marketing: one prompt per resolution, cooldown-suppressed, RG-suppressed, never incentivized in-product.

## 8. UX Requirements

- **Rules list:** position-ordered with drag-reorder, active toggles (permission-gated), matched-count sparkline, last-run, guard-trip badges; revision indicator on edited-active rules.

- **Rule editor:** three panels (Trigger → Conditions tree builder with all/any group nesting, per-row type-aware operator/value inputs, keyword-set picker → Actions ordered list with per-action config cards); inline validation (e.g., SMS action without a template chosen); the dry-run drawer alongside (FR-05) with per-condition truth badges (✓/✗ per row on a sample); save-as-draft vs activate flows per FR-07.

- **SLA policies:** ordered list with match summaries; policy editor shows a live example (“a High Deposits ticket created Tue 17:00 would target first response Wed 08:40”) computed from the calendar; pause-condition toggles with plain-language captions; the re-target choice explained with a diagram.

- **Business hours:** week-grid painter (drag intervals), holiday list with recurring toggles, department-override diff view (“Payments differs: Sat closed”); the prospective-only rule stated on the edit screen (BR-03).

- **Macros:** manager grouped by scope with usage counts; editor with variable palette, live preview against a sample ticket, bundled-action chips; the Doc 07 palette per FR-16.

- **Fields manager:** entity tabs, drag-order, type-locked badges, retired section; option editors with value-stability warnings; “where used” panel (forms, rules, views) before deactivate.

- **Form builder:** left palette (field types + existing custom fields), center canvas with step tabs and drag placement, right inspector (labels per language with completeness dots per Doc 10 pattern, validation, conditional builder with a plain sentence render: “Show when Method is not Cash Agent”); **dual live preview** toggle (widget render / Telegram chat simulation — the Doc 05 FR-27 side-by-side pattern); publish dialog with the FR-22 validation results and usage banner; version history with diff (fields added/removed/changed).

- **CSAT:** survey settings page (cooldown, window, comment prompt on/off, per-language button labels); the customer-facing renders per FR-25 contracts.

- **Empty/loading/error:** seeded content makes empties rare; rule editor skeletons; per-action failure states in execution detail with retry-action affordance (single action re-run, permission-gated, audited).

## 9. UI Requirements

- Condition truth badges (✓ green / ✗ gray with the evaluated value shown) in dry-run — the single most useful debugging affordance, specified as mandatory.

- Guard-trip badges: amber shield with the guard name.

- SLA chips per FR-11 tokens; pause glyph = pause icon + reason tooltip; the amber transition at warning uses a step change, not animation.

- Form canvas: step tabs with completeness dots; conditional fields render with a branch glyph; Telegram preview uses the device-styled frame (Doc 05 §9 component).

- Variable tokens render as pills in editors (uneditable inner text, delete-as-unit).

- Responsive: managers tablet-capable; rule editor and form builder require ≥ 1024 px (fat-finger policy, consistent); SLA/hours/macros/CSAT settings phone-readable, edit on tablet+.

- Dark/light; reduced-motion throughout.

## 10. Technical Requirements

T-01 Tables: automation_rules (+rule_revisions, rule_executions per Doc 01), keyword_sets, sla_policies, sla_instances (snapshot jsonb, stage rows with target_at/warned_at/breached_at/paused ranges), business_calendars (+holidays), macros, field_definitions (+ options), forms + form_versions (schema jsonb), surveys + survey_responses — all per Doc 01 conventions. T-02 Rule engine: event subscriber compiles active rules per trigger into cached decision structures (invalidated on activate); condition evaluation against a materialized event context (ticket+customer+message+AI facts assembled once per event); actions dispatched as an ordered job chain with per-action try/log/continue semantics (BR-02 failures isolate). T-03 Chain-depth: rule-caused events carry automation_depth in payload metadata (Doc 01 event schema extension); depth ≥ 3 → evaluation skip + loop_guard log. T-04 SLA clocks: pure functions over interval sets (calendar intersection, pause subtraction) with a property-tested library (the module’s hardest correctness surface); next-fire-at computed per stage and armed in the ZSET timer (Doc 06 T-03 shared infrastructure); every state-affecting event (status change, message, priority, unreachable flag) triggers recompute. T-05 Calendar snapshots: armed instances embed the resolved calendar (not a ref) per BR-03. T-06 Macro variable resolution: server-side resolver shared by palette preview and send path (one implementation — no drift); unresolved detection blocks at the send API, not only UI. T-07 Form runtime: version schema → widget JSON contract (Doc 13) and Telegram flow state machine (Doc 05 flows consume; per-conversation flow state in Redis with 24 h TTL + DB checkpoint at step boundaries — a crashed flow resumes at the last checkpoint); conditional logic evaluated server-side per step submission (client mirrors for UX, server is truth). T-08 CSAT: survey rows armed on resolution events; channel delivery via the standard outbound path; response ingestion via callback (Telegram) / widget API with single-response upsert (edit window per FR-25). T-09 Contract tests: rule-order/guard fixtures; SLA clock property tests + golden timelines (shared with Doc 15 per Doc 06 AC-09 pattern); form dual-render conformance (schema → both renders → submissions identical shape); macro no-blank-variable enforcement at API; CSAT dedupe/cooldown matrix.

**Rule evaluation flow (text diagram):**

event (outbox) → trigger index → candidate rules (position order)
  → per rule: condition tree vs event context ──✗→ next
        └─✓→ enqueue action chain [a1→a2→…] (per-action log; failures continue)
guards: ≤10 rules, ≤20 actions, depth ≤3, per-rule cooldown → loop_guard log + alert
SLA: state events → recompute stage clocks → arm next-fire → warn/breach events

## 11. Security Requirements

SEC-01 Rule actions execute with a system actor whose capabilities are the *union floor* of what the rule’s activator held at activation for those action types (captured on the revision) — a demoted author’s old rules can’t out-privilege the org silently; activator-capability drift surfaces as rule warnings (re-activation by a privileged admin refreshes). SEC-02 Webhook-out actions: destination allowlist per tenant (admin-managed), signed payloads (Doc 01), no internal-network targets (SSRF egress rules, Doc 04 SEC-03 family). SEC-03 Keyword sets and rule conditions may embed sensitive patterns — automation.rules.view gates reading; execution logs elide message content beyond matched-keyword markers for non-privileged viewers. SEC-04 Form file fields ride Doc 04’s full media security; form definitions are inert content (no script blocks exist in the schema — type-closed). SEC-05 CSAT endpoints are token-bound to their survey (unguessable ids), rate-limited, single-response enforced server-side. SEC-06 High-risk audit events: rule activation/deactivation, SLA policy changes, field deactivation, form publish (Doc 17 visibility classes).

## 12. Performance Requirements

- Budgets per NFR-01–05 under L2 with the guard caps as the worst-case bound.

- SLA recompute ≤ 5 ms per instance; 100k armed instances sweep-capable per Doc 06 T-03 budgets.

- Form step submission round-trip ≤ 150 ms p95 server-side (validation + checkpoint).

- Rule compilation on activate ≤ 1 s for 200 rules; cache invalidation cluster-wide ≤ 10 s (Doc 01 conventions).

- Dry-run progressive first results ≤ 1 s.

## 13. Accessibility Requirements

Condition tree builder fully keyboard operable (add/group/indent via buttons, tree semantics with aria-level); truth badges text-paired; week-grid painter has an interval-list alternative editor (grid painting is enhancement, not requirement); form builder drag has placement menus; Telegram preview transcripts readable as conversation logs; variable pills announced with their resolution state; SLA example sentences (§8) are the accessible explanation of clock math; CSAT customer renders meet AA on both channels (button labels, star row with radio semantics in the widget).

## 14. Localization Requirements

All admin strings workflow.* (en + am); rule/policy/macro names are operator content; form labels/help/validation messages per language with completeness gates (FR-20/22); Telegram flow prompts and error retries localized per the form’s language resolution (customer language → form’s languages → fallback chain); CSAT button labels per language; SLA example sentences ICU-templated; calendar editor honors tenant timezone display with explicit labels; macro variables resolve locale-formatted values (dates, wait bands) per customer language on send.

## 15. Edge Cases

EC-01 Two rules both assign to different queues on ticket.created → position order wins; the second’s assign action logs superseded_state (acted on already-changed state honestly) — the dry-run makes this visible before activation. EC-02 SLA next_response armed, customer sends 5 rapid messages → one armed stage (re-arm is idempotent while armed); clock from the first unanswered message (documented; fixture-pinned). EC-03 Priority raised mid-stage with retarget_remaining → remaining % of the old target rescales to the new target’s duration (formula documented: remaining_new = remaining_pct × target_new); badge explains (Doc 06 FR-22). EC-04 Pause conditions overlap (pending_customer AND unreachable) → paused intervals union (no double subtraction); property tests cover. EC-05 Holiday added while instances armed → snapshot calendars unaffected (BR-03); new instances honor it; the edit screen banner names both facts. EC-06 Form version published while a Telegram flow is mid-form on the old version → the flow completes on its version (checkpointed version ref); submissions snapshot correctly (BR-06). EC-07 Conditional logic hides a required field → publish validation demands the requirement be conditional too (“required when visible”) — the builder auto-suggests the fix. EC-08 Choice option removed after submissions exist → historic renders show the stored label snapshot (FR-18); rule conditions referencing the removed value flag a warning on the rule. EC-09 Macro bundled action illegal for the inserting agent (lacks tickets.priority.change) → text inserts, the bundled action is skipped with an inline notice (per-capability honesty; no silent privilege). EC-10 schedule trigger during a DST-less-but-timezone-shifted tenant change → schedules recompute on timezone setting change; next-run preview always shown in the editor. EC-11 CSAT tap arrives at hour 73 → expired survey; a polite “window closed” (Telegram) / disabled state (widget); no response recorded; csat.expired already emitted. EC-12 Rule cooldown vs a legitimate rapid sequence (form submitted twice for linked-work tickets) → cooldown keys on (rule, ticket) — the second ticket evaluates fresh; fixture-pinned. EC-13 Webhook action target starts failing → per-destination retry ×8 backoff then auto-disable + alert (Doc 01 webhook semantics reused); rule continues (action isolation). EC-14 An admin deactivates a field used by an active rule condition and a published form → the “where used” panel (FR §8) lists both; deactivation proceeds with the rule flagged warning (evaluates as is-empty) and the form’s next publish blocked until edited — live version keeps rendering the snapshot.

## 16. Failure Scenarios

FS-01 Automation queue backlog → rule actions delay (evented lag alert); evaluation itself (synchronous, cheap) is unaffected; SLA clocks and timers are independent (Doc 06 T-03 infrastructure) — deadlines don’t slip because actions queue. FS-02 Timer infrastructure loss → Doc 06 FS-01 rebuild semantics; SLA fires late-not-early (recompute from truth), post-recovery reconciliation marks any missed warned/breached with true timestamps (honest backdating in events, flagged late_fired). FS-03 Rule compilation cache corrupt → fall back to interpretive evaluation (slower, correct) + alert; recompile heals. FS-04 Form flow Redis state lost mid-conversation → resume from the last DB checkpoint (T-07); the customer sees a “let’s continue from…” recap prompt (one step of repetition at worst). FS-05 A rule mass-fires wrongly (bad activation) → the guard caps bound per-event damage; the kill path is the active toggle (instant); the execution log scopes the blast radius for cleanup; the dry-run requirement in review culture (Recommendation 2) is the real prevention.

## 17. Success Scenarios

SS-01 The Doc 04-era journey J5 (new bonus launch) executes end-to-end in under an hour: KB article (Doc 10), Bonus form v2 here, routing rule here, bot menu (Doc 05), SMS template (Doc 14) — zero engineering, full audit. SS-02 An SLA dispute (“you breached VIP terms”) is settled from the instance record: snapshot policy, pause intervals with reasons, warned/breached instants — arithmetic anyone can check. SS-03 A mid-form Telegram handoff delivers partial form data in the packet; the agent completes the two missing answers conversationally; the submission history shows both phases honestly. SS-04 Six months in, the rules list is a living operations manual: position-ordered, named, revision-historied — a new supervisor reads it top to bottom and understands the operation.

## 18. Acceptance Criteria

AC-01 Rule semantics: position order, per-action isolation (transition-service rejection continues chain), all guards (10/20/depth-3/cooldown) fixture-proven incl. EC-01/EC-12. AC-02 Dry-run parity: dry-run verdicts equal live evaluation for a replayed event corpus (simulation-vs-replay, Doc 09 AC-03 pattern). AC-03 SLA clock property tests green (interval math, pause union EC-04, retarget formula EC-03) + golden timelines shared with Doc 15; timer accuracy ±30 s measured; snapshot immutability (BR-03) proven against policy/calendar edits. AC-04 Hours: split shifts, recurring holidays, department overrides, and the SLA example-sentence computation verified against the clock library (one implementation, §8 example uses it). AC-05 Macros: variable resolution parity (preview = send), no-blank enforcement at API (T-06), bundled-action permission honesty (EC-09), palette budget (NFR-05). AC-06 Fields: full type matrix (13 types × validation × render in forms/conditions/filters), lifecycle (retire/reactivate/no-reuse/no-type-change), option-snapshot rendering (EC-08), where-used completeness (EC-14). AC-07 Forms: dual-render conformance suite (schema → widget + both Telegram modes), conditional logic incl. EC-07’s required-when-visible, versioned submissions (EC-06), publish validations (all FR-22 blocks), mid-form handoff partial data (SS-03 fixture), checkpoint resume (FS-04 drill). AC-08 CSAT: trigger matrix (human/AI resolution), cooldown + RG suppression, single-response with edit window, expiry (EC-11), event completeness for Doc 15. AC-09 Security: SEC-01 activator-capability floor enforced and drift-flagged; webhook allowlist + SSRF rules; log elision per SEC-03 role fixtures.

## 19. Future Expansion

- Additional triggers/actions by catalog extension (the engine is trigger/action-registry-driven): time-in-status trigger, customer-anniversary schedule, bulk-action rules.

- Approval steps inside rules (human-in-the-loop actions) — pairs with Doc 03 four-eyes machinery.

- SLA business-value tiers (contractual reporting exports per VIP segment).

- Form logic v2: calculations, cross-step references, prefill from customer fields (prefill is the near-term one — reserved in the schema).

- Macro suggestions fully personalized by Doc 09 edit-mining (currently usage-ranked).

- A/B on CSAT prompt wording under the same one-prompt rule.

## 20. Risks

R-01 Rule sprawl becoming unmaintainable (High likelihood) → position-ordered single list (no hidden layers), naming conventions in onboarding, matched-count visibility exposing dead rules, revision history; consider a quarterly rules-review ritual (operator-side). R-02 SLA clock disputes (High impact) → the property-tested pure library + snapshot semantics + the human-readable instance record are the defense; golden fixtures shared with reporting kill “two numbers” arguments. R-03 Form complexity creep toward a workflow platform (Medium/Medium) → v1 logic limits (no cross-step forward refs, no calculations) are explicit scope walls; §19 lists the sanctioned path. R-04 Automation acting during incidents amplifying damage (Medium/High) → guard caps, per-action isolation, the instant active toggle, and Doc 09’s kill-switch culture extended here (a tenant-wide “pause all rules” switch ships in v1 under features.toggle). R-05 Cooldowns/guards masking legitimate high-frequency needs (Low/Medium) → per-rule cooldown tunable to zero within platform caps; guard-trip logs make the ceiling visible so raising platform caps is an evidenced decision.

## 21. Recommendations

- Build the SLA clock library first as a pure, property-tested package — it is the module’s correctness heart and both Doc 07’s chips and Doc 15’s metrics stand on it.

- Make dry-run-before-activate a cultural requirement (onboarding + a nudge when activating a never-dry-run revision) — FR-05 only prevents incidents if it’s used.

- Ship the seeded forms and 3–5 seeded example rules (deactivated) as teaching artifacts; operators learn the engine by reading working examples.

- Keep the tenant-wide rules-pause switch (R-04) rehearsed in the incident runbook alongside Doc 09’s AI kill switches — one page, all the stop buttons.

- Hold the line on BR-02 (no bypass powers) against every future “just let the rule force it” request; the day automation skips the transition service, Doc 06’s invariants and this module’s honesty both die.

*End of Document 12. Next in series: Document 13 — Web Chat Widget.*