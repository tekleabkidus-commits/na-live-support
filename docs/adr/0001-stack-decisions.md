# ADR 0001 — Stack decisions (Session 0)

Status: accepted · Date: 2026-07-27 · Owner: product owner · Author: engineering

## Context

CLAUDE.md §3 fixes the stack; this ADR records it at the moment of adoption
with the exact versions actually installed (see README.md for the full
version table), plus the two rules that will look odd to a future reader.

## Decision

- PHP 8.4.23, **Laravel 13.23.0** (latest stable at bootstrap; the "Laravel 11+"
  wording in Doc 01's header predates release cadence — CLAUDE.md §3 "latest
  stable" governs), Octane 2.18 on FrankenPHP.
- PostgreSQL 16 (RLS, JSONB, partitioning), Redis 7 ×2, Horizon 5.48,
  Reverb 1.11, Meilisearch 1.16 (Scout 11.4), Pennant 1.24,
  Fortify 1.37 + Sanctum 4.3, Nutgram 4.49 (laravel bridge 1.7),
  Inertia 3.1 + Vue 3.5 + TypeScript 6.0 + Tailwind 4.3,
  Pest 4.7 + Playwright 1.62 + axe-core, PHPStan 2.2 (max) via Larastan 3.10,
  Pint, ESLint 10 + Prettier 3.

### The two rules that will look odd later

**1. SMPP is never implemented in PHP.** Ethiopian telco SMS uses SMPP.
SMPP is a long-lived, binary, stateful socket protocol with enquire-links and
window management — precisely what a request-scoped PHP runtime is worst at.
A Go or Node sidecar (`sidecars/smpp/`) owns the SMPP session and exposes a
private mTLS HTTP API; PHP talks HTTP. If you are tempted to "just use a
composer SMPP package", re-read this paragraph.

**2. The two-Redis split is permanent.** `redis-volatile` (cache, presence,
rate limits; `allkeys-lru`, no persistence) and `redis-durable` (queues,
outbox, timers; AOF `everysec`, `noeviction`). One instance cannot serve both
masters: cache pressure evicts queue state (silent work loss), and queue
durability makes cache churn an fsync bottleneck. Cost pressure is not a
reason to merge them (Doc 18 BR-07). Config comments in
`config/database.php` enforce the story at the point of temptation.

### Dependency added beyond CLAUDE.md §3

- **symfony/yaml v8.1** (first-party Symfony, same family Laravel already
  ships): required to parse the two YAML registries (`gates.yaml`,
  `traceability.yaml`) mandated by the Session 0 spec for `registry:check`
  and the CI gates. Dev-tooling surface only — no runtime feature uses it.
  Flagged to the owner in the Session 0 report for retroactive approval.

## Consequences

Version upgrades are deliberate acts recorded in README.md; the framework
major (13.x) is pinned by composer constraint `^13.8` and upgraded only as a
dedicated task, never as a side effect.
