Table of Contents

# Document 11 — Departments, Teams, Queues & Routing

**Product:** AI-First Support Platform for Betting Companies **Document series:** 11 of 19 · Standalone enterprise specification **Audience:** Backend engineers, frontend engineers, QA, product, operator admins, supervisors **Depends on:** Doc 01 (tenancy, settings, events), Doc 03 (scopes, org.* and supervision.* permissions, temporary grants), Doc 05 (connection routing dimension, window/unreachable states), Doc 06 (tickets, transitions, assignment), Doc 08 (RG flag, VIP-relevant fields), Doc 09 (suggested routing, queue-wait estimates). Consumed by: Doc 07 (queue views, agent status contract), Doc 12 (automation routing actions, business hours, SLA), Doc 15 (queue/team reports, supervisor dashboard data). **Stack context:** Laravel, PostgreSQL, Redis (routing state, presence), Horizon

## 1. Executive Summary

This document specifies the organizational structure and the routing engine: **Departments** (the business dimension tickets belong to — Payments, Withdrawals, Bonuses, Verification, Technical, Responsible Gaming, VIP, General…, all operator-configurable and unlimited), **Teams** (people groupings for scope and reporting), **Queues** (the work pipes inside departments that hold unassigned open-family tickets), and the **assignment engine** that moves tickets from queues to agents by round-robin, load-based, or skill/language strategies, honoring agent status and capacity, VIP and RG priority rules, business hours, and supervisor interventions (pull-in, reassignment, monitor/whisper hooks). It defines routing determinism (one documented decision order), the queue-wait estimator the chatbot quotes to customers, rescue flows for unreachable/orphaned tickets, temporary queue membership with auto-expiry (the Doc 03 FR-09 mechanism), the full admin UX for structure management, and every edge case from empty queues at 3 AM to mass agent-deactivation. Routing is where the operator’s service promises become mechanical reality; this document makes the mechanics inspectable — every routed ticket carries a routing-decision record explaining why it landed where it did.

## 2. Purpose

- Define one deterministic routing pipeline so identical inputs always route identically — inspectable, testable, explainable to operators.

- Specify structure administration (departments/teams/queues) as pure Backoffice configuration with safe restructuring semantics for live work.

- Contract the interfaces other modules rely on: Doc 07’s queue views and agent-status consumption, Doc 09’s wait estimates and suggested routing, Doc 12’s routing actions, Doc 15’s queue metrics.

## 3. Scope

**In scope:** department/team/queue models and administration; membership (incl. temporary); agent capacity and status consumption (status semantics owned by Doc 07 FR-28); the routing decision order; assignment strategies; priority ordering inside queues (incl. VIP/RG/window-expiring boosts); business-hours interaction; wait estimation; manual routing operations (reassign, pull-in, broadcast hooks); rescue flows; restructuring live state (delete/rename/move); routing-decision records; events. **Out of scope:** automation rule conditions that *call* routing actions (Doc 12); SLA policy math (Doc 12 — this doc only orders by SLA urgency); supervisor dashboard rendering (Doc 15); monitor/whisper conversation UX (Doc 07/15 — permissions and events only here); notification delivery (Doc 16/18).

## 4. Functional Requirements

### 4.1 Structure

FR-01 **Department:** name (i18n-capable operator content), slug, description, position, default_queue_id, business-hours override ref (Doc 12), active flag. Unlimited count; seeded set at provisioning (General, Payments, Withdrawals, Deposits, Bonuses, Technical, Verification, Responsible Gaming, VIP) — all editable/deletable (content, not fixtures; RG deletion warns about the Doc 08/09 routing contracts and requires choosing a replacement RG target queue). FR-02 **Team:** name, members (users), team lead(s); teams are scope/reporting constructs (Doc 03 Team scope; Doc 15 team reports) and may span departments; a user may belong to multiple teams. FR-03 **Queue:** belongs to exactly one department; fields: name, position, assignment_strategy (round_robin | load_based | skill_based), capacity_per_agent (default from setting routing.capacity.default = 5 concurrent open-assigned), priority_rules (ordered boost list, FR-12), membership (agents; direct or via team), temporary members (grant + expires_at, Doc 03 FR-09), active flag, is_rg (at most one per tenant — the Doc 09 FR-13 target), routing tags (matchable by Doc 12 rules and Doc 05 connection routing). FR-04 Membership resolution: effective queue agents = direct members ∪ members-via-team ∪ unexpired temporary members, intersected with Doc 03 department access (an agent in a queue of a department they can’t see is a configuration error — surfaced as a warning badge, excluded from routing).

