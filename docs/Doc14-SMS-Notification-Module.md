Table of Contents

# Document 14 — SMS Notification Module

**Product:** AI-First Support Platform for Betting Companies **Document series:** 14 of 19 · Standalone enterprise specification **Audience:** Backend engineers, frontend engineers, QA, DevOps, operator admins, compliance **Depends on:** Doc 01 (settings, events, quotas, vault), Doc 02 (DLR webhook auth FR-24), Doc 03 (permissions sms.*), Doc 05 (window-expired re-engagement FR-23, connection-unreachable context), Doc 06 (ticket lifecycle events), Doc 08 (phone identities, RG suppression FR-15, blocked customers), Doc 12 (automation SMS action, quiet-hours source, business hours). Consumed by: Doc 07 (SMS panel in expired-window composer state), Doc 13 (offline-form confirmation), Doc 15 (SMS reports, cost tracking), Doc 17 (audit). **Stack context:** Laravel gateway drivers (HTTP aggregators in-process; SMPP via a Go/Node sidecar exposing a private HTTP API), Redis (rate/queue state), Horizon sms queue

## 1. Executive Summary

This document specifies the SMS module in its deliberately narrow role: **notification-only, template-only, never a conversational support channel**. It defines the event-driven send pipeline (ticket created / assigned / updated / resolved / closed, security notifications, verification codes, service announcements, the Telegram reply-window re-engagement, and the widget offline-form confirmation), the template system (per-event, per-language, variable-filled, with segment/cost estimation and test-send), the **gateway abstraction with per-country routing and fallback** built for markets where global aggregators fail — Ethiopia first, with local gateways over HTTP or SMPP (the SMPP protocol isolated in a sidecar, never in PHP) — delivery-receipt (DLR) tracking with per-message state, the opt-out registry honored globally, quiet-hours handling, suppression contracts (RG-flagged and blocked customers), per-tenant cost metering against rate cards, throughput shaping per gateway, the announcement (broadcast) subsystem with its own guards, admin screens for templates and gateways, and the full failure ladder from a flapping gateway to a country-wide telco outage. The module’s honesty rule mirrors the platform’s: SMS states shown to staff are exactly what the gateway reported — queued, sent, delivered, failed, opted-out — never inferred.

## 2. Purpose

- Give the platform a reliable outbound-notification arm in markets where SMS is the one channel that always reaches a phone — with local-gateway routing as a first-class design, not an afterthought.

- Keep SMS structurally incapable of becoming an unmanaged conversation or marketing channel: templates only, events only, opt-out absolute.

- Make cost and deliverability visible per gateway, per country, per template — the operational levers that decide whether SMS earns its keep.

## 3. Scope

**In scope:** event catalog & send triggers; template model (per-event, per-language, variables, versioning); the send pipeline (resolve → render → route → shape → send → DLR); gateway drivers (HTTP + SMPP-sidecar) and the country routing table with fallback; DLR ingestion & state machine; opt-out registry (inbound STOP handling where gateways support it, manual management, API); quiet hours; suppression contracts; verification-code sends (the platform’s own OTP-style messages for staff flows — not customer identity verification, which does not exist per Doc 08); announcements/broadcasts with guards; cost metering & rate cards; throughput shaping; admin UX (templates, gateways, opt-outs, health); events & report feeds. **Out of scope:** inbound SMS conversations (explicitly not a feature — see BR-01); template *triggering* rules beyond the fixed event catalog (Doc 12 automation calls the send action); customer phone collection (Doc 08); marketing campaign tooling (does not exist in the product — BR-02); SMS-based customer identity verification (does not exist per operator policy, Doc 08).

## 4. Functional Requirements

### 4.1 Event catalog & triggers

