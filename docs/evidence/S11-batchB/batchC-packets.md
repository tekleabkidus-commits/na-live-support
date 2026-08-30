# S11 BATCH B CHECKPOINT — the packets Batch C needs countersigned BEFORE it lands (escalate-first)

## P-C1 — SCHEMA AMENDMENT 5, ADDITION 1: `rescue_flags` (Doc 11 FR-16 / AC-06; Doc 07 FR-05's Rescue population)
Doc 07's Rescue view predicate is `tickets.channel_unreachable = true` in BOTH forms (SystemViews::directQuery
and ListProjector's per-ticket sync) — Doc 05's field. FR-16 adds two more rescue conditions that carry no
column: (a) heartbeat-lost agent's tickets after `routing.rescue.minutes`, (c) orphaned tickets (queue deleted
mid-flight). Proposal: `rescue_flags` (id, tenant_id, ticket_id FK, reason CHECK heartbeat_lost|orphaned,
flagged_at DB-time, cleared_at nullable, auto_returned bool) — INSERT + UPDATE(cleared_at, auto_returned)
grants, REVOKE DELETE, FORCED RLS, partial unique (tenant_id, ticket_id) WHERE cleared_at IS NULL. R-19b:
flagged_at app-unwritable (ordering-relevant for the Rescue sort). The `unreachable` condition stays Doc 05's
column (no duplication).

## P-C2 — Doc 07 core edit: the Rescue predicate widens to `channel_unreachable OR live rescue_flag`
`SystemViews::directQuery('rescue')` gains `->orWhereExists(rescue_flags live)`; `ListProjector`'s rescue sync
reads the same predicate (the NFR-07 dual form — both sides change together, `SystemViewMembershipTest`'s
rescue fixture grows the two new conditions); `ListProjector` gains `rescue.flagged` / `rescue.auto_returned`
as consumed events (registry entries + the Doc 07 contract fixture already generated at step 6). The Rescue
row's reason surfaces through the existing row payload ([D15]/Doc 07 rendering unchanged: the view TYPE exists).

## P-C3 — NoPollingLoopTest allowlist AMENDMENT (filed BEFORE the run): + `routing:rescue-sweep`
FR-16a's "after routing.rescue.minutes" is a TIMER: a ZSET by deadline (the TicketTimers pattern) armed on
`agent.status_changed{reason: heartbeat_lost}` per assigned open ticket, disarmed on the agent's return, swept
by a 1-minute `routing:rescue-sweep` that flags (and, with `routing.rescue.auto`, returns to queue with a
system note). A timer sweep is not a queue poll — the dispatcher stays event-driven — but the pin is exact,
so the allowlist grows by ONE named command through this filed amendment (enumeration law).

## P-C4 — `{{queue_wait}}` consumption (Doc 05 seam retirement + Doc 09 HandoffDecision interpolation)
See `docs/evidence/S11-step8/packet-queue-wait-consumption.md` (filed at step 8's drafting).

## P-C5 — Broadcast DELIVERY note ([D16])
`Routing/Manual/Broadcast` returns the [D16] delivery contract {queue_id, recipients, category, note}; no
Doc 07 bell rows are written by Doc 11 (Doc 07's `inbox_notifications` is the projector's). If the advisor
prefers immediate in-app delivery, a Doc 07 packet would write per-member `notify.routing.broadcast` rows —
presented as an option, not chosen.
