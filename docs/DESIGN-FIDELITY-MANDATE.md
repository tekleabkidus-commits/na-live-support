# DESIGN-FIDELITY-MANDATE (v8 reissue)

The frozen design record is the specification of every screen in scope. Implement it at
100% fidelity — every frame, state, viewport, theme, and language; tokens from the
design system only; deviations only in the three classes below, every one filed in
BUILD-STATUS's DESIGN-DEVIATIONS. Nothing skipped, nothing approximated, nothing
redesigned.

## §1 — THE FROZEN RECORD

`design/frozen/` holds **217 files** (the v7 kit said 219; 217 is the ls-verified count,
filed as honest trail at S08). Screens and audits at their highest frozen version; the
system is `B1-DS-design-system-v2` §2 (both colourways) plus addenda `B5`, `B6` (v3),
`B7` (v4). The advisor derives each document's batch file list INDEPENDENTLY from
`PROGRAM-REPORT-v1.md` and cross-checks it against the frozen listing in BOTH
directions before the session prompt names it. The session reads the named files only,
at the versions named; other batches' files are not consistency sources — the system
files are.

## §2 — TOKENS FIRST

Verify the existing encoding against the design system with the committed
hex-extraction script RE-RUN before the first screen. All styling consumes tokens,
never literals. When a drawn colour must be named, name it BY EVIDENCE — extract the
drawn hexes and match them to the token the same family already consumes (the S08
badge-dot precedent: --ds-status-closed-fg, proven, not guessed).

## §3 — DEVIATIONS: EXACTLY THREE CLASSES, ALL FILED, NONE SILENT

- **Class 1 — technical necessity or document conflict.** The document wins, always.
  File with BOTH citations (the document's line and the frozen file's own text) and the
  resolution's reasoning. A frame that claims completeness while omitting a mandatory
  behavior IS a contradiction and is class 1 (the S08 D06 merge-reason precedent).
- **Class 2 — design gap.** The documents demand a surface no frame draws. Fill from
  design-system components ONLY, reusing the nearest drawn pattern for the same idea
  (the S08 reason-field fill reused B4-S05's drawn Reason-Required input), with an
  owner ruling naming the fill's shape. Filed.
- **Class 3 — design defect.** The frozen file is internally wrong and no document
  resolves it: flag, wait for the owner, build nothing for it meanwhile.

A design conflict or gap is a MANDATORY batch stop. Stale frame references (a frame
number in no frozen file) are STRUCK from plans, never built. Never bend a status code,
weaken a guard, or fork a second implementation to satisfy a drawing. First real
implementation of a never-rendered drawn state is a FRAME-MAPPING row, not a deviation
(the S08 badge precedent — a deviation register that accumulates non-deviations stops
being readable).

## §4 — PRECEDENTS THAT BIND (cite them when the shapes recur)

- Legal next states are ABSENT, not greyed (D3 — legality ships as data).
- Identical controls, not a reduced set, at 375 (B14 — by construction via shared
  slot fills, then proven).
- Refusals render inline in the surface's own strip (B2-S06); countdowns render
  SERVER-computed stamps only.
- Proofs assert VISIBILITY, not existence (D2 — an element that renders empty passes
  an existence check).
- Absence, not redaction, extends everywhere a gated fact could leak by inference:
  API keys omitted (not null), UI traces absent (not placeholder), search indexes
  never fed the gated columns, and indicator dots ABSENT for unpermitted viewers
  (S08: SEC-01/BR-03, B4-S05 E03).
- Accessibility proofs assert what is ANNOUNCED, not that an attribute exists (the
  AC-B4-03 precedent: one polite region, each moment spoken once, in order).
- Confirmation text is computed from the SAME query that performs the action, never
  hand-written (the AC-04 precedent).

## §5 — FOR DOC 09 SPECIFICALLY

Any frame implying the AI states betting-account or transaction facts is a class-1
conflict on its face (Doc 08 §3's law binds the AI's answers). Any frame drawing an
AI-set RG affordance must respect the built seam: source=ai rows carry a mandatory
excerpt and a NULL actor by CHECK — a frame contradicting that shape is class 1. AI
uncertainty, fallback, and provider-outage states are real states: if the document
requires them and no frame draws them, that is class 2 — fill from the design system's
existing degrade/unavailable patterns (FS-01's labeled-degrade precedent), never a
silent empty.