### 4.2 Agent availability & capacity

FR-05 Routable = status Online (Doc 07 FR-28 contract) ∧ open-assigned count < capacity ∧ member of the queue. Away/Offline/heartbeat-lost agents are never assigned; system-away (heartbeat) additionally triggers rescue timers (FR-16). FR-06 Capacity counts open-family assigned tickets (Doc 06 FR-10 definition) minus pending_customer (waiting-on-customer doesn’t consume a slot — setting routing.capacity.count_pending default off); per-agent capacity override on the user record (routing.capacity.user) for trainees/leads.

### 4.3 Routing pipeline (normative decision order)

FR-07 Trigger points: ticket created (Doc 06 all paths), transferred (FR-22 Doc 06), unassigned (manual or Doc 06 EC-06 deactivation), reopened-with-inactive-assignee (Doc 06 FR-08), automation route action (Doc 12), queue pull of new backlog. FR-08 Decision order (each step logged into the routing record):

1. Department resolution: explicit (transfer/API/rule) > AI suggestion accepted by config
   (routing.ai.autoaccept: on=apply, off=General+flag) > conversation default
   (Doc 05 connection/bot routing tag) > tenant default department.
2. Queue resolution within department: rule-matched routing tag > department default_queue.
   RG override: rg intent/flag (Doc 09 FR-13, Doc 08 FR-15) forces the is_rg queue — final, step 3 boosts cannot demote it.
3. Queue position: base = FIFO by created_at; boosts (FR-12) compute an effective priority score.
4. Assignment attempt: eligible agents (FR-05) → strategy (FR-09/10/11) → assign (Doc 06 FR-15 semantics, actor=system).
5. No eligible agent → ticket waits in queue (status new); re-evaluated on every availability event
   (agent online, capacity freed, membership change) — event-driven, no polling loop.

FR-09 **Round-robin:** rotating pointer per queue over eligible agents (skips at-capacity), pointer persisted (Redis + DB fallback); joins/leaves splice without resetting fairness. FR-10 **Load-based:** least open-assigned count; tie → least-recently-assigned; deterministic tiebreak by user id (documented, testable). FR-11 **Skill-based:** ordered matchers on ticket attributes → required skills (v1 skills = declared languages + department proficiencies on the user profile, admin-managed): filter eligible to skill-matching agents, then load-based among them; no match → fallback per queue setting (nearest_skill = drop language requirement | wait = hold for a matching agent with an SLA-aware alert at threshold). FR-12 Priority boosts (ordered, additive score; each boost logged): urgent/high ticket priority (Doc 06 FR-17); VIP (customer tag/field match configured per tenant, Doc 08 fields); reply-window closing (Doc 05 FR-22 closing — beat the window); SLA at-risk (Doc 12 warned); reopened (customer already waited once); age-cap guarantee: any ticket waiting > routing.starvation.minutes (default 30) jumps to queue head regardless of boosts (no starvation, ever).

### 4.4 Waiting, estimates, hours

FR-13 Queue-wait estimator (consumed by Doc 09 FR-12 handoff copy and Doc 13 widget): per queue, rolling estimate = f(current depth ahead of this ticket’s position, trailing 30-min assignment throughput, eligible-agent count); returns a banded string contract (“~5 min” / “~20 min” / “over an hour” / “when we open at 08:00” outside hours) — bands, not false precision; recomputed on depth/throughput events, cached 30 s. FR-14 Outside business hours (Doc 12 calendars, department override per FR-01): routing still assigns if agents are Online (night shifts exist); if none, tickets queue with the off-hours estimate band; the chatbot’s off-hours template (Doc 05 FR-28) consumes the same estimator.

