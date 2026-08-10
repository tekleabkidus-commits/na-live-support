# DESIGN-FIDELITY-MANDATE.md — how the build implements the delivered UI design
_File 4 of the handoff package. Commit to `docs/` beside PROJECT-HANDOFF.md. This file BINDS
every Claude Code session that builds anything a user sees. The advisor folds its rules into
every session prompt whose scope includes a designed surface (SESSION-PROMPT-SPEC.md §B
gains this as a mandatory block), and the owner attaches the relevant design files directly
to the terminal session alongside it. Carried forward UNCHANGED from the v3/v4/v5 kits (the
owner has not amended it); v6 note: the S23 build added four filed deviations and committed
the re-runnable hex-extraction script — see PROJECT-HANDOFF-v6 §10._

---

## 1. Authority and rank

The seven-batch Claude Design program delivered the platform's UI: 165 frozen files, audited
batch by batch (PROGRAM-REPORT-v1 is the map). For everything visual, those files are LAW,
ranked as follows:

1. **The 19-document series + CLAUDE.md** — always supreme. If a design file ever
   contradicts a numbered requirement, the session STOPs and asks; it never picks a winner.
   (Designs were audited against the documents, so a real conflict is rare and is always an
   escalation, never a judgment call.)
2. **The design files** — the drawn screens, their states, their copy, their tokens.
3. **The build session's own preferences** — rank: none. A builder's taste is not an input.

## 2. The fidelity rule — 100%, not "similar"

**The build implements the design exactly as drawn. Not inspired-by, not approximately, not
"in the spirit of" — the drawn screen IS the specification of the screen.** Concretely:

- **Tokens come from the design system, never from the builder.** `B1-DS-design-system-v2`
  is the token source (its §2 carries every fixed token in BOTH light and dark colourways),
  extended additively by the B5 addendum, v3 (Batch 6), and v4 (Batch 7, the frozen system
  of record). Colors, spacing, radii, type sizes/weights, and component metrics are read
  from these files and encoded once as design tokens (CSS variables / Tailwind config) —
  never re-derived, never eyeballed from a screenshot.
- **Dark mode comes from v2 §2's dark column — never mixed by eye.** The design program's
  worst recorded error was twice inventing dark tokens when the real column existed
  (§6 of the program report). The build does not repeat it: if a dark value seems missing,
  the answer is "search the design system, then ask" — never "approximate it".
- **Fixed tokens are outside the theme and are never re-mixed** (design-system law R-07/R-08):
  the eight status pills, priority dots, SLA chip, reply-window chip, connection state,
  channel-health rollup, note tokens, and the violet AI accent keep their exact values in
  both colourways; status hues are reserved to their semantic components — generic
  pass/fail UI uses the neutral success/danger tokens.
- **Every frame, every state, every viewport, every theme, every language.** A design file's
  frames are enumerated like spec ids: idle, loading, empty, error, edge and race states,
  1440 and 375 (and the filed exceptions), light and dark, English and Amharic — each drawn
  frame maps to an implemented state. The drawn Amharic strings are the `am` translations
  (i18n keys as always; the design's copy is the copy).
- **Layout and behavior as drawn**: information order, component choice, interaction
  patterns (the visible-and-focusable bulk-bar reason, the single-input segmented code
  field, the MERGE token beside the input, the 56px stop button with its named-consequence
  contract on incident surfaces only, the 44px mobile floor over desktop metrics). Where a
  design encodes a ruling, the ruling ships.

## 3. What MAY deviate — narrow, named, and never silent

The owner permits customization only in these classes, and **every instance is filed**:

1. **Technical necessity** — a rendering, accessibility, framework, or performance
   constraint makes the drawn form unimplementable as-is. The implementation gets as close
   as the constraint allows.
2. **Required functionality the design doesn't draw** — the documents demand a behavior or
   state no frame covers (designs can't draw everything; the specs still bind). The gap is
   filled using existing design-system components and patterns — the empty-state pattern,
   the confirmation component, the per-key settings row contract — never invented styling.
3. **Genuine design defects** — a measured error in a frozen file (wrong contrast, a token
   the system doesn't contain). Not fixed unilaterally: flagged to the owner, who routes it
   to the design track for a scope-limited reopen or rules an implementation-side value.

**Filing discipline:** every deviation, from any class, is recorded in a
`DESIGN-DEVIATIONS` section of BUILD-STATUS — design file + frame, what differs, which
class, why — AND named in the session's close-out report. A deviation that isn't filed is
treated as a fidelity violation. "I improved it" is never a class.

**Explicitly forbidden:** substituting components ("a dropdown works like the drawn
segmented control"), skipping drawn states because they're rare, dropping a viewport or
theme or language, re-spacing "to feel better", using a UI library's default styling where
a drawn component exists, and omitting anything from a design file without a filed
deviation. Nothing is skipped, nothing is left out, nothing is "taken as inspiration".

## 4. How a session works with design files (the loop)

1. **The owner attaches** the design files for the surfaces in scope (plus B1-DS v2 + the
   addenda + v4, always) directly to the terminal session, alongside the session prompt.
2. **The plan table maps frames like ids**: for each designed surface, the plan enumerates
   the design file's rows/frames and maps frame → component/file → proving test, exactly as
   it maps FR/AC ids. A designed surface with no frame rows in the plan is an incomplete
   plan.
3. **Tokens first**: before the first screen, the session encodes the design-system tokens
   once (or verifies the existing encoding against the files) — all subsequent styling
   consumes tokens, never literals.
4. **Proof**: functional states proven as always (tests, sabotage-before-counting); visual
   fidelity proven by pixel baselines where they exist — `B7-AC09-manifest.json` names 44
   auth frames (language × theme × viewport, each with its target PNG name) that become
   Doc 02 AC-09's baselines the moment the owner fires the export; later batches' exports
   attach the same way as their surfaces get built. Until a surface's baselines are
   exported, its traceability row says so instead of claiming visual coverage.
5. **Acceptance lists become tests**: B12-A01's nine behaviour rows, B3-A01's one, and
   B4-A01's three are build obligations — each closes as an executed test when its surface
   is built, cited by row id. (Batches 5–7's equivalent obligations currently live as prose
   in their closing audits; if the owner commissions the three missing acceptance files,
   they bind the same way.)
6. **Conflicts and gaps**: design-vs-document conflict → STOP and ask. Design gap → class-2
   fill with design-system patterns, filed. Design defect → class-3 flag, wait.

## 5. Session-prompt obligation (binds the advisor)

Every session prompt whose scope touches a user-visible surface MUST: name this file in its
reading list; list the exact design files the owner will attach; require the frame-mapping
rows in the plan table (§4.2); and carry this sentence verbatim in its SCOPE block:

> "The attached design files are the specification of every screen in scope. Implement them
> at 100% fidelity per DESIGN-FIDELITY-MANDATE.md — every frame, state, viewport, theme,
> and language, tokens from the design system only, deviations only in the mandate's three
> classes and every one filed. Nothing skipped, nothing approximated, nothing redesigned."

Sessions with no designed surface in scope (pure backend documents) read this file once and
carry no further obligation from it.
