Table of Contents

# Document 06 — Ticketing System

**Product:** AI-First Support Platform for Betting Companies **Document series:** 06 of 19 · Standalone enterprise specification **Audience:** Backend engineers, frontend engineers, QA, product, operator admins **Depends on:** Doc 01 (tenancy, sequences, outbox), Doc 03 (permissions tickets.*), Doc 04 (conversations, messages, one-open-ticket invariant), Doc 05 (reply-window states, channel_unreachable). Consumed by: Doc 07 (inbox rendering), Doc 09 (AI handoff), Doc 11 (routing), Doc 12 (SLA/automation/forms), Doc 14 (SMS events), Doc 15 (metrics). **Stack context:** Laravel, PostgreSQL, Redis, event-driven per Doc 01

## 1. Executive Summary

This document specifies the ticket: the bounded unit of support work created inside a permanent conversation (Doc 04). It defines the complete status state machine (new, open, pending_customer, pending_internal, escalated, resolved, closed, spam) with every legal transition, all creation paths (AI handoff, agent-manual, automation, API, offline form), assignment semantics, priorities, tagging, followers, the merge / split / transfer / escalate operations with full data semantics, reopen and auto-close policies, spam disposition, per-tenant human-friendly numbering, the AI handoff packet contract, the ticket event stream that powers SLA, automation, audit, and reporting, and the property-panel UX contract consumed by Doc 07. SLA *policies* and automation *rules* live in Doc 12; this document defines the ticket-side hooks they attach to.

## 2. Purpose

- Freeze ticket semantics (states, transitions, invariants, events) as the contract for the five documents that consume them.

- Specify operations precisely enough that merge, split, and reopen behave identically via UI, API, and automation.

- Define measurable timestamps (first_response_at, resolved_at, …) exactly once, so Doc 15’s metric dictionary has unambiguous sources.

## 3. Scope

**In scope:** ticket model & numbering; status state machine; creation paths; assignment & claim; priority; tags & followers; subject handling; the handoff packet; merge/split/transfer/escalate; pending semantics; resolve/close/auto-close/reopen; spam; channel_unreachable interplay; metric timestamps; events; property-panel UX contract; bulk operations semantics. **Out of scope:** queue/routing algorithms (Doc 11); SLA policy definition & timers UI (Doc 12 — instances referenced here); custom fields & forms definition (Doc 12); inbox layout (Doc 07); CSAT surveys (Doc 12/15); reporting formulas (Doc 15).

## 4. Functional Requirements

### 4.1 Model & numbering

FR-01 Ticket fields per Doc 01 Phase-22 schema; additionally normative here: source (ai_handoff|agent|automation|api|offline_form), escalation {path, reason, target, at} jsonb, pending_reason (nullable enum: awaiting_customer, awaiting_internal:{department|named_user|external}), spam_marked_by/at, reopened_count, channel_unreachable (bool, Doc 05 FR-10). FR-02 Numbering: per-tenant sequential integer from tenant_sequences (Doc 01 T-02), displayed #4812; never reused; gaps possible on rolled-back creates — acceptable and documented. FR-03 Subject: optional at creation; if absent, AI generates one from the first messages (Doc 09) within 30 s, marked subject_source=ai, editable by staff (tickets.edit).

### 4.2 Status state machine (normative)

 new ──assign/claim──► open ◄──customer replies── pending_customer
  │                    │  ▲                          ▲
  │                    │  └──staff needs info──────► │ (pending_reason=awaiting_customer)
  │                    ├──await internal──► pending_internal ──answered──► open
  │                    ├──escalate──► escalated ──de-escalate──► open
  │                    ├──resolve──► resolved ──customer replies (≤ reopen window)──► open
  │                    │                 │
  │                    │                 └─auto-close timer──► closed
  │                    │                        closed ──reopen (≤ window, perm)──► open
  │                    │                        closed + customer msg (> window) ──► [new ticket]
  └──mark spam──────────┴──────────────► spam (terminal; unmark ≤ 7 d → prior status)

