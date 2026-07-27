# SESSION 01 — DOC 01: PLATFORM FOUNDATION & MULTITENANCY
### Paste this whole file as your first message to a **fresh** Claude Code session.

---

You are the principal engineer and platform architect for **Na Live Support**. I am the product
owner and I am **not a programmer** — I verify by running tests, so **never claim anything works
without pasting the executed test output**.

**Read, in full, before writing any code:**
1. `CLAUDE.md` (the project constitution)
2. `docs/Doc01-Platform-Foundation-Multitenancy.md` (this session's law, all 21 sections)
3. `docs/BUILD-STATUS.md` (what Session 0 left you)

This is **the most important session in the project**. Doc 01 §21 says it plainly: build T-01…T-05
first and freeze their interfaces — every other module builds on top of them from here on. A
tenancy defect found in month four is not fixable; it is a rewrite. Treat isolation as the deliverable
and everything else as supporting cast.

---

## SCOPE

Implement **all of Doc 01** — FR-01…FR-11, NFR-01…NFR-08, T-01…T-10, SEC-01…SEC-07, EC-01…EC-10,
FS-01…FS-05 — and prove it against AC-01…AC-08.

**Out of scope this session** (do not touch): authentication mechanics (Doc 02), permission checks
(Doc 03), any channel (Docs 05/13/14), Backoffice UI surfaces (Doc 16). Where Doc 01 defines a
*contract* those documents consume, build the contract and stub the consumer.

---

## THE EIGHT THINGS THAT MUST EXIST WHEN YOU ARE DONE

### 1. Tenant model & lifecycle (FR-01, FR-05, FR-06, BR-01, BR-05)

- `tenants` table with UUIDv7 pk, name, state, region, default language, timezone, plan flags, quotas.
- Lifecycle state machine: `provisioning → active → suspended → archived`, implemented as **one
  transition service with a database-level invariant** — no other code path may change the state.
- **Suspended semantics are the subtle part**: staff logins blocked; inbound channel traffic still
  **accepted and queued (nothing lost)**; **no outbound sends**; a non-dismissable banner on any
  permitted surface. Beyond 30 days (platform-configurable) inbound is still ACKed but stored in
  cold summary form (count + last-message pointer) per BR-05.
- **Archived**: read-only export for platform admins only; all keys revoked.
- Provisioning is an **idempotent job** (idempotency key = tenant id + step) creating: tenant row,
  default settings set, default roles (leave a seed hook for Doc 03), default SLA policy, default
  business hours, empty KB with starter templates (hook), and the audit entry `tenant.provisioned`.
  It must check tenant state **between steps** and halt cleanly if suspended mid-flight (EC-04),
  and never leave a tenant partially `active` (FS-04).

### 2. Tenant resolution (FR-03, FR-04, SEC-06)

One `TenantResolver` with exactly six entry paths, **all server-controlled**:
staff session/Sanctum token · public API key · Telegram webhook bot key · widget key ·
SMS DLR gateway key · queued job (tenant id serialized at dispatch; **a job with no tenant context
fails before its handler runs**).

- `tenant_id` is **never** read from body, query, or header. Anywhere. (FR-04)
- Key lookups are Redis-cached to meet the ≤1 ms p99 budget (NFR-02).
- Mismatched-tenant lookups return **404, not 403**, and increment a security metric (SEC-06).

### 3. Two-layer isolation (T-01, NFR-01, SEC-01) — **the deliverable**

**Layer 1 — application:** a `BelongsToTenant` trait applying a global Eloquent scope reading from
`TenantContext`; auto-fills `tenant_id` on create; **throws** on save with a mismatched tenant id.

**Layer 2 — database:** RLS on every tenant table:
`USING (tenant_id = current_setting('app.tenant_id')::uuid)`.
The app sets `app.tenant_id` **on connection checkout and clears it on release**. Under Octane,
connections persist — this is the #1 risk in the whole project (R-01, EC-01). Write the
checkout/release hooks **and** the test that asserts the variable is empty on a fresh checkout.

A dedicated `platform_admin` DB role bypasses RLS, used **only** by the platform console service
account and retention jobs, with actor identity logged (SEC-02). Application tenant traffic must be
unable to reach it — prove that with a test.

**RLS is on in local dev too.** Parity is not negotiable (SEC-01).

### 4. TenantContext propagation (T-05)

Four bindings: HTTP middleware · job middleware (tenant id embedded at dispatch by a Bus pipe) ·
Reverb channel-auth callback · Artisan command option. Any non-platform code reading models without
a bound context throws `MissingTenantContext`.

### 5. Settings engine (FR-07, T-04, EC-06)

- `registries/settings.php` is the code-side registry (key, type, default, validation rule,
  `tenant_editable`). `tenant_settings` table holds overrides `(tenant_id, key, value jsonb, updated_by)`.
- Types: `string, int, bool, json, encrypted-string` (encrypted via Laravel encrypted casts, with
  key-rotation support).
- Read path: Redis hash per tenant `settings:{tenant}` — **≤0.5 ms p99**, registry cold-load ≤50 ms.
  Write path invalidates and republishes; propagation ≤2 s across nodes (NFR-03).
- **Every change is audited old → new.**
- Concurrent writes: last-write-wins with an optimistic `updated_at` check; **both** writes audited
  and a conflict signal returned to the loser (EC-06).
- Redis down → fall back to DB reads, degraded and logged, never failed (FS-01).
- Reading or writing an **unregistered** key is an error, not a silent pass.

### 6. Domain event bus + transactional outbox (FR-08, T-03, T-06, NFR-04, FS-02)

- Publishing an event = **inserting an `outbox` row inside the business transaction**. Never a
  direct dispatch. Adds ≤5 ms p99 to the publishing transaction.
- An `outbox-relay` Horizon worker reads batches, pushes to the event queues, marks relayed, and
  **resumes from the last relayed id** after a crash — no loss, no duplicates in effect, because
  consumers are **idempotent on `event_id`** (C-09).
- `events` table: `id (uuidv7), tenant_id, name, payload jsonb, occurred_at, correlation_id,
  causation_id`, **monthly partitions**, pre-created **3 months ahead**, with a monitor asserting
  future partitions exist (EC-10). Retention: raw 13 months rolling, aggregates forever (NFR-07).
- `correlation_id` flows from the originating request through every resulting job and event.
- Outbox payloads **exclude PII beyond ids** wherever a subscriber can re-fetch by id (SEC-07).
- Publish a **living event catalog** (name, payload schema, producers, consumers) checked in CI —
  this becomes the integration contract for Docs 04–17 (Doc 01 §21).

### 7. Conventions made mechanical (T-02, T-07, T-08, T-09, T-10, FR-09, FR-10, FR-11)

- **UUIDv7** everywhere; `tenant_sequences` per-tenant counters via `UPDATE … RETURNING` for
  human-facing ticket numbers.
- **RFC 9457 problem-details** error responses with a shared stable `code` enum
  (`quota_exceeded`, `reply_window_expired`, …) and `trace_id` matching logs.
- **Cursor pagination** helper (`cursor`, `limit ≤ 100`, `next_cursor`); offset pagination
  prohibited on unbounded collections — add a lint or test that enforces it.
- Cache convention helper enforcing the `t:{tenant}:` prefix and a mandatory TTL.
- Seeded, platform-versioned reference tables: `ref_languages`, `ref_countries`, `ref_timezones`,
  `ref_currencies`. Additive-only seeds within a release; removals need two-release deprecation (EC-07).
- The i18n layer + the `string-lint` CI gate flipped from Session 0's warn mode to **blocking**.
- A **feature-flag registry** (Pennant, tenant-scoped) that modules register keys into, so Doc 16's
  Backoffice can enumerate and toggle them with no code knowledge.

### 8. Quotas & observability hooks (NFR-06, FS-05, SEC-05)

Per-tenant quotas: messages/min, AI tokens/day, storage GB, API req/min. Exceeding returns
**429 `quota_exceeded` naming the quota**; an internal alert fires at **80%**. Counters live in
Redis with a **periodic DB reconciliation job** that repairs drift and logs deltas (FS-05). Lowering
a quota below current usage takes **no destructive action** — future writes blocked, alert raised,
grace policy configurable (EC-09). Rate limiting happens **before** expensive work (SEC-05).

---

## THE TESTS THAT DECIDE WHETHER THIS SESSION SUCCEEDED

Put these in `tests/Isolation/` and wire them into `registries/gates.yaml` as **blocking gates from
this session forward**. These run on every deploy, in every environment, for the life of the project.

| AC | Test |
|---|---|
| **AC-01** | For **every** tenant-scoped table: authenticate as tenant A, attempt read and write of a tenant B row through **every public endpoint touching that table** → expect 404/none. Then direct SQL as the app role under A's context selecting B's rows → **0 rows**. Generate this from the schema so new tables are covered automatically |
| **AC-02** | Fuzz: 500 requests with forged `tenant_id` in body, query, and header → **zero** effect on resolution, proven via trace ids |
| **AC-03** | Kill each worker class mid-burst → after drain, **no lost** tickets/messages/events; counts reconcile |
| **AC-04** | Outbox chaos: crash the relay between DB commit and queue push → the event is still delivered exactly-once-in-effect |
| **AC-05** | Run provisioning **twice concurrently** → one consistent tenant |
| **AC-06** | Every foundation string renders in Amharic with **zero** key leakage; `missing.translation` telemetry = 0 for shipped keys |
| **AC-07** | p99 latency budgets (§12) verified under load profile L1, with the numbers pasted as evidence |
| **AC-08** | Suspended-tenant matrix: staff login, API, webhook, widget, DLR — each behaves exactly per FR-05 |

Plus the named edge-case tests: **EC-01** (Octane leak: `app.tenant_id` empty on fresh checkout),
**EC-02** (`afterCommit` default), **EC-05** (double provisioning trigger), **EC-08** (no app-server
wall clocks in ordering decisions), **EC-10** (future partitions exist).

---

## HOW TO SEQUENCE IT

```
1  Migrations: tenants, tenant_settings, tenant_sequences, outbox, events (partitioned), ref_* tables
2  RLS policies + the platform_admin role + connection checkout/release hooks   ← do this SECOND, not last
3  TenantContext + BelongsToTenant trait + MissingTenantContext
4  TenantResolver (all six paths) + middleware + job middleware + Reverb callback + Artisan option
5  The isolation suite (AC-01, AC-02, EC-01) — write it HERE, before the features, and watch it pass
6  Settings engine + registry + cache + audit hook
7  Outbox + relay worker + events table + event catalog
8  Lifecycle state machine + idempotent provisioning job
9  Quotas + reconciliation + the problem-details/pagination/cache conventions
10 The remaining ACs, then the full gate suite
```

Step 5 before step 6 is deliberate. If isolation is not proven before features exist, it never gets
proven at all.

---

## CONSTRAINTS

- Do not implement Doc 02 or Doc 03. Where you need a user or a permission, stub the interface and
  leave a documented seam.
- Do not build the platform console UI. Doc 01 §8/§9 define its contract; build the API and the
  contract test, note the UI as deferred to a design-led session.
- Every user-visible string is an i18n key with `en` **and** `am` entries.
- Every setting you add goes in the settings registry. Every state change goes in the audit-coverage
  registry. Every AC goes in the traceability registry. `make gates` enforces this.
- If Doc 01 conflicts with something you believe is better practice, **say so and wait** — do not
  quietly deviate. If I agree, we write an ADR.

---

## HOW TO FINISH

1. `make test`, `make gates`, `make lint` — paste **real** output for each.
2. Paste the AC-07 latency numbers against the §12 budgets, as a table with pass/fail per budget.
3. **Prove the isolation suite actually catches things**: deliberately remove the global scope,
   show AC-01 failing (caught by RLS alone), restore it; then deliberately disable RLS on one table,
   show AC-01 failing (caught by the scope alone), restore it. Paste both. This is how I learn that
   the two layers are genuinely independent rather than one layer wearing two hats.
4. Self-audit table: every FR / NFR / T / SEC / EC / FS / AC id in Doc 01 with
   `DONE` / `PARTIAL` / `NOT DONE` and a one-line reason for anything not `DONE`.
5. Update `docs/BUILD-STATUS.md` and all six registries.
6. Report in the `CLAUDE.md` §9 shape: BUILT / PROVEN / NOT DONE / BLOCKERS / RISKS / NEXT SESSION.

**Before writing any code: give me your PLAN — a table of every Doc 01 id, the file(s) that will
satisfy it, and the test that will prove it — and wait for my approval.**
