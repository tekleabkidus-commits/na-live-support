Table of Contents

# Document 15 — Reporting, Analytics & Dashboards

**Product:** AI-First Support Platform for Betting Companies **Document series:** 15 of 19 · Standalone enterprise specification **Audience:** Backend engineers, frontend engineers, data engineers, QA, product, operator admins, supervisors **Depends on:** Doc 01 (event stream, aggregates table, tenancy), Doc 03 (permissions reports.*, scope rules, reports.agents.individual), Doc 05 (Telegram events, calibration telemetry), Doc 06 (metric timestamps FR-24, spam exclusion, golden fixtures), Doc 09 (ai_decisions, digests, anomaly outputs), Doc 11 (routing events, queue data, supervisor ops), Doc 12 (SLA instances, CSAT events, golden timelines), Doc 13 (widget beacons/events), Doc 14 (SMS events, cost). Consumed by: every management persona; Doc 16 (scheduled-report notification delivery); Doc 17 (export audit). **Stack context:** Laravel, PostgreSQL (events + hourly aggregates), Redis (realtime counters), Reverb (live dashboard), Browsershot/Laravel Excel (exports), Horizon reports queue

## 1. Executive Summary

This document specifies the analytics layer: the **semantic metric dictionary** — one governed registry where every metric has a name, a normative formula bound to source events and Doc 06’s transactional timestamps, declared filters, business-hours awareness, and exclusion rules (spam and test/sandbox traffic never count) — the **two-speed data architecture** (an hourly-aggregate warehouse fed by the Doc 01 event stream for historical reports, and a Redis realtime layer for the supervisor live dashboard), the **prebuilt report catalog** across eight families (Agent, Team/Queue, Customer, AI, Telegram, Live Chat, SMS, SLA), the **supervisor live dashboard** (agents, queues, SLA risk, alerts, and the manual-intervention surface contracted from Doc 11), the **dashboard builder** (drag-and-drop widgets — KPI cards, line/bar/pie charts, tables, hour×day heatmaps, leaderboards, activity feeds — with sharing and full-screen rotating TV mode), **exports** (PDF snapshots, Excel, CSV; one-off and scheduled with delivery via Doc 16), scope-correct data visibility including the reports.agents.individual gate on per-person metrics, drill-down paths from every number to its underlying tickets, and the reconciliation discipline (golden fixtures shared with Docs 06/12, drift checkers, and the rule that a metric that cannot be explained by drill-down does not ship). The design goal is a single sentence: **no two screens ever show two different numbers for the same question.**

## 2. Purpose

- Centralize metric truth: every consumer (prebuilt reports, custom dashboards, exports, digests, the API) reads the same dictionary-resolved values.

- Give supervisors a live floor view and managers an honest historical view, each on the storage tier built for it.

- Make every number defensible: formula on hover, drill-down to source tickets, fixtures shared with the producing modules.

## 3. Scope

**In scope:** the metric dictionary (registry, formulas, dimensions, exclusions, versioning); ingestion (events → hourly aggregates; realtime counters); the report catalog per family with each report’s content; supervisor live dashboard (data + interactions); dashboard builder (widgets, layout, sharing, TV mode); date/filter semantics; drill-downs; exports & scheduling; report API; scope & privacy rules; reconciliation & drift tooling; performance tiers. **Out of scope:** event production (owning docs); the SLA clock math (Doc 12’s library — this doc consumes instances); AI anomaly generation (Doc 09 — rendered here); notification transport for schedules (Doc 16); data-warehouse-external BI connectors (future §19); billing/invoicing (not in product).

## 4. Functional Requirements

### 4.1 Metric dictionary

