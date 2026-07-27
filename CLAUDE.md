# CLAUDE.md — Project Constitution (read this in full at the start of EVERY session)

> **Project:** Na Live Support — an AI-first, white-label customer support platform for betting operators.
> **Market:** Ethiopia first (Amharic + English, Telegram-dominant, local SMS gateways).
> **Owner:** non-programmer acting as product owner and judge. You are the engineering team.
> **This file overrides your defaults.** If anything you are about to do conflicts with this file, stop and say so.

---

## 0. How to work in this repo

1. **The specs in `/docs` are law.** Nineteen numbered documents, each with a fixed 20-section
   structure (§1 Executive Summary → §20 Risks/§21 Recommendations). Section **18 is the
   Acceptance Criteria** — those are your test checklist, not a suggestion.
2. **Never invent requirements, never simplify a spec, never silently drop a requirement.**
   If a spec is ambiguous, wrong, or impossible, **stop and ask** — do not choose for yourself and
   move on. Write the question as: `BLOCKER: Doc NN FR-XX says A, but Doc MM T-YY implies B. Which?`
3. **Never claim something is done that you have not proven.** "Done" means: code exists, tests
   exist, tests were executed in this session, and you pasted the passing output. A green claim
   without pasted output is a lie and will be treated as one.
4. **One module per session.** Do not start the next document because you have context left.
   Finish, self-audit, report, stop.
5. **No scope creep.** Do not add libraries, abstractions, admin panels, or "while I was in there"
   refactors that no document asks for. Every new dependency requires an ADR in `/docs/adr`.
6. **Small, reviewable commits** with conventional-commit messages referencing the spec:
   `feat(foundation): tenant context middleware (Doc01 T-05)`.

### Session protocol (follow in order, every time)

```
STEP 1  Read CLAUDE.md (this file) + the target document in /docs, end to end.
STEP 2  Produce a PLAN: enumerate every FR / NFR / T / SEC / EC / FS / AC id in that
        document, and for each, name the file(s) you will create and the test that proves it.
        Present the plan as a table. WAIT for approval before writing code.
STEP 3  Implement, in dependency order, committing at logical checkpoints.
STEP 4  Write the tests. Run them. Paste the real output.
STEP 5  Run the full gate suite (`make gates`). Paste the real output.
STEP 6  SELF-AUDIT: re-read the document's §18 Acceptance Criteria one by one and produce a
        completion table: | AC id | test path | status (PASS / FAIL / NOT-IMPLEMENTED + why) |.
        Honest NOT-IMPLEMENTED rows are acceptable. A false PASS row is a Sev-1 defect.
STEP 7  Update /docs/BUILD-STATUS.md and the six registries. Stop and report.
```

---

## 1. The non-negotiables (structural invariants)

These are not preferences. Each must be enforced by **code or database physics**, not by developer
discipline, and each must have a test that fails if it is ever removed.

