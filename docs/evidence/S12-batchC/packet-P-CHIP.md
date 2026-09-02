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

---
COUNTERSIGN GRANTED AS FILED (batch-C acceptance, grounded at b0365de: fail-first
null pin; poisoned-payload values-from-truth control; Doc 07 suites green same
commit; seeder hand-paint stays until the fidelity session). LANDED at S12
step 13 (this commit). WHERE THE MECHANISM LIVES, stated exactly: the row
builder `TicketListItems::recompute` (the Doc 07 read-model file the
projector calls on EVERY pass) feeds sla_state/sla_due_at from
`Chips::forTicket` for a ticket that HOLDS an sla_instance (amber → warned,
red → breached, green/none → null; due_at = the live chip's target) and
leaves the seam columns untouched otherwise; `Chips::hasInstance` (Doc 12's
file) is the one added door. `ListProjector` itself needed no new branch —
sla.warned/sla.breached carry ticket_id and ride its default recompute path;
the CLEAR rides the staff-reply / status-change paths it already owns because
the builder re-reads truth. ConsumerRegistry: the projector joined both flip
events; doc07-events.json gained the two read-slices; the ListProjectorTest
31-event pin → 33 (same commit). PROOF: tests/Feature/Inbox/SlaProjectionTest —
fail-first captured RED on the pre-landing tree (pchip-prestate-01.txt: the
row stayed null under a warned stage); after: Doc 07 suites + SLA suites +
the drill pins 144 passed / 975 assertions exit 0 (pchip-suites-01.txt);
lint exit 0; gates 14/14 exit 0. RESIDUAL, named: the SLA lifecycle consumer
and the projector ride the same event on the same family — if the projector
runs first the row heals on the ticket's next event (view membership is
unaffected: sla_at_risk also requires the open family). The FR-26
notify.inbox.sla_warning bell row now HAS a producer — its projector arm is
outside this packet's filed scope and is named for the checkpoint.