FR-01 Sendable events (fixed catalog v1; each toggleable per tenant, each mapped to exactly one template per language): ticket_created, ticket_assigned (customer-facing “an agent has your case” — off by default), ticket_updated (staff public reply while customer unreachable on the origin channel — see FR-03), ticket_resolved, ticket_closed (off by default), security_notification (staff-account events per Doc 02 where the *staff* user opted into SMS), verification_code (platform OTP for staff flows, e.g., optional SMS 2FA fallback — distinct from TOTP, off by default), service_announcement (broadcast, §4.6), window_reengagement (Doc 05 FR-23’s expired-window fallback, agent-initiated from the composer card), offline_form_confirmation (Doc 13 FR-15). FR-02 Trigger sources: Doc 01 bus events (ticket lifecycle) filtered by per-event toggles; explicit agent action (sms.send, window_reengagement + a manual “notify by SMS” on tickets whose customer has a phone); Doc 12 automation action (template-constrained, FR-03’s guard applies); the broadcast subsystem. Free-text SMS does not exist at any permission level (BR-01). FR-03 Per-customer send guards (evaluated in order, each skip logged with reason): no phone identity → skip; opted-out → skip (absolute); customer blocked (Doc 08 FR-18) → skip except security_notification; RG-flagged → skip all except window_reengagement and ticket_resolved (the Doc 08 FR-15 suppression contract — essential service continuity only); duplicate-suppression (same event + ticket + phone within 10 min) → skip; quiet hours (FR-12) → hold or skip per template flag.

### 4.2 Templates

FR-04 Template = {event, language, body (GSM-7/UCS-2 aware editor), variables (allowed set per event: {{ticket.number}}, {{customer.name}}, {{brand.name}}, {{queue.wait_band}}, {{code}} for verification, {{link}} slots resolved to short tenant URLs), active, quiet_hours_exempt (default: only verification_code and security_notification true), version history}. Editing follows the platform draft→publish pattern with instant rollback; sends always use the published version and record its id. FR-05 Editor affordances: live segment counter (GSM-7 vs UCS-2 auto-detected — Amharic forces UCS-2 70-char segments; the editor states this plainly), per-gateway cost estimate from rate cards, missing-variable validation (a template referencing a variable its event doesn’t provide cannot publish), test-send to an admin-verified number (sandbox-tagged, excluded from reports per the platform test-mode rule). FR-06 Rendering: server-side resolver shared with the Doc 12 macro engine’s discipline (T-06 there): unresolved variable at send time → per-template setting on_missing: skip_send (default) | fallback_text; either path logged; a {{blank}} never leaves the platform (Doc 12 BR-04 extended).

### 4.3 Gateways & routing

FR-07 Gateway = {name, driver (http_generic with per-gateway request mapping config | named aggregator drivers | smpp_sidecar), credentials (vault refs), sender_id (alphanumeric or shortcode per telco rules, per-country overrides), rate card (per-country price per segment, currency), throughput limit (msgs/s), DLR config (callback secret / polling per driver), active, health state}. FR-08 Country routing table: ordered rows {country_prefix (E.164), primary gateway, fallback gateway(s), sender_id override}; resolution = longest-prefix match; no match → tenant default gateway or explicit no_route skip (setting) — for an Ethiopia-first tenant the seeded row is +251 → local gateway, fallback: aggregator. FR-09 Failover: a send failing with a gateway-level error (auth, unreachable, throttled-hard) after driver retries → re-route to the next fallback in the same pipeline run (once per fallback, loop-bounded); per-message failovers are logged; sustained failures trip the gateway’s circuit breaker (health → down, all its routes fail over, alert) with automatic half-open probing. FR-10 SMPP sidecar contract: a private HTTP API (POST /send, GET /health, DLR webhooks back to Doc 02 FR-24’s endpoint) run as a separate service per SMPP bind; the platform treats it as just another HTTP driver — bind management, enquire_link keepalives, and window management live entirely in the sidecar (Go/Node), never in PHP (Doc series stack rule).

### 4.4 Pipeline, DLR, state machine