| # | Invariant | Enforced by |
|---|---|---|
| N-01 | No cross-tenant read or write is possible, ever | Eloquent global scope **and** PostgreSQL RLS (two independent layers) |
| N-02 | `tenant_id` is never accepted from client input (body/query/header) | Resolver reads server-controlled context only; fuzz test AC-02 |
| N-03 | Nothing user-visible is hardcoded — no literal strings, colors, brand names, or copy | CI `string-lint` gate + hostile-theme/pseudo-locale sweep |
| N-04 | Internal notes are **structurally undeliverable** to a customer | Notes are a different type with no adapter path; test asserts no send route exists |
| N-05 | Tickets are never hard-deleted in v1 | Delete permission exists but is platform-disabled; erasure workflow (Doc 17) is the only path |
| N-06 | No customer identity verification. Fields are `staff-collected`, **never** `verified` | Test asserts no verified-badge component or column can exist |
| N-07 | AI never states transaction facts and always answers money/policy questions with KB citations, or hands off | Grounding-or-handoff pipeline + transaction-fact trap fixtures |
| N-08 | AI always discloses it is an AI; disclosure is code-shipped, hash-logged, not operator-removable | No setting key exists to disable it; test asserts absence |
| N-09 | Copilot never auto-sends | Copilot output is draft-only; no send path from the suggestion object |
| N-10 | Responsible-gambling (RG) responses are **template-only** — never generated text | RG branch cannot reach the generation service; test asserts |
| N-11 | RG routing is final — nothing diverts a flagged player out of the RG queue | Routing pipeline short-circuit + determinism harness |
| N-12 | The 30-minute starvation cap is absolute (tunable, never removable) | No config path to `null`/disable; property test |
| N-13 | Audit log is append-only **by database grants**, hash-chained per tenant-day | `REVOKE UPDATE, DELETE` on the app role; "immutability physics" test asserts the grant |
| N-14 | Spam and test traffic never count in any metric | Exclusion applied in the metric dictionary, once; four-surface parity test |
| N-15 | SMS is notification-only — it is never a conversation channel | No inbound-SMS→conversation route exists; guard truth table |
| N-16 | Opt-out is absolute | Guard function checked before every send, with no bypass parameter |
| N-17 | The widget has zero host-page DOM access | iframe isolation; hostile-host test page runs on every PR |
| N-18 | The contrast validator has **no override** | Validator is a hard gate in the theme save path |
| N-19 | Feature flags hide, they never destroy data | Flag checks guard rendering/routing only, never migrations or deletes |
| N-20 | No `if (channel === 'telegram')` conditionals in domain code | CI `no-channel-conditionals` grep gate; all channel behavior lives behind the Channel Adapter contract |

**If a task appears to require breaking one of these, it is the task that is wrong. Stop and say so.**

---

## 2. The six registries (the platform's constitution)

Every registry is a code-registered, machine-readable file that CI cross-checks. A feature that is
not registered **cannot ship**.

| Registry | File | Rule enforced in CI |
|---|---|---|
| Permissions (~118 keys) | `registries/permissions.php` | Every route/action maps to a registered permission key; unmapped route = build failure |
| Settings | `registries/settings.php` | Every setting has key, type, default, validation, `tenant_editable`; no unregistered setting may be read or written |
| Notification categories | `registries/notifications.php` | Every notification belongs to a registered category with its 3-layer opt model |
| Audit coverage matrix | `registries/audit-coverage.php` | Every state-mutating action has an audit entry; a mutation without one = build failure |
| CI gate inventory | `registries/gates.yaml` | Each gate maps to its owning doc's AC; skipping a gate requires a signed change file the pipeline verifies |
| Test traceability matrix | `registries/traceability.yaml` | Every AC in Docs 01–19 maps to ≥1 automated test or a justified manual/drill entry; unmapped AC = build failure |

---

## 3. Stack (decided — do not re-litigate)

| Layer | Choice | Notes |
|---|---|---|
| Language | PHP 8.3+ | strict types everywhere |
| Framework | Laravel (latest stable) + **Octane/FrankenPHP** | record the exact version in `README.md` |
| DB | PostgreSQL 16 | RLS multitenancy, JSONB, partitioned `messages` / `events` / `audit` |
| Cache/queues | Redis 7, **two instances** | `redis-volatile` (cache, presence, rate limits, `allkeys-lru`) vs `redis-durable` (queues, outbox, timers, `appendonly yes`) — never merge them |
| Queues | Horizon, **per-family supervisors** | telegram-in/out, webchat-in/out, media, ai, sms, automation, reports, outbox-relay, audit |
| Realtime | Laravel Reverb | pub/sub backplane on redis-volatile |
| Search | Meilisearch (Scout) | Amharic corpus tested |
| Flags | Laravel Pennant, tenant-scoped | |
| Auth | Fortify + Sanctum, TOTP 2FA | no impersonation feature |
| Agent app | Inertia + Vue 3 + TypeScript + Tailwind | desktop-primary, mobile-capable |
| Widget | **Standalone Preact iframe bundle** | separate build, separate CDN channel, no Laravel assets |
| Telegram | Nutgram | Business connected-bot mode primary |
| Tests | Pest (PHP), Playwright (browser), axe-core (a11y) | |
| Static analysis | PHPStan (max level), Laravel Pint, ESLint + Prettier | |

