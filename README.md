# Na Live Support

AI-first, white-label customer support platform for betting operators.
Ethiopia first: Amharic + English, Telegram-dominant, local SMS gateways.

**Start here:** [CLAUDE.md](CLAUDE.md) is the project constitution. The 19
specification documents in [docs/](docs/) are law. [docs/BUILD-STATUS.md](docs/BUILD-STATUS.md)
says what is actually built and proven.

## Installed versions (checked, not assumed — Session 0, 2026-07-27)

| Layer | Version |
|---|---|
| PHP | 8.4.23 |
| Laravel | **13.23.0** |
| Octane / FrankenPHP | 2.18.0 / `dunglas/frankenphp:1-php8.4` image |
| PostgreSQL | 16 (`postgres:16` image) |
| Redis | 7 (`redis:7` image) × 2 instances |
| Horizon | 5.48.1 |
| Reverb | 1.11.0 |
| Meilisearch | v1.16 (server) / 1.16.1 (client) · Scout 11.4.0 |
| Pennant | 1.24.0 |
| Fortify / Sanctum | 1.37.3 / 4.3.3 |
| Nutgram | 4.49.0 (laravel bridge 1.7.1) |
| Inertia | 3.1.1 (`@inertiajs/vue3` 3.6.1) |
| Vue / TypeScript | 3.5.40 / 6.0.3 |
| Vite / Tailwind | 8.1.5 / 4.3.3 |
| Node / npm | 20.20.2 / 10.8.2 |
| Pest | 4.7.5 |
| Playwright / axe-core | 1.62.0 / @axe-core/playwright 4.12.1 |
| PHPStan (max) | 2.2.6 via Larastan 3.10.0 |
| Pint / ESLint / Prettier | 1.29.3 / 10.8.0 / 3.9.6 |
| symfony/yaml | 8.1.1 (registry tooling — ADR 0001) |

## The owner's interface (Makefile)

| Command | What it does |
|---|---|
| `make up` | bring the whole stack up (first run builds images) — app at http://localhost |
| `make down` | stop the stack (data survives) |
| `make fresh` | rebuild + migrate + seed from scratch (**destroys local data**) |
| `make test` | full Pest suite — expects the stack up (`make up` first): parity means the integration/smoke tests talk to the real services |
| `make gates` | every CI gate in `registries/gates.yaml` order, one PASS/FAIL line each + summary |
| `make lint` | Pint + PHPStan (max) + ESLint + vue-tsc |
| `make browser` | Playwright suite incl. axe-core accessibility scan (stack must be up) |
| `make shell` | shell into the app container |
| `make logs` | tail all services |
| `make status` | what is running, what is healthy |

## Stack topology (docker/compose.yaml)

App, worker (Horizon, 11 queue-family supervisors), scheduler, reverb —
one shared image. Postgres 16 (RLS-ready; separate `app` and `platform_admin`
roles), redis-volatile (cache; LRU, no persistence) + redis-durable (queues;
AOF) — **never merged** (ADR 0001), Meilisearch, MinIO, Mailpit, ClamAV, and
three deterministic mock providers (`mock-telegram`, `mock-sms`, `mock-ai`)
so CI and local dev never touch a real provider.

Host ports: app **80** · reverb **8080** · postgres **5432** ·
redis-volatile **6379** · redis-durable **6380** · meilisearch **7700** ·
minio **9000/9001** · mailpit **8025** (smtp 1025).

## Repository layout

See CLAUDE.md §5. Highlights: `/docs` (the law) · `/registries` (the six
constitution registries) · `/ci` (gate scripts) · `/docker` (stack) ·
`/widget` (standalone Preact iframe bundle, Wave 2) · `/sidecars/smpp`
(Go/Node SMPP sidecar — SMPP is never implemented in PHP, ADR 0001).
