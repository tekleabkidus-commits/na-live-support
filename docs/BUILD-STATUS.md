# BUILD STATUS

Updated by Claude Code at the end of every session. The owner reads this file to know where the
project actually stands. A row is only `DONE` when its acceptance criteria have executed tests.

| Doc | Module | Status | Session | ACs proven | Notes |
|---|---|---|---|---|---|
| — | Repo bootstrap + CI gates | DONE (CI-proven) | S0 | smoke 6/6, gates 8/8, browser 2/2 | Laravel 13.23; 14-service stack healthy; 8 gates live (3 bootstrap-warn until first registry entry); 4 gates falsified+restored. **Proven by CI run [30393265519](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30393265519) on `2990d33` — Stages 1–6 all green** (Stage 3 integration 6 passed/33 assertions, Stage 4 browser 2 passed). Stage 5 is a placeholder until Doc 19 L1; Stage 7 (prod deploy stub) is `skipped` by design — manual approval, never yet executed. Re-confirmed on the ADR-0002 bind-mount commit: run [30492124123](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30492124123) on `af9562d`, Stages 1–6 green, Stage 7 skipped |
| 01 | Platform Foundation & Multitenancy | NOT STARTED | S1 | 0/8 | |
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

## Deferred with reason

_(Claude Code appends here. Every deferral needs a spec id and a reason.)_

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