FR-04 Legal transitions are exactly the edges above; all others rejected with illegal_transition {from, to}. Every transition records actor (user/ai/automation/system), reason where required (escalate, spam, staff reopen-of-closed), and emits ticket.status_changed with old/new. FR-05 new → open occurs on first assignment (auto or manual) OR first staff public reply (implicit claim, setting tickets.implicit_claim default on). FR-06 resolved: requires either a public staff/AI reply in the ticket or an explicit resolution note (setting tickets.resolve.requires_reply default on); triggers CSAT flow (Doc 12) and SMS event ticket_resolved (Doc 14). FR-07 Auto-close: resolved → closed after tickets.autoclose.hours (default 48) of customer silence; timer cancels if the customer replies (→ open per reopen rule). Closed tickets are immutable except: reopen, tag management, and serving as a merge target. FR-08 Reopen: customer message within tickets.reopen.days (default 7) of closed_at reopens the same ticket (reopened_count++, event ticket.reopened, assignee = previous if active else re-route via Doc 11); beyond the window → new ticket in the same conversation carrying a previous_ticket_id link. Staff reopen of closed tickets requires tickets.reopen and a reason. FR-09 Spam: tickets.spam.mark → status spam, excluded from SLA/CSAT/agent metrics (Doc 15 contract); the dialog offers a separate, separately-confirmed conversation block (Doc 04 FR-03); unmark within 7 days restores prior status and re-includes metrics with audit; after 7 days spam is terminal. FR-10 One open-family ticket per conversation (Doc 04 BR-05): {new, open, pending_*, escalated} are open-family; resolved is not — a customer message on a resolved ticket reopens it rather than creating a duplicate (FR-08 takes precedence over new-ticket creation).

### 4.3 Creation paths

FR-11 **AI handoff** (primary): Doc 09 calls createFromHandoff(conversation, packet); the handoff packet (normative contract): {summary (2–4 sentences), intent+confidence, language, sentiment, urgency, suggested {department, priority} + reasons[], form_data?, kb_shown[] (article ids), transcript_anchor (message id range)}; the packet is pinned to the ticket as an immutable snapshot and rendered by Doc 07. FR-12 **Agent-manual:** from a conversation (tickets.create) — pre-fills customer/channel; blocked if open-family exists (FR-10), showing a jump link instead. FR-13 **Automation** (Doc 12) and **API** (POST /v1/tickets): same validation path; source recorded; duplicates prevented via idempotency keys. FR-14 **Offline form** (Doc 13 widget): creates new, tagged offline, customer identity per Doc 08 shell rules.

### 4.4 Assignment, priority, followers, tags

FR-15 Assignment: tickets.assign (others) / tickets.assign_self (claim); assignee must hold view scope over the ticket (Doc 03 intersection) — violations rejected assignee_out_of_scope; unassign returns to queue (Doc 11). Every change emits ticket.assigned {old, new, by}. FR-16 Claim race: first commit wins; loser receives already_claimed {by} and the UI updates in place (inline avatar swap, no error-modal storm). FR-17 Priority low|normal|high|urgent: settable by staff (tickets.priority.change), AI suggestion at handoff, automation rules; every change evented (SLA re-target per Doc 12 policy rules). FR-18 Followers: staff with view scope can follow/unfollow themselves (tickets.follow); assigners can add followers; followers receive notifications (status, public reply, note-mention, escalation) with per-user preferences (Doc 16). FR-19 Tags: free-form per tenant with autocomplete; bulk-manageable; tag rename/merge is an admin background operation propagating historically, audited with counts.

### 4.5 Operations

