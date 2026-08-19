# ADR 0017 — the Horizon `ai` queue family (Doc 09 FR-03)

**Status:** approved with the S09 plan (owner, 2026-08-19). Written before the code it
covers. The ADR 0007 (`mail`) shape: a family exists because its work must never queue
behind other work, and its depth must be legible on its own.

## Decision

AI task jobs ride a dedicated Horizon **`ai`** supervisor family (already named in
CLAUDE.md §3's supervisor list — this ADR activates it) on **redis-durable**, split
into two queues:

- **`ai`** — customer-blocking work (chatbot turns, classification): the priority
  lane. A customer is waiting on every job here.
- **`ai-background`** — digests, QA pre-scores, anomaly scans, nightly embeddings:
  work that must never delay a customer-facing turn (FR-03's own priority-lane
  requirement).

## Terms

- Per-tenant concurrency is bounded by the FR-03 **Redis semaphore** inside the job,
  not by queue topology — the family bounds host resources, the semaphore bounds
  tenant fairness.
- AI calls are **external APIs from queued jobs, never inline in a request**
  (CLAUDE.md §3 hard rule); the only inline AI-adjacent work is enqueueing.
- Depth/failure visibility: Horizon's per-family metrics; FS-04's decision-log
  backlog staleness pattern applies to the log writer, not to turn jobs.
