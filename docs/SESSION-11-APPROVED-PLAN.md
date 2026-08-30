# SESSION-11 PLAN — Doc 11: Departments, Teams, Queues & Routing

**Status: APPROVED — advisor plan audit PASSED (90/90 exact), STOP-A…H ruled (§0a), STOP-E owner-ruled option 1 (the pin stands), 2026-08-30. Committed at approval per standing rule 12. THIS FILE is the plan of record; §0's STOPs are kept as the proposals the rulings answered. UNCOMMITTED until
approval (standing rule 12). Step-0 record grounded at `32270d7` (BUILD-STATUS only). Family
count 90 stated in §1 for the advisor's private reconciliation. The plan-time STOPS in §0 are
proposals; nothing in them is decided.**

Read in full before this plan (prompt §6, every item): CLAUDE.md; Doc11-Departments-Teams-
Queues-Routing.md (all 21 sections); BUILD-STATUS Doc 10 close section + S10-ACCEPTANCE + risks
0a–0l + incidents #14–#16 + platform notes; ANSWERS Q1–Q9, A-1…A-48, R-1…R-26, SCHEMA
AMENDMENTS 2/3/4, A-11 (as-if-assigned); ADRs 0003/0010/0011/0015–0019; DESIGN-FIDELITY-MANDATE
v10; ADVISOR-RULINGS-D11 (R-D11-1…4); the substrate AS CODE — Doc 03 (`ScopeSets`,
`MembershipSource`/`EmptyMembership`, `TicketScope::visibleOnceAssignedTo`, `OverrideAdministration`,
`rbac:expire-overrides`, `RoleTemplates`/`RoleSeeder`), Doc 06 (`TicketTransitionService`,
`AssignTicket`, `CreateFromHandoff`/`HandoffPacket`, `AssigneeDeactivated`, `TransferTicket`,
`TicketTimers`/`TicketTimerSweep`, the `ticket-status-writer` gate + `StatusWriterEncapsulationTest`),
Doc 07 (`SystemViews`, `ViewVariants`, `ViewHash`, `AgentStatus`, `WorkspaceController::queueViewsSeam`,
`ViewsRail.vue`, `TicketTimeline.vue`, `ThreadPane.vue` system events), Doc 08 (`RgFlagService`,
`CustomerPayload` visibility law), Doc 09 (`HandoffDecision`, `RgOverride`, `TemplateResolver`
`{{queue_wait}}` seam), platform (`EventCatalog`, `ConsumerRegistry`, `OutboxRelay`,
`TenantProvisioner`/`ProvisioningStep`, `registries/*`); SESSION-09/10 approved plans; the frozen
files `B5-S09-organization-v1`, `B5-S10-restructure-wizards-v1`, `B5-S02-interventions-v2`,
`B5-S00-checklist-v3`, `B5-S11-closing-audit-v2`, `B1-DS-design-system-v2` + addenda B5/B6/B7.

---

## §0 — PLAN-TIME STOPS (rulings needed before step 1; each with evidence and a proposal)

### STOP-A (mandatory; prompt §7 + §10) — T-02 "assignment commits through Doc 06's TicketTransitionService (actor=system)" vs the BUILT assignment door

**Evidence.** `app/Domain/Tickets/Transitions/TicketTransitionService.php` owns STATUS only
(`transition()`, `closeForMerge()`, `reopenFromUnmerge()`, `recordPublicReply()`). Assignment lives
in `app/Domain/Tickets/Assignment/AssignTicket.php`: `assign(Ticket, User $assignee, User $actor)`,
`claim(Ticket, User $actor)`, `unassign(Ticket, User $actor)` — **every door takes a staff
`User $actor`; no system path exists**. `AssignTicket::write()` L114-117 writes `assignee_id`
directly, then calls the transition service only for FR-05's `new → open` with
`TransitionContext::staff($actor)`. `TransitionContext` L71-73 refuses a user on a non-staff
context. The `ticket-status-writer` gate (`ci/ticket-status-writer.sh`, gate #13 of 14 in
`registries/gates.yaml`) polices **status writes only**; nothing today restricts who writes
`assignee_id`/`department_id` (`TransferTicket` L82 and `AssigneeDeactivated` L65 both do).