FR-11 Send pipeline (queued, sms queue): guard evaluation (FR-03) → template render (FR-06) → route (FR-08) → throughput shaping (per-gateway token bucket; over-limit waits, never drops) → driver send → provider message id stored → state sent | failure classification (retryable: network/5xx/throttle → backoff ×4; non-retryable: invalid number, blocked by carrier, no route → failed with reason) → DLR updates → terminal state. FR-12 State machine (normative): queued → sending → sent → delivered | undelivered(carrier-reported) | expired(no DLR after 48 h → shown as "sent — delivery unconfirmed", never guessed delivered); plus skipped {reason} (guards) and failed {reason}. All transitions evented; the ticket timeline shows SMS entries with state chips (Doc 06 integration — SMS sends related to a ticket appear as system events with live state). FR-13 DLR ingestion: per-driver mapping of carrier status codes to the state machine + raw code retained; out-of-order and duplicate DLRs handled idempotently (message id + status precedence rules — a delivered is never downgraded by a late sent).

### 4.5 Opt-out, quiet hours

FR-14 Opt-out registry: per-tenant phone list with source (inbound_stop where the gateway relays MO/STOP, staff manual with reason, api); absolute for all events except none — even security notifications respect opt-out (the registry is the customer’s word; documented explicitly); opt-in restoration is staff-manual with reason (both audited); every skipped send logs skipped:opted_out. FR-15 Inbound STOP: gateways that deliver inbound keywords → the DLR/MO endpoint parses configured stop-words per language (STOP, ተወው, configurable list) → registry insert + a confirmation SMS where telco rules require it (template optout_confirmation, exempt from its own rule once). FR-16 Quiet hours: window from settings (sms.quiet_hours, default 21:00–08:00 tenant timezone, source-linked to Doc 12 calendars optional); non-exempt messages arriving in-window are **held** (state queued(held) with release at window end) or skipped per template flag; held messages surface in the admin queue view; verification_code/security_notification exempt by default (FR-04).

### 4.6 Announcements (broadcast)

FR-17 Broadcast = {name, template (event service_announcement), audience (filter over customers: language, country, tags, has-open-ticket, last-contact range — never RG-flagged, structurally excluded), schedule (now | at), throughput cap (per-broadcast msgs/min within gateway shaping), state (draft → scheduled → sending → done | canceled)}. Guards: audience preview with exact count before confirm; typed confirmation ≥ 1,000 recipients; per-tenant broadcast quota (sms.broadcast.monthly_cap setting, platform ceiling); opt-out and all FR-03 guards apply per recipient; cancel stops un-sent remainder. FR-18 Broadcast execution: chunked jobs with per-chunk progress, per-recipient guard logs, cost accrual live on the progress screen; a mid-broadcast gateway failover continues seamlessly (FR-09).

### 4.7 Cost, metering, events

FR-19 Cost = segments × rate-card price per (gateway, country) accrued per message at sent; corrected at DLR where carriers re-rate (driver-mapped); per-tenant monthly spend visible with an alert threshold setting; the Doc 01 quota system caps monthly segments (sms.quota.segments) with the 80% alert. FR-20 Events: sms.queued|held|sent|delivered|undelivered|failed|skipped|expired, sms.optout.added|removed, sms.gateway.health_changed, sms.broadcast.started|progress|completed|canceled, sms.cost.threshold — feeding Doc 15 reports and Doc 17 audit (template/gateway/opt-out admin changes are standard audit; broadcast launches and opt-in restorations are high-visibility).

## 5. Non-functional Requirements

