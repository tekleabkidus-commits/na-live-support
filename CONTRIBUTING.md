# Contributing

This repository is built by engineering sessions governed by
[CLAUDE.md](CLAUDE.md) — read it in full first; it overrides everything here
on conflict.

## The short version

1. **The specs in `/docs` are law.** No invented requirements, no silent
   drops, no simplifications. Ambiguity → stop and ask
   (`BLOCKER: Doc NN FR-XX says A, but Doc MM T-YY implies B. Which?`).
2. **One module per session**, in the CLAUDE.md §7 build order.
3. **Nothing is done without executed tests and pasted output.**
4. **Every user-visible string is an i18n key** (en + am). `string-lint`
   enforces this.
5. **No new dependency without an ADR** in `docs/adr/` and owner approval.
6. **Conventional commits referencing the spec:**
   `feat(foundation): tenant context middleware (Doc01 T-05)`.

## Before you push

```
make lint    # Pint + PHPStan max + ESLint + vue-tsc
make test    # full Pest suite (stack up first: make up)
make gates   # the CI gate suite — the same gates CI runs
```

A red gate is never worked around: fix the code, or change the gate through
the signed change-file process (`ci/skips/`, Doc 18 FR-08).

## Registries

A feature that is not registered cannot ship. New permission keys, settings,
notification categories, audit actions, gates, and test-traceability entries
go in `/registries` — `php artisan registry:check` validates them.
