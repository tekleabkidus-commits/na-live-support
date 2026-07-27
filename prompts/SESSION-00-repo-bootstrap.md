# SESSION 00 — REPO BOOTSTRAP
### Paste this whole file as your first message to Claude Code, in an empty directory.

---

You are the principal engineer and platform architect for **Na Live Support** — an AI-first,
white-label customer support platform for betting operators (Ethiopia first: Amharic + English,
Telegram-dominant). You have 25+ years building systems of this class (Zendesk, Intercom,
Stripe-grade). I am the product owner and I am **not a programmer**: I verify your work by running
tests and clicking through, not by reading your code. Therefore **never claim something works
unless you have executed the test and pasted the real output.**

There is a complete 19-document enterprise specification in `docs/` and a project constitution in
`CLAUDE.md`. **Read `CLAUDE.md` in full, then `docs/Doc01-Platform-Foundation-Multitenancy.md` and
`docs/Doc18-Infrastructure-DevOps-Deployment.md` sections 4.3 and 4.4, before writing a single
line.** Those files are law. This session does **not** implement Doc 01's features — it builds the
ground they stand on.

---

## YOUR GOAL THIS SESSION

Produce a repository that boots the entire stack with **one command**, runs an empty-but-real CI
gate suite with **one command**, and contains the six registries as typed, empty, CI-enforced
structures. Nothing more. **Do not implement any business feature.**

Success is: I clone this, run `make up`, get a working app at `http://localhost`, run `make gates`,
and see every gate execute and pass with zero features implemented.

---

## PART A — Repository skeleton

Create exactly this layout (empty dirs get a `.gitkeep`):

```
app/{Domain,Http,Jobs,Listeners,Support}
registries/
database/migrations/
docs/            ← the 19 spec .md files already live here; do not modify them
docs/adr/
resources/js/
widget/
sidecars/smpp/
tests/{Unit,Contract,Integration,Isolation,Security,Property,Browser}
fixtures/
ci/
docker/
```

Also create at the root: `Makefile`, `README.md`, `CONTRIBUTING.md`, `docs/BUILD-STATUS.md`,
`.editorconfig`, `.gitattributes`, a thorough `.gitignore`, and `.env.example`.

`README.md` must record **the exact versions you actually installed** (PHP, Laravel, Postgres,
Redis, Node) — check them, do not assume from memory.

---

## PART B — The Laravel application

Install the latest stable Laravel with PHP 8.3+, `declare(strict_types=1)` enforced repo-wide, and:

- **Octane with FrankenPHP** (dev and prod parity)
- **Horizon** — configure supervisors as separate queue families now, even though they are empty:
  `telegram-in`, `telegram-out`, `webchat-in`, `webchat-out`, `media`, `ai`, `sms`, `automation`,
  `reports`, `outbox-relay`, `audit`. Saturation in one family must never starve another.
- **Reverb** (WebSockets), backplane on redis-volatile
- **Fortify + Sanctum** (installed, not yet configured — that is Doc 02)
- **Pennant** (tenant-scoped feature flags)
- **Scout + Meilisearch**
- **Nutgram** (installed, unwired — that is Doc 05)
- **Inertia + Vue 3 + TypeScript + Tailwind** for the agent app, with one placeholder page
- **Pest** for PHP tests, **Playwright** for browser tests, **axe-core** wired into Playwright
- **PHPStan at max level**, **Laravel Pint**, **ESLint + Prettier**

Configure **two Redis connections** in `config/database.php`: `volatile` (cache, presence, rate
limits) and `durable` (queues, outbox, timers). Cache uses `volatile`; queues use `durable`. Add a
comment in the config explaining that merging them is forbidden and why (cache pressure evicting
queue state).

Set the bus to dispatch **`afterCommit` by default** (convention C-06).

---

## PART C — Docker Compose stack (`docker/compose.yaml`)

Services, all on a private network with healthchecks and named volumes:

| Service | Notes |
|---|---|
| `app` | FrankenPHP/Octane |
| `worker` | same image, Horizon command |
| `scheduler` | same image, scheduler command |
| `reverb` | same image, reverb command |
| `postgres` | **postgres:16**, RLS-capable, init script creates the `app` role **and** a separate `platform_admin` role |
| `redis-volatile` | `maxmemory-policy allkeys-lru`, no persistence |
| `redis-durable` | `appendonly yes`, `appendfsync everysec` |
| `meilisearch` | |
| `minio` | S3-compatible object storage |
| `mailpit` | outbound mail capture |
| `clamav` | media scanning (Doc 04) |
| `mock-telegram` | tiny HTTP stub — records outbound calls, replays fixture updates |
| `mock-sms` | tiny HTTP stub — accepts sends, emits DLR callbacks |
| `mock-ai` | tiny HTTP stub — deterministic canned completions |

**Parity rule (Doc 01 SEC-01):** RLS, Octane, and queues are ON in local dev. Local must not be a
softer environment than production.

The three mock services are small and must be **deterministic** — they exist so that CI never
touches a real provider. Put them in `docker/mocks/`.

---

## PART D — The six registries

Create each as a real, typed, loadable structure with **zero entries** plus a schema comment
describing every required field. They must be loadable and cross-checkable today, so that Doc 01
onward simply fills them in.