NFR-01 Event-to-queued ≤ 2 s p95; queued-to-sent ≤ 5 s p95 in-shape (excluding held/quiet-hours by definition). NFR-02 Throughput: platform sustains 50 msgs/s aggregate with per-gateway shaping; broadcasts never starve transactional sends (separate lane priority: transactional > re-engagement > broadcast on the same gateway buckets). NFR-03 DLR ingestion ≤ 1 s from webhook to state visible; idempotent under duplicate storms. NFR-04 State honesty: no state shown anywhere that the pipeline cannot substantiate (FR-12’s expired wording is the pattern); zero inferred-delivered states (release-gate assertion). NFR-05 Gateway failover engages ≤ 30 s from circuit-trip; a country-wide outage degrades to honest failed/queued states with alerts, never silent drops. NFR-06 Segment/cost estimates in the editor match actual accrual within 1 segment for the fixture corpus (GSM-7/UCS-2 detection correctness).

## 6. User Stories

US-01 As a player, when my ticket is resolved I get one SMS in my language telling me so — and if I ever reply STOP, that’s the end of it, permanently, until I ask otherwise. US-02 As an agent staring at an expired Telegram window, one click sends the re-engagement SMS and I see “delivered” turn green on the ticket timeline. US-03 As an operator admin, I edit the resolved-template in Amharic, watch the segment counter warn me at 71 characters, test-send to my own phone, and publish. US-04 As an operator admin, I route +251 to our local gateway with the aggregator as fallback, and when the local telco hiccups at midnight, messages fail over automatically and I read about it in the morning alert digest — not from angry players. US-05 As a finance reviewer, monthly SMS spend per gateway and per template is one report, reconciled against the telco invoice within a segment. US-06 As a compliance officer, RG-flagged customers receive nothing promotional-adjacent, broadcasts structurally cannot include them, and every skip has a logged reason. US-07 As a supervisor, a broadcast to 8,000 dormant customers runs at a capped rate, shows live progress and cost, and I can cancel the remainder at any moment.

## 7. Business Rules

BR-01 **Notification-only is structural:** no free-text send exists at any permission level; no inbound-message threading exists (inbound is STOP-parsing only); the one-way design is the feature, not a limitation — support conversations belong on Telegram and the widget. BR-02 No marketing machinery: broadcasts are service announcements with quotas, caps, and audience guards; the product ships no campaign, segmentation-analytics, or A/B tooling for SMS, and the service_announcement template family is the only broadcast-capable event (scope wall against drift). BR-03 Opt-out is absolute and instant (FR-14) — including security notifications; the customer’s explicit word outranks the platform’s judgment about what’s good for them. BR-04 Local-first routing: per-country tables with local gateways are the default architecture, not an enterprise add-on; deliverability in the operator’s actual market is the module’s reason to exist. BR-05 Transactional beats broadcast always (NFR-02 lanes): a resolved-notification never waits behind an announcement. BR-06 SMS supports, never verifies: nothing in this module implements customer identity verification (operator policy per Doc 08); verification_code is strictly platform/staff OTP plumbing. BR-07 Costs are visible before they’re incurred: editor estimates, broadcast previews with totals, quota alerts — no surprise invoices.

## 8. UX Requirements

- **Templates screen:** event-grouped list with per-language completeness dots (Doc 10 pattern), active toggles, last-edited; editor per FR-05 (segment counter with encoding badge “UCS-2 · 70/segment”, variable palette with per-event availability, cost-estimate row per routed gateway, on_missing choice, quiet-hours-exempt toggle with a caution note, test-send panel); version history with diff and rollback.

- **Gateways screen:** cards with health pills (healthy/degraded/down), driver badge, throughput gauge (current vs limit), today’s sent/delivered/failed sparkline, cost-to-date; detail = credentials (write-only fields per Doc 05 SEC-01 pattern), sender-id rules per country, rate-card editor (per-country rows), DLR config with the callback URL + secret rotate, circuit state with manual reset (permission-gated, audited); **routing table** as an ordered prefix list with a “test a number” resolver box showing the exact route + fallback chain a given E.164 would take.

- **Queue ****&**** history:** filterable message log (state, event, gateway, country, date), each row expandable to the full lifecycle (guards evaluated with skip reasons, render, route, attempts, DLRs with raw codes), held-messages view with release-now (permission-gated); ticket timelines show the Doc 06-integrated SMS entries with live chips.

