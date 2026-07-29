# ADR 0002 — Dev-only source delivery (bind mount + reload)

Status: accepted · Date: 2026-07-28 · Owner: product owner · Author: engineering

## Context

The application image bakes the source: `COPY . .` in `docker/app/Dockerfile`,
with only `.env` mounted at run time. That is exactly right for CI and for the
production image — the artifact is immutable and what the pipeline tests is
what a release would ship.

It is wrong for local iteration, and it failed silently during the Session 0
close-out. A test file was deleted on the host, `make test` was run, and the
container executed the **deleted test** from the baked layer and reported a
pass. Nothing warned that the working tree and the running code had diverged.
For a project whose central rule is "never claim something is done that you
have not proven" (CLAUDE.md §0.3), a local test run that silently asserts
against stale code is a defect in the proof mechanism itself.

The naive fix — bind-mount the source — moves the staleness rather than
removing it. Octane and Horizon are long-lived processes that hold compiled
PHP in memory for the life of the worker. With a bind mount alone, the files
on disk are fresh while the process serving `/up` is still running yesterday's
code. That is the same bug wearing a different hat.

## Decision

Add `docker/compose.dev.yaml`, a **dev-only** override that bind-mounts the
working tree into the four PHP roles (`app`, `worker`, `scheduler`, `reverb`),
and make the reload explicit and automatic.

1. **Source is bind-mounted; runtime state is not.** `..:/app` delivers the
   working tree. Anonymous volumes pin `vendor/`, `node_modules/`,
   `public/build/`, `bootstrap/cache/` and `storage/` back to the image's own
   copies, so the host cannot shadow them — and a fresh clone with no `vendor/`
   or built assets cannot produce a container that fails in ways that look like
   application bugs.

2. **`make reload` exists and `make test` depends on it.** `octane:reload`
   restarts the Octane workers in place; `worker`, `scheduler` and `reverb` are
   container-restarted (there is no `restart:` policy in the base compose, so
   `horizon:terminate` would kill the worker for good). The target then blocks
   on `up -d --wait` until healthchecks pass — without that, the Integration
   suite's assertion about Horizon's eleven supervisors races the restart and
   becomes flaky. A Pest run can therefore never assert against code the server
   is not running.

3. **`make up` / `make fresh` pass `--renew-anon-volumes`.** Docker will
   otherwise reuse an anonymous volume built from an older image, which is a
   third flavour of the same staleness.

4. **CI never loads it.** `.github/workflows/ci.yml` names
   `-f docker/compose.yaml` alone, in every stage. Only the `Makefile` adds
   `-f docker/compose.dev.yaml`. The pipeline continues to build and test the
   immutable image.

## Parity reasoning

Doc 01 SEC-01 requires that local is never a softer environment than CI. It
still isn't. Everything that constitutes the security posture is defined in
`docker/compose.yaml` and is untouched by the override:

| Property | Where it lives | Changed by the override? |
|---|---|---|
| PostgreSQL RLS; `app` cannot bypass, `platform_admin` is the only bypasser | `docker/postgres/init/01-roles.sql` | No |
| Two-Redis split (`allkeys-lru` vs AOF `noeviction`) | `docker/redis/*.conf` | No |
| Real Horizon workers, eleven supervisors | `config/horizon.php`, service `command` | No |
| Octane/FrankenPHP as the HTTP server | image `CMD` | No |
| Service topology, network isolation, mocks, egress | `docker/compose.yaml` | No |
| Environment, secrets | `../.env` mount | No |
| **How source reaches the container** | image `COPY` vs bind mount | **Yes — this, and only this** |

The override changes source delivery. It does not soften a single control.

## Consequences

- Editing a `.php` or `.js` file is live in the container immediately; `make
  test` reloads the long-lived processes first, so tests always match the tree.
- Changing `composer.json`, `package.json` or the `Dockerfile` still requires
  `make up` (rebuild) — dependencies and assets are container-owned by design.
  This is a deliberate limit, not an oversight.
- Octane file-watching (`--watch`) was considered and rejected: it needs Node
  and `chokidar` inside the runtime image, which would either bloat the shared
  CI image or require a second build target. A deterministic reload wired into
  the one command that matters (`make test`) is simpler and cannot silently
  half-work.
- Residual gap, stated honestly: if you edit a file and then browse the app
  manually without running `make test` or `make reload`, the served page can
  still be stale. The test path — the one that produces claims of proof — cannot.
