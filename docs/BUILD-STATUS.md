# BUILD STATUS

Updated by Claude Code at the end of every session. The owner reads this file to know where the
project actually stands. A row is only `DONE` when its acceptance criteria have executed tests.

| Doc | Module | Status | Session | ACs proven | Notes |
|---|---|---|---|---|---|
| — | Repo bootstrap + CI gates | DONE (CI-proven) | S0 | smoke 6/6, gates 8/8, browser 2/2 | Laravel 13.23; 14-service stack healthy; 8 gates live (3 bootstrap-warn until first registry entry); 4 gates falsified+restored. **Proven by CI run [30393265519](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30393265519) on `2990d33` — Stages 1–6 all green** (Stage 3 integration 6 passed/33 assertions, Stage 4 browser 2 passed). Stage 5 is a placeholder until Doc 19 L1; Stage 7 (prod deploy stub) is `skipped` by design — manual approval, never yet executed. Re-confirmed on the ADR-0002 bind-mount commit: run [30492124123](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30492124123) on `af9562d`, Stages 1–6 green, Stage 7 skipped |
| 01 | Platform Foundation & Multitenancy | **BUILT — final audit below** | S1–S3 | 6/8 + AC-04 & AC-07 partial | Wrap-up (S3) added: EC-10 maintain+monitor (incl. per-partition RLS the S1 migration promised), BR-05 policy+store+suspended_at, GrantPhysicsTest (hand-run claims → executable assertions; caught 2 real defects). **Final 69-id audit: 37 DONE / 30 PARTIAL / 2 NOT DONE — see the S3 section.** Remaining ids block on other docs (AC-03→04/06, SEC-04→18, seams→03/10/12, L1→19) or the design-led console |
| 02 | Authentication, Sessions & Security Core | NOT STARTED | S2 | — | |
| 03 | RBAC, Roles, Permissions, Overrides | NOT STARTED | S3 | — | |
| 04 | Conversations & Messaging Core (fake adapter) | NOT STARTED | S4 | — | |
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

## Deferred with reason

_(Claude Code appends here. Every deferral needs a spec id and a reason.)_

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