1. `registries/permissions.php` — `key, label_i18n_key, group, scopes[Own|Team|Dept|All], owning_doc`
2. `registries/settings.php` — `key, type(string|int|bool|json|encrypted-string), default, validation, tenant_editable, owning_doc`
3. `registries/notifications.php` — `category_key, label_i18n_key, default_channels, layer_model, owning_doc`
4. `registries/audit-coverage.php` — `action_key, entity, actor_types, required_fields, owning_doc`
5. `registries/gates.yaml` — `gate_id, name, command, owning_doc, owning_ac, owner, added_date, blocking(true)`
6. `registries/traceability.yaml` — `ac_ref, suite_path, type, tier, cadence, environment, owner`

Write an artisan command `php artisan registry:check` that validates all six against their schemas
and cross-checks them (every gate names a real command; every traceability entry names a real file
once tests exist). It must exit non-zero on any violation.

---

## PART E — CI gates (the important part)

Create `ci/` scripts, each independently runnable, each exiting non-zero on violation. Implement
them **for real** — an empty codebase must genuinely pass them, and they must genuinely fail when
you deliberately introduce a violation. **Prove each one by introducing a violation, showing the
failure, then reverting.**

| Gate | Script | What it does |
|---|---|---|
| `string-lint` | `ci/string-lint.php` | Fails on user-visible string literals outside the i18n layer (Doc 01 FR-09, Doc 16 FR-05). Allowlist file for genuine exceptions |
| `no-channel-conditionals` | `ci/no-channel-conditionals.sh` | Greps domain code for `telegram`/`webchat`/`sms` conditionals (Doc 04 T-09) |
| `migration-safety` | `ci/migration-safety.php` | Blocks destructive ops shipped with dependent code, non-concurrent index creation on hot tables, and audit-table changes without the change-control flag (Doc 18 FR-09) |
| `registry-check` | `php artisan registry:check` | Part D |
| `permission-mapping` | `ci/permission-mapping.php` | Every registered route maps to a registered permission key (Doc 03) |
| `audit-coverage` | `ci/audit-coverage.php` | Every state-mutating action has an audit-coverage entry (Doc 17) |
| `traceability` | `ci/traceability.php` | Every AC in `/docs` maps to a test entry; unmapped AC fails the build (Doc 19 FR-02) |
| `gate-skip-guard` | `ci/gate-skip-guard.sh` | A gate marked skipped requires a signed change file in `ci/skips/`; otherwise fail (Doc 18 FR-08) |

Bootstrap-mode note: `traceability`, `permission-mapping`, and `audit-coverage` must **warn but not
fail** while the registries are empty, and flip to **blocking** the moment the first entry is added.
Implement that flip explicitly — do not leave it as a TODO.

Then a GitHub Actions pipeline (`.github/workflows/ci.yml`) with the Doc 18 FR-07 stage order:

```
lint+static → unit → integration (ephemeral stack) → browser → performance budgets → (deploy stages stubbed)
```

---

## PART F — Makefile (the owner's entire interface)

Each target must work and be documented in `README.md`:

```
make up          bring the whole stack up
make down        stop it
make fresh       rebuild + migrate + seed from scratch
make test        full Pest suite
make gates       every CI gate, in pipeline order, with a clear PASS/FAIL summary
make lint        Pint + PHPStan + ESLint
make browser     Playwright suite
make shell       shell into the app container
make logs        tail all services
make status      what is running, what is healthy
```

`make gates` output must be readable by a non-programmer: one line per gate, `PASS`/`FAIL`, and a
final summary count.

---

## PART G — Proof of life

1. One smoke test asserting the app boots, Postgres is reachable with RLS **enabled**, both Redis
   instances respond and are **distinct**, Horizon sees all eleven queue families, and Reverb
   accepts a connection.
2. One Playwright test loading the placeholder page and running an axe-core scan.
3. `docs/BUILD-STATUS.md` initialized with a table of Docs 01–19 and their status (all `NOT STARTED`
   except this bootstrap).
4. `docs/adr/0001-stack-decisions.md` recording the stack from `CLAUDE.md` §3 **and** the two
   rules that will look odd to a future reader: no SMPP in PHP, and the two-Redis split.

---

## CONSTRAINTS

- **Do not implement any Doc 01 feature.** No tenant model, no RLS policies, no TenantContext, no
  settings engine, no outbox. Those are Session 1. If you find yourself writing a migration for a
  business table, stop.
- **Do not add a dependency that is not in `CLAUDE.md` §3** without writing an ADR and asking me first.
- **Do not skip the mock provider services.** They are load-bearing for every later session.
- Ask before choosing anything the spec does not settle.

---

## HOW TO FINISH

1. Run `make up`, `make test`, `make gates`, `make lint`. **Paste the real output of each.**
2. Deliberately break one gate, show it failing, revert it, show it passing. Paste both.
3. Produce the self-audit table: every item in Parts A–G, with `DONE` / `PARTIAL` / `NOT DONE` and
   a one-line reason for anything not `DONE`.
4. Report in the `CLAUDE.md` §9 shape: BUILT / PROVEN / NOT DONE / BLOCKERS / RISKS / NEXT SESSION.

**Before you write any code: give me your PLAN as a table (file → purpose → which part of this
prompt it satisfies) and wait for my approval.**
