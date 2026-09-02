# PACKET P-SEND (S12 step 9; escalate-first — PROPOSED, countersign before landing)

T-06's own words: "unresolved detection blocks at the send API, not only UI."
The AUTOMATION half is enforced (SendMacroReplyAction refuses blank_variable
through MacroGuard — proven in MacroNoBlankSendTest). The STAFF half's send API
is Doc 04's ComposeOutbound (Doc 07's composer posts through it).

THE EDIT (Doc 04's ONE file, one guard): ComposeOutbound::compose refuses a
deliverable body matching MacroGuard's unresolved-pill grammar with the stable
code `blank_variable` (C-04) — the same one-line posture as its note/blocked
guards. Notes are exempt (not deliverable; a draft may hold pills). The B12-S03
drawn law completes: "It is never sent as-is — the send button stays off" (UI)
AND the API refuses (physics).
CONTROLS: fail-first — TODAY a pill-carrying body passes compose (asserted in
MacroNoBlankSendTest as the packet's pinned pre-state); after landing, that
assertion flips (compose throws blank_variable) and Doc 04's suites run green
in the same commit (no existing fixture sends literal {{...}} — verified by
grep before filing).

---
COUNTERSIGN GRANTED AS FILED (batch-C acceptance, grounded at b0365de: one guard,
notes exempt, pinned pre-state flips, Doc 04 suites green same commit). LANDED
at the step-13 opener (this commit) under KEEP-AND-VERIFY: the edit was found
drafted on the inherited dirty tree, diffed against this packet line by line
(one guard in ComposeOutbound after the note/blocked guards; the pinned
pre-state assertion in MacroNoBlankSendTest FLIPPED to the refusal + a clean
body still sends), then verified — Doc 04 conversations + NoteIsolation +
macros + the flipped pin: 110 passed / 675 assertions exit 0
(docs/evidence/S12-step13/psend-suites-02.txt; -01 is the prior session's
capture of the same run, kept); make lint exit 0 (psend-lint-01.txt);
make gates 14/14 exit 0 (psend-gates-01.txt).