### 4.5 Manual operations & rescue

FR-15 Supervisor ops (Doc 03 supervision.*): reassign any in-scope ticket (drag or dialog; bypasses strategy, logged manual_by); **pull-in**: add an agent to a queue temporarily (expiry default shift-end via business hours, editable) creating the Doc 03 temporary grant; force agent status (Doc 07 FR-28 attribution); broadcast note to queue members (event to Doc 16 notifications). FR-16 Rescue flows: (a) heartbeat-lost agent (Doc 07 FR-28) with active assigned open tickets → after routing.rescue.minutes (default 10) tickets flagged in the Rescue view (Doc 07 FR-05) and — if setting routing.rescue.auto on — returned to queue with a system note; (b) channel_unreachable (Doc 05 FR-10) tickets populate Rescue immediately (routing never auto-acts on these — BR-07 Doc 06); (c) orphaned tickets (queue deleted mid-flight, EC-06) auto-move to department default with a system note. FR-17 Routing-decision record: every automatic routing writes {trigger, steps: [{step, input, outcome}], strategy, eligible_count, boosts_applied, assigned_to|waiting} attached to the ticket timeline (collapsed system event, expandable) — the “why did this land here” answer for supervisors and support-of-support.

### 4.6 Restructuring live state

FR-18 Rename: free (references are by id). Deactivate queue: stops intake (rules targeting it fall to department default with warnings listed), existing tickets drain normally. Delete queue: requires empty-or-reassign choice (bulk move to another queue with progress); Doc 07 EC-09 view behavior follows. Delete department: requires reassigning its queues/tickets and updating rules (guided wizard listing every reference: rules, bot routing tags, SLA policies, views); RG department special-case per FR-01. FR-19 Events: org.department|team|queue.created|updated|deleted, queue.membership_changed, queue.temporary_grant.created|expired, ticket.routed {record_id}, ticket.waiting {queue, position}, routing.starvation_boosted, routing.no_skill_match, rescue.flagged|auto_returned — feeding Doc 15’s live dashboard and Doc 17 audit.

## 5. Non-functional Requirements

NFR-01 Routing decision (steps 1–4) ≤ 50 ms p99 with 200 eligible agents; assignment commit uses Doc 06 transition budgets. NFR-02 Availability-event reaction (agent frees capacity → next waiting ticket assigned) ≤ 2 s p95; no polling loops anywhere (event-driven per FR-08 step 5). NFR-03 Determinism: identical snapshot inputs produce identical routing (property-based test harness); all tiebreaks documented. NFR-04 Estimator staleness ≤ 30 s; banded output changes are rate-limited (no flapping between “~5” and “~20” every recompute — hysteresis). NFR-05 Restructure operations on 10k-ticket queues complete ≤ 60 s (chunked) with progress; intake never pauses for sibling queues. NFR-06 Starvation guarantee is absolute: the age-cap boost (FR-12) is exempt from every other rule; weekly report asserts max observed wait ≤ cap + assignment latency.

## 6. User Stories

US-01 As a player, the wait estimate the bot quoted is honest — banded, and roughly what happened. US-02 As an agent, work arrives at my capacity and skill: Amharic payment tickets when I’m the Amharic payments person, nothing while I’m at my limit. US-03 As a supervisor at match time, I drag two General agents into Deposits for the evening, and they auto-leave at shift end without me remembering. US-04 As a supervisor, when a ticket sits oddly long, I expand its routing record and see exactly why (no Amharic agent online; boost history; starvation jump at minute 30). US-05 As an operator admin, I restructure departments after a reorg through a wizard that shows me every rule and bot binding I’m about to break — before I break it. US-06 As an RG specialist, flagged-customer tickets reach my queue no matter what else is configured, and nothing demotes them. US-07 As a trainee, my capacity is set to 2 while I learn, and the router respects it.

## 7. Business Rules

