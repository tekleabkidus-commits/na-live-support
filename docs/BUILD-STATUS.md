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

- **Dev host: native `redis-server` is STILL enabled and running — and the root cause is now
  known.** Reported disabled by the owner three times; verified otherwise four times (latest check
  **2026-07-30 00:30 EAT**). This blocker stays OPEN and the volatile port is NOT reclaimed.

  Evidence at the 4th check:

  | Check | Result |
  |---|---|
  | `systemctl is-active redis-server` | `active` |
  | `systemctl is-enabled redis-server` | `enabled` (`UnitFileState=enabled`) |
  | `redis-cli -h 127.0.0.1 -p 6379 PING` | `PONG` |
  | enable symlink `/etc/systemd/system/multi-user.target.wants/redis-server.service` | still present, mtime still **2026-04-06 18:03:48** |
  | native redis `uptime_in_seconds` | `182142` (≈50 h 35 m) — running continuously since **2026-07-27 21:56:27**, matching systemd `ActiveEnterTimestamp` |

  The uptime is the decisive datum: `systemctl disable --now` *stops* the unit, and this process has
  not restarted in 50 hours. So the command has never reached this systemd. The symlink mtime says
  the same thing independently — `disable` unlinks it, and it still carries its April install mtime.

  **Root cause — CONFIRMED by direct test, not inferred: `sudo` on this host requires a password,
  and no shell reachable from Claude Code is a TTY.** Two independent probes:

  ```
  # in Claude Code's tool shell
  sudo -n true
  → sudo: a password is required                                        (exit 1)

  # typed by the owner at the Claude Code prompt as: ! sudo systemctl disable --now redis-server
  → sudo: a terminal is required to read the password; either use the -S option to read
    from standard input or configure an askpass helper
  → sudo: a password is required
  ```

  So **`sudo` can never be run from inside Claude Code on this host, by either path** — including
  the `!` prefix, which is otherwise the way to run interactive commands. Every
  `sudo systemctl disable --now redis-server` attempted here failed at the sudo layer before systemd
  was involved, which is why the unit's 50-hour uptime is unbroken. The failure is easy to miss in
  the scrollback, and a pasted command line that lacks the leading `!` never executes at all — it
  arrives as chat text. Both of those account for the three "already disabled" reports.

  This is *not* a different-WSL-distro problem (hostname `Kidus`, `WSL_DISTRO_NAME=Ubuntu`, both
  confirmed at the console) — that earlier guess in this entry was wrong.

  Do **not** work around this with `sudo -S` piping the password in: it would put the owner's
  password into the transcript and the session logs, violating C-13. Privileged host changes on this
  machine are owner-only, in a real terminal, full stop.

  **Impact is worse than previously recorded — the collision is live, not theoretical.** Docker
  publishes `redis-volatile` as `0.0.0.0:6379->6379`, but its `docker-proxy` never bound: `ss -ltn`
  shows **no** `*:6379` listener (contrast `*:6380`, which redis-durable's proxy holds), because the
  native daemon already owns `127.0.0.1:6379`. The published mapping is cosmetic. Fingerprinting
  `127.0.0.1:6379` from the host proves the **native** daemon answers, with the wrong eviction
  policy for a volatile cache:

  | Property | host `127.0.0.1:6379` (answering) | `redis-volatile` container (intended) |
  |---|---|---|
  | `redis_version` | `7.0.15` | `7.4.10` |
  | `executable` | `/usr/bin/redis-server` | `/data/redis-server` |
  | `config_file` | `/etc/redis/redis.conf` | `/usr/local/etc/redis/redis.conf` |
  | `maxmemory-policy` | `noeviction` | `allkeys-lru` (per `docker/redis/volatile.conf`) |

  The container's healthcheck runs *inside* the container, so it reports `healthy` and hides this.

  Still bounded for tests and CI: no test can silently reach the native daemon
  (`stackEndpoint()` forbids every localhost fallback, S0 close-out note above), and CI runners have
  no native Redis — run [30492124123](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/30492124123)
  on `af9562d` is green. The exposure is S1+ application code or any tool run from the host against
  `localhost:6379`: it gets a `noeviction` 7.0.15 instance instead of the `allkeys-lru` 7.4.10 one.

  **Owner action — must be run in a real interactive Ubuntu terminal, not through Claude Code**
  (Claude Code cannot type a sudo password, which is why the last three attempts silently failed):

  ```
  sudo systemctl disable --now redis-server
  systemctl is-active redis-server; systemctl is-enabled redis-server
  ls -la /etc/systemd/system/multi-user.target.wants/redis-server.service
  ```

  Expect `inactive`, `disabled`, and `No such file or directory`. Then reclaim the port — the
  container must be recreated, since its proxy failed to bind at start and will not retry:

  ```
  cd ~/projects/na-live-support && docker compose -f docker/compose.yaml up -d --force-recreate redis-volatile
  redis-cli -h 127.0.0.1 -p 6379 info server | grep redis_version   # expect 7.4.10
  redis-cli -h 127.0.0.1 -p 6379 config get maxmemory-policy        # expect allkeys-lru
  ```

  Paste that output and the blocker can be closed on evidence rather than on report.