FR-01 Registry entry = {key (stable, e.g. tickets.frt.median), family, i18n label + description, formula (normative text + implementation ref), source events/tables, unit, aggregations allowed (sum/avg/median/p90/count/rate), dimensions allowed (time grain, department, queue, team, agent, channel, language, tag, priority, template, gateway…), business-hours variant (yes/no — where yes, both calendar and business-hours values exist as distinct keys), exclusions (spam per Doc 06 FR-09/AC-06, sandbox/test per Docs 05/13/14 test-mode rules — both mandatory platform-wide), scope behavior (how Doc 03 scopes filter it), privacy class (aggregate | per_agent — the latter gated by reports.agents.individual), version}. FR-02 v1 metric set (keys per family; formulas in the registry, headline definitions normative here): - **Agent:** handled, resolved, reopened-rate, escalated, FRT (first agent_first_response_at − opened, Doc 06 FR-24), avg response time, resolution time, CSAT avg/count, AI-assist usage (Copilot inserts per Doc 09 FR-16), active time (activity-event derived per Doc 07 BR-06 — never status-clock), tickets-per-active-hour. - **Team/Queue:** volume in/out, backlog + age profile (open-family buckets), SLA attainment per stage, utilization, workload balance (max/min assigned ratio), throughput, wait p50/p90 (queued→assigned). - **Customer:** created/closed, repeat-contact rate (contacts per resolution), top intents (Doc 09 classifications), satisfaction distribution, deflection (Doc 10 FR-12 attribution: KB view/citation → no ticket in-conversation within 24 h). - **AI:** resolution rate (AI-resolved / AI-touched), handoff rate + reasons distribution, reopen-after-AI, CSAT(AI) vs CSAT(human) — always rendered together with resolution rate (the honest triad, Doc 09 R-06), confidence calibration buckets, top unanswerable intents, token cost per resolution. - **Telegram:** messages in/out, delivery success/failure by class, media volume, active conversations, per-connection health history, window expiries + re-engagement conversion (SMS sent → customer returned, the Doc 05/14 loop measured), calibration telemetry (Doc 05 FR-21). - **Live Chat:** loads, opens, chats started, AI-resolved, handoffs, queue-wait actual vs banded estimate honesty check, widget error rate (Doc 13 beacons), proactive conversion. - **SMS:** sent/delivered/undelivered/expired by gateway+country, cost by template/gateway, opt-out rate, guard-skip distribution, DLR latency, missing-DLR rate (the Doc 14 FS-03 health signal). - **SLA:** attainment per policy/stage/priority, breach count + time-to-breach distribution, pause-time composition (which pause reasons consume clock), warning→save rate (warned then met). FR-03 Governance: new metrics enter via registry change control (owner sign-off, fixture required — the Doc 01 R-03 pattern); keys never renamed (deprecate + replace); every metric’s hover shows label, formula sentence, exclusions, and business-hours mode (“First response time = …, business hours per the Payments calendar, spam and test traffic excluded”).

### 4.2 Data architecture

FR-04 **Historical tier:** consumers of the Doc 01 event stream project into reports_aggregates (metric, dimension tuple, hour bucket, value) with idempotent upserts keyed by event ids; late events (backlog drains, Doc 06 FS-02) correct affected buckets (re-aggregation of touched hours); raw events retained per Doc 01 NFR-07 enabling full rebuilds. FR-05 **Realtime tier:** Redis counters/sets maintained by the same consumers for the live dashboard’s window (now-state + trailing 60 min): agents by status, active chats, queue depths + oldest waits (Doc 11’s ZSETs read directly), SLA at-risk/breach counts, alert feed; realtime values are *approximate by contract* (labeled “live”) and never the source for historical reports (the two tiers answer different questions and are never mixed in one widget). FR-06 Reconciliation: nightly checker recomputes a sample of aggregate buckets from raw events (drift alert per Doc 07 NFR-07 pattern); the Doc 06 AC-09 / Doc 12 AC-03 golden fixtures run against the dictionary in CI (the shared-fixture contract realized); full-rebuild runbook (Doc 18) with per-metric rebuild support.

### 4.3 Reports, filters, drill-down

FR-07 Prebuilt report pages per family (FR-02’s content as designed pages): each = header KPI row + primary chart + breakdown table, with the global filter bar (date range with comparison period, department, queue, team, agent [gated], channel, language, tag, priority; family-specific: policy, template, gateway, bot/connection, widget); date semantics: tenant timezone, inclusive ranges, grain auto (hour ≤ 3 d, day ≤ 90 d, week/month beyond), comparison renders as delta chips. FR-08 Drill-down: every aggregate number links to its constituent list (tickets/messages/sends) rendered through Doc 07’s list components with the metric’s filter context applied — capped at 10k with export-instead guidance; drill-down respects the viewer’s scope even where the aggregate was computed tenant-wide for privileged viewers (documented duality: an All-scope manager drilling into a number sees everything; a Department-scope lead viewing a shared dashboard sees the number recomputed to their scope — see FR-15). FR-09 The AI daily digest (Doc 09 FR-17) and anomaly alerts render in a Reports-home feed panel with links into the relevant report at the relevant range.