BR-01 Fairness to customers outranks matching sophistication: the starvation cap (FR-12/NFR-06) overrides every strategy and boost — no customer waits unboundedly because they’re low-priority. BR-02 RG routing is final (FR-08 step 2): no rule, boost, strategy, or manual bulk action may divert RG-flagged intake from the RG queue (individual manual reassign by RG-permitted staff excepted, audited). BR-03 Humans override machines, visibly: manual reassignment always wins and is always attributed — the router never “fixes” a supervisor’s placement. BR-04 Structure is operator content: unlimited, renameable, deletable with guided consequences — the platform has opinions (seeds), not fixtures. BR-05 Waiting is a state, not an error: empty-agent scenarios queue honestly with honest estimates; the router never assigns to Away agents to make numbers look better. BR-06 Every automatic decision is explainable (FR-17): if the record can’t express why, the feature doesn’t ship. BR-07 Temporary means temporary: pull-ins expire mechanically (Doc 03 FR-09); standing exceptions belong in membership, and the UI nudges conversion when the same pull-in recurs (3× in a week → “make permanent?” hint).

## 8. UX Requirements

- **Structure admin (Backoffice → Organization):** three tabs (Departments / Teams / Queues). Departments: reorderable list with queue counts and default-queue badges; detail = fields + hours override + danger zone (deactivate/delete wizard). Teams: member management with role-context chips, multi-team visibility. Queues: list grouped by department with strategy badges, member avatars stack, capacity, temporary-member chips with countdowns, warning badges (FR-04 config errors, rule-target orphans); detail = strategy config (per-strategy panel: RR shows the rotation preview; load shows the tiebreak note; skill shows matcher builder + fallback choice), priority-boost ordering (drag list with the locked starvation row pinned last-but-absolute, visually explained), membership editor, routing-tag list.

- **Delete/restructure wizards:** reference inventory step (rules, bots, SLA, views, ticket counts) → choices per reference → typed confirmation → progress with per-chunk status.

- **Supervisor surfaces (data contracts; rendering in Doc 15/07):** queue table rows (depth, oldest wait, eligible/online agents, throughput sparkline), drag-reassign affordance, pull-in dialog (agent picker + expiry defaulted to shift end), routing-record expandable in ticket timeline (step list with inputs/outcomes in plain language: “Skill match: Amharic — 0 agents online → fallback: wait”).

- **Empty states:** no queues in a department (“intake falls to… nothing — create a queue” warning-toned); no eligible agents banner on queue detail with the online/at-capacity breakdown.

- **Loading/error:** structure lists skeleton; wizard reference-inventory shows per-system checks with retries; assignment failures (EC-03 race) resolve silently per Doc 06 FR-16 patterns.

## 9. UI Requirements

- Strategy badges: RR (cycle glyph), Load (scale glyph), Skill (target glyph) + labels.

- Temporary-member chips: dashed border + countdown (Doc 05 chip component family).

- Warning badges: amber triangle + text, hover for the specific misconfiguration.

- The starvation rule row in the boost list: locked style with a plain-language caption (“After 30 min waiting, any ticket jumps the line. Always on.”).

- Routing-record steps: numbered rows, monospace inputs, human sentence outcomes; copy-as-text for support escalations.

- Responsive: structure admin tablet-capable; wizards full-screen mobile-capable read-only (execution desktop/tablet per fat-finger policy); supervisor drag falls back to menus on touch (Doc 15 alignment).

- Dark/light; reduced-motion (no sparkline animation).

## 10. Technical Requirements

