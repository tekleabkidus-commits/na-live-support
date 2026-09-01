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
