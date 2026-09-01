# PACKET P-CHIP (S12 Batch C head; approved-to-file at the batch-B acceptance — PROPOSED, countersign before landing)

THE GAP: Doc 07's FR-11 chip render reads `ticket_list_items.sla_state` /
`sla_due_at`, which nothing feeds in production (the browser seeders hand-paint
it). Doc 12's sla.warned / sla.breached events now exist and carry
{ticket_id, stage, due_at, late_fired}; Chips (fixtures/sla/doc07-chip-contract.json)
is the contract. Closing row 11's BoostMatcher half reads REAL stages already —
this packet is the RENDER half.

THE EDIT (Doc 07-owned files; the S11 P-C2 rescue-arms precedent):
1. ListProjector gains three arms: sla.warned → sla_state='warned' +
   sla_due_at=<payload due_at>; sla.breached → 'breached' + due_at; and the
   CLEAR — on ticket.status_changed to resolved/closed, or on the staff-reply
   projection path the projector already owns, sla_state → null where the live
   stage cleared (the projector re-reads Chips::forTicket — values from truth,
   the RollupConsumer posture — never from payloads).
2. ConsumerRegistry: ListProjectorConsumer joins sla.warned + sla.breached
   (pins move with the registry, the routine line).
3. fixtures/inbox/consumers/doc07-events.json gains the two events' read-slices.
CONTROLS: fail-first — before the arms, a warned stage leaves sla_state null
(pinned); after, the projector answers Chips' truth (values-from-truth leg: a
poisoned payload due_at never lands — the projector re-reads). Doc 07's
owning + contract suites green in the same commit. Browser seeders KEEP their
hand-paint until the fidelity session (stated: repointing scenes is [B12-batch]
adjacent, not this packet).