T-01 Tables: departments, teams (+team_members), queues (+queue_members with kind: direct|temporary, granted_by, expires_at), user_skills (language codes, department proficiencies), routing_decisions (ticket_id, record jsonb, at), routing tags on queues; Doc 03’s temporary-grant rows link to queue_members temporary kind (single source). T-02 Routing engine: a synchronous service invoked from Doc 06 transition/creation flows and availability-event listeners; queue waiting sets in Redis ZSETs per queue (score = effective priority + age function) mirroring DB truth (rebuildable, Doc 05/06 pattern); assignment commits through Doc 06’s TicketTransitionService (actor=system) — routing never writes ticket rows directly. T-03 Availability events: Doc 07 status changes, Doc 06 assignment/transition events (capacity deltas), membership changes → a per-queue dispatcher that pops the head of the ZSET and attempts assignment (optimistic; on race → next candidate) — the FR-08 step-5 loop as pure event reaction. T-04 Round-robin pointer: Redis with DB checkpoint; splice logic unit-tested against join/leave sequences. T-05 Skill matchers: declarative config (attribute → requirement) evaluated in order; language sourced from ticket/conversation language (Doc 04), department proficiency from queue’s department. T-06 Estimator: per-queue Redis rolling counters (assignments/30 min) + depth; banded mapper with hysteresis (NFR-04); exposed internally to Doc 09/13 via a cached endpoint. T-07 Expiry sweep for temporary members: ZSET by expires_at (the platform’s standard timer pattern), 1-min sweep + event-driven checks at assignment time (an expired member is ineligible even before the sweep — resolver time check, Doc 03 FR-09 alignment). T-08 Property-based determinism harness (NFR-03): generated org snapshots + ticket streams replayed twice must produce identical assignments; runs in CI on the routing package. T-09 Contract tests: the FR-08 order as table-driven fixtures (every override precedence, RG finality, starvation exemption); consumer contracts for Doc 09 estimator bands and Doc 07 Rescue-view population.

**Assignment reaction (text diagram):**

availability event (agent online / slot freed / membership change)
  → queue dispatcher: pop ZSET head → eligible? (status·capacity·skill·membership)
       ├─ yes → TransitionService.assign(system) → routed event + record
       └─ race/ineligible → next candidate … → none → remains waiting (estimate updates)
ticket events (created/transferred/…) → pipeline FR-08 steps 1–4 → same commit path

## 11. Security Requirements

SEC-01 All manual operations re-check Doc 03 scope on target tickets and target agents (assignee scope per Doc 06 FR-15); pull-in creation requires org.queues.pull_in and is bounded to queues within the granter’s scope. SEC-02 Routing records may contain skill/availability facts about agents — visible to supervision-scope roles only (reports.agents.individual gates the per-agent details inside expanded records for non-supervisors; the record renders with agent names elided otherwise). SEC-03 Temporary grants are the only privilege-widening path routing can create, and they flow through Doc 03’s audited grant machinery — the router itself holds no grant authority. SEC-04 Restructure wizards execute with the operator admin’s live permissions per chunk (Doc 03 job semantics); reference inventories never display out-of-scope rule contents (names + counts only where scope lacks). SEC-05 Estimator outputs (customer-facing) contain bands only — never agent counts or names (no staffing intelligence leakage to customers). SEC-06 routing.starvation_boosted and RG-routing events are standard audit; manual RG-queue diversions (BR-02 exception) are high-visibility.

## 12. Performance Requirements

- Budgets NFR-01/02 under L2 with 50 queues, 500 agents, 200 waiting tickets/queue burst.

- ZSET operations O(log n); dispatcher throughput ≥ 100 assignments/s platform-wide.

- Estimator recompute ≤ 5 ms; endpoint cached 30 s (T-06).

- Structure admin lists ≤ 200 ms p95 at 100 departments/500 queues (pathological but supported).

- Restructure chunking per NFR-05 with intake isolation verified (sibling-queue assignment latency unchanged during a migration — measured in the drill).

## 13. Accessibility Requirements

Structure admin fully keyboard operable (reorder via move buttons, wizards as stepped forms with progress announced); strategy/warning badges icon+text; temporary countdowns follow the platform SR cadence; routing records render as ordered lists with plain-language outcomes (the monospace inputs have text labels); drag-reassign and pull-in have complete dialog equivalents; queue tables are proper tables with sortable headers announced; the locked starvation row’s explanation is programmatically associated with the list.

## 14. Localization Requirements

Structure names are operator content (any language); platform strings under routing.* (en + am); estimator bands localized with culturally sane phrasing (reviewed: “over an hour” equivalents); off-hours estimates use the tenant timezone with explicit labels (Doc 01 rules); routing-record sentences generated from templated i18n keys with slot-safe grammar (ICU); skill language names from the Doc 01 reference data localized list.

## 15. Edge Cases

