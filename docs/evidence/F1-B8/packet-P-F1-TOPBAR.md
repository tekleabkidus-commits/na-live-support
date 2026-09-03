# PACKET P-F1-TOPBAR (SESSION-F1 [B8]; escalate-first — PROPOSED, countersign before landing)

THE FILE: Doc 07's inbox shell top bar (the component carrying `data-testid="agent-status"` and `data-testid="profile-menu"`).

THE FINDING (SESSION-F1 L04, the Amharic sweep at 375): with the staff user's locale = am, the plain `/inbox` list page is 48 px wider than a 375 px viewport before any Copilot UI opens. The agent-status button (width 75, right edge 378) and the profile-menu button (right edge 423) sit in a row that does not wrap or shrink for the longer Amharic status word. Evidence: `docs/evidence/F1-B8/shots/diag-am-375.jpg`. The AI surfaces themselves have no overflow (the Copilot sheet's own scrollWidth is measured in `f1-b8.spec` L04).

THE EDIT (one file, presentation only): let the top-bar's right cluster shrink — `min-width: 0` on the status button's label with `text-overflow: ellipsis` (or hide the status WORD under 640 px and keep the dot + `aria-label`), so the cluster fits 375 in both languages. No logic, no testid, no copy change.

CONTROLS: `inbox-*.spec.ts` byte-untouched; the F1 sweep re-measures the document overflow on `/inbox` at 375 in am after landing.

UNTIL COUNTERSIGNED: the shell stays as built; the fidelity register carries L04 as DONE for the AI surfaces with this finding named (B8-D37).