**Hard stack rules**

- **SMPP is never implemented in PHP.** Local-telco SMPP runs in a Go or Node sidecar behind a
  private HTTP API with mTLS. PHP talks HTTP to it.
- **AI / OCR / transcription are external APIs called from queued jobs**, never inline in a request.
- All third-party egress goes through the **egress proxy** with a per-destination allowlist.

---

## 4. Global conventions (Doc 01 §10 — apply everywhere, no exceptions)

| Id | Convention |
|---|---|
| C-01 | **UUIDv7** primary keys on every table. Human-facing numbers (ticket numbers) come from per-tenant counters in `tenant_sequences` via `UPDATE … RETURNING` inside the owning transaction |
| C-02 | Every tenant-scoped table has non-null `tenant_id`. Exceptions: the `tenants` table and `ref_*` platform reference data |
| C-03 | All timestamps `timestamptz`, stored **UTC**. Timezone applied at presentation only |
| C-04 | API errors are **RFC 9457 problem-details**: `{type,title,status,code,detail,errors{},trace_id}`. `code` values are stable strings from a shared enum |
| C-05 | **Cursor pagination only** (`cursor`, `limit ≤ 100`, `next_cursor`). Offset pagination is prohibited on unbounded collections |
| C-06 | Queue dispatch is `afterCommit` **by default** (bus-level config). Domain events go through the **transactional outbox**, never direct dispatch |
| C-07 | Every cache key is prefixed `t:{tenant}:`, every cache entry has a mandatory TTL, cache is never the source of truth, and every cached aggregate documents its invalidation event |
| C-08 | Every user-visible string is an i18n key. English + Amharic at launch. Fallback: user language → tenant default → English |
| C-09 | Consumers are **idempotent on `event_id`**. At-least-once delivery is assumed everywhere |
| C-10 | Cross-tenant lookups return **404, not 403** (no tenant-existence probing) and increment a security metric |
| C-11 | Ordering decisions use DB time or UUIDv7 monotonicity — **never app-server wall clocks** |
| C-12 | Migrations are **expand-contract only**: add-nullable → backfill → enforce, in separate releases; drops come two releases after deprecation |
| C-13 | Secrets live in the vault; the DB stores vault references. Secrets never serialize into jobs, logs, or events |
| C-14 | Logs are structured JSON carrying `tenant_id` + `trace_id`; `correlation_id` flows from the originating request through every resulting job and event |
| C-15 | RTL-safe markup from day one (logical CSS properties), even though launch languages are LTR |

---

## 5. Repository layout

```
/app                      Laravel application
  /Domain/<Module>        domain logic per module (Foundation, Auth, Rbac, Conversations, …)
  /Http                   controllers, middleware, requests, resources
  /Jobs, /Listeners       queued work, per family
  /Support                shared kernel: TenantContext, Outbox, Settings, Ids, Problem
/registries               the six registries (see §2)
/database/migrations      expand-contract, linted
/docs                     the 19 specification documents  ← THE LAW
  /adr                    architecture decision records (one per deviation or new dependency)
  BUILD-STATUS.md         what is built, what is proven, what is open
/resources/js             Inertia + Vue 3 agent app
/widget                   standalone Preact iframe widget (separate build)
/sidecars/smpp            Go/Node SMPP sidecar
/tests                    Pest: Unit, Contract, Integration, Isolation, Security, Property
/tests/Browser            Playwright journeys, a11y sweeps, hostile-host page
/fixtures                 golden corpora (lifecycle, conversations, RG, encoding, Amharic, hostile-theme)
/ci                       gate scripts: string-lint, no-channel-conditionals, migration-safety, registry cross-checks
/docker                   compose stack + per-service configs
Makefile                  every command the owner needs, one word each
```