EC-01 All agents at capacity, one frees a slot as two tickets wait in different queues they serve → per-queue dispatchers race; the agent’s capacity check at commit serializes — one wins, the other’s dispatcher moves on; fairness across queues emerges from event ordering (documented; the starvation cap bounds any pathology). EC-02 Agent goes Away with the RR pointer on them → pointer skips (FR-05), fairness splice keeps their turn on return (T-04 sequences). EC-03 Two dispatchers pick the same agent for different tickets simultaneously → optimistic assign; second hits capacity → next candidate (T-03); no error surfaces. EC-04 Skill queue with wait fallback and the only Amharic agent resigns → routing.no_skill_match alert at threshold; the starvation cap still fires (language requirement dropped at the cap — BR-01 over matching; the record shows both facts); admin warning badge on the queue. EC-05 VIP boost configured on a tag agents can edit → boost respects Doc 03 (tickets.tags.manage) but the config screen warns when the VIP source is an agent-editable tag, recommending a customer field instead. EC-06 Queue deleted while its dispatcher processes → in-flight assignment completes or fails to the orphan path (FR-16c); waiting ZSET migrates in the wizard’s bulk move. EC-07 Pull-in expiry mid-conversation → assignments already made persist (tickets stay with the agent — Doc 03 EC-05 scope note applies to *new* visibility); no new assignments; the agent’s queue view vanishes per Doc 07 EC-09. EC-08 Reopened ticket (Doc 06 FR-08) with previous assignee now Away → waits for them per setting routing.reopen.wait_for_previous (default 15 min) then re-routes; the record shows the courtesy hold. EC-09 Business-hours boundary during assignment (17:59:59 ticket) → hours affect estimates and off-hours templates, never eligibility (FR-14) — an Online agent at 18:01 gets assigned; no cliff behavior. EC-10 Circular fallbacks (department default queue deactivated, rules target each other) → validation at save prevents defaultless departments; the orphan path (FR-16c) is the terminal net with an alert. EC-11 A tenant with one department, one queue, two agents (tiny operator) → everything above degrades gracefully to “shared inbox with fairness”; no minimum-structure requirements. EC-12 Mass deactivation (Doc 06 EC-06, 10 agents at shift end without status discipline) → capacity events flood; dispatchers drain; the Rescue view + system-away timers catch stragglers; the onboarding materials teach the status habit (Doc 07 BR-06 tie-in).

## 16. Failure Scenarios

FS-01 Redis routing state lost (ZSETs, RR pointers) → rebuild from DB truth (waiting = open-family unassigned per queue; pointers reset with fairness restart noted in ops log); assignments pause ≤ rebuild time (target < 60 s); alert per Doc 18. FS-02 Dispatcher worker crash mid-pop → ticket remains in ZSET (pop is read-then-commit; removal only on successful assignment) — at-least-once with idempotent assignment (Doc 06 semantics). FS-03 Estimator counters corrupted → bands fall back to depth-only heuristic with a degraded flag (Doc 09/13 consume the same honest band contract); heals on counter rebuild. FS-04 Routing engine exception on a ticket (bad config edge) → ticket parks in the department default queue with an error record + alert (never lost, never looping); the parked-with-error state is queryable. FS-05 Temporary-grant sweep down → assignment-time expiry checks (T-07) keep security correct; only the cleanup lags (Doc 03 FS-04 family).

## 17. Success Scenarios

SS-01 Match-night: Deposits depth spikes 10×; supervisors pull in two agents (auto-expiring), boosts and the starvation cap keep max wait bounded, the estimator’s bands stay honest, and the morning report reconstructs the whole evening from routing records and events. SS-02 A misroute complaint (“why did VIP wait 40 min?”) is answered in one minute from the ticket’s routing record: VIP boost applied, zero Amharic agents online 19:10–19:40, starvation jump at 19:40, assigned 19:41. SS-03 A reorg (splitting Payments into Deposits/Withdrawals) executes through the wizard in 20 minutes with every rule and bot binding consciously redirected — zero orphaned tickets, zero silent breakage. SS-04 The determinism harness (T-08) has run green for six months of routing changes — refactors land without behavioral drift.

## 18. Acceptance Criteria