**Proposal (Doc 06 core-edit packet, countersign before landing):**
1. `AssignTicket` gains ONE system door: `assignBySystem(Ticket $ticket, User $assignee,
   RoutingCommit $commit): Ticket` — same `write()` (the FR-16 `whereNull('assignee_id')` guard IS
   the race), same FR-15 `visibleOnceAssignedTo` check under A-11 (Own satisfied by the assignment;
   Team/Dept must match the ticket's department — real now), the FR-05 `new → open` transition
   through `TransitionContext::system('routing')`, `ticket.assigned {old, new, by: null}` with the
   audit row's actor `system` + the `routing_decision_id` carried in the audit metadata. The
   commit-time capacity check (FR-05/EC-01/EC-03) lives INSIDE this door's transaction
   (`SELECT … FOR UPDATE` on the agent's open-assigned count row — see §2), so over-capacity is
   refused at commit, not at pop.
2. Queue placement is a Doc 06-owned write too: `app/Domain/Tickets/Placement/PlaceInQueue.php`
   (new file in Doc 06's tree, actor system|staff) writes `tickets.queue_id` + `queued_at` and
   emits `ticket.waiting` / the transfer-side queue change. Routing NEVER writes `tickets`.
3. **Filed interpretation A-49 (proposed):** T-02's "commits through Doc 06's TicketTransitionService"
   is read as "through Doc 06's transition AND assignment doors, actor=system" — the document's own
   §21 sentence ("committed-through-Doc-06") is the intent; the service name is shorthand. The
   assignment door already delegates its status step to the transition service, so there is still
   one status writer.
4. **A new structural gate as CODE (step 1, before any engine line):** `RoutingNeverWritesTicketsTest`
   (static): under `app/Domain/Routing/**` there is no `Ticket::query()`, `table('tickets')`,
   `UPDATE tickets`, `assignee_id =>`, `department_id =>`, `queue_id =>` write; positive control = a
   planted write turns it red. `StatusWriterEncapsulationTest` + the gate stay untouched and green
   every commit (the "13th gate stays green" law).

### STOP-B (mandatory; schema countersign) — SCHEMA AMENDMENT 5 outline (filed in full at step 2a, BEFORE any migration; R-19b per column; ADR-0003 grant physics; FORCED RLS; REVOKE DELETE where the document forbids deletion)

T-01's tables, all NEW (no `departments|teams|queues|skills` table exists — verified across
`database/migrations`): `departments` (name i18n-capable jsonb or text + slug, description,
position, `default_queue_id` nullable FK deferred, `business_hours_override_ref` nullable
(**[D12] seam column, never read here**), `is_active`), `teams` + `team_members` (+ `is_lead`),
`queues` (department_id FK, name, position, `assignment_strategy` CHECK round_robin|load_based|
skill_based, `capacity_per_agent` nullable → setting default, `priority_rules` jsonb ordered list,
`skill_fallback` CHECK nearest_skill|wait, `is_active`, `is_rg` with a **partial unique index
`(tenant_id) WHERE is_rg`** — "at most one per tenant" as physics, `routing_tags text[]` + GIN),
`queue_members` (`kind` CHECK direct|temporary, `granted_by`, `expires_at`, `override_id` FK to
`user_permission_overrides` NOT NULL WHEN temporary — CHECK `(kind='temporary') = (override_id IS
NOT NULL)`: the SINGLE SOURCE T-01 demands), `user_skills` (language codes from Doc 01 `ref_*`,
department proficiencies), `routing_decisions` (ticket_id, `record` jsonb, `at` = DB
`clock_timestamp()`, `trigger`, `outcome` CHECK assigned|waiting|parked_error; **INSERT-only grant
— a record is never rewritten**), `user_departments` (A-50: Dept-scope membership, kind direct|temporary, override-linked when temporary), `user_routing_capacity` (the `routing.capacity.user` override on
"the user record" — a side table, not a `users` column, so Doc 02's table is untouched),
`agent_assignment_stats` (open-assigned count + last_assigned_at per user — the FOR UPDATE row of
STOP-A's capacity check; rebuildable from `tickets`).

**Doc 06 table deltas (core-edit, same amendment):** `tickets.queue_id` uuid nullable (expand) +
`queued_at` timestamptz(6) nullable + `routed_at`; both added to `APP_WRITABLE_COLUMNS` and the
`TicketGrantPhysicsTest` split; `tickets.department_id` gains an FK to `departments` as
`NOT VALID` then `VALIDATE` in a later release (C-12 — the S06 docblock "no FK until Doc 11"
discharges here). `ticket_list_items.queue_id` (Doc 07) stops being "fixture-fed" — the projector
reads the real column (Doc 07 core edit, ≤ 3 lines).

**Doc 05 delta:** NONE (STOP-C.3 ruled fixture seam — `fixtures/routing/seams/doc05-connection-tags.json`; reopener filed on Doc 05's FR-08 row).

### STOP-C — FR-01's seeded departments: no provisioning step and no declared consumer exist

**Evidence.** `ProvisioningStep::ordered()` = tenant_record, default_settings, default_roles, sla_policy,
business_hours, knowledge_base — no organization step. `EventCatalog` `tenant.provisioned` consumers
= `Doc03/RoleSeeder, Doc10/KnowledgeBaseSeeder, Doc17/Audit` — no `Doc11/*`. A-45 (S10 plan §0a,
handoff v10:70) rules that a DECLARED consumer is implemented; here nothing is declared, so A-45 does
not choose for us.

**Proposal C.1 (recommended):** `ProvisioningStep::Organization = 'organization'` (owning Doc11), placed
AFTER `default_roles` and BEFORE `sla_policy`, dispatched in `TenantProvisioner` as
`app(OrganizationSeeder::class)->seed()` — the RoleSeeder pattern (in-process, idempotent on
tenant+step, `seeded_fingerprint` so an operator-edited set is never overwritten, EC-07's shape).
Rationale: FR-08 step 1's terminal fallback is "tenant default department" — it must EXIST before
the tenant is Active and the first ticket can arrive; an asynchronous consumer leaves a window
with no department (the very tension A-45's standing instruction named). Doc 01 core-edit packet:
enum case + one dispatch line + `ProvisioningStepsTest` pin move; the catalog's
`tenant.provisioned` consumers list is NOT touched (nothing is declared, nothing is invented).
Seeded set verbatim from FR-01: General (tenant default, with one default queue "General"),
Payments, Withdrawals, Deposits, Bonuses, Technical, Verification, Responsible Gaming
(`is_rg` queue "Responsible Gaming"), VIP — each with ONE default queue so EC-10's
"defaultless department" validation holds from birth. Content, not fixtures (BR-04): operator-editable.
Alternative C.2: amend the catalog to declare `Doc11/OrganizationSeeder` and implement it as a durable
consumer (A-45 shape) — rejected on the ordering evidence above; presented for the ruling.

**C.3 — Doc 05's connection/bot routing tag is UNBUILT.** Doc 05 FR-08 names a per-connection routing
tag; `telegram_business_connections` has no such column and `grep routing_tag` across app/ is empty.
FR-08 step 1 consumes it ("conversation default (Doc 05 connection/bot routing tag)"). Proposal: a Doc 05
core-edit packet adds nullable `routing_tag` to connections and bots + the B6-S02 connections screen's
tag input (Doc 05's own FR-08 sentence, never built — a Doc 05 closing-row discharge, filed as such),
and Doc 11 reads it through a `ConversationRoutingTagSource` seam interface (fail-closed null → next
step). If the advisor prefers not to open Doc 05's files this session, the alternative is a fixture-fed
seam (`fixtures/routing/seams/doc05-connection-tags.json`) with the step-1 precedence proven against it
and the Doc 05 column filed as a [D05-OA] closing row — stated, not chosen.

### STOP-D — What "Team scope over tickets" and FR-04's "Doc 03 department access" resolve to (Doc 03 FR-03 vs Doc 11 T-01)

**Evidence.** Doc 03 FR-03: Team = "records of the user's teams", Department = "records of departments the
user belongs to". Doc 11 T-01 defines `team_members` and `queue_members` but NO user↔department table;
tickets carry `department_id` and (after STOP-B) `queue_id`, never a `team_id`.
`TicketScope::columns()` maps team → `department_id` as a declared PLACEHOLDER (docblock L27-28).
B5-S09 J04/J06 draws "in this team but has no access to Verification" with a **Give Verification access**
action — so the design assumes department access is distinct from team membership.

**Proposal (filed interpretation A-50, countersign):**
- `departmentIdsFor(user)` = departments of every queue where the user is an effective member (direct ∪
  via-team ∪ unexpired temporary) — "belongs to" derived from T-01's own tables; nothing invented.
- `teamIdsFor(user)` = `team_members` rows (direct).
- **Team scope over tickets** = tickets whose `department_id` ∈ departments of queues where ANY of the
  user's teams is a member (the team's reach) — expressed on the existing `department_id` column, so
  `TicketScope`/`ListReadModel` change only their `team:` column's id SOURCE, not the column. A-11's
  "Team/Dept must match the ticket's department" stays literally true.
- **FR-04's config error** = a queue member whose ticket-view scope cannot reach the queue's department:
  scope None (no `tickets.view`), or Team/Dept scope whose resolved department set excludes it (a
  direct member of a Verification queue whose role is Team-scoped and whose teams reach only Payments).
  Own- and All-scoped members always pass (A-11). Excluded from eligibility, badged (J06/J12 warning).
- "Give Verification access" (J04) = add the user as a direct member of that department's default queue
  (the only access-granting act T-01 defines) — a frame-mapping row, not a new door.
Alternative: add `department_members` — rejected (not in T-01; would be an invented table).

### STOP-E — THE PIN: Doc 07's Unassigned×Own-scope revisit (OWNER ruling; R-D11-4 item 12)

**The filed record (advisor-ruled at step 0, five locations):** `tests/Security/InboxSecurityTest.php:56-60`
("Own scope: Doc 03's literal composition — an unassigned ticket is assigned to nobody, so it is outside
EVERY Own-scoped viewer's set (A-11's own analysis; routing hands Own-scoped agents their work)");
`tests/Feature/Inbox/PerViewerCountsTest.php:104` ("an Own-scope unassigned variant must be EMPTY — the
pinned Doc 03 literal composition (step-2 disposition)"); ANSWERS A-11; PROJECT-HANDOFF-v7:102;
PROJECT-HANDOFF-v10:140 ("the S07 owner override left open by name").

**Today:** an Own-scoped agent (Agent / Junior Agent templates) sees an EMPTY Unassigned view; D7's
"Claim the next one" lands on an empty list for them. Doc 11 changes the facts: routing now HANDS them
work (FR-08 step 4), so the pin is no longer a gap in their workflow — it is a question of whether
self-claim from a queue should exist for Own-scoped agents at all.

**Options for the owner:**
1. **KEEP THE PIN (recommended as the Doc 11 default).** Own = assigned-to-me, literally (Doc 03 FR-03).
   Own-scoped agents receive work by routing; self-claim is a Team/Dept/All-scope act. Zero core edits;
   the two test pins stand; BR-05 ("the router never assigns to Away agents to make numbers look better")
   and BR-03 stay clean. The D13 empty state "Claim the next one" for Own-scoped agents reads its real
   count (0) — the drawn state already handles it.
2. **QUEUE-MEMBERSHIP VISIBILITY FOR SELF-CLAIM.** Own-scoped agents additionally see, in the `unassigned`
   system view ONLY, tickets waiting in queues where they are effective members. Doc 03 core edit (a
   filed widening of Own for one view), Doc 07 core edits (`ViewVariants` own: grammar, `ListReadModel`
   hydration, both pins rewritten with the new law), a new AC-02 fixture. Widens visibility deliberately;
   must be proven with the same before/after pair as the T-03 transition.
3. **DEFER to Doc 15** (supervisor-dashboard era) — the pin stays, revisit filed forward.
The session builds option 1 unless the owner rules 2; option 2's packets are enumerated in §11 so the
ruling can pick without re-planning.

### STOP-F — R-D11-1 side-row: the 0i wait-hardening packet, DIAGNOSED AT CAUSE (proposal; countersign before landing)

**`tests/Browser/inbox-ticket-panel.spec.ts:333` (D7–D10).** Recorded cause (Stage-4 job 99141508542 of
run 33267349240, f81e9b4 — the batch-D look, re-read not re-taken): `Error: locator.click: Test timeout
of 30000ms exceeded … waiting for locator('[data-ticket]').filter({ hasText: 'Kidist Haile' })
.locator('[data-role="row-open"]') … element was detached from the DOM, retrying` at spec line 404
(the `g u` bounce click). The Unassigned list is re-keying its rows while the just-seeded
`ticket.assignee_deactivated` projection, the D7 claim-next view switch and the FR-11 batched resort are
still landing — the click target is found, detaches, is found again, forever. **Setup/wait defect.**
Proposed hardening: a shared `settleList(page)` helper in `tests/Browser/support/` (the `coach.ts`
shape) that (a) polls `window.__inboxList.state().viewKey` to the expected view and (b) polls the
row-order snapshot to be unchanged across two consecutive 500 ms reads, applied before the two bounce
clicks at lines ~401 and ~408. Assertions byte-untouched.

**`tests/Browser/inbox-mobile-interactions.spec.ts:233` (EC-03 dual-tab drafts).** Recorded cause
(`docs/evidence/S08-step1/ci-31788529540-stage4-log.txt:4208-4217` and the batch-D window's retry-red):
`expect(received).not.toBeNull()` at spec line 252 — `window.__inboxList.state().order[0]` is read
immediately after `goto('/inbox')` + coach-skip, before the list's first fetch lands. **Setup/wait
defect.** Proposed hardening: `await expect(page.locator('[data-ticket]').first()).toBeVisible()` before
the read, then the SAME `expect(target).not.toBeNull()` line (assertion untouched); tab B's row click
gets the same first-row wait.

Proof per R-D11-1 §3: three consecutive first-attempt local full-browser greens (`make browser`, wall
power, `octane:reload` before each), then the next checkpoint's single CI look. No product defect found
in either diagnosis; if the local runs contradict that, mandatory STOP.

### STOP-G — R-D11-3 §3 correction: the routing-decision record is DRAWN, not a gap

**Evidence.** `design/frozen/B5-S02-interventions-v2.dc.html` §D (`data-blk="record"`, light + dark) draws
`C09 · Expanded in the ticket timeline`, `C10 · Skill match with nobody online — it holds rather than
mis-routes` / `The starvation jump, and a reopened ticket's courtesy hold`, `C11 · The same record, viewer
without individual-metrics rights` — FR-17 + SEC-02 exactly. R-D11-3 §3 called the expandable a class-2
gap; §4 said B5-S02's Doc 15/07-rendered surfaces stay seams. FR-17 is Doc 11's OWN requirement ("attached
to the ticket timeline (collapsed system event, expandable)"), rendered inside Doc 07's `TicketTimeline.vue`.
**Proposal:** build the expandable THIS session as a Doc 07 slot-fill packet (the S09 shape) bound at full
fidelity to C09–C11 as FRAME-MAPPING rows (mandate §3: first real implementation of a drawn state), not
class 2. The server side: `ticket.routed {record_id}` rows already reach the timeline reader because it
selects `name LIKE 'ticket.%'` (`TicketActionsController.php:177-222`); the record body is fetched by id
through a new `GET /inbox/t/{id}/routing/{record}` door (`tickets.view`; SEC-02 elision server-side,
absence not redaction). Rendering of C01–C08 and C12–C14 stays SEAM (data contracts + events only).

### STOP-H — the Doc 07 queue-view seam's contract when it becomes real

`WorkspaceController::queueViewsSeam()` renders `fixtures/inbox/seams/doc11-queue-views.json` behind
`?seam_queues=1`. Doc 07 FR-05 (Doc 07's text): queue views "auto-generate per accessible queue". Proposal
(Doc 07 slot-fill packet): replace the seam with `QueueViews::forViewer(user)` = queues where the viewer is
an effective member (direct ∪ team ∪ unexpired temporary), each a system view `queue:{id}` whose membership
= waiting tickets in that queue (unassigned, open-family, ordered by effective priority then FIFO — FR-08
step 3's order, so the agent sees the queue as the dispatcher does); counts per A-30 variants; EC-07/Doc 07
EC-09 vanish-with-toast on expiry/deletion. The fixture and the `?seam_queues` door are DELETED in the same
commit (the seam's own comment says so); `inbox-views.spec.ts:150` is rewritten against real rows under
the 0l law. Countersign owed as a Doc 07 core edit.

## §0a — ADVISOR RULINGS FOLDED (paste of 2026-08-30; plan audit PASSED, 90/90 exact; grounded at 32270d7)

- **STOP-A APPROVED IN DIRECTION** — the Doc 06 packet (`AssignTicket::assignBySystem` + `PlaceInQueue`,
  capacity check inside the SAME transaction, `RoutingNeverWritesTicketsTest`, A-49). Escalate-first: diff
  shown + countersigned BEFORE landing. Obligations bound: the system path carries FR-15's as-if-assigned
  check (A-11) exactly as the staff path, actor=system, audited; AC-03's zero-over-capacity concurrent proof
  rides the packet; the gate lands with a positive control (planted direct ticket write → red → restored);
  Doc 06's own suites green as the owning-document regression proof; A-49 files to ANSWERS.
- **STOP-B APPROVED IN DIRECTION** — SCHEMA AMENDMENT 5 files complete for countersign BEFORE any migration
  (R-19b per column, ADR-0003 grant physics, deliberate APP_WRITABLE split). Required physics: `is_rg`
  partial unique index per tenant; `routing_decisions` INSERT-only BY GRANT; temporary `queue_members`
  CHECK-linked to Doc 03's grant rows; `tickets.queue_id/queued_at/routed_at` ride the STOP-A packet's
  countersign (Doc 06-owned; the two stamps are ordering-relevant → DB-time `clock_timestamp()`).
  File-and-STOP at 2a (the S10 shape).
- **STOP-C RULED** — **C.1 APPROVED**: `ProvisioningStep::Organization` as a Doc 01 packet, escalate-first with
  countersign; the default department exists before Active. **C.3 RULED FIXTURE SEAM**: queue-side routing
  tags are Doc 11's and build fully; the CONNECTION-side tag source stays a fixture-fed seam
  (`fixtures/routing/seams/doc05-connection-tags.json`) — the step-1 precedence slot preserved and tested
  against the fixture, the resolver returns null in production (falls through to tenant default), a named
  reopener filed on Doc 05's FR-08 row. No new Doc 05 connection feature this session. §6 loses the
  conditional [D05-OA] bucket; the reopener is a Doc 05 row annotation, not a Doc 11 PARTIAL.
- **STOP-D COUNTERSIGNED (2026-08-30)** — A-50 as pasted (points 1–8), with FIVE proving obligations bound:
  (1) INDEPENDENCE PROOF — fixtures hold a user with a `user_departments` row and NO queue membership, and a
  user with queue membership and NO `user_departments` row; FR-04's config error fires on the second, badged
  with the department named (the J04 case executed); (2) DIVERGENCE FIXTURE pinning point 3 — a teammate
  ASSIGNED a ticket in a department outside the team's queue reach is NOT in Team scope (visible, testable,
  never drifts); (3) ONE-ACT AUDIT — J04's grant writes both rows under a single
  `org.department.access_granted` action in ONE transaction, a partial write proven impossible; (4) JOINT
  EXPIRY — point 6's three rows expire together on the same `override_id`; sweep disabled → the
  assignment-time check still refuses (AC-05's independence bar applies to the department half too);
  (5) AC-03 RE-PROOF per point 8, exact sets, all four levels, against real rows. A-50 files to ANSWERS with
  the derivation self-correction as honest trail (the session's own catch, no blame row) and a Doc 15
  reopener note: team REPORTS may later want assignee-based grouping — a metric question for Doc 15, not a
  scope change, named so no future sweep re-litigates point 3.
- **STOP-E — OWNER RULED, OPTION 1: THE PIN STANDS.** Unassigned remains EMPTY for Own-scoped agents; routing
  (FR-08 step 4 / A-11) hands Own-scoped agents their work. Actions received so far: the two pinned docblocks
  (`InboxSecurityTest.php:56-60`, `PerViewerCountsTest.php:104`) and `PerViewerCountsTest` stay
  byte-identical; the ruling files in ANSWERS as the pin's revisit CLOSED (citing the five filed locations +
  this ruling); the handoff-carried revisit line is annotated resolved. **The S07 owner override is hereby
  exercised as KEEP.** (Tail received complete, "END OF RULING".) §11 row 11 (option-2 packets) is struck.
  Filing lands at step 2b with A-49…A-54.
- **PLAN OF RECORD:** committed at this fold (rule 12), pushed; BATCH A released — the 0i packet
  (STOP-F/R-D11-1) first, then the safety floor before any engine line; CI verdicts owed forward to the
  batch-A checkpoint.
- **STOP-F COUNTERSIGNED** — the 0i packet as diagnosed (`settleList()` + first-row wait; assertions
  byte-untouched). Land per R-D11-1: three consecutive first-attempt local full-browser greens on BOTH specs;
  the 0i ledger annotated with cause + fix commit.
- **STOP-G ACCEPTED** — the routing record is DRAWN (B5-S02 §D C09/C10/C11): a Doc 07 slot-fill packet at FULL
  fidelity with frame-mapping rows, escalate-first with countersign. R-D11-3 §3 corrected on the record —
  one honest-trail line in BUILD-STATUS at step 11b, no blame row.
- **STOP-H APPROVED** — real queue views per effective membership; the fixture AND the `?seam_queues` door
  DELETED in the same commit that lands the real views, Doc 07's suites green, the FR-05 annotation updated.
- **PLAN APPROVED conditional** on: (1) this fold; (2) STOP-E's owner ruling folded on arrival; (3) the plan
  committed as the plan of record at that moment (rule 12), one row per id, sabotage list at 14. CI verdicts
  owed forward (rule 15). Batch A releases at the plan commit.

### STOP-D — A-50 FULL TEXT (revised on the advisor's representability question; needs countersign)

**The question answered honestly first.** My original derivation (dept access := departments of queues the
user is an effective member of) makes FR-04's intersection a tautology for every Dept-scoped member: a queue
member always "belongs to" the queue's department, so the config-error state could arise ONLY through role
scope (None, or a Team-scoped role whose teams reach elsewhere) — never through department access itself.
That is a narrower warning than FR-04 draws, and J04/J06's "Yonas is in this team but has no access to
Verification → Give Verification access" cannot be represented at all under the derivation (a team member
of a Verification-serving team would automatically HAVE access). **The derivation cannot fully represent
FR-04. The advisor's named alternative is adopted.**

**A-50 (FILED INTERPRETATION, revised) — department access is a membership of its own.**
1. **`user_departments` (amendment 5 addition):** `(tenant_id, user_id, department_id, kind CHECK
   direct|temporary, granted_by, override_id nullable, ended_at nullable)` — the storage of Doc 03 FR-03's
   "departments the user belongs to". `MembershipSource::departmentIdsFor(user)` = live `user_departments`
   rows (direct ∪ unexpired temporary). Independent of queue membership BY TABLE.
2. **`teamIdsFor(user)`** = `team_members` rows.
3. **Team scope over tickets** = tickets whose `department_id` ∈ departments of queues where ANY of the
   user's teams is a queue member (the team's reach), expressed on the existing `department_id` column —
   `TicketScope`/`ListReadModel` keep `team: 'department_id'`; only the id SOURCE changes (the S06
   placeholder docblock is rewritten to say so). A-11's "Team/Dept must match the ticket's department"
   stays literally true. (Doc 03 FR-03's "records of the user's teams": a ticket has no team; the team's
   records are the tickets in the departments its queues serve — filed, not assumed.)
4. **FR-04's effective queue agents** = (direct ∪ via-team ∪ unexpired temporary queue members) ∩
   {users whose ticket-view scope reaches the queue's department}: All → always; Dept → `user_departments`
   contains it; Team → the team reach (3) contains it; Own → satisfied by the assignment itself (A-11);
   None → never. A member failing the intersection is a **CONFIG ERROR**: excluded from routing, badged
   (J06/J12 warning with the specific department named). This is now representable for every scope level,
   including the J04 case (team member, Dept-scoped role, no `user_departments` row for Verification).
5. **J04's "Give Verification access"** creates BOTH the `user_departments` direct row AND a direct
   `queue_members` row on the department's default queue (the advisor's shape) — one audited act,
   `org.department.access_granted`, so the warning clears and the agent becomes routable in one step.
6. **Pull-in (FR-15/FR-03 temporary)** creates the Doc 03 override + a temporary `queue_members` row AND a
   temporary `user_departments` row for the queue's department when the agent lacks access (US-03: a
   General agent pulled into Deposits must be able to see Deposits tickets once assigned — A-11 makes Own
   agents fine, but a Dept-scoped Team Leader pulled across departments needs the access row). All three
   rows expire together (same `override_id`, same `expires_at`; EC-07: assignments made persist).
7. **Seeding (STOP-C.1):** provisioning creates departments and queues only — NO `user_departments` rows
   (no users exist at provisioning). Super Admin/Admin/QA/Read Only are All-scoped and need none.
8. **The AC-03 re-proof:** `ScopeFixtureTest`'s `FixtureMembership` cases are re-run against
   `OrgMembership` with real rows in the three tables; the exact sets are unchanged in shape (the fixture
   already models "dept" as a set independent of "team").
Alternative rejected: derived access (above) — cannot represent FR-04/J04.

### Filed-interpretation candidates raised now (A-4 mechanism; each filed BEFORE its step)
- **A-49** STOP-A's reading of T-02. **A-50** STOP-D's membership derivations. **A-51** FR-12's
  "effective priority score" arithmetic: score = Σ(configured boost weights, ordered list position
  → weight) with FIFO `created_at` (DB time, ADR 0010) as the age term; starvation = a hard queue-head
  promotion OUTSIDE the score (NFR-06 "exempt from every other rule") — never a large weight that could
  be out-stacked. **A-52** FR-13's band thresholds: the frozen J15 frame draws `< 8 min → "~5 min"`,
  `8–35 → "~20 min"`, `> 35 → "over an hour"`, closed → "when we open at HH:MM"; hysteresis = a band
  changes only when the raw estimate crosses the boundary by ≥ 20 % of the band width AND ≥ 30 s have
  elapsed since the last change (NFR-04's "no flapping"; numbers are settings). **A-53** FR-15 pull-in's
  "expiry default shift-end via business hours": Doc 12 is unbuilt → default = the tenant-timezone end of
  day (23:59:59, Doc 01 timezone rules) as the fixture-shaped hours contract [D12], editable in the dialog
  (C03 draws the field). **A-54** EC-08's courtesy hold timer rides `TicketTimers`' ZSET pattern (Doc 06's
  auto-close shape) with its own key — a Doc 06 pattern REUSED, not a new sweep species.

---

## §1 — Id count by family (from Doc 11's own sections; cross-document citations excluded — Doc 11 cites Doc 03 FR-09/T-03/EC-05, Doc 05 FR-10/FR-22/FR-28, Doc 06 FR-08/FR-10/FR-15/FR-16/FR-17/FR-22/EC-06/BR-07, Doc 07 FR-05/FR-28/EC-09/BR-06, Doc 08 FR-15, Doc 09 FR-12/FR-13, Doc 12, Doc 15, Doc 17, Doc 18 — each checked against the section text, none counted)

| FR | NFR | US | BR | T | SEC | EC | FS | SS | AC | R | Total |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 19 | 6 | 7 | 7 | 9 | 6 | 12 | 5 | 4 | 10 | 5 | **90** |

Unnumbered requirement rows carried as plan rows too: §8 (structure admin; wizards; supervisor data
contracts; empty states; loading/error), §9 (badges; chips; warning badges; starvation row; record rows;
responsive; dark/reduced-motion), §12 (six budget rows), §13 (a11y), §14 (localization).

**Event family (FR-19; closed at SIXTEEN; catalog amendment filed at step 2b BEFORE any publish):**
`org.department.created|updated|deleted`, `org.team.created|updated|deleted`,
`org.queue.created|updated|deleted`, `queue.membership_changed`, `queue.temporary_grant.created|expired`,
`ticket.routed {record_id}`, `ticket.waiting {queue, position}`, `routing.starvation_boosted`,
`routing.no_skill_match`, `rescue.flagged|auto_returned`. Payloads: identifiers, queue/department ids,
record id, position integers — never names or record text on the bus. Consumers declared: Doc07/Inbox
(queue views, rescue), Doc15/Reporting, Doc17/Audit, Doc16/Notifications (broadcast + no_skill_match +
rescue alerts), Doc18/Alerts (FS-01/FS-04). **Every routing consumer is DURABLE (ADR 0011):** registry
entry + `ReopenConsumerDrillTest` pin move + `fixtures/tickets/consumers/doc11.json` tightening +
`fixtures/routing/consumers/doc11-events.json` + `tests/Contract/RoutingEventConsumerContractsTest.php`
in the SAME commit. Doc 11's consumers (all `App\Jobs\Routing\*`, `routing` Horizon family — declared
in CLAUDE.md §3's supervisor list as `automation`? NO: a new `routing` family requires an ADR → we ride the
EXISTING `automation` family per ADR 0007's precedent, stated): `ticket.created`, `ticket.assigned`
(null new = unassign), `ticket.assignee_deactivated`, `ticket.transferred`, `ticket.reopened`,
`ticket.status_changed` (capacity deltas), `ticket.split`, `agent.status_changed` (first consumer),
`customer.rg_flag.set`, `ai.handoff.created` (decision enrichment join), `queue.membership_changed`,
`queue.temporary_grant.expired`, `org.queue.deleted` (EC-06 orphan path).

**Permission keys (CLOSED catalog, 114 EXECUTED; no addition):** `org.departments.manage` (FR-01/FR-18
department doors + wizard), `org.teams.manage` (FR-02), `org.queues.manage` (FR-03, boosts, strategy,
tags, deactivate/delete), `org.queues.pull_in` (FR-15 pull-in, SEC-01), `supervision.reassign`
(FR-15 reassign), `supervision.agent_status.force` (FR-15 force — Doc 07's existing door, consumed),
`supervision.broadcast` (FR-15 broadcast), `supervision.monitor`/`supervision.whisper` (permissions +
events only, §3), `reports.agents.individual` (SEC-02 elision), `tickets.tags.manage` (EC-05 warning
source check), `tickets.assign`/`tickets.assign_self` (Doc 06's, consumed), `tickets.view` (record read,
queue views), `customers.rg_flag.view` (RG facts inside records elide without it — Doc 08's law),
`org.business_hours.edit` (NOT bound — Doc 12's door; the hours-override REF field on departments is
[D12]). Structure READ for the admin lists = `org.*.manage` holders (no `org.view` key exists in the
catalog; the admin surfaces are manage-gated, stated as a frame-mapping note).

**Settings (registry; every one named by the document, `routing.*`):** `routing.capacity.default` int 5
(min 1 max 50) tenant_editable; `routing.capacity.count_pending` bool false tenant_editable;
`routing.starvation.minutes` int 30 `required|integer|min:1|max:1440` tenant_editable — **NO nullable,
no zero, no absent (N-12; the validation string is the physics)**; `routing.rescue.minutes` int 10
tenant_editable; `routing.rescue.auto` bool false tenant_editable; `routing.reopen.wait_for_previous`
int 15 (min 0 — 0 = no courtesy hold, still a value) tenant_editable; `routing.ai.autoaccept` bool false
tenant_editable; `routing.estimator.cache_seconds` int 30 platform; `routing.estimator.hysteresis_pct`
int 20 platform; `routing.estimator.band_thresholds_minutes` json `{"short":8,"medium":35}` platform
(A-52); `routing.skill.no_match_alert_minutes` int 15 tenant_editable (FR-11 wait fallback's
"SLA-aware alert at threshold" — the SLA input is [D12]; the threshold is ours); `routing.pull_in.recurrence_hint`
int 3 platform (BR-07). `routing.capacity.user` is a per-user override ROW, not a setting key (FR-06's
"on the user record"); stated. Nothing that disables the starvation cap, RG finality, or the record.

**Audit-coverage rows (state-mutating actions, all with actor + required fields):**
org.department.created/updated/deleted/reordered, org.team.created/updated/deleted,
org.team.member_added/removed, org.queue.created/updated/deleted/deactivated/reordered,
org.queue.member_added/removed, org.queue.strategy_changed, org.queue.boosts_changed,
org.queue.tags_changed, routing.pull_in.created/ended (both SEC-03: the override row is the grant),
routing.manual_reassign (BR-03; **HIGH-VISIBILITY when the source queue is_rg — SEC-06**),
routing.broadcast_sent, routing.starvation_boosted (SEC-06 standard), routing.rg_routed (standard),
routing.rescue.auto_returned, routing.orphan_moved, routing.engine_error_parked (FS-04),
org.restructure.executed (wizard, per chunk under the operator's live permissions — SEC-04),
user_skills.updated, routing.capacity_override.set. Read-only routing decisions themselves are records,
not audit rows (they are INSERT-only rows in `routing_decisions`; `ticket.routed` is the audit-feeding
event per FR-19).

**Notification categories:** `notify.routing.broadcast` (user layer; delivery [D16]),
`notify.routing.no_skill_match` (supervisor), `notify.routing.rescue_flagged` (supervisor),
`notify.routing.pull_in_recurring` (BR-07 hint, in-app). **Feature flags:** none the document names.

---

## §2 — Schema (SCHEMA AMENDMENT 5: outline in STOP-B; filed in full at step 2a, countersigned BEFORE the first migration; DB time only; `clock_timestamp()` on every ordering-relevant column; migrator-owned index DDL)

Grant physics per table: `departments/teams/team_members/queues/queue_members/user_skills/
user_routing_capacity` — app role INSERT/UPDATE on the named columns, **REVOKE DELETE on departments
and queues** (FR-18 "delete" = `deleted_at` tombstone + wizard-executed reference moves; N-19/BR-04 —
the wizard deletes references, never rows, so SS-03 "zero orphaned tickets" is physics), DELETE allowed on
membership rows (`team_members`, `queue_members` non-temporary; temporary rows END by `ended_at`, never
DELETE — EC-07's persistence is a row that still exists), `routing_decisions` INSERT-only,
`agent_assignment_stats` INSERT/UPDATE (rebuildable). FORCED RLS + `tenant_isolation` policy everywhere;
`RoutingGrantPhysicsTest` (the KbGrantPhysicsTest shape) sweeps them. Redis: ZSET `t:{tenant}:routing:q:
{queue}` on redis-durable (waiting sets are timers-class state, mirroring DB, rebuildable — FS-01),
RR pointers `t:{tenant}:routing:rr:{queue}` durable with a `queues.rr_pointer_user_id` DB checkpoint
(T-04), temporary-expiry ZSET `t:{tenant}:routing:expiry` durable (T-07, the TicketTimers pattern),
estimator counters `t:{tenant}:routing:est:{queue}` volatile with TTL (T-06).

---

## §3 — Steps (FIFTEEN; one commit each; safety tests BEFORE the features they constrain; every step-commit = DOC 11 SUITES + full battery + `make gates` + `make lint`, no pipes)

| # | Step | Safety-ordering justification |
|---|---|---|
| 0.3 | **R-D11-1 side-row (EARLY):** the wait-hardening packet as diagnosed in STOP-F → countersign → land → three consecutive first-attempt local full-browser greens → BS 0i ledger annotated | Unmasks Stage 4 for every later push |
| 1 | **The safety floor, before any routing line exists:** (i) `RoutingNeverWritesTicketsTest` (static; STOP-A.4) + the 13th gate green; (ii) **THE SCOPE-WIDENING TRANSITION PAIR** — `MembershipTransitionTest`: PRE-binding, with the real tables present and populated, `EmptyMembership` still yields `[]` for Team/Dept in `ScopeFixtureTest`, `ChannelScopeReproofTest`, `TransferEscalateTest` (fail-closed control); POST-binding (a test-only container rebind of `MembershipSource` → the real `OrgMembership`) EXACTLY the expected sets (direct ∪ via-team ∪ unexpired temporary, ∩ department access per A-50), a foreign row or an expired temporary anywhere = red at the named line, and the `ScopeFixtureTest` AC-03 suite re-proven against `OrgMembership` with real rows; (iii) **THE STARVATION PROPERTY TEST** `StarvationCapPropertyTest` (tests/Property): setting cannot be null/0/absent (validation physics + registry read throws), generated boost stacks never bury a ticket past cap+latency, the cap is exempt from every rule, EC-04's language-drop-at-cap holds and the record shows BOTH facts; (iv) `RgFinalityTest` fixtures (BR-02 table) and the `is_rg` partial-unique physics; (v) the structural **no-polling assertion** `NoPollingLoopTest` (static: no `sleep(`/`while (true)`/scheduler entry that scans queues under `app/Domain/Routing`; the only scheduled commands are the 1-min expiry sweep and the FS-01 consistency probe, pinned) | Every guard exists and is falsified before the code it constrains |
| 2a | SCHEMA AMENDMENT 5 filed in full (§2 + STOP-A/B/C/D resolutions as ruled) → **MANDATORY STOP** for countersign | B1 split: nothing touches the DB before the countersign |
| 2b | Migrations + `RoutingGrantPhysicsTest` + `TicketGrantPhysicsTest` split update + the Doc 06 `tickets.queue_id` packet + the Doc 05 routing-tag packet (as ruled) + the FR-19 catalog amendment (16 events, consumers declared) + `settings.php` routing.* + `audit-coverage.php` rows + `notifications.php` categories + traceability rows (10 ACs, honest initial statuses) + A-49…A-54 filed | Tables and registries before writers |
| 3 | **Structure + membership (FR-01, FR-02, FR-03, FR-04, BR-04, EC-10, EC-11, FS-04's parked state as data):** `Org/{Departments,Teams,Queues}` writers with validation-at-save (defaultless department refused; `is_rg` uniqueness; circular default refused), `OrgMembership implements MembershipSource` (direct ∪ team ∪ temporary ∩ department access) — **THE BINDING** in `AppServiceProvider` (Doc 03 core-edit packet, countersigned) landing WITH the step-1 transition pair flipping to its post state, `TicketScope`/`ListReadModel`/`ViewHash`/`CustomerScope`/`TransferTicket` re-proven (their own suites), FR-04 config-error computation + badge data, `OrganizationSeeder` + `ProvisioningStep::Organization` (Doc 01 packet as ruled in STOP-C), Doc 05 tag read seam | Membership before any eligibility |
| 4 | **Availability + capacity (FR-05, FR-06, EC-01, EC-03, AC-03, T-03's event side):** `agent_assignment_stats` maintained by durable consumers of `ticket.assigned`/`ticket.status_changed`/`ticket.transferred` (+ rebuild command); `RoutableAgents` (Online ∧ count < capacity ∧ member ∧ not config-error); `routing.capacity.count_pending`; per-user override; the Doc 06 `assignBySystem` packet with the FOR UPDATE commit-time check (STOP-A, countersigned) — `CapacityRaceTest` concurrent-load: ZERO over-capacity under N parallel dispatchers | Capacity physics before the engine that relies on it |
| 5 | **THE ENGINE + THE RECORD TOGETHER (FR-07, FR-08, FR-12, FR-17, BR-01, BR-02, BR-06, NFR-06, T-02, T-09, AC-01, AC-08's record half, SEC-02, SEC-06, §21 line 1):** `RoutingPipeline` steps 1–4 as table-driven fixtures (`fixtures/routing/fr08-order.json`: explicit > AI-accepted > connection tag > tenant default; rule tag > default queue; RG finality; boost order; starvation exemption), `RoutingDecisionRecord` written as the FIRST engine line (INSERT-only, `ticket.routed {record_id}`), effective-priority score (A-51) + the starvation head-promotion outside the score, `ticket.waiting {queue, position}`, FS-04 park-with-error (never lost, never looping, queryable), trigger consumers (created/transferred/unassigned/deactivated/reopened-with-inactive-assignee/EC-06 hand-back) all DURABLE with pins; SEC-02 elision at the read door (absence, not redaction) | Record with the first line, per §21 |
| 6 | **Strategies + dispatcher (FR-09, FR-10, FR-11, T-03, T-04, T-05, NFR-02, NFR-03, T-08, EC-02, EC-04, EC-12, FS-01, FS-02, AC-02, AC-04 dev-scale, AC-10):** RR pointer (Redis + DB checkpoint; splice on join/leave without fairness reset — `RoundRobinSpliceTest` sequences), load-based with the documented tiebreak (least open → least-recently-assigned → user id), skill matchers (declarative, ordered; language from Doc 04 conversation language, proficiency from the queue's department) + both fallbacks + `routing.no_skill_match` at threshold + EC-04's cap interaction; the **per-queue dispatcher** as pure event reaction (`agent.status_changed` first consumer, capacity-freed, membership-changed) — pop = read-then-commit, removal only on successful assignment (FS-02 drill: kill mid-pop, re-drive, exactly one assignment), race → next candidate; ZSET rebuild from DB (`routing:rebuild`, FS-01 drill); **the T-08 determinism harness** (generated org snapshots + ticket streams replayed twice → identical assignments) as a `tests/Property` suite inside DOC 11 SUITES and CI's routing package | Event-driven from the first dispatcher line; no polling ever exists to remove |
| 7 | **Temporary membership + pull-in + manual ops (FR-15, FR-03's temporary half, T-07, BR-03, BR-07, SEC-01, SEC-03, EC-07, FS-05, AC-05; Doc 03 FR-09's second half):** pull-in creates the Doc 03 override through `OverrideAdministration::add()` (audited; the router holds NO grant authority) + the `queue_members` temporary row linked by `override_id` (single source); expiry dual-path — 1-min ZSET sweep AND assignment-time resolver check, each proven with the other DISABLED; EC-07 persistence; BR-07 3×-in-a-week hint (nudge only); manual reassign (bypasses strategy, `manual_by`, SEC-01 re-checks both scopes, RG-source = HIGH-VISIBILITY audit); force status through Doc 07's door; broadcast → event only ([D16]); `rbac:expire-overrides` ends the temporary row when it tidies the override (Doc 03 core edit, ≤ 5 lines, packet) | Grants before any UI that can create them |
| 8 | **Estimator + hours (FR-13, FR-14, T-06, NFR-04, FS-03, SEC-05, EC-09, AC-09):** rolling counters + depth → banded mapper with hysteresis (A-52), degraded flag on corrupted counters, cached endpoint `GET /internal/routing/estimate/{queue}` (bands only — `EstimatorLeakageTest` asserts no count/name key exists in the customer-facing payload shape), Doc 09 consumption: `TemplateResolver` SEAM_FIXTURES `queue_wait` replaced by the live band (**zero Doc 09 PHP lines if the resolver's variable merge takes the value from a bound provider; else escalate**), `HandoffDecision`'s template path noted (two paths, one seam — filed); hours: fixture-shaped calendar contract [D12] → off-hours band + EC-09's no-cliff eligibility proof | Bands before any customer copy consumes them |
| 9 | **Rescue + reopen courtesy + orphan (FR-16 a/b/c, EC-06, EC-08, AC-06; Doc 07 FR-05 Rescue population contract):** heartbeat-lost timer (`routing.rescue.minutes`) → `rescue.flagged` + Rescue view rows (the Doc 07 `SystemViews::rescue` predicate widened to `channel_unreachable OR rescue_flagged` — Doc 07 core edit packet, both forms), `routing.rescue.auto` return with a system note (`persistSystemMarker`, N-03), unreachable = immediate, never auto-acted (Doc 06 BR-07); orphan path on `org.queue.deleted`; EC-08's hold via `TicketTimers`-pattern ZSET (A-54) | Rescue timers after the dispatcher they hand back to |
| 10 | **Restructure + wizards backend (FR-18, NFR-05, SEC-04, EC-06's ZSET migration, AC-07, SS-03):** reference inventory (rules [D12] fixture-shaped, bots' routing tags, SLA [D12], saved views — REAL, ticket counts — REAL), per-reference choices, typed confirmation computed from the SAME query, chunked execution (≤ 60 s at 10k, progress rows, operator's live permissions per chunk), RG special-case (replacement target REQUIRED), sibling-intake isolation measured in the drill (§12) | Wizard after every write it orchestrates exists |
| 11 | **Doc 07 slot-fill packets (countersigned):** (a) queue views become real (STOP-H) + fixture/seam DELETED + `inbox-views.spec` rewritten (0l: dedicated fixed-id rows, heal-by-reprojection); (b) the routing-record expandable in `TicketTimeline.vue` bound to C09–C11 (STOP-G; SEC-02 elision VISIBLE-absence proof); (c) Rescue view population (step 9's read half) | UI packets after their data contracts |
| 12 | **Structure admin UI (Doc 11 §8/§9/§13; FULL fidelity to B5-S09 J01–J15 + S10 §C/§D 375 frames; R-D11-3):** tokens re-extracted first (`tokens-hex-extract-script.php`, RESULT: PASS pasted); three tabs; departments reorderable (move buttons) with default-queue badges + hours-override REF ([D12], displayed as drawn J03); teams with role-context chips + the J04/J06 specific warning + "Give access"; queues grouped by department: strategy badges (icon+text), avatar stack, capacity, **temporary-member chips = `CountdownChip` dashed variant** (Doc 05 family), warning badges (FR-04 + rule-target orphans), detail: per-strategy panels (J07 rotation preview, J08 tiebreak note, J09 matcher builder + fallback + live hold state), boost drag list with move buttons + **the locked starvation row, pinned last, no controls, caption programmatically associated (§13)**, membership editor (direct/via-team provenance), routing-tag list, J14 no-eligible-agents banner with the breakdown + "Pull an agent in", J20 loading skeleton + edit-conflict error (Reload theirs / Keep mine) — every frame-mapping row filed | Backend-first honored (§21) |
| 13 | **Wizards UI (FR-18; B5-S10 J16–J21 incl. 375 executable per the ALREADY-FILED v3 deviation — cited, not re-filed) + supervisor data contracts (§8: queue-table rows, pull-in dialog contract, drag-reassign contract → C01–C08/C12–C14 SEAMS with fixtures for Doc 15/07):** steps 1–4 (inventory with per-system checks + retry + "continue with a partial list" J19; choices; typed confirmation J16; progress J16), J17 deactivate-first, J18 department delete with the RG target requirement (disabled-with-reason), a11y (stepped form, progress announced) | UI after the wizard backend |
| 14 | **§14/§13/i18n (R-14 join; en + am for every `routing.*` string, destructive tier flagged: delete/restructure confirmations, force-status, RG replacement choice; bands with culturally sane Amharic; record sentences from ICU-templated keys; real Ethiopic in browser fixtures with the 375 no-overflow leg; `RoutingI18nParityTest`; axe sweep both themes both languages) | Strings before the close |
| 15 | **Close-out (protocol step 6):** 90-id itemized self-audit, ledger item-by-item (§7), [D11] closing-set flips with fresh BS lines (§5), DESIGN-DEVIATIONS final (frame-mapping rows), inherited-row outcomes (§4), core-edit enumeration with countersign citations (§11), honest trail, R-14 totals with the destructive tier, §12 dev-scale figures with misses flagged, final battery + the CI look ONCE; SHA grounded by the owner BEFORE the recount | — |

**Checkpoint batches:** A = 0.3 + steps 1–2b · B = 3–6 · C = 7–10 · D = 11–15. Mandatory mid-batch stops
only (prompt §12's seven): 2a countersign; every core-edit packet (STOP-A `AssignTicket`/`PlaceInQueue`,
STOP-C provisioner + Doc 05 tags, the Doc 03 binding + `rbac:expire-overrides`, Doc 07 rescue predicate +
queue views + timeline expandable + the 0i packet, Doc 06 `ReopenConsumerDrillTest` pins, `ticket_list_items`
projector read); any enumeration amendment; a red battery; a design gap/conflict; the STOP-E owner ruling if
option 2 is chosen (its packets).

---

## §4 — Inherited rows (handoff v10 §4 / prompt §9), dispositions

| Row | Disposition |
|---|---|
| 0a — the 0i browser pair | DISCHARGE early per R-D11-1: STOP-F's diagnosis → countersign → step 0.3 → three first-attempt greens → BS 0i ledger annotated citing R-D11-1 + the fix SHA |
| 0b — key rotation before any production key | OWNER-ACTION, restated at every checkpoint |
| 0c — Addis AI DPA-or-switch | OWNER-ACTION before launch; not this build |
| 0d — [B8]/[B9] passes | DISCHARGED at step 0 (`32270d7`): named-debt rows landed per R-D11-2 |
| 1 — no credential gate; step 0 = rulings + [D11] set | DISCHARGED at step 0 (12/12 confirmed) |
| 2 — [OA] Doc 05 rows | CARRY untouched (a Doc 05 routing-tag packet, if ruled, is a NEW Doc 05 closing-row discharge, not an [OA] row) |
| 3 — risks 0a–0l | CARRY; 0l binds every browser fixture (dedicated fixed-id rows: tickets #4901–#4940 reserved for Doc 11 scenes, heal-by-reprojection in `RoutingBrowserSeeder`); 0k reds escalate-first; 0i triage law per red |
| 4 — R-14 queue | DISCHARGE per step-commit: every `routing.*` en+am string joins the queue; destructive tier flagged (delete/restructure wizards, force status, RG replacement choice) — totals at close |
| 5 — per-door `shouldRenderJsonWhen` | CARRY: every Doc 11 Inertia door follows it |
| 6 — bounded-deferral wording | CARRY: every PARTIAL names its bucket and unblocker |
| 7 — Doc 18/19 buckets | CARRY: NFR-01/02 p-values, §12 L2 legs → [D19]; FS-01/FS-04 alert wiring → [D18] |
| 8 — Doc 12/16 seams | CARRY: calendars/SLA/rules/tag-matching → [D12] with fixture-shaped contracts; broadcast/no-skill/rescue delivery → [D16] |
| 9 — owner hygiene | CARRY (rule 17/18 every checkpoint) |
| 10 — parked: Doc 01 QuotaTest order-dependence; partition-maintenance fold-in; Doc 07 channel-health ↔ AI-provider cross-link | CARRY untouched, stated |
| 11 — [OA] ADR 0019 | CARRY |

---

## §5 — The [D11] closing set (12 rows, enumerated at step 0 from BUILD-STATUS's own rows; advisor-confirmed 12/12; lines are as of `32270d7` and are RE-LOCATED BY TEXT at flip time)

| # | Row | BUILD-STATUS line @32270d7 | Closed/annotated by |
|---|---|---|---|
| 1 | Doc 09 FR-12 PARTIAL [D11] | 215 | step 8 (live `{{queue_wait}}` band) → FLIP |
| 2 | Doc 09 FR-13 PARTIAL [D11] | 216 | step 5 (RG finality, `is_rg` delivery from `customer.rg_flag.set`/`ai.handoff.created`) → FLIP |
| 3 | Doc 08 FR-15 PARTIAL [D11]/[D12] | 832 | step 5 → the Doc 11 HALF flips; [D12]/[D14] halves stay, worded |
| 4 | Doc 03 FR-03 PARTIAL | 4873 | step 3 (real membership re-proof) → FLIP |
| 5 | Doc 03 T-03 PARTIAL | 4905 | step 3 (binding) → FLIP |
| 6 | Doc 03 AC-03 PARTIAL + top-table row 11 annotation | 4935 + 11 | step 3 (`ScopeFixtureTest` against `OrgMembership`) → FLIP + annotate |
| 7 | Doc 03 FR-09 DONE (pull-in grants seam) | 4879 | step 7 → ANNOTATE |
| 8 | Doc 03 EC-04 DONE (warning state seam) | 4919 | step 12 (FR-04 badges drawn) → ANNOTATE |
| 9 | Doc 07 FR-05 DONE (queue-view seam) | 1603 | step 11a → ANNOTATE (seam deleted) |
| 10 | Doc 07 FR-28 DONE (`agent.status_changed` seam) | 1626 | step 6 (first durable consumer) → ANNOTATE |
| 11 | Doc 06 FR-22 DONE (fail-closed transfer scopes) | 4068 | step 3 (`TransferEscalateTest` re-proven real) → ANNOTATE |
| 12 | Doc 07 Unassigned×Own pin (five filed locations) | 36–41 (S11 step-0 honest trail) | STOP-E OWNER RULED option 1 (KEEP) → ANSWERS entry "revisit CLOSED" + handoff revisit line annotated resolved (step 2b) |

---

## §6 — Pre-agreed closure buckets (the ONLY lawful PARTIAL homes)

| Bucket | Holds | Unblocker |
|---|---|---|
| [D12] | FR-01 hours-override REF (column stored, never read); FR-14 calendar consumption + pull-in shift-end default (fixture-shaped hours contract, A-53); FR-12 SLA-at-risk boost INPUT (fixture flag); FR-07 automation-route trigger; FR-08 step-2 rule-driven tag matching (the tag MATCH exists; the RULE that sets it is Doc 12's); FR-18's rule/SLA inventory sources; FR-11's "SLA-aware" alert threshold input | Doc 12 builds calendars/SLA/rules |
| [D15] | §8 queue-table/dashboard RENDERING (data contracts shipped); team reports; monitor/whisper surfaces; NFR-06's weekly max-wait REPORT (the assertion function ships; the weekly report surface is Doc 15's) | Doc 15's session |
| [D13] | Widget consumption of the estimator bands (FR-13's second consumer) | Doc 13's session |
| [D19] | NFR-01 ≤ 50 ms p99 @ 200 agents, NFR-02 ≤ 2 s p95, AC-04 under L2, §12 50 queues/500 agents/200-burst legs, ≥ 100 assignments/s — dev-scale indicative figures taken on wall power regardless | Doc 19's load environment |
| [D16]/[D18] | Broadcast-note DELIVERY, no_skill_match/rescue NOTIFICATION delivery (events emitted here); FS-01/FS-04 alert wiring | Their sessions |

PARTIAL outside a named bucket does not exist.

---

## §7 — Sabotage enumeration (FOURTEEN; floor ten; grown only by filed amendment; captures FIRST; fail AND restored-pass kept; positive control per assertion; an invalid green filed under its honest name and re-executed hardened)

1. **Scope-widening falsification** — `OrgMembership::departmentIdsFor` poisoned to return one foreign department id → `MembershipTransitionTest` red at the named "exactly the expected set" line AND `ScopeFixtureTest` red (step 3). *(prompt-mandated)*
2. **Starvation-cap falsification** — (a) `routing.starvation.minutes` validation loosened to `nullable` → `StarvationCapPropertyTest` physics red; (b) the head-promotion folded INTO the score → the boost-stack property red (step 1/5). *(prompt-mandated)*
3. RG demotion — a boost rule allowed to move an `is_rg`-routed ticket to another queue → `RgFinalityTest` BR-02 fixtures red (step 5).
4. Commit-time capacity check dropped (the FOR UPDATE count check removed from `assignBySystem`) → `CapacityRaceTest` over-capacity red (step 4).
5. Routing writes a ticket row directly (`DB::table('tickets')->update` planted under `app/Domain/Routing`) → `RoutingNeverWritesTicketsTest` red; the 13th gate stays green BY DESIGN (status untouched) — the two-layer demonstration (step 1/5).
6. A trigger path skips the record (the transfer trigger assigns without `RoutingDecisionRecord::open`) → AC-08 completeness fixture red (step 5).
7. A polling loop introduced (a scheduled `routing:scan` command every minute) → `NoPollingLoopTest` red (step 6).
8. Temporary-expiry: (a) sweep disabled → assignment-time check still refuses (GREEN by design — the independence proof's first direction); (b) resolver check removed with sweep disabled → AC-05 red; (c) sweep removed with resolver check present → cleanup lag assertion red (step 7). Both directions captured.
9. Estimator payload carries `eligible_agents: 3` → `EstimatorLeakageTest` SEC-05 red (step 8).
10. RR pointer reset on member join → `RoundRobinSpliceTest` T-04 fairness red (step 6).
11. ZSET pop removes BEFORE the commit → FS-02 kill-mid-pop drill red (ticket lost) (step 6).
12. Record elision dropped (agent names rendered for a viewer without `reports.agents.individual`) → SEC-02 absence proof red (step 5/11b).
13. Determinism broken (tiebreak by `array_rand`) → T-08 harness red on the second replay (step 6).
14. Wizard confirmation text computed from a DIFFERENT query than the one that acts (count read from the stale inventory) → AC-07 "same query" assertion red (step 10).

---

## §8 — "DOC 11 SUITES" (standing rule 14; defined now; files later steps create are named now)

```
./vendor/bin/pest tests/Feature/Routing \
                  tests/Feature/Org \
                  tests/Property/StarvationCapPropertyTest.php \
                  tests/Property/RoutingDeterminismHarnessTest.php \
                  tests/Security/RoutingNeverWritesTicketsTest.php \
                  tests/Security/MembershipTransitionTest.php \
                  tests/Security/EstimatorLeakageTest.php \
                  tests/Security/RoutingRecordElisionTest.php \
                  tests/Security/NoPollingLoopTest.php \
                  tests/Isolation/RoutingGrantPhysicsTest.php \
                  tests/Contract/RoutingEventConsumerContractsTest.php \
                  tests/Contract/TicketEventConsumerContractsTest.php \
                  tests/Feature/Rbac/ScopeFixtureTest.php \
                  tests/Feature/Tickets/ChannelScopeReproofTest.php \
                  tests/Feature/Tickets/TransferEscalateTest.php \
                  tests/Feature/Tickets/StatusWriterEncapsulationTest.php \
                  tests/Feature/Tickets/ReopenConsumerDrillTest.php \
                  tests/Feature/Inbox/PerViewerCountsTest.php \
                  tests/Security/InboxSecurityTest.php
```
Run in-container (`$(COMPOSE) exec -T app …`, the S10 environment law). Every step-commit: DOC 11 SUITES +
full battery + `make gates` + `make lint` (bare, `&&` chains) regardless of BAT1 (this machine reports no
BAT1 node — wall power is stated per run from `/sys/class/power_supply`, "timing not taken" said plainly
when it cannot be evidenced). CI once per checkpoint. `octane:reload` before every browser run. Never Pest
beside gates; `/proc` swept before relaunch.

---

## §9 — Plan table of record (ONE row per id: id → file(s) → proving test; paths under `app/Domain/Routing/` or `app/Domain/Org/` unless noted; tests under `tests/`)

| Id | File(s) | Proving test |
|---|---|---|
| FR-01 | Org/Departments/{Department,DepartmentWriter}.php; Foundation `ProvisioningStep::Organization` + `OrganizationSeeder` (STOP-C packet); RG-delete guard in Restructure/DepartmentDeleteWizard.php | Feature/Org/DepartmentsTest (fields, seeded set at provisioning, RG delete requires replacement target, hours-override REF stored [D12]) |
| FR-02 | Org/Teams/{Team,TeamWriter}.php; team_members | Feature/Org/TeamsTest (multi-team, spans departments, leads) |
| FR-03 | Org/Queues/{Queue,QueueWriter}.php; queue_members; routing_tags | Feature/Org/QueuesTest (one department, strategy CHECK, capacity default from setting, boosts list, direct/team/temporary members, `is_rg` ≤ 1 physics, tags) |
| FR-04 | Org/Membership/OrgMembership.php (binds `MembershipSource`); Org/Membership/ConfigErrors.php | Security/MembershipTransitionTest + Feature/Org/EffectiveMembersTest (∪∪ ∩ access; config error excluded + badged) |
| FR-05 | Routing/Eligibility/RoutableAgents.php (Doc 07 `AgentStatus` consumed) | Feature/Routing/EligibilityTest (Online only; away/offline/heartbeat-lost never) |
| FR-06 | Routing/Capacity/{OpenAssignedCount,CapacityResolver}.php; user_routing_capacity | Feature/Routing/CapacityTest (pending exclusion toggle; per-user override) |
| FR-07 | Jobs/Routing/{TicketCreatedRouter,TicketTransferredRouter,TicketUnassignedRouter,AssigneeDeactivatedRouter,TicketReopenedRouter}.php (durable) | Feature/Routing/TriggersTest (every trigger; automation trigger = [D12] fixture) |
| FR-08 | Routing/Pipeline/RoutingPipeline.php + Steps/{ResolveDepartment,ResolveQueue,PositionInQueue,AttemptAssignment}.php; fixtures/routing/fr08-order.json | Feature/Routing/DecisionOrderTest (AC-01 table-driven) |
| FR-09 | Routing/Strategies/RoundRobin.php + RrPointer.php | Feature/Routing/RoundRobinSpliceTest (T-04 sequences; skip at capacity) |
| FR-10 | Routing/Strategies/LoadBased.php | Feature/Routing/LoadBasedTest (tiebreak least-recent → user id) |
| FR-11 | Routing/Strategies/SkillBased.php + Skills/{Matcher,UserSkills}.php | Feature/Routing/SkillBasedTest (ordered matchers; nearest_skill vs wait; no_skill_match alert) |
| FR-12 | Routing/Priority/{BoostScorer,StarvationCap}.php | Property/StarvationCapPropertyTest + Feature/Routing/BoostsTest (each boost logged; SLA input [D12]) |
| FR-13 | Routing/Estimator/{Counters,BandMapper,EstimateEndpoint}.php | Feature/Routing/EstimatorTest (bands, hysteresis, 30 s cache) + Security/EstimatorLeakageTest |
| FR-14 | Routing/Hours/HoursContract.php (fixture-shaped, [D12]) | Feature/Routing/HoursTest (Online at 18:01 assigned; off-hours band) |
| FR-15 | Routing/Manual/{Reassign,PullIn,Broadcast}.php; Http/Controllers/Routing/SupervisorOpsController.php; force = Doc 07 `AgentStatus::force` consumed | Feature/Routing/SupervisorOpsTest (reassign attributed; pull-in → Doc 03 override + temporary row; broadcast event; SEC-01 both scopes) |
| FR-16 | Routing/Rescue/{HeartbeatRescue,UnreachableRescue,OrphanRescue}.php; Doc 07 rescue predicate packet | Feature/Routing/RescueTest (AC-06 three fixtures; auto-return per setting) |
| FR-17 | Routing/Record/{RoutingDecisionRecord,RecordReader}.php; routing_decisions; TicketTimeline expandable packet | Feature/Routing/DecisionRecordTest (completeness; replay = same outcome) + Security/RoutingRecordElisionTest |
| FR-18 | Org/Restructure/{Rename,DeactivateQueue,DeleteQueueWizard,DeleteDepartmentWizard,ReferenceInventory,ChunkedMover}.php | Feature/Org/RestructureTest (AC-07; Doc 07 EC-09 follows; RG special-case) |
| FR-19 | Support/Events/EventCatalog.php amendment (16 events); ConsumerRegistry entries; fixtures/routing/consumers/doc11-events.json | Contract/RoutingEventConsumerContractsTest + Contract/TicketEventConsumerContractsTest (doc11.json tightened) |
| NFR-01 | Routing/Pipeline (measured) | Performance/RoutingBudgetsTest dev-scale (p99 @ 200 agents indicative) → [D19] |
| NFR-02 | Routing/Dispatcher/QueueDispatcher.php | Performance/RoutingBudgetsTest reaction ≤ 2 s dev-scale → [D19]; Security/NoPollingLoopTest |
| NFR-03 | Routing/Determinism/{SnapshotGenerator,Replay}.php | Property/RoutingDeterminismHarnessTest (AC-10, in CI) |
| NFR-04 | Routing/Estimator/BandMapper.php (hysteresis) | Feature/Routing/EstimatorTest (no flapping fixture) |
| NFR-05 | Org/Restructure/ChunkedMover.php | Feature/Org/RestructureTest 10k chunked ≤ 60 s dev-scale + sibling-intake isolation drill |
| NFR-06 | Routing/Priority/StarvationCap.php + Routing/Reports/MaxWaitAssertion.php | Property/StarvationCapPropertyTest (absolute; weekly assertion function; report surface [D15]) |
| US-01 | FR-13 | EstimatorTest honest-band fixture |
| US-02 | FR-11 + FR-05 | SkillBasedTest Amharic-payments fixture; capacity stop |
| US-03 | FR-15 pull-in + T-07 | SupervisorOpsTest + TemporaryMembershipTest auto-leave |
| US-04 | FR-17 | DecisionRecordTest SS-02 shape + browser C09 expand |
| US-05 | FR-18 | RestructureTest inventory-first + Browser/org-wizards.spec |
| US-06 | FR-08 step 2 + BR-02 | RgFinalityTest |
| US-07 | FR-06 per-user override | CapacityTest trainee=2 |
| BR-01 | StarvationCap | StarvationCapPropertyTest (cap overrides every strategy/boost) |
| BR-02 | Routing/Rg/RgFinality.php | Feature/Routing/RgFinalityTest (no rule/boost/strategy/bulk diverts; individual reassign by RG-permitted staff audited HIGH) |
| BR-03 | Routing/Manual/Reassign.php (router never overrides manual) | SupervisorOpsTest manual-wins fixture |
| BR-04 | Org writers (unlimited, renameable, deletable-with-consequences) | DepartmentsTest/QueuesTest |
| BR-05 | RoutableAgents (never Away) + estimator honesty | EligibilityTest + EstimatorTest |
| BR-06 | RoutingDecisionRecord (every automatic decision) | DecisionRecordTest completeness |
| BR-07 | Routing/Manual/PullIn.php recurrence hint | TemporaryMembershipTest 3×-in-a-week hint = nudge, no conversion |
| T-01 | database/migrations/2026_08_31_*_doc11_*.php (amendment 5) | Isolation/RoutingGrantPhysicsTest |
| T-02 | RoutingPipeline + Doc 06 `AssignTicket::assignBySystem` / `PlaceInQueue` (STOP-A packet) | Security/RoutingNeverWritesTicketsTest + Tickets/StatusWriterEncapsulationTest green |
| T-03 | Routing/Dispatcher/QueueDispatcher.php + Jobs/Routing/AvailabilityConsumer.php (agent.status_changed first consumer) | Feature/Routing/DispatcherTest (pop → eligible? → assign → race → next) |
| T-04 | Routing/Strategies/RrPointer.php (Redis + DB checkpoint) | RoundRobinSpliceTest |
| T-05 | Routing/Skills/Matcher.php (declarative, ordered) | SkillBasedTest |
| T-06 | Routing/Estimator/* + cached endpoint | EstimatorTest + EstimatorLeakageTest |
| T-07 | Routing/Temporary/{ExpiryZset,ExpirySweep}.php + resolver time check in OrgMembership | Feature/Routing/TemporaryMembershipTest (each path alone) |
| T-08 | Routing/Determinism/* | Property/RoutingDeterminismHarnessTest |
| T-09 | fixtures/routing/fr08-order.json; fixtures/routing/contracts/{estimator-bands,rescue-population}.json | DecisionOrderTest + Contract/RoutingConsumerContractsTest |
| SEC-01 | Manual ops re-check (`TicketScope::visible` + `visibleOnceAssignedTo`; pull-in bounded to granter's scope) | SupervisorOpsTest scope matrix |
| SEC-02 | Record/RecordReader.php elision (absence) | Security/RoutingRecordElisionTest + browser C11 |
| SEC-03 | PullIn → `OverrideAdministration::add()`; no grant writer under Routing | Security/RoutingNeverWritesTicketsTest (extended: no `user_permission_overrides` write under Routing) |
| SEC-04 | Restructure per-chunk `JobAsUser` re-resolution; inventory names+counts only where scope lacks | RestructureTest SEC-04 fixture |
| SEC-05 | EstimateEndpoint payload shape | Security/EstimatorLeakageTest |
| SEC-06 | audit-coverage rows: starvation/RG standard; manual RG diversion HIGH-VISIBILITY | Feature/Routing/AuditRowsTest |
| EC-01 | Dispatcher race + commit-time capacity | CapacityRaceTest cross-queue |
| EC-02 | RrPointer skip + splice | RoundRobinSpliceTest away-then-return |
| EC-03 | QueueDispatcher next-candidate | DispatcherTest simultaneous pick |
| EC-04 | SkillBased wait fallback + cap interaction + alert + badge | SkillBasedTest + StarvationCapPropertyTest (both facts in the record) |
| EC-05 | Org/Queues boost config warning (VIP source = agent-editable tag) | QueuesTest warning + browser J10 |
| EC-06 | OrphanRescue + wizard ZSET migration | RescueTest orphan + RestructureTest |
| EC-07 | Temporary row `ended_at`; assignments persist | TemporaryMembershipTest persistence |
| EC-08 | Routing/Reopen/CourtesyHold.php (`routing.reopen.wait_for_previous`, A-54) | Feature/Routing/ReopenHoldTest (record shows the hold) |
| EC-09 | HoursContract never touches eligibility | HoursTest no-cliff |
| EC-10 | Org validation at save + orphan terminal net | DepartmentsTest defaultless refused + RescueTest orphan alert |
| EC-11 | tiny-tenant fixture | Feature/Routing/TinyOperatorTest (one dept, one queue, two agents) |
| EC-12 | mass deactivation drain | Feature/Routing/MassDeactivationTest (10 hand-backs drain; rescue catches stragglers) |
| FS-01 | Routing/State/RebuildFromTruth.php + `routing:rebuild`; alert [D18] | Feature/Routing/RebuildDrillTest (flush → rebuild == DB) |
| FS-02 | QueueDispatcher read-then-commit | DispatcherTest kill-mid-pop + re-drive exactly once |
| FS-03 | BandMapper degraded flag | EstimatorTest corrupted counters → depth-only + degraded |
| FS-04 | Pipeline exception → park in department default + error record + alert [D18] | Feature/Routing/EngineErrorParkTest (queryable state) |
| FS-05 | Sweep down → resolver check holds | TemporaryMembershipTest sweep-disabled |
| SS-01 | match-night fixture | Feature/Routing/MatchNightScenarioTest (depth ×10, pull-ins, bounded wait, records reconstruct) |
| SS-02 | record explains VIP wait | DecisionRecordTest SS-02 shape |
| SS-03 | reorg via wizard | RestructureTest split-Payments scenario (zero orphans) |
| SS-04 | harness green in CI | RoutingDeterminismHarnessTest in the CI routing package (six months → [D19]/time; the CI wiring is DONE) |
| AC-01 | fr08-order.json | DecisionOrderTest |
| AC-02 | strategies | RoundRobinSpliceTest + LoadBasedTest + SkillBasedTest |
| AC-03 | capacity | CapacityTest + CapacityRaceTest (zero over-capacity) |
| AC-04 | reaction latency | RoutingBudgetsTest dev-scale (≤ 2 s indicative) → PARTIAL [D19] |
| AC-05 | temporary membership | TemporaryMembershipTest (grant linkage; each expiry path alone; 3× hint) |
| AC-06 | rescue | RescueTest + Doc 07 rescue view population |
| AC-07 | wizards | RestructureTest (inventory complete; chunked; isolation measured; RG case) |
| AC-08 | records | DecisionRecordTest replay + browser C09/C11 rendering + RoutingRecordElisionTest |
| AC-09 | estimator | EstimatorTest + EstimatorLeakageTest |
| AC-10 | harness in CI | RoutingDeterminismHarnessTest + `.github/workflows` routing package step |
| R-01 | FR-17 records | DecisionRecordTest (antidote proven) |
| R-02 | StarvationCap | StarvationCapPropertyTest |
| R-03 | RebuildFromTruth | RebuildDrillTest; staging probes [D18] |
| R-04 | user_skills last-reviewed nudge | Feature/Org/UserSkillsTest (nudge data) |
| R-05 | wizard inventory-first | RestructureTest; staging rehearsal via config export [D16] |
| §8 | Pages/Org/{Departments,Teams,Queues,QueueDetail,DepartmentDetail}.vue; Wizards/*.vue; supervisor contracts (fixtures) | Browser/org-structure.spec + org-wizards.spec + axe |
| §9 | Components/Org/{StrategyBadge,TemporaryChip(CountdownChip variant),WarningBadge,StarvationRow,RecordSteps}.vue | Browser specs + reduced-motion leg |
| §12 | dev-scale figures per row | Performance/RoutingBudgetsTest + restructure drill; misses flagged |
| §13 | keyboard/SR proofs | Browser a11y legs (announced) |
| §14 | lang/en/routing.php + lang/am/routing.php; ICU record keys | Feature/Routing/RoutingI18nParityTest + Ethiopic 375 leg |

---

## §10 — Design binding (R-D11-3; mandate v10) — FRAME INVENTORY, ls-verified from the frozen files themselves

ls-verified at plan time (`32270d7`): `design/frozen` = **187 top-level entries + 1 subdirectory = 230 files recursive** (`find design/frozen -type f | wc -l` → 230; 165 of them `.dc.html`; 13 `B8-*`) — matches R-D11-3's reconciled 230 exactly; the top-level count is stated so nobody reads 187 as a shortfall. Bound files
and their frames (caption-enumerated; the files carry no `data-frame` ids — captions are the row labels):

**B5-S09-organization-v1** — §A `desk` light+dark 1440: J01/J05/J06/J13 (Queues tab shell: grouped queues,
Default queue badge, strategy badges, avatar stacks, `cap N`, temporary countdown, warnings, RG "Routing
final" note, Bonuses empty/degraded banner). §B `strat` light+dark 520px: J07 RR rotation preview; J08 load
tiebreak note; J09 skill matcher + fallback radio + live hold state; J10/J11 boosts drag list + locked
starvation row; J15 estimator bands. §C `dept` light+dark 520px: J02 departments reorderable; J03 department
detail incl. hours override + danger zone; J04/J06 teams + the specific warning + Give access/Remove; J12/J14
membership provenance + routing tags + no-eligible-agents banner; J20 loading + edit-conflict error.
**B5-S10-restructure-wizards-v1** — §A `wiz` light+dark 1440 (Amharic chrome): J16/J18 steps 1–4. §B `alt`
light+dark: J17 deactivate-first; J18 department delete with the RG requirement; J19 inventory failure. §C
`m1`/`m2` light+dark 375: queues list + empty state + queue detail; wizard steps 1+3 executable (the
ALREADY-FILED v3 deviation — `B5-S00-checklist-v3` lines 66-72/253/258, `B5-S11-closing-audit-v2` L10 —
cited, not re-filed). §D `m3`/`m4` light+dark 375: departments/detail/teams; boosts (move buttons; the locked
row has NO controls), RR, load, bands, deactivate-vs-delete, inventory-incomplete, failed save. Not drawn at
375 per S10's own delivery note: J18 and wizard steps 2/4 (reuse the drawn step chrome — frame-mapping rows).
**B5-S02-interventions-v2** — §D `record` light+dark: C09/C10/C11 → BUILT here (STOP-G). §A–C, §E, §F
(C01–C08, C12–C14, the 375 confirmation tiers) → SEAMS: data contracts + events only; frames bind Doc 15/07.

Drawn laws applied: legal next states ABSENT not greyed; identical controls at 375 (move buttons everywhere
drag exists); refusals inline; server-computed stamps (countdowns from `expires_at`); proofs assert VISIBILITY;
absence not redaction (C11); labeled degrade never silent empty (J14, J19, J20, FS-03/FS-04 states);
confirmation text from the SAME query that acts (J16 typed confirm). Tokens: `docs/evidence/S23-step11/
tokens-hex-extract-script.php` re-run in-container before step 12, `RESULT: PASS` pasted. Mandate §5's Doc 11
lines: any frame implying an RG override = class 1 (none found — J13/J18 draw finality); a configurable/absent
starvation cap = class 1 (none — J10/J11 draw it locked); a record summary without "why" = class 1 (none —
C09/C10 draw the steps). DESIGN-DEVIATIONS for S11: expected class-1 rows = NONE at plan time; frame-mapping
rows for every built surface at its step.

---

## §11 — Core-edit enumeration (escalate → advisor ruling → land; every packet countersigned BEFORE landing)

| # | Owner doc | File(s) | Change | Countersign at |
|---|---|---|---|---|
| 1 | Doc 07 | `tests/Browser/inbox-ticket-panel.spec.ts`, `inbox-mobile-interactions.spec.ts`, `tests/Browser/support/settle.ts` (new) | R-D11-1 wait hardening (STOP-F) | step 0.3 |
| 2 | Doc 06 | `app/Domain/Tickets/Assignment/AssignTicket.php` (+ `assignBySystem`), new `Placement/PlaceInQueue.php`, `Transitions/TransitionContext` (no change expected; if a system+user shape is needed → in the packet) | STOP-A | step 2a/4 |
| 3 | Doc 06 | `database/migrations/*create_tickets_table` successors: `queue_id`, `queued_at`, `routed_at`, FK NOT VALID; `TicketGrantPhysicsTest` split | amendment 5 | step 2a |
| 4 | Doc 01 | `ProvisioningStep.php`, `TenantProvisioner.php` (+1 case, +1 dispatch line), `ProvisioningStepsTest` pin | STOP-C.1 | step 2a/3 |
| 5 | Doc 05 | NONE — STOP-C.3 ruled fixture seam; a reopener annotation on Doc 05's FR-08 BUILD-STATUS row only | ruled | step 2b |
| 6 | Doc 03 | `app/Providers/AppServiceProvider.php:67-70` (binding), `TicketScope::columns()` docblock only (column unchanged under A-50), `RbacExpireOverrides` (+ end the temporary row), `ScopeFixtureTest` (re-proof rows ADDED, none removed) | STOP-D + step 7 | steps 3, 7 |
| 7 | Doc 06 | `ReopenConsumerDrillTest` pins (every new registry entry), `fixtures/tickets/consumers/doc11.json` tightening | routine line | each consumer commit |
| 8 | Doc 07 | `ListProjector`/`ticket_list_items.queue_id` read; `SystemViews::rescue` predicate; `WorkspaceController::queueViewsSeam` DELETED + `QueueViews`; `ViewsRail.vue` (real rows); `TicketTimeline.vue` expandable; `inbox-views.spec.ts:150` rewrite | STOP-G/H + step 9 | steps 9, 11 |
| 9 | Doc 09 | `TemplateResolver` SEAM_FIXTURES — target ZERO lines (provider-bound variable); if a line must change → packet | step 8 | step 8 |
| 10 | Doc 08 | none expected (RG facts read through `CustomerPayload`'s law) | — | — |
| 11 | Doc 03/07 | STRUCK — STOP-E ruled option 1 (the pin stands; docblocks + PerViewerCountsTest byte-identical) | owner ruling 2026-08-30 | — |

---

## §12 — STEP 0 record (grounded at `32270d7`)

Solo guard clean (one `claude`, own ancestor; no transports/daemons/orphans); integrity 6 steps / `Then STOP.`;
R-D11-1…4 at `72a8332`; S10-ACCEPTANCE + Doc 10 row at `052e3e4`; amendments 2/3/4 at ANSWERS 1514/1996/2161;
ADRs 0015–0019 present; catalog EXECUTED **114**; every Doc 11-routed key PRESENT; department door
`org.departments.manage` (risk high); [D11] closing set 12/12 confirmed; the pin's five filed locations ruled as
its record (honest-trail line landed); [B8]/[B9] named-debt annotations landed.
