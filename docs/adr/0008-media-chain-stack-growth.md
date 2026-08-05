# ADR 0008 — Media-chain stack growth: egress proxy, S3 driver, image library, clamd client, lane workers

**Status:** accepted (owner ruling R-15, filed at Doc 04 plan approval)
**Date:** 2026-08-04
**Owner trigger:** Doc 04 S20 plan approval — SEC-03, T-04, BR-06, FR-15/T-02

## Context

Doc 04's media pipeline is a security surface with hard requirements no current
dependency covers: SSRF-safe fetching through an allowlisted egress proxy
(SEC-03, CLAUDE.md §3's standing rule that ALL third-party egress goes through
the proxy — Doc 04's media fetcher is the platform's first real egress), S3
object storage with tenant-prefixed keys + SSE (T-04, MinIO in dev), EXIF
stripping and ≤3 thumbnail sizes (BR-06, T-04), a ClamAV scan with nothing ever
delivered unscanned (AC-05, EC-11), and per-conversation FIFO lane workers
(FR-15, T-02).

## Decision (five parts, each the smallest thing that satisfies its spec id)

1. **`egress-proxy` compose service** — tinyproxy with a per-destination
   allowlist. In dev the allowlist names the mock channel hosts; production
   names real channel API hosts only (Doc 18 wires the production values). PHP
   never fetches media directly: the fetcher's HTTP client is pointed at the
   proxy, and a non-allowlisted host is refused BY THE PROXY — the test
   asserts the refusal, not a PHP guard that could be bypassed.
2. **`league/flysystem-aws-s3-v3`** (composer) — the S3 storage driver for the
   media store. MinIO (already in the stack) is the dev/cookie-less origin;
   SEC-02's separate production media domain is Doc 18's wiring.
3. **`intervention/image` on the GD driver** (composer) — EXIF strip + ≤3 thumb
   sizes. GD deliberately (no system-package imagick dependency); thumbnails are
   queued jobs on the `media` family, never inline (stack rule).
4. **A hand-rolled clamd INSTREAM TCP client (~100 lines)** — NO composer
   dependency. The INSTREAM protocol is a length-prefixed stream with a one-line
   verdict; a third-party wrapper would be a supply-chain surface larger than
   the protocol itself. The client streams while enforcing the size cap —
   EC-02's mid-stream abort and the scan share one pass.
5. **Lane workers as a worker-container process** (`lanes:work`, ADR 0007's
   per-family precedent) — no new queue family: lanes are Redis lists on
   redis-durable consumed round-robin, which Horizon's per-job model cannot
   express (recorded in the S20 step-4 commit).

## Consequences

- Compose/CI commits for the proxy fall under the efficiency ruling's
  CI/infra exception (push + verify CI when changed).
- The egress allowlist is config the SEC-03 test SABOTAGES (widened → red).
- Doc 18 inherits: production media domain/CDN, proxy allowlist values,
  storage-class lifecycle (R-03), and the lanes:work supervisor wiring.
- ADR 0009 (dompdf + embedded Noto Sans Ethiopic for FR-24 transcripts) is
  approved by the same ruling and is written when step 7's code lands.
