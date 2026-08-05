# ADR 0009 — dompdf with embedded Noto Sans Ethiopic for transcript export

**Status:** accepted (owner ruling R-15, filed at Doc 04 plan approval)
**Date:** 2026-08-05
**Owner trigger:** Doc 04 FR-24 — rendered PDF transcript export

## Context

FR-24 requires a rendered PDF/text transcript of a conversation range,
watermarked and audited. The platform launches Amharic-first (C-08): a PDF
renderer whose bundled fonts cannot draw Ge'ez script would export a disputed-
withdrawal transcript (SS-03's whole scenario) as rows of tofu boxes — a
compliance artifact that cannot be read is worse than none.

## Decision

- **`dompdf/dompdf`** (composer) — HTML→PDF inside PHP, no headless-browser
  sidecar, no external renderer service, nothing on the egress path.
- **Noto Sans Ethiopic embedded** — `resources/fonts/NotoSansEthiopic-Regular.ttf`
  (SIL OFL, from the Noto project), registered via `@font-face` in the
  transcript stylesheet and embedded into every produced PDF. The font ships in
  the repo: an export must not depend on a font CDN (`isRemoteEnabled` stays
  false — a transcript that can fetch remote resources is an SSRF surface).
- dompdf runs INSIDE the queued/export flow with remote loading disabled and
  its chroot pinned to the font/stylesheet directory.

## Consequences

- The TranscriptExporterTest asserts the font is embedded (its name appears in
  the PDF binary) and that Amharic body text survives the render.
- Doc 17 inherits the export-approval flow reservation (Doc 04 §19/R-05).
- Font updates are ordinary dependency PRs; the file is versioned in-repo.