- **Opt-out manager:** searchable list with source/reason/date, add (staff, reason required), restore (reason required, high-visibility confirm), import/export CSV (audited).

- **Broadcasts:** list with states; builder (template pick → audience filter with live count → schedule → cost preview “≈ 8,000 msgs · ~9,120 segments · ~$xx” → guards summary → typed confirm at threshold); progress screen (sent/delivered/skipped counters, cost accrual, cancel button); completion report.

- **Empty/loading/error:** no gateways yet → setup checklist (“SMS is off until a gateway exists” honest banner wherever sends would occur, incl. the Doc 07 expired-window card degrading to phone-guidance-only); template test-send states; routing-resolver misses show the no_route consequence plainly.

## 9. UI Requirements

- State chips: queued (clock), held (moon glyph — quiet hours), sent (single check), delivered (double check — truthful here per DLR), undelivered/failed (red !), expired (“sent · unconfirmed” gray) — consistent with the Doc 04/05 glyph language, never conflicting with Telegram’s single-check ceiling (different channel, different truth, tooltips explain).

- Encoding badge and segment boundary markers inline in the editor (a faint rule at each segment break).

- Health pills and circuit-state indicators mirror Doc 05’s connection styling.

- Routing table rows drag-orderable with prefix-overlap warnings (“+2519 shadows +251 below it”).

- Broadcast progress uses the platform progress-drawer pattern (Doc 06 bulk family).

- Responsive: monitoring screens phone-readable; template editing tablet+; gateway/routing editing desktop (fat-finger policy); dark/light; reduced-motion (static gauges).

## 10. Technical Requirements

T-01 Tables per Doc 01: sms_templates (+versions), sms_gateways, sms_routes (prefix, position, gateway ids), sms_messages (per Doc 01 schema + guard_log jsonb, template_version_id, attempts jsonb, raw DLRs), sms_optouts (phone, source, reason, at), sms_broadcasts (+progress counters), rate cards embedded in gateway config jsonb. T-02 Driver interface: send(RenderedMessage) → {provider_id, accepted|failed{code, retryable}}, parseDlr(payload) → {provider_id, status, raw}, capabilities() → {inbound_stop, alphanumeric_sender, max_throughput}; the http_generic driver is config-mapped (URL, method, field mapping, auth style) so unlisted local aggregators onboard without code; named drivers and the sidecar driver are code. T-03 SMPP sidecar: separate deployable (Doc 18 owns packaging) with its own health endpoint scraped into the gateway health rollup; the platform’s only contract is T-02 over private HTTP + mTLS; sidecar restarts are invisible to the pipeline (retryable classification). T-04 Shaping: per-gateway Redis token buckets (Doc 05 T-08 Lua pattern) with the three-lane priority (NFR-02) implemented as separate queues drained by weighted workers. T-05 Quiet-hours hold: the platform ZSET timer pattern (release-at score) — one mechanism family across Docs 05/06/12/14. T-06 Guard evaluation is a pure, ordered function over (customer, event, template, time) returning proceed | skip{reason} — unit-tested as a truth table (the module’s correctness heart alongside routing). T-07 Broadcast executor: chunked (500/chunk) jobs on the broadcast lane with per-chunk guard evaluation at execution time (a customer opting out mid-broadcast is skipped in later chunks — live guards, Doc 06 EC-12 semantics). T-08 Contract tests: state-machine matrix (all transitions incl. DLR precedence FR-13); guard truth table (every FR-03 order interaction incl. RG × event exceptions); routing longest-prefix + failover + circuit fixtures; encoding/segmentation corpus (GSM-7, UCS-2, mixed, Amharic) vs cost accrual (NFR-06); STOP-parsing per language; broadcast live-guard chunking; the sidecar contract via a mock SMPP server.

**Send pipeline (text diagram):**

