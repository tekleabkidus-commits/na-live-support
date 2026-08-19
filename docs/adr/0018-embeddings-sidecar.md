# ADR 0018 — local embeddings sidecar, pinned model (Doc 09 T-05 / STEP 0.2d)

**Status:** approved with the S09 plan (owner ruling, 2026-08-19): embeddings run on a
**pinned LOCAL model (bge-m3 or equivalent)** — no Gemini embeddings, no second egress
vendor. Written before the code it covers.

## Why

Addis AI serves no embeddings endpoint (proven at STEP 0.2d: `POST /v1/embeddings` →
HTTP 404 for every model name; capture in `docs/evidence/S09-step0-capability/`).
T-05's retrieval needs one. The owner ruled local over a second cloud vendor: no new
egress surface, no new data-use terms, deterministic and free at dev scale, and
bge-m3 is multilingual with strong Amharic-relevant coverage.

## Decision

- A dedicated **`embeddings` sidecar container** (the `/sidecars` precedent —
  CLAUDE.md §3's SMPP shape: a small non-PHP service behind a private HTTP API).
  Node + ONNX runtime serving **BAAI/bge-m3** (dims **1024**), model version pinned in
  the image tag; no internet access at runtime (the model ships in the image).
- The sidecar speaks the **OpenAI-compatible `/v1/embeddings` shape**, so the ADR 0016
  thin client reaches it as just another provider config (baseURL swap) — the
  provider-agnostic seam is preserved and the fallback-chain executor treats it like
  any provider.
- The fixtures-only egress guard applies to it exactly as to any provider — same
  choke point, no exemption (a local hop today can become a remote one in an ops
  change; the guard does not care).

## Determinism split (stated for the test record)

Retrieval CORRECTNESS proofs run over **fixture-seeded vectors** (deterministic,
CI-safe, no sidecar dependency); the sidecar itself is proven by its own integration
probe (real text → 1024-dim vector, cosine sanity) and by the nightly staging leg.
A model upgrade is an expand-contract re-embed (ADR 0015's rule).