### 4.4 Supervisor live dashboard

FR-10 Layout (the Doc 11/Doc 07 contracts rendered): KPI strip (waiting, oldest wait, active chats, agents online, SLA at-risk, breaches today) → queue table (per Doc 11 §8: depth, oldest, eligible/online, throughput sparkline, drag-reassign) → agent grid (status + attribution per Doc 07 FR-28, active load, time-in-status, today’s handled/resolved/CSAT [per-agent gate applies], monitor/whisper entry points per Doc 03 supervision.*) → live alert feed (SLA warnings/breaches, window expiries, routing no-skill-match, channel health changes, AI anomalies, gateway circuits — each actionable with its deep link). FR-11 Interactions: everything Doc 11 FR-15 (reassign, pull-in, force status, broadcast note) executes from here with the owning module’s semantics; monitor/whisper opens the conversation in the Doc 07 thread with a supervision banner (whisper messages are internal-note-typed — Doc 04 BR-01 keeps them structurally undeliverable). FR-12 TV mode: any dashboard (incl. this one) full-screens with auto-refresh, rotating pages (config), large-type tokens, and no interactive affordances (display-only; a TV session holds no privileged interaction surface — SEC-04).

### 4.5 Dashboard builder

FR-13 Dashboard = {name, layout (12-col grid, drag/resize), widgets[], sharing (private | team | tenant; share requires reports.dashboards.share), tv_config (rotation, refresh)}. Widget = {type: kpi_card | line | bar | pie | table | heatmap(hour×day) | leaderboard | activity_feed, metric key(s) from the dictionary, dimensions/filters, comparison period, realtime|historical tier flag (type-constrained: activity_feed and now-state KPIs = realtime; charts = historical), display options (targets/thresholds with color bands, units)}. Widgets validate against the registry (a metric’s allowed aggregations/dimensions constrain the editor — invalid combinations are unbuildable, not error-prone). FR-14 Leaderboards render per-agent metrics only for viewers holding reports.agents.individual; shared dashboards containing gated widgets show a “restricted widget” placeholder to unentitled viewers (never partial data). FR-15 Shared-dashboard scope semantics: widgets recompute per viewer’s scope (the FR-08 duality) — a tenant-shared “Department performance” dashboard is safe by construction; the editor previews “as: me | as: role X” for share-sanity checks.

### 4.6 Exports, schedules, API

FR-16 Exports: any report/dashboard → PDF (rendered snapshot with filters + generation stamp + requesting-user watermark), Excel/CSV (tabular per widget/table with the dictionary keys as headers alongside localized labels); reports.export (high) gates all; every export audited (Doc 17) with parameters. FR-17 Schedules: {report/dashboard, filters, cadence (daily/weekly/monthly at tenant-timezone time), format, recipients (staff users/roles — delivery via Doc 16 notification/email)}; reports.schedule gates; scheduled outputs render under the *schedule owner’s* scope frozen at save (owner deactivation pauses the schedule with an alert — no orphaned-privilege exports); each delivery audited. FR-18 Report API (GET /v1/reports/{key} per Doc series API catalog): dictionary-resolved, same scope rules, same exclusions — the API is a consumer, never a bypass.

## 5. Non-functional Requirements

NFR-01 Historical report page ≤ 1.5 s p75 warm (aggregate reads only — never raw-event scans in the request path); 13-month range renders within budget via monthly rollups layered over hourly. NFR-02 Realtime tiles update ≤ 2 s from source event (Reverb push); the live dashboard sustains 50 concurrent supervisor sessions per tenant. NFR-03 Aggregation lag ≤ 60 s p95 from event to queryable bucket (Doc 01 outbox lag budgets inherited + projector budget). NFR-04 Drill-down list first page ≤ 800 ms p95. NFR-05 Export generation: PDF ≤ 30 s, Excel 100k rows ≤ 60 s, async with progress + notification. NFR-06 Reconciliation: nightly sample drift = 0 tolerance (any drift alerts and blocks the “verified” badge shown on report pages — an honest freshness/integrity indicator). NFR-07 The exclusion rules (spam, test) are enforced in the projection layer once — no per-report reimplementation (single-point correctness).

## 6. User Stories