event/action → guard fn (ordered; skip{reason} logged)
  → render (published template version; on_missing policy)
  → route (longest prefix → gateway, fallback chain)
  → shape (lane priority: transactional > reengage > broadcast; token bucket)
  → driver.send → sent | retryable(×4 backoff) | failover(next gateway) | failed
  → DLR webhook → precedence-safe state update → timeline chips + events
quiet hours: non-exempt → held (ZSET release) → resumes pipeline at window end

## 11. Security Requirements

SEC-01 Gateway credentials and sidecar mTLS material vault-only; write-only credential fields in UI (Doc 05 SEC-01 pattern); DLR endpoints authenticated per Doc 02 FR-24 with replay windows. SEC-02 Phone numbers masked in logs (Doc 08 SEC-03 shared scrubber); full numbers visible only in permissioned UI (sms.optout.manage, message log under sms.templates.view+ scope) and never in events beyond ids. SEC-03 Broadcast audience queries execute with the launcher’s live scope (Doc 03 job semantics) and structurally exclude RG-flagged (FR-17 — a WHERE clause asserted by test, not a filter someone can uncheck). SEC-04 Opt-in restoration and manual circuit resets are high-visibility audit events; opt-out CSV export is customers.export-class (high). SEC-05 The {{link}} variable resolves only to platform-generated short URLs on the tenant’s configured domain (no arbitrary URLs in templates — smishing-shape prevention); link targets are the platform’s own surfaces (ticket status page when it exists per Doc 06 §19, KB articles). SEC-06 Rate cards and spend data are reports.view-gated; per-customer SMS history follows customer-scope rules (Doc 03).

## 12. Performance Requirements

- Budgets NFR-01–03 under L2 plus a broadcast running (lane isolation measured: transactional p95 unchanged during an 8k broadcast — the SS-04 drill).

- Guard function ≤ 1 ms; render ≤ 5 ms; route resolution ≤ 0.5 ms (prefix trie in memory, rebuilt on table change).

- DLR endpoint sustains 200/s bursts (carriers batch);

- Held-release sweep per the platform timer budgets; 10k held messages release ≤ 60 s spread (jittered to respect shaping).

- Broadcast throughput = min(per-broadcast cap, gateway shape, lane weight) — deterministic and displayed on the progress screen.

## 13. Accessibility Requirements

Admin screens meet WCAG 2.2 AA: segment counter and encoding badge announced on change (“Now 2 segments, UCS-2”); state chips icon+text with lifecycle rows readable as definition lists; the routing test-resolver announces the resolved chain; drag-ordered routing rows have move buttons; broadcast confirmations read totals in text; held/quiet-hours moon glyph paired with the label; credential write-only fields properly described (“saved — enter a new value to replace”).

## 14. Localization Requirements

Templates are per-language operator content with completeness dots; the module warns when an event is enabled for a language lacking a template (falls back per the Doc 01 chain with the fallback logged per send); STOP keyword lists per language (FR-15) seeded for en + am; admin strings under sms.* (en + am); segment math surfaces the Amharic/UCS-2 reality in plain words (FR-05); quiet hours display in tenant timezone with explicit labels; costs display in the rate card’s currency with tenant-currency conversion shown informationally where configured.

## 15. Edge Cases