AC-01 FR-08 order fixtures: every precedence (explicit > AI > connection-default > tenant-default; rule-tag > default queue; RG finality; boost ordering; starvation exemption) proven table-driven. AC-02 Strategy conformance: RR fairness over join/leave/skip sequences (T-04); load tiebreak determinism; skill matcher + both fallback modes incl. EC-04’s cap interaction. AC-03 Capacity semantics: pending_customer exclusion toggle, per-user overrides, race serialization (EC-01/03) under concurrent-load test with zero over-capacity assignments. AC-04 Event-reaction latency: agent-frees-slot → next assignment ≤ 2 s p95 under L2 (NFR-02 measured). AC-05 Temporary membership: creation via pull-in → Doc 03 grant linkage, expiry via sweep AND assignment-time check independently (disable one path in test, security holds), recurrence hint at 3× (BR-07). AC-06 Rescue: heartbeat-loss, unreachable, and orphan fixtures populate the Doc 07 Rescue view per FR-16 with correct auto-return behavior per setting. AC-07 Restructure wizards: reference inventories complete (seeded cross-references all discovered), chunked execution within NFR-05, sibling-intake isolation measured, RG special-case enforced. AC-08 Routing records: every automatic routing produces a record whose replay through the engine (T-08 harness) yields the same outcome; supervisor rendering matches the plain-language contract; SEC-02 elision per role verified. AC-09 Estimator: band mapping + hysteresis fixtures; off-hours band; SEC-05 leakage assertions (no counts/names in customer-facing payloads). AC-10 Determinism harness green in CI (NFR-03) across the release’s routing changes.

## 19. Future Expansion

- Richer skills (certifications, product lines) and proficiency levels with weighted matching — the matcher config is the extension point.

- Forecast-driven staffing suggestions (Doc 15 volume forecasts → “you’ll need 3 more Deposits agents Saturday”) — workforce-management seed.

- Priority scoring by predicted handle time or churn risk (Doc 09 signals) — as *boosts* under the same starvation cap, never replacing it.

- Cross-queue balancing policies (spillover rules between sibling queues) — currently manual pull-in territory by design.

- Customer-choice routing (“press 1 for…” style flow bindings mapping to routing tags — Doc 05/12 flows already produce tags).

## 20. Risks

R-01 Routing opacity eroding trust (“the system hates my queue”) (High likelihood) → FR-17 records + SS-02-style explainability are the designed antidote; supervisor training leans on them. R-02 Boost-stacking pathologies burying normal tickets (Medium/High) → the absolute starvation cap (BR-01) bounds worst case mathematically; weekly max-wait assertion (NFR-06) detects drift. R-03 Redis-state divergence from DB truth (Medium/Medium) → rebuildability (FS-01), mirrored-truth pattern proven in Docs 05/06, staging consistency probes. R-04 Skill data rot (agents’ languages unmaintained) (High/Low) → skills surface on profiles with last-reviewed nudges; EC-04’s alerts expose gaps operationally. R-05 Restructure fear causing config debt (operators afraid to touch live structure) (Medium/Medium) → the wizard’s inventory-first design and SS-03 documentation exist to make reorgs routine; staging rehearsal supported via config export (Doc 16 tie-in).

## 21. Recommendations

- Implement the routing record (FR-17) with the first line of engine code, not after — explainability retrofitted is explainability lost, and it doubles as your best debugging tool during development.

- Ship round-robin + load first; skill-based follows once real language data exists on profiles — matching sophistication before data quality just routes confidently to the wrong people.

- Treat the starvation cap as a published customer promise in operator onboarding (“no ticket waits more than X regardless of priority”) — it converts an internal safeguard into a trust asset.

- Run the reorg drill (SS-03) on staging with the operator’s real structure before go-live; the wizard’s reference inventory is only trusted after it has been seen catching things.

- Keep the router stateless-but-for-Redis and committed-through-Doc-06 (T-02) permanently; the moment routing writes tickets directly, two sources of transition truth exist and Doc 06’s guarantees quietly die.

*End of Document 11. Next in series: Document 12 — Automation, SLA, Forms **&** Custom Fields.*