US-01 As an operations manager, FRT on my Monday report equals FRT in my custom dashboard equals FRT in the export — always, provably. US-02 As a supervisor, the live board shows the floor as it is right now, and every alarming number is one click from the tickets behind it. US-03 As a team lead with department scope, the tenant-shared dashboard shows *my department’s* truth, not a forbidden peek at others. US-04 As an operator admin, I hover any metric and read exactly what it means, what’s excluded, and which calendar it uses. US-05 As a QA-conscious manager, agent leaderboards exist for those entitled — and agents aren’t ranked on a wall-TV that anyone walks past (restricted widgets stay restricted in TV mode). US-06 As a finance reviewer, the AI cost-per-resolution and SMS cost-per-template reports let me steer spend monthly. US-07 As the platform team, when a manager disputes a number, the golden fixtures and drill-down settle it in minutes, not meetings.

## 7. Business Rules

BR-01 One dictionary, no exceptions: any surface displaying a metric resolves it through the registry — hardcoded formulas in UI or exports are a build-gate violation (CI grep + the fixture suite). BR-02 Spam and sandbox never count (FR-01 exclusions) — platform-wide, non-configurable (Doc 06 AC-06’s contract lands here). BR-03 Live and historical never blend: a widget is one tier; “now” numbers are labeled live and excluded from exports’ historical claims. BR-04 Per-person metrics are a privilege (reports.agents.individual), not a default — team aggregates are the universal currency; the gate applies uniformly (reports, dashboards, leaderboards, drill-downs, exports, API, TV). BR-05 The honest triad ships together (Doc 09 R-06): AI resolution rate is never displayable without CSAT(AI) and reopen-after-AI adjacent (registry-enforced companion metrics on the widget type). BR-06 Numbers must be explainable: no metric ships without drill-down or (for rates) numerator/denominator drill-downs; “trust us” aggregates are prohibited. BR-07 Active time derives from activity events, never status clocks (Doc 07 BR-06’s anti-surveillance stance carried into measurement).

## 8. UX Requirements

- **Reports home:** family cards with headline sparks, the digest/anomaly feed (FR-09), recent + pinned reports, the “verified” integrity badge with last-reconciled time (NFR-06).

- **Report pages:** per FR-07 anatomy; formula hovers per FR-03; comparison delta chips (▲▼ with color + sign); empty ranges render honest empty charts (“no data in range”), never zero-lines pretending; the filter bar persists per user per report.

- **Drill-down:** slide-over list (Doc 07 components) with the applied context summarized as removable chips; export-this-list shortcut (gated).

- **Live dashboard:** per FR-10; alert feed rows age-fade; degraded-realtime banner on socket loss with poll fallback (Doc 07 patterns); all Doc 11 interactions confirm per their owning specs.

- **Builder:** widget palette → drop on grid → inspector (metric picker with searchable dictionary incl. descriptions, dimension/filter controls constrained per registry, tier flag auto-set, target bands editor); as-viewer preview (FR-15); unsaved-changes guard; TV config panel with a live preview.

- **Exports/schedules:** progress drawer (async), schedule manager list with next-run, paused-owner warnings (FR-17), delivery history per schedule.

- **Loading:** KPI skeletons, chart shimmer→draw, table skeletons; realtime tiles show a live pulse dot; stale realtime (>10 s) dims with a staleness chip.

- **Error:** aggregate-store degradation → reports serve with a “data may lag” banner (never silently stale); export failures with retry + support reference.

## 9. UI Requirements

- Chart grammar: consistent palette per dimension across every chart (a queue keeps its color everywhere in a session); axes labeled with units from the registry; time axes in tenant timezone labeled; heatmap hour×day with weekday localization.

- Number formatting: locale-aware, tabular numerals (design-system rule), rates to one decimal, durations humanized with exact-on-hover.

- Delta chips: sign+arrow+color, color-blind safe.

- Live tiles: distinct “LIVE” micro-label; TV mode large-type scale tokens, high-contrast variant, no cursor.

- Restricted-widget placeholder: lock glyph + “requires individual-metrics permission” (never a broken chart).

- Responsive: reports phone-readable (KPIs stack, charts simplify to sparklines + table), builder ≥ 1024 px (fat-finger policy), live dashboard tablet+ with the Doc 11 touch fallbacks.

- Dark/light; reduced-motion (no live pulses, static deltas); print styles for report pages.

## 10. Technical Requirements