EC-01 A number matches two routes (+251 and +2519) → longest prefix wins; the overlap warning (§9) exists because shadowing is usually a config mistake. EC-02 Phone identity removed (Doc 08 unlink) between queue and send → guard re-evaluated at send time (live guards) → skipped:no_phone. EC-03 DLR arrives for an unknown provider_id (gateway recycled ids / cross-env leak) → logged, dropped, counted; never crashes ingestion. EC-04 delivered DLR then a later undelivered for the same id (carrier weirdness) → precedence holds delivered; the raw contradiction retained and flagged for the gateway-quality report. EC-05 Quiet-hours boundary exactly at a held-release while the window setting changes → releases compute against the setting at release time; a shrunk window releases early-but-in-window, never mid-night. EC-06 Broadcast scheduled, then the template is edited → the broadcast pins the version at schedule time (Doc 12 BR-06 family); the builder shows the pinned-version notice. EC-07 Opt-out arrives via STOP while a broadcast chunk containing that number is mid-flight → best-effort: later chunks skip (T-07); an in-flight message may deliver — documented honestly, the registry timestamps make the sequence auditable. EC-08 Alphanumeric sender rejected by a carrier that requires shortcodes → driver classifies non-retryable with the carrier code; the gateway’s per-country sender override (FR-07) is the fix, surfaced in the failure reason. EC-09 A template’s language has no gateway-supported encoding edge (rare scripts) → UCS-2 covers; the editor’s encoding detection is the guard; fixture corpus includes edge scripts. EC-10 Doc 05 window_reengagement clicked twice by an anxious agent → duplicate-suppression (FR-03, 10 min) makes the second a logged skip with an inline “already sent 2 min ago” notice in the composer card. EC-11 Tenant quota exhausted mid-broadcast → broadcast pauses at the quota wall (state paused:quota) with resume-on-quota-change; transactional sends get the Doc 09 EC-12-style 5% reserve headroom before hard stop. EC-12 Country-wide telco outage with no viable fallback → circuit opens, messages accumulate queued (bounded by a 24 h TTL → then failed:no_route_available honestly), alert escalates at thresholds; on recovery the backlog drains within shaping.

## 16. Failure Scenarios

FS-01 Primary gateway auth expiry (credentials rotated telco-side) → non-retryable auth classification → circuit trips → fallback carries traffic → alert names the gateway and error class; recovery via credential update + manual or probe-based circuit close. FS-02 Sidecar crash-loop → its health scrape marks the gateway down (T-03), routes fail over; the sidecar’s restart independence means recovery is automatic once stable; runbook in Doc 18. FS-03 DLR callback endpoint unreachable from a carrier (network ACL change) → deliveries flow but states stall at sent → the 48 h expired wording keeps honesty (NFR-04) while the missing-DLR-rate alert (a health signal per gateway) catches the pattern within hours. FS-04 Rate-card misconfiguration (price ×100 typo) → cost-threshold alert fires early (FR-19); estimates-vs-accrual divergence in the editor would have shown it pre-publish (NFR-06 discipline); correction re-rates forward only (historic accruals annotated, never rewritten). FS-05 Redis shaping state lost → buckets rebuild conservatively (start throttled, ramp to limits); worst case is temporary under-sending, never over-sending into carrier penalties.

## 17. Success Scenarios

SS-01 The Doc 05 loop closes in production: window expires → agent one-click SMS → player returns to Telegram 3 h later → resolution — the timeline shows every hop with truthful states (Doc 05 SS-03 realized with this module’s chips). SS-02 A month-end reconciliation: platform-accrued cost vs the local telco invoice matches within the tolerance; the per-template cost report reveals ticket_closed notifications aren’t worth their spend → toggled off in one click (BR-07 working as designed). SS-03 The midnight failover (US-04): local gateway degrades 00:14–02:40, the aggregator carries 312 messages, circuit self-heals, the morning digest tells the whole story — zero player-visible impact. SS-04 An 8,000-recipient announcement runs during peak transactional traffic with transactional p95 unchanged (lane isolation drill green) and 41 live opt-out skips honored mid-run.

## 18. Acceptance Criteria

