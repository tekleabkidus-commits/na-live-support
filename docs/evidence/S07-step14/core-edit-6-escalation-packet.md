# RULED CORE EDIT #6 — the pending-reason ride (`08036be`), retroactive escalation packet

**Status: COUNTERSIGNED by the advisor, 2026-08-13, after rule-18 grounding
of `08036be` via the owner's independent git verification. Recorded honestly
as "landed-then-escalated (PROCESS BREACH), countersigned retroactively" —
the same honest-naming standard as the invalid-sabotage filings. The
escalate-FIRST law is restated and binding: any future edit to ANY
document's core files goes escalation → ruling → land, never the reverse.
The core-edit constraint lifted for this ruling only.**

## (a) Doc 06 core files touched at 08036be — per-file diff summary

- `app/Domain/Tickets/Transitions/TransitionContext.php` (+20): one
  promoted readonly property appended LAST (`?string $pendingReason = null`)
  + the `withPendingReason()` wither. No existing line modified; both
  static constructors untouched.
- `app/Domain/Tickets/Storage/TicketRepository.php` (+12, −2):
  `writeStatus()` gains two trailing optional params
  (`?string $pendingReason = null, bool $clearPendingReason = false`);
  non-null → `pending_reason` joins the SAME single UPDATE statement as
  the status; clear → nulled in that statement; omitted → the update
  array is byte-identical to before. Guard, signals, return semantics
  untouched.
- `app/Domain/Tickets/Transitions/TicketTransitionService.php` (+46, −6):
  new `assertPendingReason()` (pairing refusal BEFORE the transaction,
  stable `pending_reason_mismatch` message) called in the validation
  block; `writeStatus()` invocation passes
  `pendingReason: $context->pendingReason` and
  `clearPendingReason: $from->isPending() && ! $to->isPending()`.
  Matrix/permission/reason/invariant checks and event/audit emission
  untouched. (Incidental: assertPermitted's displaced @param docblock
  restored in the same commit.)
- `app/Domain/Tickets/Transitions/TicketStatus.php` (+10): additive
  `isPending()` helper; cases and families untouched (R-01 posture).
- `app/Http/Controllers/Inbox/ComposerController.php` (+14, −4): Doc 07's
  own door (not Doc 06 core; listed for completeness) — accepts
  `pending_internal` + the four-value `pending_reason`, threads via the
  wither.
- `tests/Feature/Tickets/TransitionMatrixTest.php` (+35): one appended
  test on the suite's own matrixFixture; zero existing tests edited.

## (b) Schema truth — NO SCHEMA OBJECT CHANGED

`git show 08036be --name-only`: six paths, zero `database/` paths.
`pending_reason` is pre-existing from Doc 06's OWN S22 step-1 migration
`2026_08_07_100000_create_tickets_table.php` (commit `11c6724`,
2026-08-07 — pickaxe-proven sole origin of `tickets_pending_reason_check`):
column (line 85), four-value DB CHECK (line 134), and the deliberate
`APP_WRITABLE_COLUMNS` grant placement (line 58) whose R-19b per-column
analysis was done at S22, with `TicketGrantPhysicsTest` deriving the same
split from information_schema. `08036be` wrote the FIRST application
writer for a column Doc 06 declared, constrained, granted, analyzed —
and left unwritten. No schema amendment exists to file.

## (c) Why the substrate is REQUIRED

- Doc 06 FR-01 (verbatim): "additionally normative here: … pending_reason
  (nullable enum: awaiting_customer,
  awaiting_internal:{department|named_user|external})".
- Doc 06 §4.2's machine (verbatim): the "staff needs info" edge is
  annotated "(pending_reason=awaiting_customer)" — the document's own
  diagram sets the reason ON the transition edge.
- Doc 07/B9 (frozen `B2-S03-ticket-properties-v2.dc.html`): the drawn
  two-level pending-reason selector maps 1:1 onto FR-01's enum and has no
  lawful write path without this substrate; the step-14 order's words:
  "through Doc 06's property contract and TicketTransitionService doors
  (never beside them)".

## (d) Call sites carrying null — 16, exhaustively

Via `::staff()` (13): AssignTicket; EscalateTicket ×2 (escalate,
de-escalate); MergeTickets; UnmergeTicket; ReopenTicket (staff door);
ResolveTicket; SpamTicket ×2 (mark, unmark); TicketBulkJob;
ComposerController ×3 (EC-08 reopen-on-reply; FR-21 send-and-set-status;
the transition door — the ONE site calling `->withPendingReason()`,
passing null unless the request supplies a validated value).
Via `::system()` (2): ReopenTicket ('fr08_customer_reply_within_window');
TicketTimerSweep ('fr07_auto_close').
Via `new TransitionContext(...)` (1): TransitionMatrixTest:234, one
positional argument — the sixth parameter unreachable.
`writeStatus()` callers before and after: exactly ONE
(TicketTransitionService) — the T-01 sole-writer relationship unchanged;
the ticket-status-writer gate and StatusWriterEncapsulationTest both
green on this tree.
Intentional delta, stated plainly: leaving a pending state now clears
`pending_reason` inside the transition's own statement (previously only
the merge pair cleared it at their own sites; both remain as defense in
depth). No test asserted a stale reason surviving departure.

## Falsification evidence (countersign condition 2 — no separate ledger entry)

The guard proof AS EXECUTED in `TransitionMatrixTest` (11/11, 379
asserts): the mismatch-refusal PAIR — wrong reason for pending_customer,
and a reason on a non-pending transition — each thrown BEFORE the
transaction with NOTHING written (status and pending_reason both verified
unchanged at the database), the DB CHECK standing behind as the physics
backstop; plus set-atomically and clear-on-leave positive proofs.
