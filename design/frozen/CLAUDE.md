# Na Live Support — standing instructions

## Design system is source of truth
`B1-DS-design-system-v1.dc.html` is opened at the start of every session and stays open as the
source of truth, through Batch 7.

Any reused component is taken from it **verbatim** — never rebuilt from a screen, a screenshot,
or memory. Matching colours by eye from an approved screen is what caused the drift corrected in
`B12-R01-drift-report-v1` (79 corrections across 7 files).

Fixed tokens that a tenant cannot re-skin, and that must never be re-mixed:
status pill hues, priority dots, SLA chip, reply-window chip, connection state, channel health,
internal-note tokens, and the AI accent (violet — all AI-origin content carries it).

Mobile variants inherit the 44px touch floor: reconcile colour and type only, never below the floor.
The program rule outranks a component's desktop metrics. Flag every such deviation in the delivery note.

Authority chain for the AI accent: Doc 07 §9 mandates that all AI-origin content carries it; the
violet token itself is law by way of approved `B1-DS`.

On approval, `B12-S01` lightbox chrome (scrim, header, filmstrip, zoom bar, description slot) is
promoted to a `B1-DS` addendum and reused from there, never rebuilt by eye.

## Program rules
- The specification documents are law. Nothing may be missed, renamed, merged, or invented.
  Silence in a document is a question for the owner, never an opening for invention.
- Improvements are proposed as "OPTION, not in spec:" and are never silently baked in.
- Approved files are frozen reference law. A reopen must be explicit and scope-limited, and every
  touched file gets a version bump.
- Every screen ships both viewports (1440 + 375), both themes, and every state the documents name.
- Realistic Ethiopian data, English and Amharic, never lorem ipsum. Amharic is flagged for native
  review by the owner's own agents before i18n keys freeze.
- A false ✅ in an audit is the worst failure in this program. Partial work is ❌ with a reason.
