# ADR 0005 — The feature-flag registry becomes registry #7

- **Status:** Accepted (owner ruling, Session 2 of Doc 01 work, 2026-07-31)
- **Date:** 2026-07-31
- **Spec:** Doc 01 FR-11, CLAUDE.md §2 (the registries), N-19
- **Amends:** CLAUDE.md §2's table — six registries become seven

## Context

Doc 01 FR-11 requires every module to register its feature-flag keys with a foundation
flag registry (Pennant, tenant-scoped) so Doc 16's Backoffice can enumerate and toggle
them without code knowledge.

CLAUDE.md §2 fixes "the six registries" as the platform's constitution: code-registered,
machine-readable files that CI cross-checks, where an unregistered feature cannot ship.
The flag registry FR-11 demands has exactly those properties — a code-registered file,
a CI cross-check, an enumeration consumed by another module — but was not in the table.

That left two options:

1. **Build it as a Doc 01-internal artifact** outside the constitution. It would then
   behave like a registry while being exempt from the registry rules — the
   constitution-vs-reality drift this repo exists to prevent.
2. **Amend the constitution.** Chosen, by owner ruling.

## Decision

`registries/features.php` is **registry #7**. Entry schema: every flag key registers
`key`, `label_i18n_key`, `default`, `tenant_toggleable`, and `owning_doc` — a label
i18n key rather than free text, because the label is user-visible in Doc 16's
Backoffice and N-03 forbids hardcoded copy. Rules enforced in CI by `registry:check`,
like the other six:

- No flag may be read or written unless registered (`FeatureRegistry` throws on an
  unknown key — same posture as the settings registry).
- Every registered key names its owning document, so Doc 16 can group them and a
  removed module's flags are findable.
- N-19 stays structural: a test asserts no registered flag key appears in any
  migration, so flags hide behavior but can never guard schema or data destruction.

CLAUDE.md §2's table is updated in the same commit. The constitution changes by ruling
and ADR, never silently — this document is the record.

## Consequences

- `registry:check` gains a seventh section; its falsification (malformed entry,
  unregistered key read) is proven in the FR-11 test suite.
- Doc 16 inherits a stable enumeration contract: it reads the registry, never scans
  code for flags.
- Any future "shadow registry" (a code-registered, CI-checked list that isn't in §2)
  should be treated the same way: either it is a registry and enters the table by
  ruling + ADR, or it must not behave like one.
