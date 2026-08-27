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

## Amendment (S10 step 0.7, 2026-08-27 — STOP-C ruling, option 2, owner-confirmed)

**Correction of record:** at the S09 close this sidecar existed only as configuration
(`config/ai.php` → `http://embeddings:8081/v1`); no container, compose service or
`sidecars/embeddings/` directory was ever built — S10's step-0 liveness probe found
"no host embeddings", HTTP 000 (`docs/evidence/S10-step0/sidecar-probe-01.txt`). S09's
retrieval proofs ran on fixture vectors, exactly as this ADR's determinism split allows;
the register's wording implied more than existed (honest trail, no blame row).

**Stood up at S10 step 0.7:**
- `sidecars/embeddings/` — Node 20 + `@huggingface/transformers` **3.8.1** (ONNX runtime),
  model **`Xenova/bge-m3`** pinned to Hugging Face commit
  **`4de13258303883538bd53b696b452bf8099f0858`**, dtype **q8** (`onnx/model_quantized.onnx`),
  CLS pooling + L2 normalize → **1024 dims** (`model.mjs` is the one place the model is named).
- The model is downloaded ONCE at image build (`prefetch.mjs`) and baked into `/models`;
  the running container sets `allowRemoteModels=false` — **no runtime egress by mechanism**.
- OpenAI-compatible `POST /v1/embeddings` (+ `GET /healthz`, `GET /v1/models`); ADR 0016's
  thin client reaches it unchanged as provider `local-embeddings`; the fixtures-only egress
  guard applies to every payload sent to it (no exemption).
- Compose service `embeddings` under the **`embeddings` profile**: `make up` enables it
  locally; CI's raw `docker compose up --build` never names the profile, so **CI stays on
  the deterministic fake driver** (the ruling). The app tolerates the sidecar's absence
  (`KbIndexer` stores a null vector; the keyword half serves).
- Proof: `tests/Integration/EmbeddingsSidecarProbeTest.php`, LIVE-GATED by
  `AI_EMBEDDINGS_LIVE=1` (real text → 1024-dim unit vectors; cross-language paraphrase
  closer than an unrelated sentence; index → retrieve through the built seam with no fixture
  vector). Per the ruling, ONE recorded local run against the real sidecar is captured at
  every S10 checkpoint under `docs/evidence/S10-step*/sidecar-live-*.txt`.
- `[D18]` carries only the production/ops half (image registry, resource sizing, the
  nightly live leg's schedule).
