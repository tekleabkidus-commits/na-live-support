# PACKET P-CLS (S12 step 13; escalate-first — PROPOSED, countersign before landing)

THE GAP: Doc 12 FR-03's run-AI-task names THREE catalog-bound tasks —
summarize→note, classify-refresh, translate-last-message→note. Two landed at
step 13 on Doc 09's PUBLIC doors (LayeredAssembly, AiOrchestrator, OutputGuard,
RefusalDetector; the note through Doc 04's NotesWriter::addSystemNote). The
third cannot: Doc 09's one classification door, InboundClassification::classify
(messageId), classifies AND THEN EXECUTES THE ROUTE it decides (RouteExecutor —
a chatbot turn, an RG override, a handoff). A rule asking for a "refresh" must
never re-run a chatbot turn behind the customer's back; the arm therefore
answers `unbuilt_ai_task` for classify_refresh today — ASSERTED in
ExternalArmsTest (the P-DOORS precedent: honest until countersigned).

THE EDIT (Doc 09's ONE file, InboundClassification):
- `classifyOnly(string $messageId, string $surface = 'chatbot'): ?string` —
  the SAME burst assembly, the SAME classify_message template, the SAME
  normalize + RgClassifier two-stage confirmation, the SAME DecisionRecord
  write (so the FR-02 `ai.*` condition facts — which EventContext reads from
  the latest classify_message decision row for the message — refresh from a
  REAL decision row, never a shadow copy) — and NO RouteExecutor call. The
  existing classify() becomes classifyOnly() + the route execution, byte-
  identical in behavior (the extraction is the refactor; the drill pins hold).
- The decision row records `action: 'copilot_context'` (the catalog's own
  "no act — the row IS the delivery" verb) so replay/simulation (AC-03 of
  Doc 09) reads it as a non-routing decision.
- SURFACE: the ai_decisions CHECK admits chatbot|copilot|supervisor. The
  session proposes recording the refresh under `surface = 'chatbot'` with the
  automation rule_ref in requestPayload (no schema change; the row is what
  the chatbot's facts read anyway). ALTERNATIVE for the advisor: an
  `automation` surface value (a Doc 09 CHECK amendment) if Doc 15 should
  count these calls separately — the session has no preference and asks.
CONSUMER (Doc 12, lands with the countersign): RunAiTaskAction::BUILT gains
classify_refresh; RunAiTaskJob calls classifyOnly on the conversation's
latest customer text message (batch tail); the `unbuilt_ai_task` pin FLIPS.
CONTROLS: fail-first — the pinned `unbuilt_ai_task` (today); after: a stale
intent on a decision row → a refresh writes a NEW classify_message decision
row for the message, EventContext's `ai.intent` fact reads the new value,
and NO chatbot turn / handoff / route side effect occurs (the RouteExecutor
spy control: zero calls; the outbound message count unchanged). Doc 09's
own suites (the golden + red-team floor, the drill pins) green in the same
commit; classify() byte-identical in behavior (ChatbotTurnTest untouched).

---
COUNTERSIGN GRANTED (close-out recount rulings, grounded at 51d0292) with
surface = 'chatbot' as proposed; all controls as filed. LANDED at the S12
close (the commit carrying this note): `classify()` = `run(executeRoute:
true)` and `classifyOnly()` = `run(executeRoute: false)` — byte-identical
behavior for classify() (the same code path; the route executes only under
the flag; a quota/provider refusal on a refresh returns null — no fallback row,
no bypass handoff). RunAiTaskAction::BUILT gained classify_refresh; the
`unbuilt_ai_task` pin FLIPPED (ExternalArmsTest); ClassifyRefreshTest carries
the controls (the RouteExecutor zero-call spy; the new decision row with
action copilot_context + refresh marker; the ai.intent fact reading the new
value through EventContext; outbound count unchanged; the Doc 09 suites green
in the same commit). The Doc 15 separate-counting reopener sentence is filed
in ANSWERS ("P-CLS LANDING NOTE").