AC-01 Guard truth table (T-06/T-08): every FR-03 combination incl. RG exceptions, blocked × security, duplicate windows, quiet-hours × exemptions — exact skip reasons asserted. AC-02 State machine: full transition matrix incl. DLR precedence (EC-04), expired wording (NFR-04’s release-gate: zero inferred-delivered states in any UI, DOM-asserted). AC-03 Routing: longest-prefix incl. EC-01 shadowing warnings, failover chains, circuit trip/half-open/close cycle, the no-viable-route ladder (EC-12) with TTL honesty. AC-04 Encoding & cost: the segmentation corpus (NFR-06) incl. Amharic UCS-2; editor-estimate vs accrual parity; rate-card currency rendering. AC-05 Opt-out: STOP parsing per language, absoluteness (every event type skipped for an opted-out fixture), restoration audit, mid-broadcast live-guard behavior (EC-07 sequence auditable). AC-06 Broadcasts: audience preview count = executed count ± live-guard skips; RG structural exclusion (SEC-03 test); pinned template version (EC-06); pause-on-quota (EC-11); cancel stops remainder; typed-confirm threshold. AC-07 Lane isolation: SS-04 drill measured (transactional p95 delta ≤ 5% during broadcast). AC-08 Drivers: http_generic config-mapped onboarding of a mock local aggregator without code changes; the sidecar contract suite against the mock SMPP server incl. restart-transparency. AC-09 Timeline integration: ticket-related sends render as Doc 06 system events with live state chips (Doc 07 fixture). AC-10 Security: credential write-only round-trip, phone masking in logs, {{link}} domain restriction (SEC-05) — attempted arbitrary-URL template fails publish.

## 19. Future Expansion

- Ticket status short-link landing page (pairs with Doc 06 §19’s public states) as the primary {{link}} target.

- WhatsApp-notification parity as a second notification transport under the same template/guard model (the guard fn and template system are transport-agnostic by design).

- Smart send-time within quiet-hours-adjacent windows (deliverability optimization) — under BR-02’s no-marketing wall, service messages only.

- Carrier-quality scoring per route from DLR patterns feeding routing suggestions.

- Additional named drivers as operator markets expand (the http_generic mapper is the interim for all of them).

## 20. Risks

R-01 Local-gateway operational fragility (High likelihood in target markets) → fallback routing, circuits, the missing-DLR-rate signal (FS-03), and the sidecar isolation are the containment; the routing table makes carrier diversity a config exercise. R-02 Cost surprises from encoding (Amharic = UCS-2 = 3–4× segments) (High/Medium) → the editor’s plain-words encoding education (FR-05), estimates, quotas, and SS-02’s per-template ROI visibility. R-03 Scope pressure toward marketing SMS (High/Medium) → BR-02’s structural walls + the broadcast quotas; the documented answer to “can we just…” is this section. R-04 Regulatory variance per country (sender IDs, opt-out mandates, quiet-hour laws) (Medium/High) → per-country sender overrides, configurable stop-words, quiet-hours settings, and Doc 18’s per-market compliance checklist reference this module’s knobs; unknown markets require the checklist before routing goes live. R-05 Opt-out registry as single point of trust (Low/High) → append-only sourcing, audits on restoration, backup within Doc 18’s tier-1 data class.

## 21. Recommendations

- Build the guard function and state machine first as pure, table-tested units (T-06/T-08) — every stakeholder dispute this module will ever face (“why didn’t the SMS go?” / “did it deliver?”) is answered by those two artifacts.

- Onboard the real Ethiopian gateway on staging via http_generic before writing any named driver — the config-mapped path proves itself on day one and keeps local-market agility permanent.

- Ship with ticket_created + ticket_resolved + window_reengagement + offline_form_confirmation enabled and everything else off — earn each additional notification’s place with the SS-02 cost/value report.

- Rehearse the EC-12 country-outage ladder and the SS-03 failover as staging drills; SMS failure modes are telco-shaped and only rehearsal makes the alerts trustworthy.

- Hold BR-01/BR-02’s walls permanently: the day SMS becomes two-way or campaign-shaped, this module inherits an entire compliance and product domain the platform deliberately declined — that decision belongs to a future series revision, not a feature ticket.

*End of Document 14. Next in series: Document 15 — Reporting, Analytics **&** Dashboards.*