T-01 Registry: code-defined metric classes (key, metadata, an aggregate-query builder, a drill-down query builder, fixture refs) — the single implementation each consumer calls; CI asserts every registered key has fixtures and every rendered metric traces to a registry call (BR-01 gates). T-02 Projection: Horizon consumers per family on the Doc 01 stream → idempotent hourly upserts (ON CONFLICT accumulate with event-id dedupe sets per bucket in Redis, spilled to a processed-ids table for replay safety); monthly rollup job layers long-range performance; late-event correction re-runs touched buckets (FR-04). T-03 Realtime: the same consumers increment Redis structures (counters, ZSETs) with TTL’d correction from periodic truth-syncs (Doc 11’s queue state read directly rather than duplicated); Reverb channel tenant.{id}.supervisor per Doc 07’s protocol. T-04 Query layer: report/page/dashboard/API/export all call MetricQuery(key, range, grain, dims, filters, viewer) — viewer carries Doc 03 scope + the per-agent gate; scope application happens in the builder (indexed dimension predicates), never post-filtering in PHP. T-05 Exports: Browsershot renders report routes in an export mode (server-side session with the requester’s frozen scope token); Excel via Laravel Excel streaming writers; schedule runner on the reports queue with the FR-17 owner-scope freeze implemented as a stored scope snapshot validated at run (owner still active + still holds the permissions → else pause). T-06 Drift tooling: the nightly sampler (FR-06), the golden-fixture CI suite (Docs 06/12 shared corpora executed through T-01 builders), and a rebuild {metric} {range} operator command (Doc 18 runbook). T-07 Dashboard storage: layout + widget configs jsonb validated against the registry at save and at render (a metric deprecated later renders the widget in a “needs update” state with the replacement suggested). T-08 Contract tests: dictionary formula fixtures per metric (golden inputs → exact values), exclusion enforcement (spam/test corpora produce zero deltas), scope duality (FR-08/15 matrices), the honest-triad companion rule, per-agent gate uniformity across all seven surfaces (BR-04 list), live-vs-historical tier separation, late-event correction, TV-mode privilege absence.

**Data flow (text diagram):**

Doc 01 event stream ──► family projectors
   ├─ hourly aggregates (idempotent upserts; late-event corrections; monthly rollups)
   └─ realtime Redis (counters/ZSET reads) ──► Reverb ──► live dashboard
consumers (pages · dashboards · exports · schedules · API · digests)
   └──► MetricQuery(registry key, …, viewer{scope, gates}) ──► one truth
nightly sampler + golden fixtures + rebuild command = integrity net

## 11. Security Requirements

SEC-01 Scope enforcement in the query builder (T-04) with the Doc 03 fixtures reused; the API (FR-18) and exports (T-05) share it — no consumer-side filtering. SEC-02 The per-agent gate (BR-04) is asserted across all seven surfaces by the T-08 uniformity test; drill-downs from team aggregates elide agent columns for unentitled viewers. SEC-03 Exports watermarked + audited with full parameters (Doc 17); scheduled exports carry the owner-scope freeze (FR-17) preventing privilege time-travel. SEC-04 TV mode sessions are display-only tokens (no mutation endpoints authorized) — a lobby screen is not an attack surface; restricted widgets stay placeholders in TV. SEC-05 Realtime channels re-authorize on subscribe per Doc 07 SEC-01; supervisor-feed alerts elide out-of-scope object details (names → “a ticket in Payments” pattern where scope lacks). SEC-06 Cost/spend metrics (ai cost, SMS cost) are reports.view-gated per Doc 14 SEC-06 alignment; rate-card details remain in their owning admin screens.

## 12. Performance Requirements

- Budgets NFR-01–05 under L2 plus a TV rotation and 20 dashboards auto-refreshing; aggregate queries hit composite indexes ((tenant, metric, bucket) + dimension partials for the hot dimensions).

- Projector throughput ≥ 500 events/s platform with lag alerting per NFR-03.

- Builder metric search ≤ 50 ms over the full registry.

- Reconciliation sampler ≤ 10 min nightly window; full single-metric month rebuild ≤ 15 min.

- Realtime memory budget: ≤ 20 MB Redis per active tenant for the live window.

## 13. Accessibility Requirements

Charts ship with data-table alternatives (toggle per widget, exports always tabular); trends described in accessible summaries (“FRT down 12% vs last week”); delta chips text+symbol; live regions announce alert-feed additions politely with batching; TV mode’s large-type meets contrast at distance-viewing sizes; the builder’s drag/resize has keyboard equivalents (grid position controls); formula hovers are focusable disclosures; heatmaps expose row/column headers programmatically; all filter controls labeled; drill-down slide-overs follow Doc 07’s focus rules.