FR-20 **Merge** (tickets.merge): source(s) → target within the same customer only (cross-customer merge requires a Doc 08 customer merge first — hard rule); preview shows both timelines; on confirm: messages stay in their conversations (merge links work, never moves messages): source → closed with merged_into=target, followers+tags union into target, source form_data attached as an artifact card, target SLA governs (source instances closed superseded), events on both; source shows a permanent banner → target. Reversible for 24 h via unmerge (restores prior state from snapshot; audited). FR-21 **Split** (tickets.split): select messages in the thread → new ticket in the same conversation referencing origin_ticket_id + the message anchor range (messages referenced, not moved); chooser for department/form; origin gets a system note + link. The split target carries linked_work=true — the one sanctioned multi-open case, capped at 3 per conversation. FR-22 **Transfer:** department/queue change (tickets.department.change) re-routes per Doc 11, resets assignee unless the target department retains them, emits ticket.transferred; the existing SLA instance keeps its original targets unless the policy says re-target (Doc 12), with an explanatory badge (Doc 07). FR-23 **Escalate** (tickets.escalate): path ∈ {supervisor_review, department:{id}, named:{user}} + mandatory reason (≥ 10 chars); status → escalated; target notified; original assignee auto-follows; de-escalation returns to open with a note; escalation SLA clock per Doc 12.

### 4.6 Metric timestamps (normative sources for Doc 15)

FR-24 first_response_at: first *public* staff or AI message after ticket creation (notes excluded; pre-ticket chatbot messages excluded). agent_first_response_at: first public *human* reply. resolved_at/closed_at: last transition into the state (reopen clears resolved_at until re-resolved; history preserved in events). waiting_customer_seconds / waiting_staff_seconds: accumulated from status intervals. All computed transactionally with the transition, never derived later.

### 4.7 Bulk operations & events

FR-25 Bulk (from Doc 07 selection): assign, transfer, tag, priority, resolve, close, spam — executed as individually validated operations in a queued batch (per-item partial success; no all-or-nothing lock), capped 200/batch, evented individually + one tickets.bulk_completed {ok, failed[]} summary. FR-26 Event catalog: ticket.created|status_changed|assigned|priority_changed|transferred|escalated|deescalated|merged|unmerged|split|resolved|closed|reopened|spam_marked|spam_unmarked|follower_added|removed|tagged|untagged|subject_changed|fields_changed|bulk_completed — all with old/new payloads via the Doc 01 outbox.

## 5. Non-functional Requirements

NFR-01 A single transition commits ≤ 25 ms p99 (subscriber work is async). NFR-02 Merge/split/unmerge ≤ 2 s p95 including link rewrites; UI optimistic with rollback. NFR-03 The state machine has one server-side writer (transition service); no status writes elsewhere (CI grep + repository encapsulation). NFR-04 Bulk 200 ≤ 30 s p95 with per-item progress events. NFR-05 Numbering under concurrency: 100 parallel creates → 100 unique numbers, zero duplicates. NFR-06 Every FR-26 event schema is versioned with at least one consumer contract test per consuming doc.

## 6. User Stories

US-01 As an agent, I claim a ticket with one keystroke and know instantly if a colleague beat me to it. US-02 As an agent, I set “waiting on payments team” and the ticket leaves my active view — and returns the moment they answer. US-03 As an agent, a player raises a second problem mid-conversation and I split it to Withdrawals without losing either thread. US-04 As a supervisor, I merge three duplicate deposit tickets and the metrics don’t triple-count the work. US-05 As a supervisor, escalations always tell me why, from whom, and start their own clock. US-06 As a compliance reviewer, spam never pollutes SLA or CSAT numbers, and un-marking within a week restores everything honestly. US-07 As an operator admin, I tune auto-close to 72 h and the reopen window to 14 days for my market, from settings.

## 7. Business Rules

BR-01 Tickets are never deleted in v1 (tickets.delete exists in the catalog but is disabled at platform level; reserved for legal-erasure workflows via Doc 17) — dispute-proofing beats tidiness. BR-02 Messages belong to conversations; tickets reference ranges (Doc 04 primacy); merge/split move links, never message rows. BR-03 A reply *is* a claim (FR-05 default): whoever answers owns it — prevents “answered but unassigned” limbo; per-tenant toggle. BR-04 Resolution requires substance (FR-06): no silent resolves; the customer always received something. BR-05 Reopen beats duplicate: within the window, follow-ups continue the same ticket — continuity of context over ticket-count vanity. BR-06 Escalation is an exception path with mandatory articulation; it is reportable and coachable, never a silent shove. BR-07 channel_unreachable (Doc 05) never auto-resolves or auto-closes a ticket; timers pause per Doc 12 policy flag; humans decide outcomes for unreachable customers.

