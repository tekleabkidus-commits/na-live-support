# BUILD STATUS

Updated by Claude Code at the end of every session. The owner reads this file to know where the
project actually stands. A row is only `DONE` when its acceptance criteria have executed tests.

| Doc | Module | Status | Session | ACs proven | Notes |
|---|---|---|---|---|---|
| — | Repo bootstrap + CI gates | DONE (CI-proven) | S0 | smoke 6/6, gates 8/8, browser 2/2 | Laravel 13.23; 14-service stack healthy; 8 gates live (3 bootstrap-warn until first registry entry); 4 gates falsified+restored. **Proven by CI run [30393265519](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30393265519) on `2990d33` — Stages 1–6 all green** (Stage 3 integration 6 passed/33 assertions, Stage 4 browser 2 passed). Stage 5 is a placeholder until Doc 19 L1; Stage 7 (prod deploy stub) is `skipped` by design — manual approval, never yet executed. Re-confirmed on the ADR-0002 bind-mount commit: run [30492124123](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30492124123) on `af9562d`, Stages 1–6 green, Stage 7 skipped |
| 01 | Platform Foundation & Multitenancy | **BUILT — final audit below** | S1–S3 | 6/8 + AC-04 & AC-07 partial | Wrap-up (S3) added: EC-10 maintain+monitor (incl. per-partition RLS the S1 migration promised), BR-05 policy+store+suspended_at, GrantPhysicsTest (hand-run claims → executable assertions; caught 2 real defects). **Final 69-id audit: 37 DONE / 30 PARTIAL / 2 NOT DONE — see the S3 section.** Remaining ids block on other docs (AC-03→04/06, SEC-04→18, seams→03/10/12, L1→19) or the design-led console |
| 02 | Authentication, Sessions & Security Core | **COMPLETE — FR-09 + SEC-05 CLOSED in S19 with the real role resolver (TwoFactorRoleEnforcementTest; IpAllowlistTest + auth.ip.blocked). Final 85-id audit: 61 DONE / 22 PARTIAL / 2 NOT DONE — see the S18 section.** S18 also fixed a real WCAG 2.1.1 violation retries had been masking (devices-table scroll region unfocusable in the single-current-session state), made the a11y test deterministic, and hardened CI: `failOnFlakyTests` — a retried-and-passed browser run is now a red build | S4–S18 | **AC-01 covered (BOTH halves — S12 closed the reset half open since S4)**, AC-02 covered, **AC-03 covered (4 clauses, each falsified)**, AC-04 covered (both halves, falsified); **AC-05 covered (PARTIAL per ruling Q5 — literal tickets wording waits for Docs 03/06)**; **AC-06 covered (S14 — both layers, independence proven by removal)**; **AC-07 covered (S15 — all three clauses, spy-proven "nothing processed")**; **AC-08 covered (S16 — log-scrubber gate over all five sinks, per-sink/per-sentinel positive controls, sabotaged six ways)**; **AC-09 PARTIAL (S17 — every §8 state functional + axe-swept at 360 px in en/am; pixel baselines await the approved design screens; theming awaits Doc 16)** | S10 closed step 6 (AC-04 socket close ≤2 s, I-5 locale hop, devices + GeoIP stub per ADR 0006, FR-11 middleware wired, clear-throttle made real) — **CI-proven on `b9b407d`: run [30737987324](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30737987324), Stages 1–6 green.** S11 closed step 7 — **CI-proven on `c8181a0`: run [30740125006](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30740125006), Stages 1–6 green** — covering 2FA enrolment/challenge/recovery, FR-08 admin reset, EC-10, SEC-04's second boundary, and fixing a **pre-existing Octane defect**: `TenantHint` keyed its override by `spl_object_id`, which PHP reuses — an unrelated later request could inherit the previous request's tenant and die with an unexplained 419. Local: pest 261/2647 assertions, browser 14/14, gates 10/10, lint clean. S12 closed step 8 (password lifecycle: FR-15/16/17, SEC-02, T-09, FS-02, FR-14's password half, AC-01's reset half; ADR 0007 `mail` queue family; rulings R-5..R-8) — local: pest 303/3078, browser 17/17 ×2, gates 10/10, lint clean; **CI-proven on `6d063b8`: run [30755287680](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30755287680), Stages 1–6 green.** S13 closed step 9 (API tokens: FR-18/19/20, T-05 intersection + EffectivePermissions seam, BR-03/BR-04, FS-05, EC-06, SS-04; two real defects: middleware sorter inverted the declared bearer order — every bearer request 500'd; shouldUse('api') guard leak in the harness) — local: pest 336/3362, gates 10/10, lint clean; **CI-proven on `5218437`: run [30766536879](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30766536879), Stages 1–6 green** (first push failed Stage 3 on an untracked UserDisabler — committed, re-run green). S14 closed step 10 (widget visitor tokens: FR-21/FR-22, AC-06, BR-06, EC-08 PARTIAL per ruling, EC-09; **CI-proven on `330fdc8`: run [30771276124](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30771276124), Stages 1–6 green**; three defects found: framework-default CORS granting `api/* → *` over every staff response, the priority-list fix for the widget order silently re-sorting the STAFF stack and stripping T-07 headers off its error responses, and the staff broadcast channel 500-ing on a WidgetVisitor principal) — local: pest 366/3566, gates 10/10, lint clean. **Doc 18 handoff (owner ruling D-1): volatile-instance eviction policy must spare TTL'd visitor keys — see S14 report.** S15 closed step 11 (webhook auth: FR-23/24/25, AC-07; **CI-proven on `1908299`: run [30788851081](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30788851081), Stages 1–6 green**; filed interpretation A-1 in ANSWERS — replay cache fails CLOSED against FS-01's wording; TenantCache keymap now fails OPEN, closing a pre-existing volatile-outage 500 on every key-resolved surface) — local: pest 384/3703, gates 10/10, lint clean. S16 closed step 12 (SEC-08 both clauses + AC-08 + the C-13 secrets-never-serialize sweep: ScrubSecrets/AttachLogContext on every channel via SecurityLogTap, the fail-closed `log-scrubber` gate, 21+ seeded sentinels swept from logs/jobs/outbox/audit/relayed-events, model hidden-pin roster extended, sabotages (a)–(f) each red; one real find: Laravel's config-merge injects an untapped framework `monthly` channel — caught by the channel-pin test on its first run) — local: pest 394/3980, gates 11/11, lint clean; **CI-proven on `91e76c0`: run [30806932074](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30806932074), Stages 1–6 green on first push, `log-scrubber PASS` visible in the Stage 3 gate output.** S17 closed step 13 (the authentication screens: §8/§9/§13/§14, AC-09 → PARTIAL; login card + 2FA challenge step + enrolment wizard with the checkbox-OR-download gate + T-2 min toast + EC-02 re-login modal + invitation profile completion; interpretation A-2 filed; **two real defects found and fixed**: FR-11 expiry rendered a half-dead 200 instead of redirecting — the sorter hoists Authenticate above the lifetime middleware (S13's lesson replayed on the web stack), and the §13 sweep caught sub-4.5:1 contrast plus a hardcoded `html lang="en"` under an Amharic render). Remaining: FR-09 role enforcement (needs Doc 03); AC-09 pixel baselines (needs approved design screens) |
| 03 | RBAC, Roles, Permissions, Overrides | **BUILT — S19 close-out below. Final 75-id audit: 59 DONE / 15 PARTIAL / 1 NOT DONE (owner-recounted at acceptance).** Catalog 111 keys / 38 high seeded verbatim (rulings Q9+R-12); resolver + scopes + cache + seeder + THE ENFORCE FLIP live; Doc 02 FR-09 + SEC-05 CLOSED this session; four B6 screens shipped with first token encoding | S19 | 6/8 covered + AC-03 partial (Doc 11 re-proof bucket) + AC-08 partial (pixel half design-export bucket) | See the S19 section for the full audit, sabotage record, filed interpretations (R-9..R-14) and DESIGN-DEVIATIONS |
| 04 | Conversations & Messaging Core (fake adapter) | IN PROGRESS (S20 step 3 of 10) | S20 | AC-01 partial (fake adapter matrix), AC-04 partial (static half) | Step 1: adapter contract + DeliverablePayload physics + fake adapter + conformance suite (12th gate); rulings R-15..R-17, A-4/A-5 filed; traceability flipped (ADR 0004). Step 2 (`a6c68e0`): storage layer — 5 migrations (partitioned messages + per-partition RLS + REVOKE DELETE), repositories, composite (created_at,id) cursor (T-08's first consumer), messages half of partitions:check/maintain + FS-04 pager test; step-9 discovery recorded: partition DROP blocked by revisions FK, detach-time FK verification is RLS-policed — retention must purge revisions cross-tenant BEFORE detach (MessagePartitions docblock). Step 3 (`c1211c3`): inbound pipeline — webhook edge (quota-gated, never inline), claimant-aware SETNX dedupe, Doc 08 identity seam, one-transaction persist+outbox, FR-11 quarantine (audit-only, no retro-fan-out), BR-05 ACK-but-summarize PROVEN through the pipeline, EC-01 batch-tail debounce; dedupe + quarantine sabotaged red→green |
| 06 | Ticketing System | NOT STARTED | S5 | — | |
| 05 | Telegram Channel (Business + Direct) | NOT STARTED | S6 | — | needs staging bot + Business account on a dedicated phone |
| 07 | Unified Inbox & Agent Workspace | NOT STARTED | S7 | — | needs approved Claude Design screens |
| 08 | Customer Profiles & Identity | NOT STARTED | — | — | |
| 09 | AI System (Chatbot, Copilot, Supervisor) | NOT STARTED | — | — | needs AI provider staging keys |
| 10 | Knowledge Base | NOT STARTED | — | — | |
| 11 | Departments, Teams, Queues & Routing | NOT STARTED | — | — | |
| 12 | Automation, SLA, Forms & Custom Fields | NOT STARTED | — | — | |
| 13 | Web Chat Widget | NOT STARTED | — | — | Wave 2; needs support subdomain |
| 14 | SMS Notification Module | NOT STARTED | — | — | needs Ethiopian gateway sandbox |
| 15 | Reporting, Analytics & Dashboards | NOT STARTED | — | — | |
| 16 | White-Label, Settings & Feature Flags | NOT STARTED | — | — | |
| 17 | Audit Logging & Compliance | NOT STARTED | — | — | |
| 18 | Infrastructure & DevOps | CONTINUOUS | all | — | gates added per session |
| 19 | QA Strategy & Test Plans | CONTINUOUS | all | — | traceability grows per session |

## SESSION-03 (S19) launch incident note

SESSION-03 launch ran in concurrent sessions; `ba014d9` contains cross-verified
mixed-session Step 0 work, re-proven solo on 2026-08-04 (pest 437 passed / 4355 assertions,
gates 11/11, lint clean, alone in the stack with a single Claude process confirmed) — see
this note. All subsequent S19 work is single-session.

## SESSION-19 (Doc 03) close-out — RBAC

Steps 0–9 committed (`ba014d9`, `efd496b`, `ca5258c`, `a53a134`, `7303696`, `2e85609`,
`157dc21`, `ab0a7c5`, `2e59556`, `2539c18`, `5345e72` + this close-out). Rulings R-9..R-14
filed in ANSWERS-FROM-DOC-AUTHOR; Appendix B ratified and committed
(`docs/Doc03-AppendixB-role-templates.md`). Local at close: pest 513 passed / 7481
assertions (11 pre-existing skips), gates 11/11, browser 32/32, lint clean.

### Sabotage record (each pasted red → restored green in the session log)

catalog key renamed → snapshot red · registry risk value corrupted → gate red · role rung
hoisted above overrides → 2 AC-02 cells red · expiry filter removed → 4 EXPIRED cells red ·
scope default fell open → NFR-04 red · empty membership fell open → EC-04 + seam red ·
EC-07 edited-guard removed → red ONLY after the dangerous quadrant (edited content +
changed release) was added — the original test was masked by the identical-release
early-return, the stronger test is the keeper · staff pass-through restored → AC-01 sweep
red · last-admin guard deleted → 2 AC-05 clauses red · four-eyes distinct-rule removed →
red at the test AND at the DB CHECK (defense-in-depth demonstrated) · cache store killed →
verdicts byte-identical, metric fired (FS-01, with a positive control that the kill took).

### Defects found by this session's own tests (fixed in the flow)

1. CatalogBootCheck v1 read via pgsql_migrator, which FORCE RLS polices — the silent catch
   returned "healthy" while broken; the catch now logs, the read uses the platform
   connection. 2. The same check parked one idle BYPASSRLS connection PER Octane worker —
   43 workers exhausted postgres' 100 slots and killed a full test run; closed in a
   finally. 3. Disabled accounts counted as admin-pair holders — the disable path let the
   last real admin be disabled. 4. The drawn #9A9588 label gray fails §13's AA floor
   (~2.9:1) — remapped to the system's own compliant grays, filed below.

### DESIGN-DEVIATIONS (DESIGN-FIDELITY-MANDATE §3 — every one filed)

| File / frame | What differs | Class | Why |
|---|---|---|---|
| B6-S10 (approver header chip) | The drawn `admin.roles.approve` key does not exist; the surface is gated by ruling R-9's approval rights (admin.roles.manage / admin.overrides.manage) and the catalog stays closed at 111 | 3 (flagged to the design track) | Owner ruling R-9 at plan approval; documents outrank the drawn key (mandate §1) |
| B1-DS/B6 frames (10px uppercase labels, `#9A9588`) | `--ds-text-label` renders `#6F6A61` light / `#A6A296` dark (both from the system's own palette) | 3, with the DOCUMENT override applied now | Measures ≈2.9:1 on the white panel — below Doc 03 §13's AA floor; §13 is a numbered requirement and outranks the drawn value. Design track asked to re-issue the label gray. Owner-ratified at close-out acceptance: §13's AA floor governs; #6F6A61/#A6A296 stand until the design track re-issues the label gray |
| B6-S10 ("recent decisions" table) | Not built | — (not a deviation) | Drawn as an explicit OPTION frame; no spec id requires it. Owner ruling R-13 records the exclusion as the frame's own marking |
| B6-S07..S10 (topbar avatar initials) | Initials are server-supplied data (the signed-in user), not the drawn fixture's letters | 2 | The drawn initials are fixture content; a real page shows the real person. String-lint enforces the boundary |
| AC-08 pixel baselines | Not attached | — (pre-agreed bucket) | Design-export-blocked: baselines attach when the owner fires the Batch 6 export |

### Final 75-id self-audit (DONE = implemented + executed test in this session)

| Id | Status | Evidence / reason |
|---|---|---|
| FR-01 | DONE | Registry schema + PermissionCatalog; CatalogSnapshotTest 111/38/46 + i18n both locales |
| FR-02 | DONE | Role model/migration physics; RbacStorageTest (unique-per-tenant live names, one-role column) |
| FR-03 | PARTIAL | ScopeSets + AC-03 fixtures (exact sets, all four levels); REAL membership is Doc 11's re-proof bucket |
| FR-04 | DONE | PrecedenceMatrixTest incl. override-cannot-alter-scope; §19 scope column reserved and proven dead |
| FR-05 | DONE | Explicit decision sequence; all 10 AC-02 cells; EC-06 no-object-allow via matrix cells |
| FR-06 | PARTIAL | Middleware + UI map + jobs (SEC-06 through the real queue) DONE; exports/Reverb re-checks are mechanism-ready, consumers arrive Docs 04+ |
| FR-07 | DONE | Invalidator (both caches) + AC-04 browser ≤10 s, no re-login |
| FR-08 | DONE | (a) three guarded paths (b) four-eyes distinct admin + DB CHECK (c) delete-blocked with count — all in SafetyRailsTest, sabotaged |
| FR-09 | DONE | Resolver DB-time truth (EXPIRED cells) + rbac:expire-overrides tidy; queue pull-in grants are Doc 11's (pre-agreed) |
| FR-10 | DONE | RoleSeeder through REAL provisioning; Appendix B ratified (R-10); RoleSeederTest |
| FR-11 | DONE | DeniedCheckRecorder wired in RequirePermission; sweep asserts log+metric per deny |
| FR-12 | DONE | Nine rbac.* events, closed payloads, outbox-only; catalog regenerated; audit-coverage entries |
| NFR-01 | PARTIAL | Structure + indicative numbers (p50 0.176 ms vs 0.3 spec; cold 7.3 ms vs 20); formal closure = Doc 19 L1 (pre-agreed) |
| NFR-02 | DONE | Explicit invalidation both caches; TTL bounds pinned; AC-04 wall-clock proof |
| NFR-03 | DONE | T-05(b) snapshot + T-05(c) template drift; rename sabotaged red |
| NFR-04 | DONE | Fail-closed everywhere; both open-fall sabotages red |
| NFR-05 | PARTIAL | Matrix ships grouped+searchable+sticky at 111 rows and works in-browser; the 130+/≤1 s formal budget joins Doc 19 L1 |
| NFR-06 | DONE | permission-mapping gate blocking + AC-01 sweep |
| US-01 | PARTIAL | Editor + matrix + scopes shipped and browser-proven; the full create-journey timing claim (<5 min) is unmeasured |
| US-02 | DONE | Expiring override with audited reason through the API (RbacAdminBehaviorsTest) |
| US-03 | DONE | Team Leader template Dept-scoped per US-03's words (RoleSeederTest scope pins) |
| US-04 | DONE | QA read-only shape pinned; AC-08 contrast proves absent controls for non-holders |
| US-05 | PARTIAL | Events + per-tenant denied metric live now; the monthly REPORT is Docs 15/17/18's (pre-agreed) |
| US-06 | DONE | Blocking permission-mapping gate (unmapped route = red build) |
| US-07 | DONE | Impact modal (diff + affected count) + effective-permissions explorer, both browser-proven |
| BR-01 | DONE | Single column, no pivot — physics test |
| BR-02 | DONE | Threshold warning at the boundary (RbacAdminBehaviorsTest) |
| BR-03 | DONE | Closed catalog throws on unknown keys at every layer |
| BR-04 | DONE | strict_grant both modes tested |
| BR-05 | DONE | Read Only = the literal 18 (R-10); AC-06 sweep zero accepted writes |
| BR-06 | DONE | RG keys in NO template but Super Admin (seeder pin) |
| BR-07 | DONE | Service accounts take real roles at machine-token creation; intersection runs on resolved perms |
| T-01 | DONE | Storage shape + boot check (orphans logged+ignored, never enforced; missing = deny) |
| T-02 | DONE | Resolver + cap: cache + can()-shaped provider + middleware + payload + job path + deny shape |
| T-03 | PARTIAL | scopeSet once per module + MembershipSource seam; Doc 11 binds real sources (pre-agreed) |
| T-04 | DONE | PageCapabilities manifest — pages receive only their keys |
| T-05 | DONE | (a) gate+sweep (b) snapshot (c) drift — each falsified |
| T-06 | DONE | pending_grants mechanics + 24 h expiry + FS-04 |
| T-07 | DONE | Outbox-only (EventPublisher refuses outside transactions); no direct dispatch |
| SEC-01 | DONE | AC-01 under-privileged sweep of every existing route |
| SEC-02 | DONE | 403-naming-key + foreign-id 404 masking in one suite |
| SEC-03 | PARTIAL | Events + audit entries + metric live; the dedicated audit VIEW/notification are Docs 15/17/18's (pre-agreed) |
| SEC-04 | DONE | ≥10-char reason enforced + audited + NOT NULL |
| SEC-05 | DONE | Primitives-only cache entries (serializable_classes=false), server-side rebuild |
| SEC-06 | DONE | Real-queue demotion test |
| EC-01 | DONE | Delete-while-assigned blocked; aftermath structurally impossible |
| EC-02 | PARTIAL | Resolver-truth denial proven (EXPIRED cells + AC-07); the mid-action UI state naming the granter ships with the ticket surfaces (Docs 04/06) that have actions to interrupt |
| EC-03 | DONE | Version race through the API; loser gets the winner's state |
| EC-04 | DONE | Teamless empty-set + sabotage; editor warning data present in scope helper (drawn warning state arrives with Doc 11's membership reality) |
| EC-05 | PARTIAL | Object re-check mechanism (objectVisible reuses the list filter); the moved-out-of-scope UI state needs Docs 04/06 objects (pre-agreed bucket) |
| EC-06 | DONE | Map-level allow with filter-at-list, documented in ScopeSets |
| EC-07 | DONE | Never-overwrite-edited incl. the dangerous quadrant; deleted templates stay deleted |
| EC-08 | DONE | Enable-refused under two holders + positive control, on the settings write path |
| EC-09 | DONE | Every expiry comparison in SQL against now() |
| EC-10 | DONE | Soft-limit warning (platform dial per R-11.3) at the boundary + G20 banner |
| FS-01 | DONE | Store killed → byte-identical verdicts + metric + degrade loop |
| FS-02 | DONE | TTLs pinned (10 s / 20 s composed), invalidation-lost metric |
| FS-03 | DONE | Orphan logged+ignored; missing-in-DB denies; check failure logs instead of masking |
| FS-04 | DONE | Pending rows confer nothing (proven on a live member map); stuck rows stay visible+inert |
| SS-01 | PARTIAL | All the self-serve surfaces exist and are browser-proven piecewise; the full 40-person-org composite journey was not scripted |
| SS-02 | PARTIAL | Middleware/UI/job layers each proven for sampled permissions; the four-layers-per-permission×10 auditor drill is a demonstration script, not yet automated |
| SS-03 | DONE | AC-04 browser: open session loses/gains ≤10 s, zero errors |
| AC-01 | DONE | RouteCoverageSweepTest (see traceability) |
| AC-02 | DONE | PrecedenceMatrixTest |
| AC-03 | PARTIAL | Fixture membership; Doc 11 re-proof bucket (traceability row says so) |
| AC-04 | DONE | rbac.spec.ts |
| AC-05 | DONE | SafetyRailsTest |
| AC-06 | DONE | ReadOnlySweepTest |
| AC-07 | DONE | ExpireOverridesTest + matrix EXPIRED cells |
| AC-08 | PARTIAL | DOM half done; pixel half design-export-blocked (pre-agreed) |
| R-01 | DONE | Grouping/search shipped; sign-off gate = registry + doc-owner rule in the header |
| R-02 | PARTIAL | Badges/events/threshold live; monthly report Doc 15's (pre-agreed) |
| R-03 | DONE | One ScopeSets implementation; object check reuses the list filter |
| R-04 | DONE | Bypass keys always fresh — stale-vs-fresh contrast test |
| R-05 | NOT DONE | Default-off shipped and tested, but the in-product "enable at ≥3 admins" TIP is not built — small UI item, no drawn frame demanded it; carry to Doc 16's settings surface |

**Count: 59 DONE / 15 PARTIAL / 1 NOT DONE** (R-05's tip; EC-02/EC-05's ticket-surface UI
states are carried as PARTIAL in their own rows, re-proven with Docs 04/06).
*Corrected at close-out acceptance: the original summary read "61 / 12 / 2" — the owner's
independent recount of this same table caught the arithmetic error and a double-counted
"second NOT DONE" that duplicated EC-02/EC-05.*

### Amharic native-review queue additions (ruling R-14)

All machine-authored `lang/am/perm*.php` + `lang/am/rbac.php` strings join the standing
queue. **Destructive tier, flagged for priority review:** role delete confirmation
(`rbac.ui.roles.delete_confirm_*`, `confirm_delete`), delete-blocked
(`delete_blocked_*`), override deny effect + removal (`rbac.ui.overrides.effect_deny`,
`remove`), four-eyes reject (`rbac.ui.pending.reject`), last-admin block
(`rbac.blocked.last_admin`).

## Session-0 close-out notes

- `tests/Feature/ExampleTest.php` (Laravel scaffolding, `GET /`) was replaced by
  `tests/Feature/HealthEndpointTest.php` (`GET /up`): Stage 2 installs no Node and builds no
  assets, so the Inertia root view's `@vite` had no manifest and 500'd on every clean runner
  (CI run 30307818550) while passing locally. The full Inertia render stays proven by Stages 3–4.
- `tests/Integration/StackSmokeTest.php` lost **every** silent `localhost` fallback — Redis first,
  then Postgres and the app/Reverb HTTP checks. A host service on a published port (a native
  redis-server on 6379, an unrelated Postgres on 5432) would answer and be asserted against with
  the stack up and healthy. All five endpoints now resolve container DNS only through
  `stackEndpoint()`; the suite is container-only (`make test`) and fails with an explicit message.
- Dev source delivery changed (ADR 0002): `docker/compose.dev.yaml` bind-mounts the working tree
  into app/worker/scheduler/reverb, and `make test` depends on a new `make reload`. CI does not
  load the override. This fixes `make test` executing a *deleted* test from the baked image during
  this close-out.

## Session-1 report (Doc 01 — Platform Foundation & Multitenancy)

**Doc 01 is NOT complete.** Isolation — which Doc 01 §21 and the session prompt both
name as the deliverable — is built and proven on both layers independently. Roughly
half of the document's supporting cast is not built. This section is the honest
accounting; the session report has the full self-audit table.

### Proven by executed tests (pest: 63 passed, 3 skipped, 1271 assertions — full suite re-run 2026-07-31)

| Area | Evidence |
|---|---|
| AC-05 concurrent provisioning + EC-04/EC-05/FS-04 | `tests/Isolation/ConcurrentProvisioningTest.php` — 6 passed, 33 assertions. Two independent connections with overlapping transactions race on `unique (tenant_id, step)`; also sequential retry, double dispatch through the real job, FR-03f fail-before-handler, mid-flight suspension halt |
| T-05 job binding / FR-03f | `BindTenantContextToJob` + `TenantAware`, exercised through `ProvisionTenant` in the same suite — a job with no tenant fails BEFORE its handler runs |
| Two-layer isolation, layers proven INDEPENDENT | `tests/Isolation/TwoLayerIndependenceTest.php` — scope removed → RLS alone contains; RLS pointed at the wrong tenant → scope alone contains, with a control proving the DB really was permitting the other tenant |
| AC-01 cross-tenant read/write | `tests/Isolation/CrossTenantAccessTest.php` — generated from `information_schema`, so tables added later are covered automatically. 0 rows cross-tenant on all 10 tenant tables; forged writes refused by `WITH CHECK` on all 4 writable ones |
| AC-02 forged `tenant_id` fuzz | `tests/Isolation/ForgedTenantIdFuzzTest.php` — 500 requests, body/query/header, unique trace id each |
| EC-01 Octane session-variable leak | `tests/Isolation/OctaneConnectionLifecycleTest.php` |
| `app` role cannot disable RLS | ADR 0003's table — 6 tampering routes all refused |
| Published-port host listeners | `tests/Integration/StackSmokeTest.php`, falsified both ways |

### NOT built this session — no code, or code without a test

| Doc 01 id | State | Note |
|---|---|---|
| FR-07 / T-04 settings engine | **DONE** | 11 registry keys, five types, Redis hash + DB fallback, old→new audit, settings.read/write API. EC-06 uses a DB-side version counter, not `updated_at` — EC-08 forbids app-clock ordering and `timestamptz(0)` could not separate same-second writes. FS-01 proven against a real closed port |
| FR-08 / T-03 outbox RELAY | **PARTIAL** | Relay, `outbox:relay` command, lag metric and AC-04's four properties proven. The QUEUE-PUSH leg does not exist — Docs 04-17 own the consumers, so there is no queue leg to crash yet. The relay appends to `events`, which is what reporting replays from |
| AC-08 suspended matrix | **DONE** | 4 states x 5 surfaces as a data table, plus banner i18n key and per-key revocation on archive. Falsified by "tightening" suspension to reject inbound |
| FR-06 provisioning job | **DONE** | `TenantProvisioner` + `ProvisionTenant` job. Runs as the ordinary `app` role with NO RLS bypass (generate id → bind → write). Roles/SLA/business-hours/KB steps are recorded seams — Docs 03/12/10 own their contents, visible via `isSeamAwaitingItsModule()`. AC-05, EC-04, EC-05, FS-04 proven |
| NFR-06 / EC-09 / FS-05 quotas | **NOT DONE** | `tenant_quota_usage` table only |
| T-05 job binding | **DONE** | `TenantAware` trait + `BindTenantContextToJob` middleware, restored from their earlier deletion now that `ProvisionTenant` exercises them. Capture at dispatch (C-14 correlation id included), resolve CURRENT state before the handler, clear both layers in `finally`. `ProvisionTenant` itself declines the middleware — the tenant it binds does not exist yet; see the docblock |
| T-05 socket + console bindings | **NOT DONE** | HTTP binding built and proven; Reverb callback and Artisan `--tenant` are not |
| T-02 `tenant_sequences` | **PARTIAL** | Table and CHECK constraint exist; no allocator, no concurrency property test |
| T-08 cursor pagination | **NOT DONE** | `ErrorCode::InvalidPagination` reserved; no helper, no lint |
| T-09 cache convention helper | **NOT DONE** | Convention followed by hand in `TenantResolver` only |
| FR-11 feature-flag registry | **NOT DONE** | Pennant installed, unused |
| BR-05 30-day cold summary | **NOT DONE** | `TenantState` models the semantics; no retention path |
| NFR-07 / EC-10 partitions | **PARTIAL** | Monthly partitions +3 months created by migration, with a DEFAULT partition as a safety net; no maintenance command and no monitor |
| AC-03, AC-06, AC-07 | **NOT PROVEN** | See `registries/traceability.yaml`, which records each with its reason. AC-03 needs Doc 04/06's tickets and messages; AC-06 and AC-07 are buildable now and were not reached. (AC-05 is now proven — moved to the table above) |
| Doc 01 §8/§9 platform console | **DEFERRED** | API contract not built either; noted for a design-led session |
| Audit hash-chaining | **DEFERRED to Doc 17** | Append-only physics (`REVOKE UPDATE, DELETE`) is real now; the chain is Doc 17's |

### Inherited tasks other sessions must not miss

- **Doc 02** must re-register Fortify's routes. They were auto-registered and live —
  `/login`, `/register`, `/forgot-password`, 2FA, passkeys, 18 mutating routes with no
  audit coverage and no specification. `AppServiceProvider::deferAuthenticationRoutesToDoc02()`
  turns them off. Doc 02 removes that call and ships them with audit coverage and
  permission mapping.
- **Doc 02** inherits a constraint, not a bug: authenticating a user by email happens
  before any tenant is resolved, so it cannot go through `User`'s global scope. Same
  chicken-and-egg as `tenant_keys`; the answer is a narrow lookup, **not** removing the
  scope. See the note in `app/Models/User.php`.
- **Docs 05 / 13 / 14** each own one line in `ci/audit-exempt-routes.php`. The ingress
  probes there mutate nothing; the real handlers will, and the route names do not change,
  so the exemption would silently carry over. Each document deletes its own line.
- **Doc 18** owns the credential-separation follow-up in ADR 0003: all three database
  passwords currently live in one `.env`.
- **Doc 19** owns converting the six `not-implemented` traceability rows to automated
  tests.

### Standing pre-launch item

- **Every `am` string requires native-speaker review before any real operator sees it.**
  `lang/am/foundation.php` and `lang/am/ref.php` are machine-authored. AC-06 can prove
  zero key leakage and zero missing-translation telemetry; it cannot prove the Amharic is
  good. Tone, register and the vocabulary Ethiopian players expect are a native
  judgement. This does not expire when AC-06 goes green.

### Gaps in the tests themselves (not just in the code)

Three tests are skipped with their reasons in the file rather than deleted, and one class of
test is weaker than it looks:

- `EventPublisher` refusing to publish outside a transaction cannot be proven by the current
  harness, because every test runs inside one, so `DB::transactionLevel()` is never 0.
- The `ConnectionEstablished` re-application on reconnect needs a non-transactional harness:
  a `DB::purge` inside the test transaction discards the rolled-back fixture.
- `DeclareAuditAction`'s throw path needs `SettingsRepository` extracted to an interface
  before a stand-in can be substituted.
- **A test that reads the database with no tenant bound fails loudly, and a test that has a
  tenant bound from a fixture can pass for the wrong reason.** One AC-08 cell did: the staff
  surface resolved tenant state from leftover `app.tenant_id` rather than through the
  pre-context lookup a real request uses. Fixed by clearing BOTH layers before each request,
  and worth remembering as a review question for every new test — ambient session state is
  invisible in a green run.

### Risks carried forward

- **`RlsSession` is the most dangerous class in the project.** Set the session variable
  and fail to clear it and, under Octane, the next request on that connection inherits
  the previous tenant's visibility — while the Eloquent scope filters correctly, so
  nothing looks wrong. Three overlapping defences exist (scoped container binding,
  clear-on-terminate, clear-in-`finally`); the one skipped test in the suite is the
  reconnect case, which needs a non-transactional harness.
- **A latent recursion in the `ConnectionEstablished` hook was fixed but is worth
  remembering.** Issuing a statement from inside that hook makes booting the application
  require a live database, so `route:list` and `config:cache` hang when the database is
  unreachable. Invisible in every green test; visible only during an outage.
- **AC-01's endpoint half is only as strong as the endpoints that exist.** Doc 01 has
  five ingress probes and no CRUD. Every module that adds endpoints widens what AC-01
  must cover, and the schema-generated table half will not notice a missing endpoint.
- **No performance numbers are claimed.** AC-07 was not run. Nothing in this session
  measured the ≤1 ms resolution or ≤0.5 ms settings budgets, and the settings engine's
  ≤0.5 ms p99 read is therefore an unverified design intent rather than a measured fact.
- **`occurred_at` is Postgres transaction-start time, not insert time.** It is stamped by
  `DEFAULT now()`, and Postgres `now()` is the transaction timestamp — so a row written late
  in a long transaction carries an earlier stamp than the moment it was written. This is
  correct for causal ordering (and EC-08 wants DB time), but any future code treating
  `occurred_at` as "when this happened to the millisecond" will be wrong. It already produced
  a negative lag reading; the metric now clamps at zero.

## Session-2 report (Doc 01 — supporting cast: AC-06, AC-07, quotas, T-02/T-05/T-08/T-09, FR-11)

**Doc 01 is STILL not complete**, but the supporting cast is now largely built and proven.
One constitutional change: **registries/features.php is registry #7** (owner ruling, ADR 0005 —
the constitution changes by ruling and ADR, never silently).

### Proven by executed tests (full suite: 121 passed, 3 skipped, 1539 assertions; gates 10/10)

**CI-proven on `f5d8873`: run
[30610552926](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30610552926),
Stages 1–6 all green (Stage 3 = all 10 gates in-stack + the full 121-test suite; Stage 7 is the
by-design manual deploy stub).** Of note: this session's CI fixes mean the isolation suite,
Feature suite and migrated-main-DB smoke checks now genuinely execute in CI — the first pipeline
where that is true, so this run ID is the first end-to-end CI evidence for the whole Doc 01
test surface, not just S2's additions.

| Area | Evidence |
|---|---|
| T-09 cache conventions | `tests/Feature/Foundation/TenantCacheTest.php` — 9 passed. C-07 as signatures; the resolver's credential map is the one sanctioned pre-context exception, confined to `t:keymap:` |
| T-02 sequence allocator | `tests/Isolation/SequenceAllocatorTest.php` — 6 passed. Two-connection lock proof, rollback gap-freedom, structural no-SELECT assertion (catches the check-then-act rewrite the concurrency staging alone cannot — verified by mutation) |
| T-08 cursor pagination | `tests/Feature/Foundation/CursorPaginationTest.php` — 15 passed. Offset rejected BY NAME, opaque cursors, UUIDv7 order. First real consumer is Doc 04 |
| FR-11 flag registry (#7) | `tests/Feature/Foundation/FeatureRegistryTest.php` — 6 passed. Unregistered reads throw; tenant-scoped Pennant; N-19 migration grep; `registry:check` gains section 7, falsified with a malformed entry |
| T-05 sockets + console | `tests/Isolation/TenantBindingSurfacesTest.php` — 7 passed. Tenant from the USER record never the channel name; C-10 indistinguishable refusals; `--tenant` with no all-tenants mode; §9 Retry via `tenant:provision-retry` |
| NFR-06/EC-09/FS-05 quotas | `tests/Feature/Foundation/QuotaTest.php` — 7 passed. 429 naming the quota; refused attempts consume nothing; 80% alert once per window; flushed-counter repair from the ledger; FS-01 postures against a real closed port |
| AC-06 i18n | `tests/Feature/Foundation/I18nFallbackTest.php` — 6 passed, 127 assertions. Full-key sweep en↔am, zero leakage, zero telemetry; C-08 chain user→tenant→en falsified both ways |
| AC-07 (partial) | `tests/Performance/FoundationBudgetsTest.php` + `make perf`. Dev-hardware-indicative: resolution 0.75–0.82 ms (≤1.0), settings 0.17–0.24 ms (≤0.5), outbox 0.86–0.95 ms (≤5.0), cold-load <0.1 ms (≤50) — WITHIN. **Events insert 0.76–1.15 ms vs ≤1.0: MARGINAL, 1 of 5 strict runs breached — not engineered away.** Default runs assert a 5× regression ceiling only (p99 flakes on shared hardware; a flaky red main teaches everyone to ignore red) |

### NOT built in S2 — remaining Doc 01 debt

| Doc 01 id | State | Note |
|---|---|---|
| AC-03 worker-kill reconciliation | NOT DONE | still needs Doc 04/06 tickets+messages |
| BR-05 30-day cold summary | NOT DONE | retention path unbuilt |
| EC-10 partition maintenance | PARTIAL | +3-month partitions exist from S1; no maintenance command, no future-partition monitor |
| §8/§9 platform console | DEFERRED | design-led session; `tenant:provision-retry` is the minimal §9 Retry until then |
| EC-09 grace policy knob | DEFERRED | no shape specified in any doc; belongs with the platform console |
| AC-07 proper | PARTIAL | needs Doc 19 L1 profile + dashboards; §12's "+3 ms on authenticated GET" also needs Doc 02 |

### Inherited tasks added in S2 (other sessions must not miss)

- **Doc 16** owns the persistent Pennant store: the stock `features` table has no `tenant_id`
  (violates C-02, sits outside RLS), so Pennant is pinned to the `array` store
  (`config/pennant.php`) and toggles do not persist until Doc 16 ships a tenant-scoped driver.
- **Docs 04/05/09** wire `QuotaMeter::allow()` into their ingress/AI paths — the meter exists
  but nothing calls it yet; SEC-05's "before expensive work" is each caller's obligation.
- **Doc 04** must prove BR-05's **ACK-but-summarize** against this session's frozen contract
  (owner ruling): on every inbound message for a suspended tenant, ask
  `InboundAccrualPolicy::modeFor()`; `ColdSummary` → ACK the channel and call
  `ColdSummaryStore::record()` — the message body is never stored. BR-05 stays PARTIAL until
  that proof exists; the policy, store, and `suspended_at` stamping are proven now.
- **Doc 02/07** set the per-request user locale; the C-08 chain's first hop currently only
  fires when a caller passes a locale explicitly.
- **Doc 07** re-registers per-user notification channels under the `tenant.{tenantId}`
  namespace — the scaffolded `App.Models.User.{id}` channel was removed (it strict-compared a
  UUID with `(int) $id` and could never authorize).

## Session-3 report (Doc 01 wrap-up) — THE FINAL DOC 01 SELF-AUDIT

Evidence base: full suite **151 passed, 3 skipped (1624 assertions)**; `make gates` 10/10;
Pint/PHPStan(max)/ESLint/vue-tsc clean. **CI-proven on `c76bf7c`: run
[30613246335](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30613246335),
Stages 1–6 green, Stage 7 the by-design manual stub.** Every DONE row cites a test that executed. Two audit
findings were FIXED rather than defended: S1's "immutability physics test asserts the grant"
(N-13) referred to a hand-run ADR 0003 demonstration — no such test existed until
`GrantPhysicsTest` this session, which then immediately caught a BR-02 DELETE hole in the
brand-new `tenant_cold_summaries` table and an NFR-08 violation in scaffold `failed_jobs`.

**All 69 ids. DONE = executed test cited. PARTIAL = stated remainder. NOT DONE = not built.**

| Id | Status | Evidence / one-line reason |
|---|---|---|
| FR-01 | DONE | No tenant cap exists anywhere; multi-tenant behaviour proven across every Isolation suite |
| FR-02 | DONE | `CrossTenantAccessTest` (schema-generated) + non-null constraints in every migration |
| FR-03 | DONE | All six sources: a `SuspendedTenantMatrixTest`, b–e resolver+`ForgedTenantIdFuzzTest`, f `ConcurrentProvisioningTest`; rebind refused (`TenantScopeTest`) |
| FR-04 | DONE | `ForgedTenantIdFuzzTest` — 500 forgeries, zero effect; request input is never a source |
| FR-05 | DONE | `SuspendedTenantMatrixTest` — 4 states × 5 surfaces, banner key, key revocation on archive |
| FR-06 | PARTIAL | Job+idempotency+audit proven (`ConcurrentProvisioningTest`); roles/SLA/hours/KB are recorded seams owned by Docs 03/12/10 |
| FR-07 | DONE | `SettingsEngineTest` + `SettingsApiTest` — registry, five types, cache, audit old→new, read API |
| FR-08 | PARTIAL | Publish→outbox→events proven (`OutboxChaosTest`); queued SUBSCRIBERS don't exist until Docs 04–17 |
| FR-09 | DONE | `string-lint` gate (executed in `make gates`) + `I18nFallbackTest` full-key sweep |
| FR-10 | PARTIAL | ref_* seeded+versioned by migrations; no executed test asserts seed contents |
| FR-11 | PARTIAL | Registry #7 + tenant-scoped resolution proven (`FeatureRegistryTest`); persistent store is Doc 16's (Pennant array-pinned, C-02) |
| NFR-01 | DONE | `TwoLayerIndependenceTest` (each layer alone) + `RlsCoverageTest` (no partition ever bare) |
| NFR-02 | PARTIAL | p99 0.75–0.82 ms vs 1.0 budget, dev-hardware-indicative (`FoundationBudgetsTest`); L1 pending |
| NFR-03 | PARTIAL | Read p99 0.17–0.24 ms vs 0.5 dev-indicative; ≤2 s cross-node invalidation needs >1 node |
| NFR-04 | PARTIAL | Publish p99 0.86–0.95 ms vs 5.0 dev-indicative; L1 pending |
| NFR-05 | PARTIAL | Relay-crash half proven (`OutboxChaosTest`); worker-kill reconciliation is AC-03 (NOT DONE) |
| NFR-06 | PARTIAL | Full mechanism proven (`QuotaTest`: 429+name, 80% once, postures); zero ingress callers until Docs 04/05/09 |
| NFR-07 | PARTIAL | 13-month drop proven (`PartitionMaintenanceTest`); "aggregated forever" is Doc 15's half |
| NFR-08 | DONE | `GrantPhysicsTest` schema sweep: zero timestamp-without-tz columns (after fixing `failed_jobs`) |
| BR-01 | PARTIAL | Modeling rule; no violating surface exists, and nothing executable can assert an absence yet |
| BR-02 | DONE | `GrantPhysicsTest`: app role DENIED DELETE on all 10 tenant tables, by catalog fact |
| BR-03 | PARTIAL | ref_* changes only via migrations in practice; write-grant physics on ref_* not asserted |
| BR-04 | DONE | `CrossTenantAccessTest` + RLS: no cross-boundary read path; no export mechanism exists |
| BR-05 | PARTIAL | Policy+store+timestamps proven (`ColdSummaryRetentionTest`); ACK-to-channel clause awaits Doc 04 (owner ruling) |
| T-01 | DONE | `TenantScopeTest` + `TwoLayerIndependenceTest` + `OctaneConnectionLifecycleTest` |
| T-02 | DONE | `SequenceAllocatorTest` — lock proof, gap-free rollback, structural no-SELECT |
| T-03 | PARTIAL | Outbox+relay+idempotency proven (`OutboxChaosTest`); queue-push leg awaits first consumer |
| T-04 | DONE | `SettingsEngineTest` — registry, Redis hash, invalidation, encrypted casts |
| T-05 | DONE | All 4 bindings: HTTP (fuzz/matrix), jobs (`ConcurrentProvisioningTest`), sockets+console (`TenantBindingSurfacesTest`) |
| T-06 | PARTIAL | Table, partitions, UUIDv7 order proven; request→job→event correlation continuity has no dedicated assertion |
| T-07 | DONE | Problem-details shape asserted in `CursorPaginationTest` + `SettingsApiTest`; codes in shared enum |
| T-08 | DONE | `CursorPaginationTest` — 15 tests incl. offset rejected by name |
| T-09 | DONE | `TenantCacheTest` — prefix/TTL/invalidation-event by signature; one audited pre-context exception |
| T-10 | PARTIAL | Tables+migrations exist; same seed-content gap as FR-10 |
| SEC-01 | DONE | Isolation suite executes against RLS in local AND CI (same compose); prod env does not exist yet |
| SEC-02 | DONE | `TenantScopeTest` (refuses platform op while bound) + logged actor in every `PlatformConnection::run` |
| SEC-03 | DONE | `GrantPhysicsTest` hash-column denial + key issuance/revocation in `SuspendedTenantMatrixTest` |
| SEC-04 | NOT DONE | No vault exists; DB stores no third-party secrets yet either. Doc 18 owns it (ADR 0003 credential note stands) |
| SEC-05 | PARTIAL | Meter enforces check-before-work by construction (`QuotaTest`); real call sites arrive with Docs 04/05/09 |
| SEC-06 | DONE | Resolver 404-not-403 + metric (fuzz/matrix); C-10 indistinguishability also proven for sockets |
| SEC-07 | DONE | Event catalog fixes payload keys (ids only), enforced by the `event-catalog` gate every push |
| EC-01 | PARTIAL | 4/5 cases proven (`OctaneConnectionLifecycleTest`); the reconnect re-application case is the 1 honest skip |
| EC-02 | DONE | `GrantPhysicsTest` pins after_commit=true on durable queue; outbox half in `OutboxChaosTest` |
| EC-03 | PARTIAL | Next-request refusal + banner proven (matrix); session INVALIDATION mechanics are Doc 02's Fortify work |
| EC-04 | DONE | `ConcurrentProvisioningTest` — clean halt at step boundary, suspended stays suspended |
| EC-05 | DONE | `ConcurrentProvisioningTest` — unique-index idempotency, sequential + concurrent + via job |
| EC-06 | DONE | `SettingsEngineTest` — DB-side version counter, both writes audited, loser signalled |
| EC-07 | PARTIAL | Migration linter enforces expand-contract; seed-specific additivity has no dedicated check |
| EC-08 | DONE | DB-time/UUIDv7 ordering proven (`OutboxChaosTest` order, `SettingsEngineTest` version counter); quota buckets are a documented non-ordering exception |
| EC-09 | PARTIAL | Lower-below-usage proven destructive-action-free (`QuotaTest`); grace-policy knob deferred (no spec shape) |
| EC-10 | DONE | `PartitionMaintenanceTest` + `PartitionMonitorTest` — window rolls, monitor red on thin future OR non-empty DEFAULT, healed through maintain |
| FS-01 | PARTIAL | Settings fallback + quota postures proven against a real closed port; presence/poll degrade is Doc 07's |
| FS-02 | PARTIAL | No-loss/no-dup/resume proven (`OutboxChaosTest`); lag metric exists, ALERTING channel is Doc 18's |
| FS-03 | PARTIAL | Deploy-blocking isolation gate is LIVE in CI; emergency read-only FLAG registered (#7) but read-only enforcement unbuilt |
| FS-04 | DONE | `ConcurrentProvisioningTest` — failed step recorded with error_ref, never partially active, Retry via `tenant:provision-retry` |
| FS-05 | DONE | `QuotaTest` — flushed counter restored from ledger, drift recorded and logged |
| AC-01 | PARTIAL | SQL half complete+schema-generated; endpoint half grows with every module's endpoints (5 probes exist) |
| AC-02 | DONE | `ForgedTenantIdFuzzTest` — 500 requests, unique trace ids |
| AC-03 | NOT DONE | Needs Doc 04/06 tickets+messages for the reconcile counts; recorded in traceability with reason |
| AC-04 | PARTIAL | Rolled-back-never-published / crash-survival / exactly-once-in-effect proven; queue-push leg awaits consumers |
| AC-05 | DONE | `ConcurrentProvisioningTest` — two genuinely concurrent connections |
| AC-06 | DONE | `I18nFallbackTest` — full-key sweep both directions, zero leakage, zero telemetry (Amharic still needs native review — standing item) |
| AC-07 | PARTIAL | All measurable §12 rows dev-hardware-indicative, one (events insert) MARGINAL; AC-07 proper needs Doc 19 L1 + dashboards |
| AC-08 | DONE | `SuspendedTenantMatrixTest` — full matrix, falsified by the plausible wrong fix |
| R-01 | DONE | Mitigations built AND tested (`OctaneConnectionLifecycleTest`, scoped binding, clear-in-finally); reconnect case is EC-01's honest skip |
| R-02 | PARTIAL | Event catalog with fixed payload keys is live+gated; consumer contract tests await first consumer |
| R-03 | PARTIAL | Registry+gate live; owner sign-off is process; unused-key telemetry not built |
| R-04 | PARTIAL | 80% alert proven; usage APIs for operators not built (platform console / Doc 16) |
| R-05 | DONE | Partitioning confined to append-only `events`, accessed only via publisher/relay/`EventPartitions` — no ad-hoc Eloquent on partitions exists |

**Tally: 37 DONE · 30 PARTIAL · 2 NOT DONE (SEC-04 → Doc 18, AC-03 → Docs 04/06).**

Reading guide for the "done enough" decision: every PARTIAL falls in one of four buckets —
(1) blocked on a later document's artifacts (FR-06 seams, FR-08/T-03/AC-04 consumers, BR-05
ACK, EC-03 sessions, NFR-06/SEC-05 callers, FS-01/FS-02 infra, R-02 consumers, AC-03);
(2) blocked on Doc 19's L1 (NFR-02/03/04, AC-07); (3) needs the design-led platform console
(EC-09 grace, R-04 usage APIs, FS-03 read-only enforcement); (4) genuinely small residue
buildable today (FR-10/T-10 seed assertions, BR-03 ref grants, T-06 correlation continuity,
EC-07 seed additivity, R-03 telemetry — none is load-bearing for Docs 02–07 to proceed).


## Session-4 report (Doc 02 — Authentication, Sessions & Security Core: steps 1–4 of 14)

**Doc 02 is NOT complete and is not close.** Four of the fourteen approved steps landed.
What exists is the security *substrate* — transport, schema, pre-tenant identity resolution,
and the login path with its enumeration defence. What does not exist yet is most of the
document's user-visible surface: lockout, sessions and devices, 2FA, password lifecycle,
API tokens, widget tokens, webhook auth, and every screen.

**CI-proven on `6a4fae0`: run
[30658659162](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30658659162),
Stages 1–6 green, Stage 7 the by-design manual stub.** Local: 190 passed / 5 skipped
(2096 assertions), `make gates` 10/10, Pint + PHPStan(max) + ESLint + vue-tsc clean.

### Proven by executed tests

| Area | Evidence |
|---|---|
| SEC-06 transport | `StackSmokeTest` (verify_peer ON, SAN coverage, 30-day expiry margin, cleartext-serves-no-body, WSS handshake) + `HttpsRefusalTest` (426 in *testing* and under a simulated production env) + `transport.spec.ts` (browser: redirect, HSTS, CSP **enforced** — an unnonced inline script is refused) |
| T-07 headers | `SecurityHeadersTest` — full set on page, API and error responses; nonce rotates per request |
| T-02 / SEC-07 | `MiddlewareOrderTest` (order asserted from the real route table), `NoImpersonationTest` (routes, all seven registries, and the file tree swept) |
| I-1 route policy | `RouteRegistrationTest` — deferral gone, auto-registration permanently off, no `/register`, and in-suite mirrors of the permission-mapping and audit-coverage gates |
| Auth schema physics | `GrantPhysicsTest` + `RlsCoverageTest` + `CrossTenantAccessTest` pick up all five new tables automatically: RLS+FORCE, DELETE revoked, timestamptz. Plus the Q6 assertion that **no readable recovery-code column exists** |
| Pre-tenant doors (I-2, Q1, Sanctum ruling) | `PreTenantLookupTest` — each door answers with no tenant bound, direct reads still refused, no lasting cross-tenant visibility, exact-match only, no secret returned; both doors owned by NOLOGIN `key_resolver` |
| FR-01/FR-03a login | `LoginFlowTest` — signs in, binds the tenant discovered from the account, refuses a wrong password with problem-details |
| AC-01 (login half) | `EnumerationTest` — byte-identical bodies incl. the multi-tenant case; equalisation measured at the provider: **86.2 ms vs 0.8 ms** when removed, within ±50 ms when present |

### Three findings that falsification caught and review would not have

1. **The AC-01 suite was passing vacuously.** `/login` failures rendered as **302 redirects**,
   because Doc 01's handler only returns JSON for `api/*`. AC-01 compares two *failures*, and
   two broken responses are byte-identical — so the suite was green while the login route was
   wrong, and it *stayed* green when the timing equalisation was deliberately deleted. Fixed by
   proving the SUCCESS path first (`LoginFlowTest`) and giving refusals a real problem-details
   response (`LoginRefused`). **The general lesson: a safety test that compares two failures is
   only as trustworthy as a separate proof that success works.**
2. **The HTTP-level timing test could not detect an 89 ms hole.** With the defence removed,
   medians were 205.8 ms vs 205.7 ms — request overhead swamped the signal. Replaced with a
   provider-level measurement that fails at 86.2 vs 0.8 ms, plus a floor assertion so a future
   "optimisation" making *both* paths cheap also fails. The measurements are recorded in the test.
3. **Registering the rate limiter via the facade resolved the cache driver at boot**, which
   silently defeated Doc 01's FS-01 proof: `QuotaTest` could no longer simulate a Redis outage,
   because the cache repository was already built with a live connection. Deferred to
   `afterResolving`. A Doc 01 test went permanently green for a Doc 02 reason.

### NOT built in S4 (the remaining ten steps)

| Doc 02 area | State |
|---|---|
| FR-03 lockout state machine, AC-02, EC-11 | NOT STARTED — schema columns exist and are unused |
| FR-10–14 sessions, devices, AC-04 socket close, I-3 suspension-kills-sessions, I-5 locale | NOT STARTED — `devices` table exists and is unwritten; login records `device_id: ''` deliberately rather than faking one |
| FR-06–09 2FA, AC-03, EC-10, SEC-03 | NOT STARTED — hashed recovery-code store and 2FA columns exist, no flow |
| FR-15–17 password lifecycle, T-09 breach check, FS-02 mail | NOT STARTED — `auth_action_tokens`, history table and reset door exist, no flow. **AC-01's reset half is therefore unproven** |
| FR-18–20 tokens, T-05 intersection, AC-05, BR-03/BR-04, FS-05 | NOT STARTED — table + `resolve_api_token` door exist, no guard, no intersection engine |
| FR-21/22 widget tokens, AC-06, BR-06 | NOT STARTED — the `widget` guard is deliberately absent from config/auth.php rather than stubbed |
| FR-23–25 webhook auth, AC-07 | NOT STARTED |
| SEC-08 log-scrubber gate, AC-08 | NOT STARTED |
| §8/§9/§13 views, AC-09 | NOT STARTED — no auth screen exists; routes/auth.php carries only POST /login and /logout |

### Risks carried forward

- **The suite failed once in ~six full runs, then passed 3/3 on re-run, and I did not
  identify which test.** It is most likely the AC-01 provider timing measurement or the
  Doc 01 performance budgets (both median-based on shared hardware), but *likely* is not
  *known*. A flaky safety test teaches people to re-run red, which is how a real failure gets
  ignored — the next session should identify and stabilise it before adding more timing tests.
- **A known, deliberate residual in AC-01:** an address existing in N tenants costs N password
  verifications, so "this address is in more than one workspace" is timing-distinguishable to
  someone who already knows the address exists. Existence itself — what AC-01 specifies — is
  not. Closing it would mean padding every login to the worst case, a permanent tax on the
  shift-start spike (§12).
- **`RequirePermission` declares but does not enforce for staff sessions.** That is the agreed
  Doc 03 seam and it is documented in the class, but until Doc 03 lands, a route carrying
  `permission:settings.edit` is *mapped*, not *guarded*.
- **The dev TLS certificate is baked into the image and is public by construction.** It is
  named "NOT FOR PRODUCTION" for that reason; Doc 18 FR-12 owns real material.
- **Amharic auth copy is machine-authored** and joins the standing pre-launch native-review item.

### Inherited-task status (the six Doc 01 handed to Doc 02)

| # | Item | State |
|---|---|---|
| 1 | Remove `deferAuthenticationRoutesToDoc02` | **DONE** — replaced by permanent explicit registration; gates flipped blocking and are green |
| 2 | Pre-tenant email lookup the `resolve_tenant_key` way | **DONE** — `resolve_staff_login`, proven narrow in `PreTenantLookupTest` |
| 3 | Doc 01 EC-03: suspension invalidates LIVE sessions | **NOT DONE** — belongs to the sessions step; today suspension is refused at the next request only |
| 4 | Doc 02 owns the users-table shape | **DONE** — expand-only migration, UUIDv7/timestamptz conventions kept |
| 5 | Per-request user locale (C-08 first hop) | **NOT DONE** — `users.locale` exists and is read by the login door; nothing sets the request locale yet |
| 6 | `set_config` preamble + `REVOKE DELETE` on new tenant tables | **DONE** — all five new tables, verified by the schema-generated physics tests |



## Session-5 report (Doc 02 step 5 — lockout, plus the S4 flake follow-up)

**Evidence: 200 passed / 5 skipped (2159 assertions), `make gates` 10/10, lint clean.
CI-proven on `a77c33a`: run
[30661784756](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30661784756),
Stages 1–6 green.**

### The S4 flake: not reproduced, now self-diagnosing

Fifteen further full-suite runs — 6 quiet, 3 of the enumeration group under 8-core load,
3 full-suite under load, 3 with `--order-by=random` (which would surface inter-test state
leakage, the class BUILD-STATUS warns about) — all green. The flake did not recur and
**remains unidentified**.

Prime suspect, on reasoning rather than evidence: `FoundationBudgetsTest` is the only
default-run assertion whose outcome depends on machine load, and Doc 01 already records its
events-insert row as MARGINAL. Rather than guess further or weaken anything, its failure
messages now carry p50/p99/max, the asserted ceiling, the spec budget and which mode
produced it — verified by running `PERF_BUDGETS_ENFORCE=1`, which fires the message with
real numbers. The next occurrence will identify itself.

### AC-02 proven, and more than AC-02 asks

| Clause | Evidence |
|---|---|
| 1–4 generic, no lock | `LockoutTest` — counter increments, `locked_until` stays null, message identical to any other miss |
| 5th locks | proven; the CORRECT password is then refused, with no lock state or remaining time in the body |
| reset unlocks | EC-11's guaranteed victim path — the account works immediately afterwards |
| admin unlock audited | audited at the unit boundary (`unlockByAdmin` records the acting admin). **No route invokes it yet** — the admin surface is a later step, so this clause is not proven through HTTP |
| window rolls / lock expires | asserted at exact boundaries with an injected clock (CLAUDE.md §8) |
| per-IP throttle independent | 20 refusals then 429, and *not* before the budget, against addresses with no account — EC-11's attacker cap |
| multi-candidate (owner ruling) | one failed attempt locks EVERY account matching that address across tenants |

Falsified twice: the lockout trigger disabled (6 tests red, including brute-force and
EC-11), and counting limited to the first candidate (the multi-candidate test red). Both
restored; tree verified clean.

### Two defects this step exposed

1. **Audit writes for a failed login had no tenant bound.** A failed login binds no tenant
   by definition, but the audit row and outbox row live in the target account's tenant,
   behind RLS. The insert failed *and aborted the surrounding transaction*, so every later
   statement failed with "current transaction is aborted" and the real cause was invisible
   behind the cascade. Fixed by extracting `RunsWithinTenant` (restore in a `finally` —
   Doc 01 R-01's failure mode is exactly a helper that binds and forgets to unbind).
2. **Two test bugs of the same family, both of which produced false confidence.**
   `postJson()`'s third argument is HEADERS, so `REMOTE_ADDR` never set the client IP and
   the entire suite shared one throttle bucket — this had also been silently threatening
   the AC-01 timing suite, which makes 10+ requests. And Pest's `toContain` is *variadic
   over expected values*, so a "message" second argument was asserted as an extra element
   the array had to contain. Same trap as `toHaveKey` in S4. **In this framework custom
   messages belong to `toBeTrue`/`toBeFalse`, not to the collection matchers.**

### Still NOT built (nine of fourteen steps)

Sessions/devices (incl. inherited Doc 01 EC-03 and the locale hop), 2FA, password lifecycle
(so **AC-01's reset half stays unproven**), API tokens and the T-05 intersection, widget
tokens, webhook auth, the SEC-08 log-scrubber gate, and every screen.



## Session-6 report (Doc 02 step 6, partial — session storage; and a harness blocker)

**Evidence: 203 passed / 10 skipped (2170 assertions), `make gates` 10/10, lint clean.
CI-proven on `9d43fa0`: run
[30666654533](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30666654533),
Stages 1–6 green.**

**Step 6 is roughly half done and the second half is BLOCKED on a test-harness limitation,
not on design.** Read the blocker section before the next session plans this work.

### Built and proven

| Area | Evidence |
|---|---|
| T-04 tenant-prefixed session storage | `TenantPrefixedSessionHandler` — `t:{tenant}:sess:*` with a mandatory TTL (C-07). Guest sessions stay unprefixed and are abandoned, not migrated, at login |
| Q7 hint signature | `SessionFixationTest` — exhaustive over forgery shapes (unsigned, mutated signature, empty, separator-only) |
| Q7 hint issuance | login writes a hint naming the session's own tenant |
| Positive control | an authenticated request with a matching hint is served (so the middleware does not break the happy path) |

### Three defects found, all the same family: code that looked right, tests that could not see it

1. **`TenantHint` was stateful.** It memoised its resolution and held the request injected at
   construction, so after login every LATER request answered with the LOGIN tenant — the check
   compared a user against the tenant it had just come from and never discarded anything. This is
   Doc 01 R-01's exact shape (state outliving its request looks correct in every green run and
   serves the previous tenant under Octane). Now stateless: it reads the current request per call
   and caches nothing.
2. **`discard()` called `logout()`**, which cycles the remember-me token and therefore SAVES the
   user — with no tenant bound, so the cross-tenant guard refused it and the defence became a 500.
   Worse than the error: cycling a token on an **attacker-supplied cookie** is a denial of service
   — forge a hint, and a real user's remember-me dies on every device. Now `forgetUser()`, which
   touches no state.
3. **Two safety tests were worthless and are now skipped with their evidence.** The SEC-04 test
   stayed GREEN with `regenerate()` commented out; the hint tamper tests stayed green with the
   defence unreachable.

### THE BLOCKER (this is the important part for the next session)

**Laravel's HTTP test client does not replay `Set-Cookie`, so every simulated request starts a
fresh session.** Consequences, all discovered by falsification rather than reasoning:

- Session **fixation** cannot be observed: pre- and post-login ids differ because they are two
  unrelated sessions, whether or not anything regenerated.
- The tenant hint never reaches `$request->cookie()`, so `VerifyTenantHint` finds no hint to
  disagree with and the tamper tests cannot distinguish a working defence from an absent one.
- By extension, **everything remaining in step 6 has the same problem**: concurrent-session cap
  and oldest-eviction, idle/absolute timeouts, revocation, and the inherited Doc 01 EC-03
  suspension-kills-live-sessions all require a session that survives more than one request.

**The next session should build these against the BROWSER suite (Playwright, real cookie jar)
rather than the HTTP test client, and should treat any session assertion that passes without a
positive control as suspect.** This is now the third time in this document that a green safety
test turned out to be comparing two identical failures.

### Implemented but UNPROVEN (recorded in traceability, not counted)

- **SEC-04 boundary 1** — `regenerate()` is called at the password boundary; no test observes it.
- **SEC-04 boundary 2** (post-2FA) — the 2FA challenge does not exist yet (step 7).
- **Q7's authority check** — `VerifyTenantHint` compares a present hint against the session's user
  and discards on mismatch; it has never been observed rejecting anything.

### Not built (the rest of step 6, plus steps 7–14)

Session cap and eviction (FR-10), idle/absolute timeouts (FR-11), revocation + `session.revoked`
publish + Reverb socket close ≤ 2 s (T-04/AC-04), inherited Doc 01 EC-03 live-session
invalidation, the per-request locale hop (I-5), devices screen + T-03 fingerprint + T-08 GeoIP
(ADR 0006 unwritten), and steps 7–14 unchanged.



## Session-7 report (Doc 02 step 6 continued — the browser suite, and what it caught)

**Evidence: pest 203 passed / 10 skipped; browser 8 passed / 1 skipped; `make gates` 10/10;
lint clean. CI-proven on `723167e`: run
[30668681058](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30668681058).**

The S6 blocker is cleared: `tests/Browser/session.spec.ts` uses Playwright's request context
(a real cookie jar), and `BrowserFixtureSeeder` gives it accounts to log into. CI's browser
stage now migrates and seeds.

### THREE DEFECTS THE BROWSER FOUND THAT NO PHP TEST COULD

All three were invisible to the pest suite for the same reason: `actingAs()` injects the
principal directly and therefore never exercises the cookie path.

1. **`/api/staff/*` had no session middleware.** Laravel's `api` group is stateless, so a
   genuinely signed-in agent's cookie meant nothing on the staff API and every request 404'd —
   wearing the mask of Doc 01 SEC-06's deliberate 404-on-failed-resolution, exactly as
   `routes/api.php`'s own header warns.
2. **Rehydrating a session's user is the FIRST database read of an authenticated request**, and
   the tenant middleware cannot precede it (Doc 01 FR-03a resolves the tenant *from* the user).
   It threw `MissingTenantContext` → 500 on every session-authenticated request. The tenant hint
   now scopes that read, which is what the hint was for; `VerifyTenantHint` still independently
   confirms the loaded user belongs to it.
3. **Logout audited with no tenant bound → 500.** Same family as the failed-login audit fixed in
   S5; the logout route carries no tenant middleware because there is no tenant to resolve from
   a request that is *ending* a session.

### Proven, each with a positive control that fails if the plumbing breaks

A session survives across requests; a hint whose signature does not verify yields no session;
logout ends the session; and T-06 in passing — login without the CSRF token is 419.

### Still NOT proven, and why (both falsified, both kept honest)

- **SEC-04.** With `regenerate()` removed the BROWSER test also passed. Laravel re-encrypts the
  session cookie with a fresh IV per response, so cookie *values* differ even when the session id
  does not — a client-side comparison structurally cannot see regeneration. It needs a
  server-side assertion (old id's Redis key gone, new one present). Skipped, not counted.
- **`VerifyTenantHint`'s own comparison.** With the middleware disabled the Q7 test still passes,
  because the provider refuses an unverifiable hint first. Proving the middleware needs a validly
  signed hint for a *different* tenant, which the browser cannot forge without the app key.

### Still not built (the rest of step 6)

Concurrent-session cap and oldest eviction (FR-10), idle/absolute timeouts (FR-11), revocation +
`session.revoked` publish + Reverb socket close ≤ 2 s (T-04/AC-04), inherited Doc 01 EC-03
live-session invalidation, the per-request locale hop (I-5), and the devices screen with GeoIP
(ADR 0006 still unwritten).



## Session-8 report (ruling reconciliation + SEC-04 proven at last)

**Evidence: pest 204 passed / 10 skipped (2188 assertions); browser 10 passed / 0 skipped;
`make gates` 10/10; lint clean. CI green on `cc03fd1`, all stages.**

### Rulings filed (docs/ANSWERS-FROM-DOC-AUTHOR.md is new)

`docs/CONFLICTS.md`'s Known Gap is **CLOSED**: the 19-document series permanently supersedes the
never-supplied master design document, so no future session need caveat its absence. Binding
decisions that Docs 01–19 do not contain now have one sanctioned home.

- **R-1 definer surface: accepted as built** (4 doors, recorded deviation). **Ceiling is four** — a
  fifth needs an owner ruling AND an ADR.
- **R-2 `ambiguous_tenant`**: the >1-tenant-verifies refusal now logs its own reason instead of
  lying with `bad_credentials`. User-facing message unchanged; a test asserts the response leaks
  no "reset"/"workspace"/"tenant" hint.
- **R-3 timing across 0/1/N**: the provider now pads to a FIXED verification budget, closing the
  residual S4 recorded and accepted. NFR-01 sanctions the cost explicitly ("≤400 ms **excluding
  intentional equalization**").
- **R-4 recovery-code count from unconsumed rows**: filed; lands with the 2FA step that owns it.

### THE HEADLINE DEFECT: T-04's tenant prefix was inert

`SESSION_DRIVER` was still `redis` in `.env`, so `TenantPrefixedSessionHandler` was registered,
configured, documented — **and never in use**. Sessions were stored by the stock handler with no
tenant prefix at all. Found only because the browser suite went looking for `guest:sess:*` keys in
Redis and found none. A configured-but-unwired component is invisible to every test that does not
inspect the storage itself.

### SEC-04 proven on the third attempt

Attempts 1 and 2 compared session **cookie values** (PHP client, then browser). Both passed with
`regenerate()` deliberately removed, because Laravel re-encrypts the cookie with a fresh IV per
response. The observable that actually distinguishes them is in **storage**: after authentication
the pre-login key is GONE and a new one exists under the tenant prefix. Asserted through a
test-only `auth:session-inspect` command, and **falsified** by swapping login for a non-migrating
variant — it fails with "the planted id is still live".

Q7's middleware layer is now exercised too: a **validly signed hint naming a different tenant** is
refused (minting it needs the app key, hence the command).

### Two redundancies recorded rather than over-claimed — both found by sabotage

- `SessionGuard::login()` already migrates the session, so the controller's `regenerate()` is
  belt-and-braces. Kept, because otherwise SEC-04 rests on a framework internal; the code says so.
- `StaffUserProvider::retrieveById` scopes its lookup **by the hint**, so a wrong-tenant hint finds
  nothing even with `VerifyTenantHint` disabled. The middleware is defence in depth whose distinct
  value is discarding explicitly and logging why — not the sole barrier. Both files say so.

### A measurement lesson worth keeping

CI failed R-3's bound with medians 207.5 / 201.7 / 256.3 ms while the same code measured tight
locally. Scheduler noise is strictly **additive**, so the median drifts upward with load — by
different amounts per path. The fix was the estimator, not the tolerance: nine samples, each path
warmed, compared at the **floor**. Loosening ±50 ms would have hidden a real regression later.
Re-falsified with the new estimator (0.7 / 112.7 / 209.1 ms with padding off).

### Still not built (the rest of step 6)

Concurrent-session cap and oldest eviction (FR-10), idle/absolute timeouts (FR-11), revocation +
`session.revoked` publish + Reverb socket close ≤ 2 s (T-04/AC-04), inherited Doc 01 EC-03
live-session invalidation, the per-request locale hop (I-5), devices screen + GeoIP (ADR 0006).



## Session-9 report (Doc 02 step 6 — the session registry)

**Evidence: pest 212 passed / 12 skipped (2213 assertions); browser 11 passed; `make gates`
10/10; lint + vue-tsc clean. CI green on `5327189`, all stages.**

### Proven, each with a positive control and falsified before being counted

| Requirement | Evidence | Falsified by |
|---|---|---|
| **FR-10** cap + oldest-eviction | browser suite, asserted on session KEYS (not the index): 6th login evicts the 1st, the 6th survives, the evicted session no longer authenticates | reversing the eviction order → red |
| **FR-11** idle + absolute timeouts | injected clock at exact boundaries (479 vs 480 min; 71 vs 72 h), plus the case that justifies two clocks: a continuously-active session still dies at the absolute limit | — (boundary assertions are two-sided) |
| **T-04** revocation | session key deleted, index entry dropped | — |
| **Doc 01 EC-03** (inherited) | suspending a tenant destroys its LIVE sessions; another tenant's are untouched | making the sweep count without revoking → red |

### NOT proven, and skipped rather than left green

**AC-04's socket half.** Reverb answers `401 Authentication signature invalid` to
`terminate_connections`. Its `verifySignature` signs `$this->query` minus
`[auth_signature, body_md5, appId, appKey, channelName]` — which merges ROUTE parameters, and
the exact param set is not yet matched. Everything else in that test works and is kept: the TLS
websocket connects, Reverb confirms it, `pusher:signin` is sent, and the 2 s deadline is measured.
Only the termination call is unproven.

### Three bugs the tests caught, all about state rather than logic

1. **The index deleted itself during login.** Laravel writes the session at the END of the
   request, so the just-registered session had no key yet; reconciliation read that as "expired"
   and removed it, dropping the whole index. Silent: logins fine, cap never fired, index looked
   as though it had never been written.
2. **The cap test passed with the eviction order REVERSED**, because sessions accumulate across
   runs and the oldest vanished either way. It only became falsifiable once a `clear-tenant`
   reset gave it a known starting state.
3. **Shared state across browser workers.** All workers dial from one address, so they competed
   for a single per-IP login budget (CI: "login 5 failed"); and the cap test's reset wiped
   sessions belonging to whichever test ran beside it. The suite is now serial with a
   test-only limiter reset — the production control is untouched, and `LockoutTest` still
   proves it.

### A repeated lesson worth writing down

`vue-tsc` reported success locally twice while CI failed on real type errors, because the local
run was cached. **A cached type-check is not evidence.**

### Still not built in step 6

The per-request locale hop (I-5) and the devices screen with GeoIP (ADR 0006 unwritten).


## Session-10 report (Doc 02 step 6 CLOSED — socket proof, locale hop, devices + GeoIP)

**Evidence: pest 239 passed / 11 skipped (2411 assertions); browser 13 passed — three
consecutive full runs; `make gates` 10/10; Pint + PHPStan(max) + ESLint + vue-tsc clean
(vue-tsc uncached). CI-proven on `b9b407d`: run
[30737987324](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30737987324),
Stages 1–6 green (Stage 4 executed the 13-test browser suite including the new FR-12
tests and the real clear-throttle), Stage 7 the by-design manual stub.**

### AC-04's socket half: proven, and the S9 diagnosis was wrong

Reverb's 401 was never about route parameters. The installed
`Controller::verifySignature` parses the URI query only; the real mismatch was
**body_md5**: `Http::post()` with no data still sends the two-byte JSON body `[]`, Reverb
folds `md5(body)` into the signed params, and the revoker signed without it. Second S9
error: this Reverb version has **no `pusher:signin` at all** (grep the vendor tree) — the
test's signin frame was silently ignored, so Reverb never knew the socket belonged to the
user and correctly terminated nothing. The test now subscribes to a presence channel
whose `channel_data` carries the user id (with `user_info`, whose absence throws a
misleading 4200 deep in presence bookkeeping — diagnosed only under `reverb:start
--debug`). The revoker sends a genuinely empty body via `send()` and builds sign-string
and wire-string from ONE param array (raw `key=value` join, exactly as
`formatQueryParametersForVerification` does). **Falsified: signed string corrupted while
the wire string stayed correct → red at the 2 s deadline with the socket open; restored →
socket closes in ~0.3 s.** Bonus NFR-04 fix: the HTTP client does not throw on 401, so a
refused terminate was SILENT fail-open; non-2xx now logs
`auth.session.socket_close_failed`.

### I-5: the C-08 chain's first hop, per request

`ApplyUserLocale` (web group + staff API) makes `users.locale` the request locale;
proven end-to-end on localized problem-details bodies, with a positive control proving
the en/am sentinel strings differ (identical translations would green every assertion).
The Octane half — restore in `finally` — is proven by two users through ONE application
instance. **Falsified twice: hop disabled → 2 red; restore removed → leak test red.**
Malformed `users.locale` degrades to the chain with `i18n.invalid_user_locale` (it feeds
the translation FileLoader's path — shape-checked, never trusted).

### Devices screen + GeoIP (FR-04, FR-12, T-03, T-08, FS-04; ADR 0006 filed)

| Piece | Evidence |
|---|---|
| ADR 0006 stub `.mmdb` | `fixtures/geoip/stub-city.mmdb` (1 030 bytes, RFC 5737 ranges only, en+am names) from a checked-in generator; validated by the OFFICIAL reader — different authors, so green ≠ shared format misreading |
| T-08 resolver | in-process, fail-open with `auth.geoip.lookup_failed`; un-pins a broken handle so a replaced file heals without a deploy (`GeoIpTest`, 8 passed) |
| FS-04 | corrupt database → login still succeeds, row stores IP only, API states `location: null`, screen renders the i18n "location unavailable" copy (`DeviceRegistrationTest`, `SecuritySessionsApiTest`) |
| T-03 fingerprint | family+platform+accept-language+low-entropy hints; a browser UPGRADE is NOT a new device; label is data ("Chrome · Windows"), not copy (`DeviceRegistrationTest`, 6 passed) |
| FR-12 API | sessions as sha256 DIGESTS (a session id is the credential — raw ids never leave the server); revoke-one refuses the current session (422 — logout owns that); revoke-others audits ONCE even when empty (`SecuritySessionsApiTest`, 7 passed) |
| FR-12 end-to-end | browser: two real cookie jars, current badge, §8 current-first ordering, revoke-others kills the sibling and spares the caller; `/security` page served to staff, gated from guests (`session.spec.ts`, 13 passed) |
| Cross-user attack | a digest for ANOTHER user's session resolves against the caller's own index → 404, victim session untouched |

### THREE CONTROLS THAT EXISTED ONLY ON PAPER, found by wiring things up

1. **`EnforceSessionLifetime` was attached NOWHERE.** S9 proved the timeout POLICY with
   an injected clock; the middleware applying it to requests was dead code — FR-11 was
   not actually enforced on any surface, and FR-12's "last active" never refreshed. Wired
   into web + staff API (before tenant resolution, so expiry refuses like
   unauthenticated). **Falsified: unwiring it turns two tests red.**
2. **Wiring it immediately exposed a 500 on every expiry:** its `logout()` cycles the
   remember token → a user SAVE with no tenant bound. The exact S6 `discard()` bug,
   rediscovered the moment the code first ran. `forgetUser()` instead — which is also
   what FR-13 wants (remember-me lives to the absolute limit by its own cookie).
3. **`clear-throttle` had matched NOTHING since S9.** Named limiters store hits under
   `md5(name.key)` — the string "auth-ip" never appears in Redis, so the S9 "fix" was a
   placebo and the serial suite merely stayed under the 20/min budget until one more test
   tipped it. Now matches the real hashed keys; three consecutive full browser runs
   (≈39 logins inside a minute) pass where back-to-back runs previously 429'd.

Also fixed on the way: guests hitting an authenticated page 500'd (`route('login')`
does not exist until the views step) — `redirectGuestsTo('/')`, to be repointed at the
real login screen with FR-11's return-URL notice when the views step builds it.

### Deliberately NOT built in step 6, with reasons

- **T-03's new-device NOTICE and its toggleable setting** — there is no notification
  pipeline until Docs 07/14, and a setting nothing reads may not enter the registry.
  `devices.notified_at` exists and stays null; the notice step owns both.
- **FR-11's "session expired" notice + return-URL redirect UX** — the login SCREEN does
  not exist (views step, AC-09). What is true now: expiry de-authenticates before any
  handler runs.
- **Localized GeoIP names are stored at login in the user's locale-at-login** (the row's
  only reader is its owner). A user who changes language later sees the stored name until
  their next login from that device — recorded as accepted behaviour, not a bug.

### Standing notes

- The devices-screen Amharic copy is machine-authored — joins the native-review item.
- `lang/en/auth.php` gained the `devices` section; `registries/audit-coverage.php` gained
  `auth.session.revoked_others`; four self-service routes joined
  `ci/permission-exempt-routes.php` (Doc 03's catalog is closed and has no profile.* key
  — inventing one would violate BR-03).
- Traceability: Doc02 AC-04 is now `covered` with both falsifications recorded.

## Session-11 report (Doc 02 step 7 — 2FA enrolment, challenge, recovery)

**Evidence: pest 261 passed / 11 skipped (2647 assertions); browser 14 passed; `make gates`
10/10; Pint + PHPStan(max) + ESLint + vue-tsc clean. CI-proven on `c8181a0`: run
[30740125006](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30740125006),
Stages 1–6 green (Stage 4 executed the 14-test browser suite including the full 2FA journey),
Stage 7 the by-design manual stub.**

### AC-03 proven — all four clauses, each falsified before being counted

| Clause | Evidence | Falsified by |
|---|---|---|
| ±1 step accepted, ±2 rejected | `TwoFactorChallengeTest` against `TotpVerifier` with an **injected clock** at exact 30-second steps; codes generated by the same google2fa engine a real authenticator uses | widening `WINDOW` to 4 → red |
| 5-failure void | the fifth wrong code clears the pending state and feeds **one** failure to lockout | disabling the void branch → red |
| recovery code single-use | signs in once, reports 7 remaining, refused on replay (`used_at` stamped, row never deleted — BR-02) | removing the `used_at` stamp → red |
| EC-10 single-secret-wins | the superseded tab gets its **own** 409 copy ("rescan", not "retype"), the newest QR works | ignoring `enrolment_id` → red |
| SEC-04 second boundary / SS-02 | browser: password-half returns 401 and reads nothing; the pending session id is **gone from storage** after the code verifies | signing in on password alone → both the pest and browser tests red |

The browser suite runs the whole journey over a real cookie jar — enrol → 401 → challenge →
storage-level SEC-04 proof → recovery-code login — using a test-only `totp` affordance so the
codes come from the **server's** engine rather than a second implementation in the test.

### THE HEADLINE DEFECT: `TenantHint` keyed its override by `spl_object_id`

PHP **reuses object ids** after collection. Under Octane a later, unrelated request could be
handed the id of the request that set the login-time tenant override, so `current()` answered
with the **previous request's tenant** — that request read its session from `t:{tenant}:sess:`
instead of `guest:sess:`, found nothing, silently started a fresh session, and failed CSRF.
The symptom was a bare **419 "Page Expired" with no server-side error at all**.

This is Doc 01 R-01's exact shape, in the very class whose docblock warns about it — and it was
**pre-existing production code**, not 2FA code. The 2FA step surfaced it only because enrolment
made the collision reproducible. Now a `WeakReference`, which compares the object, so a recycled
id cannot match. `TenantHintOverrideTest` **forces** the collision (allocating until the freed id
is genuinely reused, after dropping the container's own reference — without that the collision
never happens and the test would be vacuous) and fails when `spl_object_id` is restored.

Diagnosis path worth keeping: the browser failure looked like a test bug, so it was reduced to
curl — enrol → next login 419, deterministic; the same 2FA state written **directly to the
database** → 401, correct. That difference is what identified request-scoped state rather than
data as the cause.

### Two design decisions the owner should know about

- **The password door now withholds `remember`.** `attempt()` is called with `false` and the
  remember cookie is issued only once authentication is *complete* — for a 2FA account the
  password is half an authentication, and a remember cookie minted at the halfway point is a
  credential handed to someone who has not finished proving who they are (FR-13 still says the
  cookie only extends lifetime and never skips the challenge).
- **`begin` is refused while 2FA is active.** Otherwise re-enrolment would be a code-free path to
  replacing the secret — i.e. an off-switch. The sanctioned exits both demand proof: `disable`
  (current code) or FR-08's admin reset.

### NFR-01b measured, and one number is MARGINAL

| Path | p50 | p99 | Budget | Verdict |
|---|---|---|---|---|
| TOTP verify | 0.015 ms | 0.064 ms | 150 ms | WITHIN by four orders of magnitude |
| Recovery-code verify (match, first row) | 106.6 ms | 147.8 ms | 150 ms | **WITHIN but MARGINAL** |

The recovery-code cost is argon2id's, tuned to ~100 ms by NFR-03 — the budget and the hash
tuning are in direct tension, and this is the honest reading rather than a re-tune to make the
number look better. **A recovery-code MISS walks the whole live batch (up to 8 hashes, ~850 ms)
and is deliberately not "optimised" by weakening the hash**; it is bounded by BATCH_SIZE and
gated upstream by the per-IP throttle. If NFR-01 must hold for misses too, that is an owner
decision about the hash cost, not something to fix quietly here.

### A stale skip resolved rather than left to rot

`SessionFixationTest`'s "regenerates again after the 2FA challenge" had been skipped since S6
with the reason "the 2FA challenge does not exist yet". It exists now, so the skip would have
become a lie by omission. The boundary **is** proven — in the browser suite, by the same
storage-level observable S8 established for boundary 1 — and the pest test is kept as a
signpost explaining why it cannot be asserted in a client that does not replay `Set-Cookie`.
`Doc02 SEC-04` moves from `partial` to `covered` in traceability, with both boundaries named.

### NOT built in step 7, with reasons

- **FR-09 (2FA enforcement by role) — NOT BUILT, deliberately.** "Users in
  `auth.2fa.required_roles` cannot reach any screen except enrolment" is unevaluable until Doc 03's
  resolver exists; the settings are registered and validated, and nothing speculative was written.
  Its grace-period countdown banner belongs with the same work.
- **The §8 enrolment WIZARD and AC-09 visual regression** — views step. The Security page gets a
  functional 2FA card (QR, manual secret, confirm, codes-once, regenerate, disable); the designed
  wizard with its "I have saved these" gate is the design-led session's.
- **EC-11's optional `auth.captcha.after_failures`** — belongs with the views/CAPTCHA decision.
- **Fortify's own 2FA actions stay unused.** Its storage is encrypted-retrievable with a
  re-display route, which contradicts SEC-03 and owner ruling Q6; the TOTP *engine*
  (`pragmarx/google2fa`, `window => 1`) is Fortify's and is what verifies. No new dependency.

### Risks carried forward

- **A `used_at`-stamped code and a superseded batch are different kinds of dead**, and only the
  live batch is consulted. If a future feature ever needs "was this code from an older batch",
  the data is there — but nothing reads it today, so a regression that consulted the wrong batch
  would only be caught by the two tests that assert it.
- **The challenge's attempt counter lives in the session.** An attacker who discards cookies
  resets *that* counter — by design; the durable controls are the lockout counter (which the void
  feeds) and the per-IP throttle. Worth stating plainly because "5 attempts" reads as stronger
  than it is on its own.
- **Amharic 2FA copy is machine-authored** — joins the standing native-review item.

## Session-12 report (Doc 02 step 8 — the password lifecycle)

**Evidence: pest 303 passed / 11 skipped (3078 assertions); browser 17 passed (twice
consecutively, after the fix below); `make gates` 10/10; Pint + PHPStan(max) + ESLint +
vue-tsc (uncached) clean. CI-proven on `6d063b8`: run
[30755287680](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30755287680),
Stages 1–6 green (Stage 4 executed the 17-test browser suite including the three real-mail
password journeys), Stage 7 the by-design manual stub.**

Step 8 closed: FR-15/16/17, SEC-02, T-09, FS-02, FR-14's password half, FR-03's
reset-unlock through the real flow, EC-03/EC-04, and **AC-01's reset half — unproven
since S4 — is now covered**, so Doc02 AC-01 moves to `covered` in traceability.

### Rulings filed at plan approval (ANSWERS-FROM-DOC-AUTHOR R-5..R-8)

- **R-5** dedicated `mail` queue family, ADR 0007 (account-recovery mail never queues
  behind bulk work; FS-02's depth signal needs a mail-only queue). Horizon supervisor
  #12; the stack smoke test now asserts all twelve.
- **R-6** reset-unlock is **per account**: completing a reset unlocks exactly the account
  the token was bound to; sibling candidates on the same address stay locked; the
  attacker's per-IP throttle is untouched. All three clauses asserted in one test.
- **R-7** the multi-tenant escape-hatch explanation lives in runbook copy only — the
  enumeration suite asserts reset responses byte-identical across 0/1/N AND free of
  workspace/tenant vocabulary.
- **R-8** T-01 deviation recorded: Fortify's email-keyed `password_reset_tokens` cannot
  express one-address-in-N-tenants; the flow runs on `auth_action_tokens` (one token PER
  matching account). The dead stock table drops in a later contract release (C-12).

### Proven, each with a positive control, each sabotaged red before being counted

| Requirement | Evidence | Falsified by |
|---|---|---|
| SEC-02 all four clauses | `ActionTokenTest` (8) — 256-bit/SHA-256 at rest, purpose binding both directions, email binding (rename kills links), single-use via compare-and-set, DB-time expiry two-sided, EC-04 supersede as index physics (forged second live row refused by Postgres) | consumed-check dropped → 2 red |
| FR-15 policy, settings-driven | `PasswordPolicyTest` (9) — default 12 / tenant 14 / BR-05 floor refused at the REGISTRY; classes 3-of-4 when toggled (interpretation recorded in the class docblock); reuse of current + last N with N proven to be N; refused candidate mutates nothing | history depth pinned to 1 → red |
| FR-15 max age | HTTP through the real middleware: 0 never fires; day 29 free / day 31 confined; changing the password restores access in-session | middleware unwired → red |
| T-09 / FS-03 breach check | `BreachCheckTest` (8) — **only the 5-hex prefix leaves, asserted on the faked request object**; timeout/503/unconfigured all fail OPEN with the named metric; tenant toggle off → zero calls; nothing password-derived in any log line (SEC-08) | fail-open inverted → red |
| FR-16 end to end | `PasswordResetFlowTest` (6) — request → mail → set → old dead/new works; policy failure leaves the link alive, success spends it; all sessions revoked (asserted on session KEYS); dedicated dead-link screen identical for every kind of dead | revocation removed → 2 red |
| EC-11 + R-6 via HTTP | locked victim resets while the attacker keeps going: reset account unlocked and working, **sibling still locked**, attacker's limiter count unchanged | unlock-all-candidates → red |
| FR-17 + EC-04 | `InvitationTest` (4) — invited refusal byte-identical to unknown-address; 7-day boundary two-sided; resend invalidates old link; active accounts get nothing; **BR-02 structurally: no issuance parameter is password-like, by reflection** | — (physics + reflection are the assertions) |
| FS-02 | `PasswordMailTest` (5) — queued on `mail`, tries=6, widening backoff, never inline; locale pinned at dispatch (user → tenant default → en); templates render complete in en AND am | — |
| AC-01 reset half | `EnumerationTest` +2 — bodies byte-identical across 0/1/N/invited with R-7 vocabulary assertion; timing at the handler with the R-3 floor estimator, equalized by a fixed 150 ms response floor that is itself asserted alive | floor removed → red at **0.5 ms vs 16.6 ms** — a delta that would have PASSED ±50 ms, proving the floor assertion is the load-bearing one |
| Real mail path | `password.spec.ts` (3, real cookie jars, real Horizon → SMTP → Mailpit) — reset journey with a live session dying; invited activation via EC-04 resend; FR-14 keeps the changer, kills the sibling | Horizon worker stopped → red at the mail-wait |

### THE DEFECT THE BROWSER STAGE CAUGHT THAT PEST STRUCTURALLY COULD NOT

`EnforcePasswordExpiry` read its tenant setting through `SettingsRepository::get()`,
which requires a bound tenant. The staff API binds one; **the web group does not**, so
the moment the middleware was wired, every authenticated WEB page — /security is the
only one today — 500'd. Every pest test passed, because the pest suite cannot render
that page (no Vite manifest in stage 3, the S0 lesson). The 17-test browser run caught
it on the first full pass. Fixed by binding the user's own tenant for the read;
recorded here because it is the third time the answer to "why does the browser stage
exist" has been "because it just caught something nothing else could".

### Deliberately NOT built in step 8, with reasons

- **Designed screens + strength meter** (FR-16's meter, §8/§9, AC-09) — views step; the
  functional pages ship per-rule problem-details errors, which is the exact data the
  meter will render.
- **FS-02's "UI notes possible delay" + "queue depth visible to admins"** — the delay
  note is IN the uniform response copy; the admin depth surface is deferred with the
  admin console (Horizon shows per-queue depth meanwhile). Recorded in traceability.
- **EC-03's new-device notice** — PARTIAL by ruling: another-device redemption proven;
  the notice defers with T-03's notice. Mail transport now exists, so the remaining
  unblocker is the notification-category registry entry (Doc 07/14's 3-layer opt model).
- **Admin user-creation surface** (FR-17's issuer caller) — Doc 03's; `InvitationIssuer`
  is the service it will call.
- **Reset for INVITED accounts is deliberately refused** (they get the invite resend
  instead) — recorded as an interpretation: FR-17's set-password screen doubles as
  profile completion, and a reset would bypass it.

### Risks carried forward

- **The queued reset/invite mail serializes the action URL — token included — into the
  job payload on redis-durable.** A considered C-13 tension, documented in the Mailable:
  the token is single-use, 60-min TTL (reset), superseded by any resend, and the queue
  sits inside the same trust boundary as the session store. The alternative (storing
  plaintext tokens for send-time lookup) would break SEC-02 outright.
- **`UniformResponseFloor` degrades above ~30 candidate tenants for one address** (real
  work would exceed the 150 ms floor). Same stated-bound shape as R-3's
  EQUALISED_VERIFICATIONS; unreachable in practice.
- **The 3-of-4 character-class rule is an interpretation** (Doc 02 names no classes);
  default-off, one method + one test to change if the owner prefers a different shape.
- **Amharic password/mail copy is machine-authored** — joins the standing native-review
  item.

### Registry deltas

`audit-coverage`: +6 password-lifecycle actions. `EVENT-CATALOG`: +3 (§4.8's password
trio; 15 events total, gate green). `permission-exempt`: +8 guest/self-service routes,
each with its circularity reason. `audit-exempt`: +2 uniform-response request routes
(the recorder still writes per-candidate entries whenever a token is minted).
`traceability`: Doc02 AC-01 → covered; Doc02 EC-03 added as partial per ruling.
`gates.yaml` unchanged (no new gate; SEC-08's log-scrubber gate is its own later step).

## Session-13 report (Doc 02 step 9 — API tokens)

Step 9 closed: FR-18, FR-19, FR-20 (closed code set per ruling), T-05's intersection
with the `EffectivePermissions` seam Doc 03 plugs into, AC-05 **PARTIAL by ruling Q5**,
BR-03, BR-04 (meaning fixed by ruling: DB atomicity + immediate Redis revocation +
independently-sabotaged per-request backstops on both surfaces), FS-05, EC-06, SS-04,
§4.8's three token events, and `auth.service_account.created`/`auth.user.disabled`.

Local proof: **pest 336 passed / 11 skipped (3362 assertions)**, `make gates` **10/10**,
Pint + PHPStan(max) + ESLint + vue-tsc clean. **CI-proven on `5218437`: run
[30766536879](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30766536879),
Stages 1–6 green.** The FIRST push (`93d95d6`, run 30766193447) failed Stage 3 with
`Target class [App\Domain\Auth\Disable\UserDisabler] does not exist`: the directory
was never `git add`ed, so local was green on a file CI never received — exactly the
over-claim class the CI stage exists to catch; fixed by committing the file
(`5218437`), nothing else changed. New suites: `ApiTokenLifecycleTest` (14),
`TokenScopeIntersectionTest` (5), `MachineTokenTest` (6), `UserDisableTest` (4),
`TokenPruneTest` (2), plus two new `MiddlewareOrderTest` cases (executed bearer order,
T-06 statelessness-as-absence).

### The architecture that landed

- **`tenant.bearer` binder** (`ResolveBearerTenant`): a fourth HTTP binder rather than a
  branch in `ResolveTenant`, so each surface still trusts exactly one credential kind.
  It resolves through the S4 `resolve_api_token` SECURITY DEFINER door (the only
  pre-tenant path), refuses per FR-20 BEFORE the guard, binds tenant + RLS, and clears
  both in a `finally`. Refusal order is ruling-fixed: unknown/revoked/disabled-owner all
  render `invalid_token` byte-identically (asserted literally — revoked and
  disabled-owner bodies `toBe()` the never-issued body); `token_expired` only for a
  token that would otherwise be alive.
- **Sanctum stays the guard** (T-02): `App\Models\PersonalAccessToken` (UUIDv7,
  tenant-scoped, `revoked_at`, 60 s `last_used_at` write throttle) replaces the stock
  model; `sanctum.guard = []` kills the session-fallback that would have let a staff
  cookie satisfy `auth:api`; an `authenticateAccessTokensUsing` callback re-refuses
  revoked/disabled at the guard layer (the door's second belt).
- **T-05 engine**: `TokenScopeGate` computes `abilities ∩ EffectivePermissions::for()`
  per request, cached 10 s per token in redis-volatile (C-07 ledger in the class),
  forgotten on revocation. `RequirePermission` grew its token branch: 403
  `insufficient_scope` naming the key, audited + published in one transaction. The
  staff pass-through is untouched — Doc 03 swaps ONE binding
  (`EffectivePermissions` → its resolver) and changes nothing else.
- **Machine tokens**: same table, same door, same scope model; owner is a find-or-create
  service account (`<slug>@service-accounts.invalid`, RFC 2606 — self-identifying like
  the fixture phone convention), non-login TWICE (unusable password + excluded inside
  `resolve_staff_login()` itself, with the rehydration belt in `StaffUserProvider`).
  Rotation = mint replacement with `rotation_of` audit + deliberate overlap; the
  explicit revoke closes the window (SS-04's zero-failed-calls proven live).
- **BR-04**: `UserDisabler` — one transaction for `disabled_at` + every live token's
  `revoked_at` (each through the ordinary `TokenRevoker`, so per-token audit rows fall
  out), sessions revoked after commit through the T-04 revoker, backstops per-request
  on both surfaces. No HTTP route yet by design — Doc 03 owns `admin.users.disable`
  (S5 admin-unlock precedent).
- **FS-05**: `tokens:prune`, nightly 03:00 UTC, platform role (app has no DELETE — the
  grant is asserted in-suite via `has_table_privilege` and swept by GrantPhysicsTest),
  deletes rows `expires_at <= now()` (DB clock, C-11) and nothing else; the partial
  index and the plan are documented in the command.

### Two real defects found while proving it, both invisible in the route table

1. **The middleware sorter inverted the declared bearer order.** The route declared
   `tenant.bearer → auth:api → tenant.state`, and `route:list` showed exactly that —
   but Laravel re-sorts any stack containing priority-listed middleware, `Authenticate`
   is priority-listed above the api group's `SubstituteBindings`, and it HOPPED over
   the unlisted binder. The Sanctum guard ran with no tenant bound and every bearer
   request 500'd with `MissingTenantContext`. Fix: `prependToPriorityList` — against
   the **`AuthenticatesRequests` contract**, not the concrete class; the first attempt
   targeted `Authenticate::class`, which is not literally in the priority list, and was
   silently appended at the END (worse). `MiddlewareOrderTest` now asserts the SORTED
   stack the pipeline executes, not the declaration.
2. **`shouldUse('api')` leaks across harness requests.** A successful `auth:api`
   request flips the app's default guard; production resets config per request (FPM,
   Octane), the test harness does not — so after any bearer request, an unnamed
   `actingAs($user)` planted the user on the wrong guard and every staff request 401'd.
   All new suites name the guard (`actingAs($user, 'staff')`) and forget guards before
   each bearer call, with the reason documented at the helpers.

### Falsification ledger (sabotage before counting — every red pasted in-session)

| Sabotage | Expected red | Result |
|---|---|---|
| intersection removed (`scopeSet` returns abilities) | owner-exceed + propagation tests | **red**, restored |
| cache TTL 10 → 3600 | TTL-bound assertion | **first run stayed GREEN** — the assertion compared against the sabotaged constant itself; rewritten to the literal spec 10, then **red**, restored |
| cache disabled | stale-allow-inside-window (EC-06's documented bound) | **red**, restored |
| 403 stops naming the key | AC-05 naming test | **red**, restored |
| door revoked-check removed | revoke test + byte-identity test | **red** (guard belt's generic 401 fails the FR-20 code assertion — the door's rendering is independently load-bearing), restored |
| door user_disabled-check removed | BR-04 backstop 1 | **red**, restored |
| token revocation removed from disable transaction | BR-04 main test | **red**, restored |
| rehydration disabled-check removed | BR-04 backstop 2 | **red**, restored |
| prune criterion dropped (delete all) | FS-05 survivor assertions | **red**, restored |

Plus two born-red proofs: the executed-order test failed against the real sorter bug
before the fix, and the BR-04 atomicity test asserts its own injection fired (a
non-firing injection fails the test rather than passing it).

### Registry / catalog deltas

`permissions`: +1 (`apikeys.machine.manage`, Doc 03 §4.1's name; the catalog was
checked end to end per ruling — it has NO personal-token key, so the self-service
routes are exempt with the absence cited). `audit-coverage`: +5 token actions.
`EVENT-CATALOG`: +3 (18 total, identifier-only payloads per ruling; `permission` on
scope_denied is a catalog key, not a person; regenerated same commit).
`permission-exempt`: +4 (3 personal-token self-service + the bearer context probe).
`traceability`: Doc02 AC-05 → covered, PARTIAL per Q5 with the full gap note.
`gates.yaml` unchanged.

### Deliberately NOT built in step 9, with reasons

- **AC-05's literal `tickets.read`/`tickets.reply` fixture** — ruling Q5; needs Doc 03's
  resolver + Doc 06's routes. The traceability row says exactly this.
- **Product endpoints on the bearer surface** — ruling Q-A: probe-only; Docs 04/06
  mount the first real routes on this stack.
- **Service-account role assignment** — Doc 03, through the seam (ruling Q-B).
- **Admin disable HTTP route** — Doc 03's `admin.users.disable` (high, four-eyes rail).
- **R-03's quarterly unused-token report** — Doc 15/18 reporting surface.
- **Token screens + AC-09 visual regression** — views step, like every screen so far.
- **≤2 ms token-middleware p99 measurement** — Doc 19 L1, same bucket as every other
  perf budget.

### Risks / residuals worth naming

- The guard-partition test ("session cookie never satisfies the bearer surface") is
  structurally double-covered: the door refuses before `sanctum.guard=[]` could ever
  matter, so sabotaging the config alone does not flip it red. The config line is
  defense-in-depth for FUTURE routes that might mount `auth:api` without the binder;
  recorded here rather than claimed as independently falsified.
- `resolve_staff_login()`'s service-account exclusion is asserted through behaviour
  (`candidatesFor()` returns zero rows) but was not sabotaged at the SQL layer — that
  would mean re-migrating mid-suite. The pre-change history stands in: before this
  migration the same query returned the row.

## Session-14 report (Doc 02 step 10 — widget visitor tokens)

Step 10 closed: FR-21, FR-22 (rulings D-1…D-5 applied as approved), AC-06 (both
layers, independence proven by REMOVAL), BR-06 both directions, EC-08 **PARTIAL by
ruling** (server-side ancestry proven; the structural frame-ancestors half is
Doc 13's), EC-09 including the no-linking-capability reflection assertion, plus the
widget slices of T-02 (guard), T-06 (per-widget CORS) and the T-07 posture fix below.

Local proof: **pest 366 passed / 11 skipped (3566 assertions)**, `make gates`
**10/10**, Pint + PHPStan(max, 1G) + ESLint clean. **CI-proven on `330fdc8`: run
[30771276124](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30771276124),
Stages 1–6 green, Stage 7 the by-design manual stub — first push, no re-runs.**
New suites:
`WidgetSessionTest` (13), `WidgetTokenTheftTest` (8), `WidgetPartitionTest` (5),
two new `MiddlewareOrderTest` cases (executed widget order + Authenticate-absence,
sessionless-surface absence), `SuspendedTenantMatrixTest` widget cells now drive
the REAL issuer (201, seeded allowlist) instead of the Doc 01 probe.

### The architecture that landed

- **`widgets` table** — the Doc 02-owned minimal shape the tenant_keys migration
  planned for: `key_id` (unique, → tenant_keys) + `allowed_origins` jsonb, default
  `[]` = fail-closed. Standard physics verified in the test DB: RLS enabled AND
  forced, `tenant_isolation` policy, app grants exactly INSERT/SELECT/UPDATE (no
  DELETE). Doc 13 attaches its config columns expand-contract.
- **`OriginAllowlist`** — the ONE matcher (ruling D-5): https only, case-normalized
  hosts, exact ports (absent = 443), exact origins or `*.domain` wildcards with the
  dot boundary; `evil-brand.com` vs `*.brand.com` is fixture-pinned. Punycode
  lookalikes deliberately out of scope per ruling; docblock says whose problem
  homographs are (the operator's allowlist).
- **`VisitorTokenStore`** — redis-volatile (ruling D-1), keys
  `t:{tenant}:widget-visitor:{sha256(token)}`, token `nvt_` + 128 CSPRNG bits,
  cookie secrets stored as sha256 only, sliding 30-day SETEX. No operation accepts
  a caller-chosen visitor identity — EC-09's "linking is staff-only" as an absent
  capability, asserted by reflection.
- **POST `/api/widget/v1/session`** — supersedes the Doc 01 probe on the same
  trust model (`tenant:widget_key` + `tenant.state:inbound`; a suspended
  operator's visitors keep their identity — US-02 for web chat, asserted in the
  suspended matrix). Validates Origin-header/declared-origin agreement, allowlist,
  and every declared ancestor; refusals are byte-identical `origin_not_allowed`
  403s with a security metric. Issue 201 / refresh 200 (rotate cookie, slide TTL) /
  bare-token re-issue 201 with a FRESH visitor_id and the victim untouched.
- **`widget` guard** (`WidgetGuard` + `WidgetVisitor`) — principal is a value
  object, not a User, not a model; `setUser()` throws on anything else; config
  entry has NO provider key. Four checks in order: token header → store record →
  same-widget binding → cookie pair (current-or-previous). BR-06 is structural in
  both directions and sabotage (f) — a users-provider session guard swapped in —
  set 8 tests red across two suites.
- **`WidgetCors`** — AC-06's independent CORS layer: stamps ACAO exactly for
  allowlisted origins, inside the binder (RLS scope) and outside the guard (401s
  stay CORS-readable); permissive preflight documented in the class (a preflight
  cannot know the widget; enforcement is dual-layer on the actual request).
- **Reverb door** — `POST widget/v1/broadcasting/auth` on the widget stack;
  visitor channels `tenant.{t}.visitor.{v}` guards-pinned to `widget`, the staff
  channel now guards-pinned to `staff`. Channel tests run against the REAL
  Pusher-protocol driver — the suite bootstrap's `BROADCAST_CONNECTION=null`
  would have 200'd every channel and proven nothing (recorded in the test).

### Three real defects found while proving it

1. **The framework's default CORS config granted `api/* → *`.** No
   `config/cors.php` existed, so Laravel's built-in HandleCors ran with its
   defaults on the whole API — every staff response carried
   `Access-Control-Allow-Origin: *`, and it overrode the per-widget stamp. The
   published config now disables the blanket handler with the T-06 posture
   documented per surface. This predates step 10 and was invisible because no
   test ever asserted a CORS header until AC-06.
2. **The first widget-order fix broke the staff surface.** Mirroring step 9
   (ResolveTenant + WidgetCors into the middleware priority list) held the widget
   order — and silently re-sorted the STAFF stack, hoisting the binder above the
   group-appended SecurityHeaders: staff 404s rendered with NO T-07 headers.
   `SecurityHeadersTest` caught it on the first full-suite run. The priority list
   is global; the fix is `AuthenticateWidgetVisitor` (`widget.auth`), which does
   what `auth:widget` did without implementing the priority-listed contract —
   nothing hoists, and `MiddlewareOrderTest` now asserts Authenticate's ABSENCE
   from the widget stack so the "simplification" back to `auth:widget` fails red.
3. **The staff broadcast channel 500'd on a visitor.** Under `auth:widget`,
   `shouldUse` made `widget` the request's default guard, and the unpinned staff
   channel callback received a `WidgetVisitor` — TypeError against its `User`
   type declaration, 500 where a refusal owes 403. Fixed twice over: the staff
   channel is guards-pinned, and the replacement middleware never touches the
   default guard.

### Falsification ledger (sabotage before counting — every red observed in-session)

| Sabotage | Expected red | Result |
|---|---|---|
| (a) ancestor check deleted | EC-08 disallowed-ancestor test | **red**, restored |
| (b) cookie comparison always-true | rotation-grace boundary + bare-token 401 | **red** (2 tests), restored |
| (c) wildcard weakened to bare suffix | `evil-brand.com` fixture | **red**, restored |
| (d) server-side origin check removed | server-layer refusal + CORS-independence direction one | **red** (2 tests; the CORS-layer test stayed green — that is the independence property working), restored |
| (e) ACAO stamped unconditionally | CORS-layer tests both suites | **red** (2 tests), restored |
| (f) widget guard → session driver + users provider | BR-06 partition | **red — 8 tests across two suites**, restored |
| (g) TTL slide frozen | sliding-TTL clause | **red**, restored |

Plus two discoveries that would have made tests vacuous, both now recorded in the
tests themselves: Laravel JSON test requests drop cookies without
`withCredentials()` (and the test client's cookie jar PERSISTS across in-test
requests — `resetWidgetClient()` exists so "no cookie" means no cookie), and the
NullBroadcaster approves every channel (channel tests swap in the real driver).

### Registry / catalog deltas

`permission-exempt`: +4 widget routes (visitor surface — no staff principal
exists to hold a permission; Doc 01's widget probe line removed).
`audit-exempt`: +3 (Redis-only issuance; §4.8's closed event set has no visitor
event, by ruling; Doc 01's widget probe line removed; telegram/DLR probe lines
untouched — steps 11 / Docs 05/14 own them). `traceability`: AC-06 → covered;
new rows FR-21, FR-22, BR-06 covered; EC-08 PARTIAL naming Doc 13's half; EC-09
covered. `settings`: unchanged (the 30-day TTL is spec-fixed; no key invented).
`permissions`, `notifications`, `gates.yaml`, `EVENT-CATALOG`: unchanged.

### ⚠ Doc 18 handoff (owner ruling, step-10 approval — Decision 1)

Visitor tokens live on **redis-volatile** (`allkeys-lru`, no persistence) beside
staff sessions. **The property at stake is US-07: "my chat history is still there
a week later."** Under memory pressure, `allkeys-lru` may evict a 30-day visitor
token early; the visitor silently degrades to EC-09's fresh-identity path and
continuity breaks with no error anywhere. Doc 18 therefore OWES, as spec work,
not as an optimization:

1. an eviction policy on the volatile instance that spares TTL'd visitor keys
   (`volatile-ttl` or equivalent) — chosen deliberately against the staff-session
   and cache workloads sharing the instance;
2. memory sizing that accounts for the visitor population (10k concurrent
   sessions is the Doc 01 capacity number; visitor records are ~300 B each,
   but the 30-day window means the RESIDENT population is much larger than the
   concurrent one);
3. an eviction-observed metric/alert if the instance exposes one (`evicted_keys`
   does exist in Redis INFO), so US-07 breaking shows up as a graph, not a
   support ticket.

Until Doc 18 lands this, US-07 holds only while the volatile instance is not
under eviction pressure. Recorded here so it cannot silently become "how it has
always worked."

### Deliberately NOT built in step 10, with reasons

- **The widget frame's `frame-ancestors` CSP and the hostile-host page against
  it** — Doc 13 FR-03/SEC-02; the EC-08 traceability row is PARTIAL and names it.
- **Every real visitor endpoint** (messages, config, KB, typing…) — Doc 13 T-03
  mounts them on THIS stack; the surface is probe-only beyond issuance, the
  step-9 posture.
- **Per-visitor-token rate limiting** — Doc 13 T-03's clause ("Doc 02 FR-25
  family"), belongs with the endpoints it protects; nothing invented now.
- **Widget admin/config surface, key rotation flow** — Doc 13 FR-19 (Install
  tab); today a widget row is created by console/fixture only.
- **Visitor-token screens** — none exist by design (visitors see no auth UI).
- **≤2 ms guard p99 measurement** — Doc 19 L1, same bucket as every perf budget.

### Risks / residuals worth naming

- **The volatile-eviction handoff above** — the one true product risk this step
  leaves open.
- The preflight reflects arbitrary origins (documented in `WidgetCors`). Grants
  nothing by itself, but it is the kind of header a scanner flags; the docblock
  carries the justification a security review will ask for.
- The ancestor check trusts loader-supplied data (ruling-acknowledged, advisory
  against non-browsers); the enforceable half is Doc 13's.
- `require routes/channels.php` re-registration in the channel tests is mildly
  unusual; if a future channels file gains side effects beyond
  `Broadcast::channel()` calls, those run twice in that suite.
- Doc 13 FR-08 says "token per Doc 02 (sliding 30 d)" — if Doc 13 later wants the
  TTL operator-tunable, that is a settings-registry addition under `auth.*` with
  a platform minimum, and it reopens ruling D-1's sizing arithmetic.

## Session-15 report (Doc 02 step 11 — inbound webhook authentication)

Step 11 closed: FR-23, FR-24 (signing contract covers timestamp+nonce+body —
load-bearing, see below), FR-25 (all three clauses), AC-07 (all three clauses,
spy-proven), plus the A-1 filed interpretation and a real Doc 01-era defect fixed
(TenantCache outage 500s). Rulings D-1…D-6 applied as approved.

Local proof: **pest 384 passed / 11 skipped (3703 assertions)**, `make gates`
**10/10**, Pint + PHPStan(max, 1G) + ESLint clean. **CI-proven on `1908299`: run
[30788851081](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30788851081),
Stages 1–6 green, Stage 7 the by-design manual stub — first push, no re-runs.**
New suites: `WebhookSecretTest` (6), `WebhookHmacTest`
(9), `WebhookComparatorPinTest` (2), one new `MiddlewareOrderTest` matrix case;
`SuspendedTenantMatrixTest` webhook cells now authenticate with both mechanisms
(expectations unchanged — suspended still accepts inbound, behind a proven
credential).

### The architecture that landed

- **The 403/404 layering**: route key resolves the tenant (wrong key stays 404,
  C-10); the FR-23/24 secret is a SECOND credential checked after resolution —
  AC-07's 403 leaks no tenant existence because reaching it required a valid
  unguessable key.
- **`webhook_credentials`** (Doc 02-minimal, widgets precedent): `key_id` unique
  → tenant_keys, `header_secret_hash` (SHA-256, equality credential) and
  `hmac_secret` (encrypted cast — the TOTP posture, ruling D-5). Physics verified
  in the test DB: RLS on+forced, app grants exactly INSERT/SELECT/UPDATE.
- **`webhook.auth`** (`VerifyWebhookSecret`): two channel-agnostic MECHANISMS
  (N-20 — routes bind mechanism + header name; no code branches on channel).
  `secret_header`: hash_equals on digests, Redis-free. `body_hmac`:
  HMAC-SHA256 over `{timestamp}.{nonce}.{raw body}` — covering ts+nonce is what
  makes the window real (a body-only HMAC replays forever behind fresh nonces;
  the captured-signature-fresh-nonce test pins it); ±300 s both directions;
  nonce single-use via atomic `SET NX` on volatile (ruling D-1), spent only
  AFTER the signature verifies so unsigned callers cannot burn a gateway's
  nonces. One `webhook_rejected` 403, byte-identical across every cause;
  metrics (`webhook.secret_rejected` + reason, `webhook.replay_cache_unavailable`)
  carry the distinctions responses withhold.
- **`webhook.throttle`** (`ThrottleWebhookKey`): FR-25 per key — one flooding
  bot never starves its sibling (two-keys-one-tenant test). The number is the
  new registered `auth.webhook.rate_per_minute` (default 600, floor 60,
  platform-scoped, ruling D-4). A plain class, NOT the priority-listed
  ThrottleRequests — the step-10 sorter lesson applied preemptively;
  MiddlewareOrderTest fails if either Authenticate or ThrottleRequests ever
  appears on these stacks.
- **Outage postures, all three proven against ONE real closed port** in a single
  FS test: replay guard CLOSED (A-1), throttle OPEN with metric (capacity
  protection must not convert a cache outage into a channel outage), and
  TenantCache's pre-context keymap OPEN to a DB-resolved miss.

### A pre-existing defect fixed: the resolver's own cache could down every key surface

`TenantCache::preContext*` had no outage handling, so a dead volatile instance
threw straight through **tenant resolution itself** — every key-resolved surface
(telegram, widget, DLR, public API) 500'd, which inverts C-07's "cache is never
the source of truth" and falsified FS-01's "webhooks unaffected" before step 11
ever touched the stack. Now: unreachable cache = miss, the SECURITY DEFINER door
answers from the database, `tenant.keymap_cache_unavailable` says why p99 moved.
The revocation-forget path is safe to lose because the resolver re-verifies
revocation against the DB on every hit (documented at the catch).

### A self-inflicted bug the first red run caught, kept as a lesson

phpredis's `SET` takes an options array, not protocol-style variadics; the
variadic form threw `TypeError`, and the middleware's original broad
`catch (\Throwable)` dressed that coding bug up as a permanent "cache outage" —
every valid DLR refused 403. The catch is now `\RedisException` only: coding
bugs stay loud 500s, outages stay quiet refusals. The general lesson matches
S4's: **a fail-safe branch that catches too much converts bugs into the exact
condition it exists to survive.**

### Falsification ledger (sabotage before counting — every red observed in-session)

| Sabotage | Expected red | Result |
|---|---|---|
| (a) comparison stubbed always-true | mismatch + byte-identity + spy tests | **red** (3 tests), restored |
| (b) hash_equals → `==` | ONLY the pin | **red — exactly the pin, nothing else** (the approved expectation of what a pin does), restored |
| (c) SET NX → plain SET | nonce single-use | **red**, restored |
| (d) timestamp window deleted | boundary test | **red**, restored — and this red is what makes D-1's LRU residual acceptable (the bound is real and independent) |
| (e) limiter re-keyed per tenant | per-key isolation | **red**, restored |
| (f) outage waved through | FS fails-CLOSED test | **red**, restored |
| (g) StartSession planted on the stack | executed-stack absence sweep | **red**, restored |

### Registry / catalog deltas

`settings`: +1 (`auth.webhook.rate_per_minute`). `traceability`: AC-07 →
covered; new rows FR-23/FR-24/FR-25. `ANSWERS-FROM-DOC-AUTHOR.md`: +A-1 (the
filed interpretation, with its Doc 14 revisit condition). `permission-exempt` /
`audit-exempt`: **unchanged** — the probe lines still belong to Docs 05/14 per
ruling. `permissions`, `notifications`, `gates.yaml`, `EVENT-CATALOG`: unchanged
(§4.8 has no webhook event; refusals are metrics, not audit rows).

### ⚠ Standing operational note (ruling, decision 6)

**Any environment exercising the ingress probes — staging included — needs
seeded `webhook_credentials` rows from this step forward, or its telegram/DLR
probes return 403 BY DESIGN** (fail-closed unconfigured keys). Doc 05's bot
registration and Doc 14's gateway setup become the production write paths.

### ⚠ Doc 18 handoff, extended (rulings D-1 and D-5)

Beside S14's eviction-policy entry: (1) the volatile instance now also holds
webhook replay nonces — same eviction concern, bounded by the independent ±5 min
timestamp check (sabotage (d) proves the bound); (2) **the vault migration
obligation now covers BOTH encrypted-cast secrets in one entry**:
`users.two_factor_secret` and `webhook_credentials.hmac_secret` (C-13's vault
reference model, Doc 18's slot).

### Inherited by step 12 (SEC-08 / AC-08), by ruling

- `webhook_credentials.hmac_secret` joins the log-scrubber's seeded fake
  credentials AND the secrets-never-serialize sweep (alongside
  `two_factor_secret`, visitor tokens/cookies, and every other credential class
  this document has minted).
- The scrubber must also cover the webhook headers themselves
  (X-Telegram-Bot-Api-Secret-Token, X-Webhook-Signature) in access-log shapes.

### Deliberately NOT built in step 11, with reasons

- **Real Telegram webhook URL shape, bot records, secret registration at setup**
  — Doc 05; the probe's audit-exemption line is Doc 05's to delete.
- **Gateway records, native-signature drivers, DLR processing** — Doc 14; its
  drivers adapt native schemes onto the `body_hmac` contract.
- **A timing measurement for constant-time comparison** — refused by ruling
  (noise-level signal, flaky-test hazard); the pin + always-true sabotage are
  the approved substitute, honestly labeled.
- **Per-channel rate-limit re-defaults** — Docs 05/14 under their own rulings
  when real traffic numbers exist.

### Risks / residuals worth naming

- The A-1 interpretation is filed, not author-confirmed: if a real gateway
  treats 403 as permanent and stops retrying DLRs, Doc 14 must reopen it with
  the doc author.
- LRU eviction of a nonce inside the 5-minute window re-admits a replay only
  when the attacker also holds a valid in-window signature — bounded, named,
  and inherited by the Doc 18 sizing entry.
- The comparator pin is source-text matching: a refactor that renames variables
  will fail it spuriously (annoying, safe) rather than silently (dangerous,
  the failure mode it trades away).

## Session-16 report (Doc 02 step 12 — SEC-08 secret hygiene: log scrubber + serialization sweep)

Step 12 closed: SEC-08 (both clauses), AC-08 (all five sinks), the C-13
secrets-never-serialize sweep the step-11 ruling assigned here, and the
inherited roster in full — `hmac_secret`, `two_factor_secret`, visitor
tokens/cookies, the webhook auth headers in access-log shapes, and every
other credential class this document minted (21+ sentinels).

Local proof: **pest 394 passed / 11 skipped (3980 assertions)**, `make gates`
**11/11** (the new `log-scrubber` gate among them), Pint + PHPStan (max, 1G)
clean. **CI-proven on `91e76c0`: run
[30806932074](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30806932074),
Stages 1–6 green on first push — Stage 3's log shows `Running 11 gates`,
`log-scrubber PASS`, `Gate summary: 11 passed, 0 failed, 0 skipped`, and the
full suite at 394 passed / 3980 assertions.** New suites: `LogScrubberTest` (7), `SecretsNeverSerializeTest` (3) —
274 assertions between them.

### The architecture that landed

- **Two Monolog processors, one tap** (`app/Support/Logging/`):
  `AttachLogContext` puts `tenant_id` + `trace_id` (TenantContext's C-14
  correlation id, container-resolved per record — the Octane-safe way) on
  every record; `ScrubSecrets` redacts by key/header shape, recursively,
  case-insensitively, with `_id`/`_count` suffixes exempt so tracing ids
  survive. `SecurityLogTap` pushes both on every channel; push order makes
  the scrub run last. `null` and `emergency` are exempt by the approved
  ruling, and the exemption list is safe because the **channel-pin test**
  fails on any channel — present or future — that omits the tap.
- **The pin test caught a real gap on its first run**: Laravel's
  slimmed-config merge injects the framework's `monthly` channel into the
  live channel list even though `config/logging.php` never declared it — it
  was arriving WITHOUT the tap. Now declared explicitly, tapped, with the
  find recorded in a config comment. This is the exact failure mode the
  owner's ruling on interpretation 1 predicted.
- **Division of labour, held honestly (interpretation 2)**: the runtime
  scrubber is defence-in-depth by key shape; the guarantee AC-08 counts is
  the seeded-VALUE sweep over the real flows. Sabotage (a) proved the
  boundary: a password logged under an innocent key sailed past the
  scrubber and the sweep went red on it.
- **One flow driver, every credential class** (`driveSeededCredentialFlows`
  in Pest.php): real HTTP against login (wrong+right), 2FA enrolment +
  TOTP challenge + recovery-code challenge, PAT + machine-token minting,
  password reset, invitation, widget session issue/context, telegram
  secret-header (match+mismatch), DLR HMAC (signed+tampered).
  Server-generated credentials are CAPTURED from the real responses rather
  than seeded — the sweep scans for the actual live values the platform
  minted.
- **Sinks and counting bar**: rendered log file (the real `single` channel,
  path repointed only), `jobs` (real serialization on the database driver),
  `outbox`, `audit_log`, and `events` after a genuine committed
  `OutboxRelay` run (commitFixtures + platform-worker relay + cleanup, the
  OutboxChaosTest pattern; `cleanupTenants` extended with the Doc 02
  tables). Every absence assertion is preceded by plant → detect → remove:
  file plants for the log sink, savepoint-rollback inserts for the DB sinks
  (works even on audit_log, where DELETE is revoked by grant).
- **The recorded C-13 carve-out is now encoded, not just documented**:
  PasswordResetMail/InvitationMail tokens may appear in `queue='mail'` job
  rows ONLY. The sweep asserts both directions — present there (so the
  carve-out cannot silently close without reopening the ruling), absent
  everywhere else (so it cannot silently widen).
- **Model hidden-pin roster (owner ruling)**: `AuthActionToken.token_hash`
  and `UserRecoveryCode.code_hash` gained `#[Hidden]`; the pin test covers
  User (`password`, `remember_token`, `two_factor_secret`),
  WebhookCredential (both columns), PersonalAccessToken (`token`), and
  TenantKey (`hash` — Doc 01's model, on the roster because Doc 02's widget
  and webhook keys ride it). `Device.fingerprint_hash` excluded with the
  reason in the test: it hashes public client hints, not credential
  material.
- **The gate** (`ci/log-scrubber.sh`, registered in gates.yaml → Doc02
  AC-08): container-aware like isolation-suite, and **fail-closed by
  ruling** — missing suite files or a stack that is not running exit
  nonzero with "did not run is never passed" in the message. Proven by
  running it from a directory without the suites (exit 1) and via
  `make gates` (11/11).

### Sabotage ledger (all reverted; each red on the assertion that counts it)

| # | Sabotage | Result |
|---|---|---|
| (a) | `Log::info('debug aid', ['note' => $password])` in the real login flow | RED — `seeded credential "staff_password" leaked into the rendered log sink (AC-08)` |
| (b) | Tap removed from `stack` + `single` | RED ×5 — channel pin names `stack`; scrub liveness, header shapes, both trace tests fail. (Seeded sweep stays green — honest: first-party flows do not log secrets themselves; the scrubber is defence-in-depth) |
| (c) | `ScrubSecrets` stubbed to passthrough | RED ×2 — liveness and header-shape values reach the rendered sink |
| (d) | `AttachLogContext` stubbed to passthrough | RED ×2 — "the record shipped without the bound correlation id"; refused-request line carries no trace_id |
| (e) | Reset-token plaintext wedged into the `auth.password.reset_requested` audit payload | RED — `seeded credential "reset_token" reached an audit payload (C-13)` |
| (f) | `$hidden` emptied on WebhookCredential | RED — DecryptException the moment the encrypted column enters serialization (red by crash rather than exposure; either way the pin trips) |

### Interpretations applied (owner-approved this session)

1. Channel exemptions (`null`, `emergency`) with the new-channel pin making
   the list safe — and it did, on day one (`monthly`).
2. Key-shape scrubber as defence-in-depth; seeded-value sweep as the
   counted guarantee.
3. TOTP codes scanned with digit boundaries; the challenge-path
   captured-records fallback is PRE-AUTHORIZED on the first flake — switch
   immediately, never re-run red (the S4 lesson).

### Sink boundary (recorded in the traceability row, per ruling)

Swept: rendered logs, jobs, outbox, audit payloads, relayed events.
Out: Reverb/Redis publish payloads — `session.revoked:{id}` is
identifier-only by T-04's design and the event catalog's closed-payload rule
polices bus shapes.

### Step-11 inheritance — discharged

Both items: `webhook_credentials.hmac_secret` is in the seeded roster AND
the serialization sweep (alongside `two_factor_secret`, visitor
tokens/cookies, and the rest), and the webhook headers themselves
(X-Telegram-Bot-Api-Secret-Token, X-Webhook-Signature) are scrub-asserted in
nested access-log shapes.

### Self-audit (Doc 02 §18, honest statuses)

| AC | Test path | Status |
|---|---|---|
| AC-01 | EnumerationTest + PasswordResetFlowTest (reset half, S12) | PASS (prior sessions) |
| AC-02 | LockoutTest | PASS (S5) |
| AC-03 | TwoFactorChallengeTest / TwoFactorEnrolmentTest | PASS (S11) |
| AC-04 | SessionLifetimeTest + SessionRevocationSocketTest | PASS (S10) |
| AC-05 | TokenScopeIntersectionTest | PASS — PARTIAL per ruling Q5 (S13) |
| AC-06 | WidgetSessionTest + CORS layer tests | PASS (S14) |
| AC-07 | WebhookSecretTest + WebhookHmacTest | PASS (S15) |
| AC-08 | tests/Security/LogScrubberTest.php + tests/Security/SecretsNeverSerializeTest.php, enforced by the `log-scrubber` gate | **PASS (this session — output above, sabotaged six ways)** |
| AC-09 | — | NOT-IMPLEMENTED — no auth views exist yet (the views step); traceability row remains honest `not-implemented` |

### Risks / residuals worth naming

- **The `emergency` channel is exempt and therefore unscrubbed.** Bounded:
  it only receives records when the logging system itself throws, and the
  sweep + first-party context discipline keep secrets out of log calls in
  the first place — but a secret-shaped context key logged during a logging
  outage would land unredacted. Named rather than hidden; revisit if Doc 18
  centralizes log shipping.
- **TOTP digit-boundary scan retains a ~1-in-10⁶-per-line collision chance
  with rendered microsecond fields.** Fallback pre-authorized (ruling on
  interpretation 3); zero flakes across this session's runs.
- **The scrubber redacts context, not message strings.** Free text has no
  keys to match; a secret interpolated into a message is the sweep's to
  catch (sabotage (a) class). Recorded in the ScrubSecrets docblock.
- The committed events-relay test leaves no residue on success (cleanup in
  `finally`, jobs rows deleted by fixture markers), but an aborted run can
  strand committed fixture rows in the test database — same standing
  caveat as the Doc 01 chaos tests, self-identifying via `@fixture`/
  `FAKECRED` markers.

## Session-17 report (Doc 02 step 13 — the authentication screens: §8, §9, §13, §14, AC-09)

**Evidence: pest 431 passed / 11 skipped (4303 assertions), full suite in-stack — includes the enumeration suite per the owner invariant; browser 25 passed (8 new in
`tests/Browser/auth-screens.spec.ts` + all 17 existing); `make gates` 11/11; Pint +
PHPStan(max) + ESLint + vue-tsc clean. **CI-proven on `1c6e601`: run
[30843064597](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30843064597),
Stages 1–6 green on first push** (Stage 4 executed the 25-test browser suite including the
new 8-test auth-screens spec with its axe sweeps), Stage 7 the by-design manual stub.**

### Built (every §8 screen state now exists and is exercised)

| Surface | What §8/§9 asked for, delivered |
|---|---|
| **Login card** (`GET /login`, new — only the POST existed) | Brand-card shell, states idle → submitting (spinner-in-button, locked fields) → inline generic error → 2FA step → redirect; forgot-password always visible; endonym language switcher; remember-me (FR-13's only UI surface) |
| **2FA challenge step** | A state of the card, driven by the existing `401 two_factor_required` contract; segmented 6-digit input that is STRUCTURALLY one `<input>` (§13), auto-focus (§8's named exception to the heading-focus rule), paste-friendly, auto-submits on the 6th digit; recovery-code alternative with FR-07's remaining-count interstitial and low-codes banner; `restart_login` RFC 9457 extension member gives the card a stable back-to-password signal for FR-07's void |
| **Enrolment wizard** (Security page) | Three steps (scan → verify → codes); the codes step BLOCKS until "I have saved these" is checked OR the file downloaded (both halves of the OR proven independently, gate sabotage run red); copyable secret, copy-all codes; regenerated codes re-enter the same gated step (SEC-03) |
| **Timeout warning** (§8 T-2 min) | `SessionTimeoutSentinel` + `GET /api/staff/session/deadline`: toast scheduled from the SERVER's `effective_seconds_remaining`; "stay signed in" calls the endpoint, which rolls the idle clock and can never move the absolute cap (the load-bearing pest pair) |
| **EC-02 re-login modal** | Same sentinel, staff-Inertia scope only (owner ruling, stated in the docblock); trigger is interpretation A-2 (confirm-against-the-deadline-probe, because C-10 makes a dead session's 404 deliberately ambiguous); modal handles password + 2FA + recovery; original request replays with a refreshed CSRF header; in-page state survives — proven by typing into a field, killing the session server-side, recovering, and finding the field intact |
| **Invitation profile completion** | Name + language (exact en|am set) ride the accept, persisted only AFTER the password applies (no unaudited write on a failed accept — proven); form prefills the admin-entered name. **Avatar: NOT-IMPLEMENTED — blocked on the media pipeline (Docs 04/08)** |
| **§9 components** | PasswordField (visibility toggle + TEXT strength meter arguing from the tenant minimum, §13), SegmentedCodeInput, AuthCard; host-tone props because Doc 16 owns theming |
| **§14** | Guest locale cookie hop (exactly `en|am`, staff `users.locale` always wins, malformed ignored + counted through the S16 scrubber); `html lang` now follows the request locale (was hardcoded `en` under a stale comment) |

### Proven — the §13 falsification core, each sabotage RED before counting

All at 360×740 (§9/NFR-05), with axe-core WCAG 2.2 AA sweeps per screen state:

| Sabotage | Red output (verbatim assertion that failed) |
|---|---|
| (a) `role="alert"` removed from the login error | `expect(error).toHaveAttribute('role', 'alert')` → `unexpected value "null"` |
| (b) code input split into two inputs | `expect(codeInputs).toHaveCount(1)` → `Expected: 1 Received: 2` |
| (c) wizard heading focus call dropped | `expect(wizard-scan-heading).toBeFocused()` → `unexpected value "inactive"` |
| (d) codes-step gate removed | `expect(finish).toBeDisabled()` → `unexpected value "enabled"` |

Also proven: complete keyboard-only sign-in; label/`aria-describedby` association on
every error; the FR-11 walk (expiry → redirect → notice → return to the preserved URL);
the ruled return-URL refusal fixtures (absolute, `//host`, `/\host`, `\/host`, plus the
whole backslash/control-char class) at both unit and page level; the deadline clock pair;
guest-cookie locale with staff-wins and exact-set refusal (logged + scrubbed); invitation
profile persistence, refusal, and ordering. **The enumeration suite was re-run as part of
the full-suite gate per the owner's invariant — the new screens introduce no response
difference between existing and non-existing accounts (the card renders one error path,
and its props carry nothing account-shaped, asserted).**

### TWO REAL DEFECTS the new tests caught (both pre-existing, both fixed)

1. **FR-11's redirect never happened on the web surface.** The middleware sorter hoists
   `Authenticate` (priority-listed contract) ABOVE the group-appended
   `EnforceSessionLifetime` — S13's bearer-stack lesson replayed on the web stack. So
   Authenticate passed on the not-yet-flushed session, the middleware then silently
   de-authenticated, and an expired session's page rendered **half-dead with a 200**
   (null user, empty props) instead of redirecting. Found by the FR-11 walk-through
   test's first run; fixed by the middleware OWNING its redirect (`redirect()->guest`)
   instead of assuming its position; the bootstrap comment that claimed the old ordering
   was corrected. The staff API surface was never affected (its stack has no
   priority-listed auth middleware).
2. **§13 contrast and language-tag violations in shipped screens.** The axe sweep found
   `slate-500`-on-`slate-950` table text at ≈4.3:1 and the §9 components' dark chrome at
   ≈2.9:1 on the light forms; fixed with tone props, not test exemptions. The root blade
   hardcoded `lang="en"` (stale "until i18n lands" comment) — an Amharic render carried
   the wrong pronunciation tag for assistive tech.

### Decisions filed

- **A-2 in docs/ANSWERS-FROM-DOC-AUTHOR.md**: EC-02 says "next request 401" but Doc 01
  C-10 makes a dead session's staff-API answer a 404 indistinguishable from not-found —
  the modal's trigger is therefore client-side confirmation via the deadline probe,
  server responses unchanged.
- **§8 vs §13 focus collision resolved by the spec's own text**: wizard transitions focus
  the step heading (§13); the login challenge step auto-focuses the code input (§8 names
  it). Recorded in both components.
- **Focus is wired to `Transition@after-enter`**, not `nextTick` — with `mode="out-in"`
  the incoming step is not mounted when the state changes; the naive version passed on
  the first step and failed on every later one, which is exactly what the browser test
  caught.

### NOT DONE (recorded, not dropped)

- **AC-09 pixel baselines** — PARTIAL by design (owner ruling): functional + axe coverage
  of every §8 state exists at 360 px in en/am; visual regression attaches when the
  approved Claude Design screens land. Traceability row updated to `partial` with the
  named unblocker.
- **Avatar upload** (§8 invited profile) — needs the media pipeline (Docs 04/08).
- **Theming** — screens are neutral (logical properties per C-15); Doc 16 owns tokens.
- **FR-09 2FA role-enforcement policy** — still the one Doc 02 item outside this plan;
  needs Doc 03's roles.

### Registry corrections in this session

- **Doc02 T-04 traceability row**: the stale tail claiming the revocation half, session
  cap and timeouts were NOT BUILT (disproven by S9/S10) replaced with citations to
  `SessionRevocationSocketTest`, `session.spec.ts`, and `SessionLifetimeTest`; row now
  `covered`, and it records S17's expiry-redirect fix.
- **Doc02 AC-09 row**: `manual/not-implemented` → `automated/partial` against
  `tests/Browser/auth-screens.spec.ts` with the pixel-baseline unblocker named.

### Risks carried forward

- **The sentinel monkey-patches `window.fetch` on the staff Inertia surface.** Scoped,
  documented, and restored on unmount — but any future page that captures `fetch` before
  the sentinel mounts bypasses the EC-02 modal for its requests. Doc 07's app shell
  should mount the sentinel once at the root.
- **The EC-02 replay repeats the original request after re-login.** Idempotent for
  everything on today's surface; a future non-idempotent staff POST recovered through
  the modal would run once — after a failure the server never processed. Reviewed as
  safe today; Doc 07 should re-review when the composer sends messages.
- **The strength meter is advice, not policy** — deliberately weaker than the server
  (no breach/reuse check client-side); the copy never promises acceptance.
- **Amharic for every new string is machine-authored** — joins the standing
  native-review item.
- **Local browser runs need `docker compose cp public/build app:/app/public/`** after a
  frontend build (ADR 0002 pins `public/build` to the image's copy); CI is unaffected
  (it builds the image). Recorded here so the next session does not chase "stale
  screens" as an application bug.

## Session-18 report (Doc 02 CLOSE-OUT — the flaky a11y defect fixed, retry policy hardened, final 85-id self-audit)

**Evidence: pest 431 passed / 11 skipped (4306 assertions) in-stack; `make gates` 11/11;
Pint + PHPStan(max) + ESLint + vue-tsc clean; browser 25 passed ×3 CONSECUTIVE full runs
(1.6 m / 1.5 m / 1.4 m). Sabotage: the fixed attribute removed → the axe assertion red on
3/3 runs with the exact violation (`scrollable-region-focusable`, serious, target `.mt-6`);
restored → green. CI-proven on `66f2b63`: run
[30850307901](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30850307901),
Stages 1–6 green on first push — Stage 4 ran the 25-test browser suite with
`failOnFlakyTests` active — Stage 7 the by-design manual stub.**

### The defect: a real WCAG 2.1.1 violation that retries had been laundering green

The devices table wrapper (`resources/js/Pages/Security/Devices.vue`, the `overflow-x-auto`
div) scrolls horizontally at 360 px, and when the table holds ONLY the current session there
is no focusable cell inside it — the revoke button is other-rows-only — so keyboard users
could not scroll it at all (axe: `scrollable-region-focusable`, serious). Whether the suite
caught it depended on leftover Redis state: sessions accumulated by earlier runs put a
focusable revoke button in the region and the sweep passed. **Fix:** the wrapper is now
`role="region"` + `tabindex="0"` + an i18n `aria-label` (`auth.devices.table_region_label`,
en + am, through the controller's copy prop — no literal strings, N-03).

**Determinism:** the wizard test now seeds the single-current-session state (`clear-tenant`
BEFORE login makes that login the only session), asserts the seeded state before relying on
it (a failed seed fails on the state assertion, never silently hands axe the easy table),
then runs the sweep. Proven by sabotage: fix removed → red 3/3 consecutive runs; restored →
green.

**Retry policy (`playwright.config.ts`):** `retries: 2` had turned this violation into a
green CI run for a whole session — the flake passed on retry and nothing shouted. Retries
stay (trace-on-first-retry is the debugging value) but `failOnFlakyTests: true` (CI only,
Playwright 1.62) now fails the build loudly when any test only passed on a second attempt.
A flaky pass is a red build with a trace attached, never a green one.

### Audit finding the close-out surfaced: SEC-05 is NOT BUILT and was never recorded as deferred

SEC-05 (per-role IP allowlists, evaluated post-auth pre-session) depends on Doc 03's roles
exactly as FR-09 does — but unlike FR-09 it was never explicitly recorded. What exists:
`FailureReason::IpBlocked` is defined (FR-04's category enum) and nothing fires it; §4.8's
`auth.ip.blocked` event is absent from the catalog. **Doc 02 is therefore complete apart
from FR-09 AND SEC-05, both blocked on Doc 03's role resolver.** Recorded here so the
"apart from FR-09" summary cannot silently absorb a second open item.

### Final self-audit — every Doc 02 id (85: 61 DONE / 22 PARTIAL / 2 NOT DONE)

A DONE row means: code exists, tests exist, tests executed (this session's full-suite run
above), traceability maps it. A false DONE is Sev-1.

| Id | Status | Evidence / reason |
|---|---|---|
| FR-01 | DONE | LoginFlowTest (S4) + card journeys incl. 2FA step (auth-screens.spec.ts, S17) |
| FR-02 | DONE | EnumerationTest — byte-identical bodies, R-3 fixed verification budget with floor assertion (S4/S8) |
| FR-03 | PARTIAL | Lockout matrix + rolling window + per-IP throttle + reset-unlock all proven (LockoutTest S5, R-6 S12); admin unlock audited at the unit boundary only — its HTTP route is Doc 03's admin console (S5 record) |
| FR-04 | DONE | DeviceRegistrationTest + audit/event assertions; reason categories in `FailureReason` (`ip_blocked` defined, fires with SEC-05) |
| FR-05 | DONE | Distinct non-technical refusals, audited (S4; SuspendedTenantMatrixTest; UserDisableTest S13) |
| FR-06 | DONE | TwoFactorEnrolmentTest (S11) + §8 wizard with checkbox-OR-download gate, both halves falsified (S17) |
| FR-07 | DONE | TwoFactorChallengeTest — ±1 step, 5-fail void feeding lockout, single-use recovery, remaining count, ≤2 banner (S11/S17) |
| FR-08 | DONE | TwoFactorAdminResetController + FR-08 test: typed-name server-enforced, target sessions all die; `admin.2fa.reset` declared, enforced when Doc 03's resolver lands (the documented seam) |
| FR-09 | **NOT DONE** | Role-set enforcement + grace countdown need Doc 03's resolver; `auth.2fa.required_roles`/`grace_days` settings registered and validated (S11 record) |
| FR-10 | PARTIAL | Redis sessions, tenant prefix, cap + oldest-eviction falsified (S8/S9). **Gap the audit names: the session cookie's HttpOnly/Secure/SameSite=Lax flags are config with no executed assertion** (only the widget cookie is flag-asserted) — a one-test gap for the next session |
| FR-11 | DONE | Two-clock boundaries at exact minutes/hours (S9), middleware wired + falsified (S10), expiry now REDIRECTS with return-URL + notice — the S17 half-dead-200 fix |
| FR-12 | DONE | SecuritySessionsApiTest + session.spec.ts two-jar journeys (S10) + §8 screen (S17) + this session's keyboard-scrollable region fix |
| FR-13 | PARTIAL | Remember withheld until authentication is COMPLETE (2FA accounts — TwoFactorChallengeTest), honored only at establishment, never cycled at expiry, absolute cap applies to every session; **the recaller-path login itself has no dedicated test** |
| FR-14 | DONE | Password half: password.spec.ts (changer survives, sibling dies); 2FA half: TwoFactorEnrolmentTest FR-14 test (enable revokes others; disable/regenerate wired the same) |
| FR-15 | DONE | PasswordPolicyTest — settings-driven min/classes/history-N/max-age, BR-05 floor refused at the registry (S12) |
| FR-16 | DONE | PasswordResetFlowTest + ActionTokenTest + real-mail browser journey; dead-link screen; strength meter (S12/S17) |
| FR-17 | DONE | InvitationTest + browser activation via EC-04 resend; profile completion name+language (S17; §8's optional avatar deferred to the media pipeline, recorded S17) |
| FR-18 | DONE | ApiTokenLifecycleTest — scopes ∩ live permissions at request time, plaintext once, last-used, revocable; `auth.token.allow_no_expiry` registered (S13) |
| FR-19 | DONE | MachineTokenTest — service-account owner non-login twice over, rotation with overlap (SS-04 live) (S13) |
| FR-20 | DONE | Closed code set per ruling; revoked/disabled byte-identical to never-issued, `insufficient_scope` names the key (S13) |
| FR-21 | DONE | WidgetSessionTest — allowlist, ancestor checks, opaque 128-bit token, sliding 30 d, visitor-only surface (S14) |
| FR-22 | DONE | WidgetTokenTheftTest — rotating cookie pair, mismatch → silent re-issue, victim untouched (S14) |
| FR-23 | DONE | WebhookSecretTest — header secret, 403 + metric, unguessable keys (S15) |
| FR-24 | DONE | WebhookHmacTest — HMAC over ts+nonce+body, ±300 s, SET NX single-use nonce (S15) |
| FR-25 | DONE | CSRF-exempt (stack absence asserted), per-key throttle (two-keys isolation), sessionless (StartSession absence falsified) (S15) |
| NFR-01 | PARTIAL | TOTP verify 0.068 ms p99 / recovery 133 ms p99 measured in-suite (this run's output); the formal login-p99 release gate is Doc 19 L1 |
| NFR-02 | PARTIAL | O(1) Redis design; the ≤1 ms p99 measurement belongs to Doc 19 L1 (S13's recorded bucket, with the token-middleware budget) |
| NFR-03 | DONE | argon2id ~100 ms server cost measured live (114.7 ms p50 this run); annual parameter review is an ops cadence, noted |
| NFR-04 | DONE | GeoIP, breach check, socket-close all fail open with named metrics (S10/S12); webhook capacity protection fails open too (S15) |
| NFR-05 | DONE | The entire auth-screens browser suite runs at 360×740 (S17 + this session's deterministic a11y state) |
| NFR-06 | PARTIAL | Every implemented FR maps to executed tests via traceability; enumeration/lockout/scope run every push in CI — the formal "release gate" designation is Doc 19's plan |
| BR-01 | DONE | No team-login surface exists anywhere; cap + device visibility proven (S9/S10) |
| BR-02 | DONE | No password-like issuance parameter (reflection, S12); no readable secret/recovery columns (Q6 physics, S4); admin reset only clears (FR-08) |
| BR-03 | DONE | Machine tokens act as their visible service account in audit (S13) |
| BR-04 | DONE | One transaction + per-request backstops on both surfaces, each independently sabotaged (S13) |
| BR-05 | DONE | Platform minimums refused AT THE REGISTRY (PasswordPolicyTest; lockout not disableable) (S12) |
| BR-06 | DONE | Structural in both directions; users-provider swap sabotage set 8 tests red (S14) |
| T-01 | DONE | Fortify drives the flows with two recorded deviations: R-8 (`auth_action_tokens` over the email-keyed stock table) and S11 (stock 2FA actions unused — their re-display route contradicts SEC-03); theming is Doc 16's |
| T-02 | DONE | Guards strictly partitioned; MiddlewareOrderTest asserts the SORTED executed stack per surface (S13's lesson); RBAC hop is the Doc 03 seam |
| T-03 | PARTIAL | Fingerprint proven (upgrade ≠ new device); the new-device NOTICE + its toggleable setting defer to Docs 07/14's notification pipeline (S10 record) |
| T-04 | DONE | Tenant-prefixed keys (the S8 inert-handler find), revocation = key delete + publish, socket close proven ≤2 s (S10) |
| T-05 | DONE | `abilities ∩ EffectivePermissions` per request, 10 s cache falsified against the literal spec number (S13) |
| T-06 | DONE | CSRF 419 (S7), bearer statelessness-as-absence (S13), per-widget CORS + the framework-default `api/* → *` defect fixed (S14) |
| T-07 | PARTIAL | Full header set + nonce CSP + HSTS on every existing surface incl. error responses (S4/S14); the widget FRAME's `frame-ancestors` allowlist half is Doc 13's (EC-08 ruling) |
| T-08 | PARTIAL | In-process reader, fail-open, un-pins a broken handle; stub MMDB per ADR 0006 — production GeoLite2 delivery + weekly refresh are Doc 18's (ADR 0006 records it) |
| T-09 | DONE | k-anonymity 5-hex prefix asserted on the faked request, 500 ms timeout, fail-open + metric, tenant toggle (S12) |
| T-10 | DONE | All auth.* settings registered with platform minimums in validation (BR-05 floor test) |
| SEC-01 | DONE | Login (S4/S8), reset (S12), invite (S12) — response-diff AND timing with the floor assertion that caught a 16 ms delta ±50 ms would have passed |
| SEC-02 | DONE | ActionTokenTest, all four clauses + EC-04 supersede as index physics (S12) |
| SEC-03 | DONE | Hashed at rest (argon2id), no readable column CAN exist (Q6), shown exactly once incl. regeneration through the gated step (S11/S17) |
| SEC-04 | DONE | Both boundaries at the STORAGE level (old key gone, new key present) — the only observable that survived falsification (S8/S11) |
| SEC-05 | **NOT DONE** | Per-ROLE allowlists need Doc 03's roles — surfaced by THIS audit, recorded above; `ip_blocked` category defined and unfired; `auth.ip.blocked` absent from the catalog. Files alongside FR-09 |
| SEC-06 | DONE | Cleartext refused in testing AND simulated production; TLS validity asserted in-container (S4) |
| SEC-07 | DONE | NoImpersonationTest sweeps routes, all seven registries, and the file tree (S4) |
| SEC-08 | DONE | Log scrubber + serialization sweep over five sinks, 21+ sentinels, six sabotages, fail-closed gate (S16) |
| EC-01 | PARTIAL | ±1 step falsified at exact boundaries; recovery alternative proven; **the "help copy mentions time sync" sentence does not exist in the challenge copy** — a one-string gap (en+am) for the next session |
| EC-02 | DONE | Re-login modal over untouched state, replay proven, A-2 interpretation filed (S17) |
| EC-03 | PARTIAL by ruling | Cross-device redemption proven; the new-device notice defers with T-03's (S12 ruling) |
| EC-04 | DONE | Supersede as index physics + self-service resend screen, rate-limited (S12/S17) |
| EC-05 | PARTIAL | Disable revokes sessions+tokens atomically with independently-sabotaged backstops (S13); socket close rides the proven T-04 revoker; **"local drafts preserved" is Doc 07's composer** |
| EC-06 | DONE | Live intersection drops within the 10 s window; the stale-allow bound itself falsified (S13) |
| EC-07 | PARTIAL | TOTP tolerance proven below drift; NTP discipline + the >5 s alert are Doc 18 ops |
| EC-08 | PARTIAL by ruling | Server-side ancestor validation proven; the structural `frame-ancestors` half is Doc 13's (S14 ruling) |
| EC-09 | DONE | Fresh identity on cleared cookies; no-linking-capability asserted by reflection (S14) |
| EC-10 | DONE | Single-secret-wins; the superseded tab gets its own 409 copy (S11) |
| EC-11 | PARTIAL | Victim reset-unlock + attacker per-IP cap proven via HTTP (S12); the OPTIONAL `auth.captcha.after_failures` stays unregistered until a CAPTCHA decision exists — a setting nothing reads may not enter the registry (S11 record) |
| FS-01 | PARTIAL | Webhook + keymap outage postures proven against a real closed port (S15); staff auth is fail-closed by construction (no session store → no session), **but the maintenance SCREEN is unbuilt and the runbook is Doc 18's** |
| FS-02 | DONE | Dedicated `mail` family (ADR 0007), tries=6 widening backoff, never inline, locale pinned at dispatch (S12) |
| FS-03 | DONE | Timeout/503/unconfigured all fail open with the named metric, zero calls when off (S12) |
| FS-04 | DONE | Corrupt MMDB → login succeeds, IP-only row, i18n "location unavailable" (S10) |
| FS-05 | DONE | Nightly prune under the platform role (app has no DELETE — asserted), DB-clock criterion, survivors asserted (S13) |
| AC-01 | DONE | Both halves — login (S4/S8) + reset (S12), byte-identity and floor-asserted timing |
| AC-02 | DONE | Full matrix incl. window roll and boundary clocks (S5) |
| AC-03 | DONE | All four clauses, each falsified (S11) |
| AC-04 | DONE | Both halves; socket close measured ~0.3 s against the 2 s deadline, falsified via corrupted signature (S9/S10) |
| AC-05 | PARTIAL by ruling Q5 | Intersection + naming + ≤10 s propagation proven; the literal `tickets.read`/`tickets.reply` fixture waits for Docs 03/06 (S13) |
| AC-06 | DONE | Both layers, independence proven by REMOVAL of each (S14) |
| AC-07 | DONE | All three clauses, spy-proven "nothing processed" (S15) |
| AC-08 | DONE | All five sinks, per-sink positive controls, six sabotages (S16) |
| AC-09 | PARTIAL by ruling | Every §8 state functional + axe-swept at 360 px en/am — now DETERMINISTICALLY, incl. the single-current-session state (this session); pixel baselines await the approved design screens; theming awaits Doc 16 (S17) |
| R-01 | PARTIAL | Offline TOTP + downloadable/copyable codes shipped; the grace-period half is FR-09's |
| R-02 | PARTIAL | Named metrics on every fail-open path; alert WIRING is Doc 18's |
| R-03 | PARTIAL | Default expiries, last-used, nightly prune shipped; the quarterly unused-token report is Doc 15/18's (S13 record) |
| R-04 | PARTIAL | EC-11 controls proven; per-tenant lockout-RATE monitoring is a Doc 15/18 dashboard surface |
| R-05 | DONE | Auth state read from Redis per request, never worker memory; the S11 WeakReference fix with its forced-collision test; AC-04 covers |

### Registry / file deltas this session

`lang/en/auth.php` + `lang/am/auth.php`: +1 key (`devices.table_region_label`).
`SecurityPageController`: ships it as `tableRegionLabel`. `Devices.vue`: the region fix.
`auth-screens.spec.ts`: deterministic seed + state assertions + the named axe sweep.
`playwright.config.ts`: `failOnFlakyTests` on CI. Registries: **unchanged** (AC-09's
traceability row already maps `tests/Browser/auth-screens.spec.ts`; no new setting, no new
permission, no new gate).

### Risks / next-session pickups (each named above in its row)

1. **SEC-05 + FR-09** — the two open Doc 02 items, both Doc 03-blocked; Doc 03's plan must
   claim them or they are lost.
2. FR-10's session-cookie flag assertion (one test), EC-01's time-sync help sentence
   (one string, en+am) — two small honest gaps, cheap to close in any Doc 02-adjacent
   session.
3. `failOnFlakyTests` means a genuinely flaky NEW test now fails CI outright — that is the
   point, but the first person to hit it should read this entry, not weaken the config.
4. Standing items unchanged: machine-authored Amharic (native review), Doc 18 handoffs
   (volatile eviction, vault migration, GeoLite2 delivery), sentinel fetch-capture note for
   Doc 07.

## Deferred with reason

_(Claude Code appends here. Every deferral needs a spec id and a reason.)_

- **Doc 02 FR-09 (2FA role enforcement + grace countdown) and Doc 02 SEC-05 (per-role IP
  allowlists + `auth.ip.blocked`) — deferred to Doc 03, recorded at the S18 close-out.**
  Both are unevaluable until Doc 03's role resolver exists ("users in those roles", "per
  role, Doc 03"). What already exists: FR-09's settings registered and validated (S11);
  SEC-05's `FailureReason::IpBlocked` category defined. Doc 03's plan MUST enumerate both
  as inherited items — this line is the tripwire.

- **Published-port listener assertion in the stack smoke suite — ADOPTED by the owner
  2026-07-30, scheduled for S1 opening housekeeping, deliberately not done in S0.**
  `tests/Integration/StackSmokeTest.php` should assert that every port `docker/compose.yaml`
  publishes has a real host listener, so a container reporting `healthy` while its host-side
  `docker-proxy` silently failed to bind can never again pass unnoticed. Motivation is the
  cleared redis blocker below: for ~53 minutes `redis-volatile` showed
  `0.0.0.0:6379->6379` and `healthy` while `*:6379` was unbound and the native daemon served
  every host connection. The healthcheck runs *inside* the container and structurally cannot
  see this.

  Reason for deferral, not a silent drop: S0's scope closed CI-proven on `af9562d`, and this
  is new test surface no S0 document asks for — adding it here would be the "while I was in
  there" work CLAUDE.md §0.5 prohibits. It carries no spec id yet; S1 should give it one
  under Doc 18/19 coverage when it lands. Owner's ruling: it belongs to S1's opening
  housekeeping, before Doc 01 implementation begins.

## Open blockers

_(Claude Code appends here. Only the owner can clear these.)_

_None open._

## Cleared blockers

- **CLEARED 2026-07-30 — Dev host: native `redis-server` occupying port 6379.** Open from
  2026-07-27; reported disabled three times and verified otherwise four times before it actually
  went. The owner ran `sudo systemctl disable --now redis-server` in an interactive Ubuntu terminal
  (the only path that works — see the root cause below), then the port was reclaimed from this
  session. Verified after the fact, not on report:

  | Check | Before | After |
  |---|---|---|
  | `systemctl is-active redis-server` | `active` | **`inactive`** (`SubState=dead`) |
  | `systemctl is-enabled redis-server` | `enabled` | **`disabled`** (`UnitFileState=disabled`) |
  | enable symlink in `multi-user.target.wants/` | present, mtime 2026-04-06 18:03:48 | **gone** — `No such file or directory` |
  | `ps` for `/usr/bin/redis-server` | PID 179, 50 h uptime | **no match** |
  | `ss -ltn` on 6379 | `127.0.0.1:6379` (native only) | **`*:6379`** (docker-proxy bound) |

  Port reclaimed with `docker compose -f docker/compose.yaml up -d --force-recreate redis-volatile`
  — the recreate was necessary because the proxy had failed to bind at original start and does not
  retry. Host `127.0.0.1:6379` now provably reaches the container, by matching `run_id`:

  | Property | was (native) | now (redis-volatile) |
  |---|---|---|
  | `redis_version` | `7.0.15` | `7.4.10` |
  | `executable` | `/usr/bin/redis-server` | `/data/redis-server` |
  | `config_file` | `/etc/redis/redis.conf` | `/usr/local/etc/redis/redis.conf` |
  | `maxmemory-policy` | `noeviction` | `allkeys-lru` |
  | `maxmemory` | unset | `268435456` (256 mb) |
  | `run_id` on host `:6379` | `985f883f…` | `5fc059e5b1d89436282972addd2b6b9df13c6624` |
  | `run_id` inside the container | — | `5fc059e5b1d89436282972addd2b6b9df13c6624` — **identical** |

  Instance separation (CLAUDE.md §3) re-verified intact afterwards: `:6380` is a distinct server
  (`run_id c22971f2…`) with `appendonly yes` / `noeviction`, against volatile's `appendonly no` /
  `allkeys-lru`. All 14 stack services `running / healthy`; `make test` 8 passed / 33 assertions,
  including `both redis instances respond and are distinct servers with opposite …`.

  **Root cause of the three false reports — confirmed by direct test, and it is a standing
  constraint on this host, not a one-off.** `sudo` here requires a password and **no shell reachable
  from Claude Code is a TTY**, by either path:

  ```
  # Claude Code tool shell
  sudo -n true
  → sudo: a password is required                                        (exit 1)

  # owner typing `! sudo systemctl disable --now redis-server` at the Claude Code prompt
  → sudo: a terminal is required to read the password; either use the -S option to read
    from standard input or configure an askpass helper
  → sudo: a password is required
  ```

  So every in-session attempt died at the sudo layer before systemd was involved — which is exactly
  why the unit's 50-hour uptime stayed unbroken across three "already disabled" reports. A second
  failure mode was observed directly: a command line pasted at the prompt **without** a leading `!`
  never executes at all, it arrives as chat text. An earlier guess in this entry blamed a different
  WSL distro; that was wrong (hostname `Kidus`, `WSL_DISTRO_NAME=Ubuntu`, confirmed at the console).

  **Standing rules that follow (apply to every future session):**
  1. Privileged host changes on this machine are owner-only, executed in a real terminal. Never ask
     for `! sudo …` — it cannot work.
  2. Never work around it with `sudo -S` piping a password in: that serializes the owner's password
     into the transcript and session logs, violating C-13.
  3. Never treat a report of a completed host change as evidence. Verify the *effect* — unit state,
     symlink presence, process uptime, and a `run_id`-level identity check on the service itself.
  4. A `healthy` container does not mean its published port works: the healthcheck runs *inside* the
     container and cannot see a failed host-side proxy bind. Check `ss -ltn` for a real listener.