## 14. Localization Requirements

Metric labels/descriptions i18n (en + am) with the stable keys alongside in exports (FR-16); dates/numbers/durations per locale in tenant timezone with explicit labels; weekday/heatmap localization; digest and anomaly text localized per recipient (Doc 09 §14 alignment); report page titles and family names localized; schedule times displayed in tenant timezone with the recipient’s local-time hint; currency display per Doc 14 §14 rules for cost metrics.

## 15. Edge Cases

EC-01 Timezone-DST tenants: hourly buckets are UTC; presentation maps to tenant timezone (a “25-hour day” renders honestly on transition dates; fixtures include them — Doc 06 R-03 family). EC-02 A ticket merged (Doc 06 FR-20): source’s metrics contribution follows the merge contract (one body of work — merged tickets excluded from resolved-counts as superseded, contacts still counted; the registry documents it and the Doc 06 SS-02 scenario reconciles). EC-03 Spam unmarked on day 6 (Doc 06 FR-09) → the restoration event triggers bucket corrections (FR-04’s late-event path) — the AC-06 contract from Doc 06 lands here mechanically. EC-04 An agent moves teams mid-month → dimensional attribution follows the event-time team (historical truth), not current membership; the team report’s hover states this. EC-05 A dashboard shared to a role later losing report permissions → viewers get the restricted placeholder page (never stale cached data); the owner sees a share-health warning. EC-06 Deprecated metric in an old scheduled export → schedule pauses with the needs-update state (T-07) and an owner notification — silent formula swaps are prohibited (keys never repurposed, FR-03). EC-07 A 10k+ drill-down (FR-08 cap) → capped list + “export the full set” path with the async pipeline; the cap is stated, not silent truncation. EC-08 Realtime counter drift (missed decrement) → the periodic truth-sync (T-03) corrects within its interval; the live label’s approximate-by-contract framing (FR-05) makes this honest by design. EC-09 Two comparison periods spanning a calendar change (Doc 12 EC-05 family) → business-hours variants compute each period against its own effective calendar snapshot lineage; the hover names both calendars when they differ. EC-10 An export requested seconds before the requester’s permission removal → T-05’s at-run validation (schedules) and the synchronous check (one-off) both re-verify; the race window is one job-pickup, documented. EC-11 Widget filter references a deleted queue (Doc 11 FR-18) → renders with a “filter references removed queue” chip and the unfiltered-safe behavior (empty result, not silent broadening). EC-12 Leaderboard ties → deterministic secondary sort (metric value, then alphabetical) — petty, but disputes about rank #3 are real; documented.

## 16. Failure Scenarios

FS-01 Projector down → aggregates lag (banner per §8), realtime degrades to truth-sync cadence; catch-up from the event backlog with late-event corrections; the “verified” badge withholds until the nightly sampler passes post-recovery. FS-02 Aggregate store degraded → reports serve last-good with the lag banner; the live dashboard (independent tier) keeps the floor running — the two-speed design as resilience. FS-03 Redis realtime loss → tiles rebuild from truth-syncs (≤ interval staleness); Doc 11’s own rebuild (its FS-01) restores queue depths; supervisors see the degraded banner throughout. FS-04 Export renderer failure (Browsershot crash) → retry ×2 then failure notice with reference; scheduled failures alert the owner and don’t skip silently (delivery history shows the gap). FS-05 A registry deploy error (metric class exception) → that metric’s surfaces render an error card (isolated), everything else unaffected (per-metric isolation in T-01’s design); alert with the key name.

## 17. Success Scenarios

SS-01 The Doc 06 SS-01 lifecycle reconciles end-to-end: the golden fixture’s ticket produces exactly the FRT/resolution/SLA/CSAT values in every surface — page, dashboard, export, API — in CI and in staging. SS-02 Match-night on the live board: depth spikes, two pull-ins, one breach saved at the warning (the warned→save metric ticks), and the next morning’s historical report tells the same story the supervisors lived — no gaslighting gap between live and historical. SS-03 A number dispute (“your FRT is wrong”) dies in five minutes: hover-formula → drill-down → three tickets with odd timestamps → one was a merged duplicate behaving per EC-02 — explained, documented, closed. SS-04 The operator runs their monthly business review entirely off two shared dashboards and one scheduled PDF — no spreadsheet archaeology.