## 8. UX Requirements

(The inbox/thread layout is Doc 07; this section is the ticket-property contract Doc 07 renders.) - **Property panel:** status control rendering only legal next-states (illegal targets absent, not disabled); priority select with color dots; assignee combo (searchable, presence dots, “assign to me”); department/queue cascade; tags input; followers stack; SLA chips (Doc 12 instance); reply-window chip (Doc 05 component); pending-reason selector appearing contextually; linked tickets list (merged/split/previous) with relationship labels. - **Handoff packet card:** pinned — summary, intent + confidence meter, sentiment/urgency chips, suggested routing with applied/overridden indicator, “customer already saw these” KB list with hover previews; collapse state remembered per user. - **Operation dialogs:** merge (side-by-side timelines, direction arrow, consequences checklist, confirm phrase for >2 sources); split (message multi-select mode with count pill, then destination step); escalate (path picker + reason with min-length meter); spam (consequences note + optional block-customer checkbox with its own confirm). - **State affordances:** resolved shows the auto-close countdown (“closes in 41 h unless the customer replies”); closed shows the reopen-window countdown or reason-required notice; spam banner shows the 7-day unmark countdown. - **Bulk bar:** selection count; allowed actions computed from per-item permission intersection (disabled actions show why); progress drawer with per-item results and retry-failed. - Loading: property-panel skeleton; inline spinners on transitions; optimistic status change with revert + surfaced reason on rejection.

## 9. UI Requirements

- Status pill hues fixed platform-wide: new=blue, open=green, pending_customer=amber, pending_internal=violet, escalated=orange, resolved=teal, closed=gray, spam=red-gray.

- Priority dots: low=gray, normal=blue, high=orange, urgent=red + label (never color-only).

- Countdown chips reuse the Doc 05 component (one temporal language everywhere).

- Confirm-phrase inputs for wide operations (merge >2 sources, bulk close >50).

- Responsive: property panel = bottom sheet on mobile with identical controls; operation dialogs full-screen on mobile; bulk capped at 25 items on mobile (deliberate).

- Reduced-motion: urgent styling is static, never pulsing.

## 10. Technical Requirements

T-01 TicketTransitionService is the sole status writer: validates legality (FR-04 map as data), permission (Doc 03), invariants (FR-10/BR-07), computes FR-24 timestamps, persists + outbox in one transaction. T-02 Open-family invariant enforced at DB level: partial unique index on tickets (conversation_id) WHERE status IN (open-family) AND linked_work = false — a guarantee behind the service check. T-03 Timers (auto-close, reopen windows): Redis ZSET ticket_timers + column truth; 1-min sweep; customer-reply events cancel/reset event-drivenly (sweep re-checks status in-transaction). T-04 Merge: transactional link rewrite + 24 h unmerge_snapshot (pre-merge state jsonb) enabling reversal; snapshots pruned after the window. T-05 Handoff packets: ticket_handoff_packets (ticket_id unique, payload jsonb, schema_version) — insert-only. T-06 Bulk: ticket_bulk_jobs + per-item results on the automation queue with per-tenant concurrency caps; progress via Reverb user.{id}; per-item permissions checked live at execution. T-07 Tag rename/merge: chunked, resumable background rewrite over the GIN-indexed tags array, audited with counts. T-08 List reads go through Doc 07’s read models; this module owns writes and the canonical row. T-09 Contract tests: the FR-04 matrix as a table-driven test (every cell); consumer contract tests for FR-26 schemas (Doc 12/15/17 fixtures).

**Transition write path (text diagram):**

UI/API/Automation → TicketTransitionService.transition(ticket, to, ctx)
  → permission (Doc 03) → legality (FR-04) → invariants (FR-10/BR-07)
  → timestamps (FR-24) → persist + outbox(ticket.status_changed …)
  → [async] SLA (Doc12) | Automation (Doc12) | Routing (Doc11)
            | Realtime (Doc07) | Audit (Doc17) | Metrics (Doc15) | SMS (Doc14)

## 11. Security Requirements