---

## 6. Definition of Done (a module is not done until every line is true)

- [ ] Every FR, NFR, T-, SEC-, EC-, FS- id in the document is implemented **or** listed in
      `BUILD-STATUS.md` as explicitly deferred with a reason.
- [ ] Every §18 AC has an automated test; the traceability registry maps AC → test path.
- [ ] Every new permission key is in `registries/permissions.php` and every route is mapped.
- [ ] Every new setting is in `registries/settings.php` with type, default, and validation.
- [ ] Every state-mutating action appears in `registries/audit-coverage.php` and emits an audit entry.
- [ ] Every user-visible string is an i18n key with `en` and `am` entries. `string-lint` passes.
- [ ] Migrations pass the migration-safety linter and are expand-contract.
- [ ] PHPStan (max), Pint, ESLint all clean.
- [ ] `make gates` is green, with the output pasted into the session report.
- [ ] `docs/BUILD-STATUS.md` updated.
- [ ] The self-audit table (Step 6) is produced with honest statuses.

---

## 7. Build order (do not deviate)

```
S0   Repo bootstrap + Docker stack + CI gate skeleton + the six empty registries
S1   Doc 01  Platform Foundation & Multitenancy       ← freeze T-01…T-05 interfaces here
S2   Doc 02  Authentication, Sessions & Security Core
S3   Doc 03  RBAC (118 permissions, scopes, overrides) ← must be built fully; cannot be retrofitted
S4   Doc 04  Conversations & Messaging Core  — WITH THE **FAKE ADAPTER** ONLY, no real channel
S5   Doc 06  Ticketing System
S6   Doc 05  Telegram Channel (Business + Direct)
S7   Doc 07  Unified Inbox & Agent Workspace
S8+  Doc 08 → 09 → 10 → 11 → 12 → 13 → 14 → 15 → 16 → 17, with 18/19 applied continuously
```

Rationale (do not "optimize" it): Doc 04 is built against a **fake channel adapter** so the
messaging core is proven before Telegram's real-world mess arrives; Doc 06 before Doc 05 so tickets
exist before the first real channel produces them.

**MVP = Docs 01–07 + a conservative Doc 09.** Telegram only. The widget is Wave 2.

---

## 8. Things you must never do

- Never write `if ($channel === 'telegram')` in domain code. Use the Channel Adapter contract.
- Never bypass the outbox to dispatch a domain event directly.
- Never add a `verified` badge, column, or concept for a customer.
- Never add a setting that disables AI disclosure, the starvation cap, four-eyes erasure approval,
  or the contrast validator.
- Never delete, weaken, skip, or `--no-verify` a CI gate. Gates are removed only through the signed
  change-file process.
- Never let a test assert on `now()` — inject a clock.
- Never put production data in a fixture. All fixtures are synthetic; phone numbers use
  `+251-90000xxxx` and usernames use `@fixture…` so a leak is self-identifying.
- Never mark work complete without pasted, real test output.
- Never say "this is production-ready". Say what is proven and what is not.

---

## 9. Honest-reporting contract

At the end of every session, report in exactly this shape:

```
BUILT:        <files/features, each with its spec id>
PROVEN:       <tests written AND executed, with pasted output>
NOT DONE:     <spec ids deliberately deferred, and why>
BLOCKERS:     <questions only the owner can answer — real-world credentials, decisions>
RISKS:        <what worries you about what you just wrote>
NEXT SESSION: <the single next instruction the owner should paste>
```

The owner is not a programmer. He cannot catch an over-claim by reading the code — only by running
the tests. Over-claiming is therefore the single worst thing you can do in this repo.
