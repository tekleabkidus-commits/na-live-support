# ADR 0016 — in-house thin OpenAI-compatible LLM client (Doc 09 T-01/FR-02)

**Status:** approved with the S09 plan (owner, 2026-08-19). Written before the code it
covers.

## Decision

The provider driver layer is a **thin in-house HTTP client** speaking the
OpenAI-compatible shape (chat completions + streaming + embeddings), ~300 auditable
lines — the ADR 0012 (`TelegramBotApi`) and ADR 0008 (clamd INSTREAM) precedent. No
vendor SDK dependency.

## Terms

- **Every request passes the fixtures-only egress guard** (`FixturesOnlyEgress`,
  S09 step 1) before any HTTP leaves the process; a test asserts no egress path skips
  it. **Every request rides the egress proxy** (CLAUDE.md §3's hard rule) with the
  provider host allowlisted.
- **Auth is a vault reference** (SEC-01/C-13): the client resolves the key at call
  time from the configured vault ref (interim: the A-19 pattern — `~/.na-vault` env
  ref in dev/staging; Doc 18's vault replaces the resolution, not the seam). The key
  never serializes into jobs, logs, or events; the scrubber's roster and the
  secrets-never-serialize sweep police it.
- **Provider-agnostic by shape** (the owner's capability ruling): switching providers
  is a baseURL/model/config change through FR-02's named provider configs. The same
  client serves Addis AI, the ADR 0018 embeddings sidecar, and any successor.
- A **record/replay driver** (T-09) implements the same interface for CI: recorded
  fixtures replay deterministically; the golden and red-team suites run against it,
  the nightly staging leg against the live provider.

## Alternatives rejected

- `openai-php/client` or similar SDKs: a dependency with its own HTTP stack and
  streaming abstractions where the platform needs exact control of egress-proxy
  pinning, guard placement, and payload logging discipline. The thin-client precedent
  has now held twice (ADR 0008, ADR 0012).