SEC-01 Operations re-check scope on *target* objects (merge target, transfer destination) — moving work cannot smuggle visibility (assignee_out_of_scope, target_out_of_scope). SEC-02 Handoff packets and unmerge snapshots are immutable (insert-only, no update grants). SEC-03 Spam-unmark, staff reopen, escalation reasons, and bulk summaries always audited with actor + old/new (Doc 17). SEC-04 Bulk enforces per-item permission — no batch-level privilege averaging (AC-07). SEC-05 tickets.export (high-risk) covers property/timeline exports; transcript export remains Doc 04’s separate gate; both watermarked. SEC-06 Cross-customer merge prohibition is a hard server rule — prevents privacy bleed between players.

## 12. Performance Requirements

- Transition p99 ≤ 25 ms; sustained 50 transitions/s platform-wide with subscriber-lag alerts.

- Timer sweep: 100k armed timers ≤ 5 s.

- Merge preview loads two 500-message timelines ≤ 400 ms p95 (anchored windows, not full loads).

- Tag autocomplete ≤ 80 ms p95 at 5k distinct tags.

- Bulk 200 ≤ 30 s p95 with linear per-item cost.

## 13. Accessibility Requirements

Status control is a radiogroup of legal targets with described consequences (“Resolved — notifies the customer and starts auto-close”); countdowns follow Doc 05 SR cadence; operation dialogs focus-trapped with step announcements; merge preview navigable timeline-by-timeline via landmarks; bulk progress announced at 25% increments; confirm-phrase fields labeled with the expected phrase; pills icon+text; the panel fully keyboard-operable with a shortcut map shared with Doc 07.

## 14. Localization Requirements

Status/priority names, pending reasons, dialogs, consequence copy, and failure reasons under tickets.* i18n keys (en + am, operator-overridable via Doc 16); countdown phrasing uses ICU duration formats; customer-facing side effects (resolved SMS, CSAT) use Doc 14/12 localized templates keyed off these events; tag content is operator free-text with no translation layer, by design.

## 15. Edge Cases

EC-01 Customer replies at the exact auto-close sweep moment → event-driven cancel wins (sweep re-checks in-transaction); no close-then-reopen flicker. EC-02 Reopen boundary (closed_at + 7 d ± 1 s) → DB-time comparison, inclusive window, fixture-pinned. EC-03 Merge chains (target later merged) → merged_into pointers resolve transitively; banners link to the final survivor; unmerge only per-link within its own 24 h. EC-04 Split cap reached (3 linked-work) → refused with guidance (“resolve or transfer existing linked work first”). EC-05 Escalation to a named user who is later disabled/out-of-scope → auto-fallback to supervisor_review with a system note + notification to the escalator. EC-06 Assignee deactivated with 30 open tickets → ticket.assignee_deactivated batch → Doc 11 re-routes; hand-back system notes on each. EC-07 Two agents resolve simultaneously → first commit wins; the second’s identical-target transition returns success-no-op (documented idempotency), UI refreshes. EC-08 Automation resolves while an agent is typing → on send, prompt: “resolved by rule X — send and reopen, or discard?”; public reply to resolved-within-window → open (FR-08 family). EC-09 Spam unmark on day 8 → refused with terminality explanation; the customer’s next message opens a fresh ticket (spam never blocks the conversation unless block was chosen). EC-10 Ticket in pending_customer when the Doc 05 reply window expires → status unchanged (orthogonal); SLA pause flag + unreachable chip both render; BR-07 governs. EC-11 AI subject generation fails → subject stays empty with retry affordance; lists fall back to first-message snippet (Doc 07). EC-12 Permission change mid-bulk → remaining items re-validated per item at execution (T-06); partial results honest.

## 16. Failure Scenarios

