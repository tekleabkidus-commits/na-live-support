# PACKET P-DOORS (S12 step 5; escalate-first — PROPOSED, countersign before landing; folds the plan-approved P-ESC shape)

FINDING (filed in the step-4 record): Doc 06's TransferTicket and TicketTags
doors take a staff `User $actor` (not nullable), and TransitionContext's own
constructor teaches the law — a system act must NOT carry a User (misattribution,
SEC-03). Doc 12 FR-03 needs system-actor arms for transfer_department,
add_tags/remove_tags, escalate, and a DIRECTED agent assignment. Four clauses,
each a Doc 06/Doc 11 core edit:

1. TransferTicket::transferBySystem(Ticket, string $departmentId, string $reason,
   string $ruleRef, ?string $queueId = null) — same internals, actor_type=system
   on the event, audit carries rule_ref; permission check skipped exactly as
   TransitionContext::system does (FR-04's vocabulary). Fail-first control: the
   system transfer WITHOUT the packet = no such method (the arm answers
   unbuilt_action today — the control is the current state).
2. TicketTags::{addBySystem,removeBySystem}(Ticket, array $tags, string $ruleRef)
   — same normalization/audit path, actor system + rule_ref.
3. DIRECTED agent assignment — proposal (advisor picks the shape):
   (a) RoutingPipeline hint `explicit_assignee_id` (Doc 11 edit): precedence
       BEFORE strategy, capacity/scope/RG checks intact, record step
       'assign' source=explicit_rule — the engine stays the ONE assigner; or
   (b) AssignTicket::assignBySystemDirected (Doc 06 edit): a RoutingCommit-less
       system door with its own record write.
   The session recommends (a): every existing guard (capacity, eligibility
   scope, RG finality, the record) applies unmodified.
4. EscalateTicket::escalateBySystem(Ticket, EscalationPath, string $reason,
   string $ruleRef) — the plan-approved P-ESC shape (STOP-F), landing here so
   both consumers (the FR-03 escalate action and FR-08's breach-escalation
   hook, step 6) get ONE door.

Every clause lands with: the owning doc's suites green in the same commit, a
fail-first control per clause, actor_type=system + rule_ref on events/audits,
assertions of the existing staff paths byte-untouched.
