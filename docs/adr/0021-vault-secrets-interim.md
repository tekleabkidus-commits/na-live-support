# ADR 0021 — `vault_secrets`: the C-13 interim behind the `vault://` ref contract

**Status:** accepted (INTERIM — retired by Doc 18's vault). **Date:** 2026-09-04 (S13; RIDER 1 of SCHEMA AMENDMENT 7; P-RIDER1-FIX countersigned).

## Context
C-13 says secrets live in the vault and the database stores vault references. Doc 18 (infrastructure) owns the real vault and is not built. Doc 13 FR-07 needs a per-widget HMAC secret NOW, with rotation and an overlap window (setting `widget.identity.rotation_overlap_hours`).

## Decision
One platform-shaped table `vault_secrets(ref PK, tenant_id, ciphertext, created_at, retired_at)` behind the `vault://<namespace>/…` ref contract (the namespace is the ref's first segment, so a later document's secrets share the table).
- **ciphertext** = the application encrypter under `APP_KEY`; never plaintext at rest.
- **Grants:** app INSERT(`ref`, `tenant_id`, `ciphertext`) + UPDATE(`retired_at`) only; no UPDATE of `ciphertext`; no DELETE; RLS forced (`WidgetGrantPhysicsTest` asserts the exact denied sets; `SecretRotationTest` executes the 42501 control on a ciphertext UPDATE).
- **The reader** (`App\Support\Secrets\VaultInterim`) reveals in memory for one comparison and never returns, logs or serializes the plaintext beyond the verifier.
- **The scrubber** redacts both VALUE shapes — a `vault://` ref and an encrypter envelope — under any key and inside the message, with a plant→detect→remove control and two near-miss controls (`VaultRefScrubberPatternsTest`, P-RIDER1-FIX).
- **Audit rows** (`widget.secret.created`, `widget.secret.rotated`) carry neither value nor ref (`SecretRotationTest`).
- **Rotation history** is the row set ordered by DB time (`created_at`, app-denied, R-19b).

## Consequences
- **The honest limit:** a compromise of `APP_KEY` together with a database dump reveals the secrets. Doc 18's vault removes it. Until then the key lives outside the database and never in a dump.
- **Reopener [D18], by name:** Doc 18 replaces this table with the real vault behind the SAME `ref` contract (`VaultInterim` becomes the vault client; no caller changes) and retires this ADR.
- **Standing law (R-D13-7):** a rider whose table holds secret material, PII, or money-adjacent data is a countersign-BEFORE-migration stop, not a checkpoint confirmation. RIDER 1 landed ahead of its countersign and is confirmed after the fact by P-RIDER1-FIX.

## Alternatives rejected
- A plaintext column — a C-13 violation.
- Environment-only secrets — no per-widget rotation, no overlap window.
- Building Doc 18's vault early — the build order (CLAUDE.md §7) is not optimized around one document's need.