FS-01 Timer ZSET lost → rebuild from columns (truth); event-driven paths keep working during rebuild; worst case auto-closes delayed, never premature. FS-02 A subscriber (e.g., SLA) down → transitions unaffected; the consumer catches up from the event backlog (Doc 01 FS-02); metrics recompute from events. FS-03 Sequence contention spike (mass API creates) → short lock waits within NFR-05; beyond threshold, creates receive backpressure 429 — never duplicate numbers. FS-04 Unmerge attempted as the snapshot prunes → snapshot presence is the authority; refusal states the window passed; both attempts audited. FS-05 Defense-in-depth breach (illegal state somehow persisted) → nightly invariant auditor scans for illegal statuses / duplicate open-family and pages with row ids; exists precisely because it should never fire.

## 17. Success Scenarios

SS-01 A deposit dispute travels new → open → pending_internal → open → resolved → auto-closed with every timestamp, SMS, CSAT trigger, and event visible on the timeline and reconciled in Doc 15. SS-02 A duplicate-storm morning (one outage, 40 near-identical tickets) is consolidated to one master via merge in under 10 minutes, metrics counting one body of work with 40 contacts. SS-03 An operator changes auto-close to 72 h; the change applies to future resolutions only (armed timers keep their value), and the settings page says exactly that. SS-04 A release adding a new event consumer runs the full transition-matrix suite green — no semantic drift.

## 18. Acceptance Criteria

AC-01 Table-driven matrix: all legal cells succeed with correct events/timestamps; all illegal cells return illegal_transition; same-state calls no-op successfully. AC-02 Open-family invariant: concurrent creates on one conversation yield exactly one open ticket + open_ticket_exists rejections; direct-SQL bypass fails on the T-02 index. AC-03 Reopen/auto-close fixtures: EC-01/EC-02 boundaries pass; reopen re-notifies an active prior assignee, re-routes otherwise. AC-04 Merge: preview → confirm → 24 h unmerge restores byte-identical prior state (snapshot diff = ∅); chains resolve transitively. AC-05 Split: linked-work flag, cap, anchors resolving to the exact selected messages, origin note present. AC-06 Spam: Doc 15 fixtures show metric exclusion on mark, restoration on unmark ≤ 7 d, terminality after. AC-07 Bulk: mixed-permission batch yields exact per-item allow/deny; 200 items within NFR-04; progress events observed. AC-08 Handoff packet: created from a Doc 09 fixture, all fields rendered, immutability enforced at DB. AC-09 Metric timestamps: shared golden lifecycle fixtures produce exactly the FR-24 values Doc 15’s dictionary asserts.

## 19. Future Expansion

- Legal-erasure workflow wiring for BR-01’s reserved delete, with a Doc 17 approval chain.

- Problem/incident parents linking many child tickets (schema reserves parent_ticket_id) — the merge-storm scenario graduates to linked children with broadcast resolve.

- Customer-visible ticket portal states (Doc 13 expansion) mapping internal statuses to a simplified public set.

- Scheduled transitions (“resolve Friday if silent”) atop the timer infrastructure.

- Per-department status *vocabularies* (display aliases over the fixed machine — the machine itself never forks).

## 20. Risks

R-01 State-machine erosion via “one more status” requests (High likelihood) → statuses platform-fixed v1; display vocabularies absorb pressure; changes require series-wide review. R-02 Merge misuse hiding workload (Medium/Medium) → merge-rate metric (Doc 15), preview friction for >2 sources, unmerge safety net. R-03 Timer correctness across DST (Low/Medium) → all-UTC storage; fixtures include DST dates. R-04 Implicit claim surprising strict-dispatch teams (Medium/Low) → per-tenant toggle, documented in onboarding. R-05 Event fan-out coupling (inherited Doc 01 R-02) → versioned schemas + consumer contract tests as containment.

## 21. Recommendations

- Build TicketTransitionService + the matrix test in this module’s first sprint; everything else composes on it.

- Keep the DB invariant index even though the service checks first — the one hotfix that bypasses the service will otherwise create a duplicate-ticket incident.

- Share golden lifecycle fixtures with Doc 15 from day one; metric disputes die when both sides run the same fixtures.

- Ship merge with unmerge or don’t ship merge; the 24 h reversal turns a scary operation into a routine one.

- Keep customer-visible status mapping out until the portal exists; internal precision first, public simplification later.

*End of Document 06. Next in series: Document 07 — Unified Inbox: Agent Workspace.*