## 18. Acceptance Criteria

AC-01 Golden-fixture parity: the shared Doc 06/12 corpora produce exact dictionary values across page, dashboard widget, export, and API (four-surface parity test per metric family). AC-02 Exclusions: spam mark/unmark (EC-03) and sandbox corpora produce the specified zero/corrected deltas platform-wide (NFR-07 single-point enforcement verified by attempting per-report bypass in test). AC-03 Scope duality: FR-08/FR-15 matrices (All-scope vs Department-scope viewers on shared dashboards and drill-downs) exact-set verified with Doc 03 fixtures. AC-04 Per-agent gate uniformity: the BR-04 seven-surface list swept with an unentitled principal — zero individual metrics anywhere (incl. TV, exports, API, drill-down columns). AC-05 Tier separation: builder cannot produce a mixed-tier widget; live labels present; exports contain no live-tier values. AC-06 Late events & corrections: replay fixtures (delayed resolution events, spam restoration) converge buckets to truth ≤ one correction cycle. AC-07 Live dashboard: event→tile ≤ 2 s p95 under L2 (shared measurement with Doc 07 AC-07 harness); all Doc 11 interactions round-trip with their owning-module assertions. AC-08 Honest triad: an AI-resolution-rate widget without its companions is unbuildable (registry rule) and un-renderable (defense in depth). AC-09 Exports & schedules: watermark/audit/parameters complete; owner-scope freeze incl. the pause-on-deactivation and EC-10 races; 100k-row Excel within NFR-05. AC-10 Integrity net: nightly sampler zero-drift over a 7-day staging soak; the rebuild command restores a deliberately corrupted metric-month to fixture truth.

## 19. Future Expansion

- Volume forecasting (feeds Doc 11 §19 staffing suggestions) atop the aggregate history.

- Cohort analyses (customer lifetime contact patterns) under the same dictionary governance.

- External BI connector (read-only warehouse credentials / scheduled Parquet drops) — the registry’s stable keys are the export schema.

- QA-module scorecards (the deferred QA wave) as a ninth family with Doc 09’s pre-scores as one input.

- Custom calculated metrics (operator-defined formulas over registry keys) — sandboxed expression language, governance-gated.

- Alerting rules on historical metrics (“email me if weekly CSAT < 4.0”) reusing the Doc 12 engine’s shape against aggregate events.

## 20. Risks

R-01 Metric sprawl and dueling definitions (High likelihood — every org’s chronic disease) → the registry + change control + BR-01’s build gates are the entire defense; the hover-formula habit is the cultural half. R-02 Projection bugs silently corrupting history (High impact) → idempotent upserts, the nightly sampler with zero tolerance (NFR-06), golden fixtures in CI, rebuildability from raw events — four independent nets. R-03 Live-board load spirals (many tabs, TV walls) (Medium/Medium) → read-model reads + Reverb fanout (no per-viewer queries), session budgets (NFR-02), TV display-only tokens. R-04 Surveillance-culture drift via per-agent metrics (Medium/High, human cost) → BR-04’s gate + BR-07’s activity-derivation + leaderboard restraint defaults; the platform ships team-first and documents why (operator onboarding). R-05 Export sprawl as shadow-BI (Medium/Low) → stable keys in exports keep downstream spreadsheets reconcilable; the future BI connector (§19) is the sanctioned pressure valve.

## 21. Recommendations

- Build the registry + three golden metrics (FRT, SLA attainment, AI resolution triad) through all four surfaces first — the four-surface parity test (AC-01) is the architecture’s proof, and everything else is repetition.

- Wire the Doc 06/12 golden fixtures into this module’s CI the same week those modules land; the shared-fixture contract only pays if it runs continuously.

- Stand up the nightly sampler and the “verified” badge before operator onboarding — trust in the numbers is easiest to establish when it has never once been broken.

- Ship the live dashboard with the Doc 11 routing-record deep links prominent; SS-02’s “no gap between lived and reported” experience is what converts supervisors into the platform’s advocates.

- Hold BR-04 and BR-07 against pressure permanently — the day the platform becomes an agent-surveillance tool, operators will use it as one, and both agent trust and data honesty (gamed metrics) die together.

*End of Document 15. Next in series: Document 16 — White-Label, Settings **&** Feature Flags.*