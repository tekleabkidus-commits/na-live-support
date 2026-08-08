# 0011 — The outbox relay dispatches durable consumers

## Status
Accepted — owner ruling, 2026-08-08. Extends Doc 01's outbox; changes no existing
event, payload, or relay ordering semantics.

## Context
Doc 01's OutboxRelay appends outbox rows to the events table and marks them relayed.
Nothing fans out to subscribers. Doc 04's only consumer (RealtimePusher) is invoked
inline post-persist, justified because a realtime push is ephemeral — the bus is the
durable truth. Doc 06 FR-08 is the first DURABLE consumer requirement: a customer
reply must reopen its ticket even if a worker dies mid-flight, and FS-02 explicitly
promises the consumer "catches up from the event backlog". No existing mechanism can
keep that promise.

## Decision
The relay gains a dispatch map: event name -> list of queued consumer jobs. After a
row is appended to events and marked relayed, the relay dispatches each mapped job
with the event id. Consumers are idempotent on event_id (C-09 already mandates this),
so the outbox's at-least-once delivery is safe. Backlog catch-up is inherited, not
built: an undispatched or failed consumer job is re-driven through the existing
dead-letter machinery, and a relay restart re-dispatches nothing already relayed —
the map fires at relay time, once per row, with the queue providing durability from
that point.

Doc 06's TicketReopenConsumer (message.received -> FR-08 reopen / EC-01 cancel-wins /
closed-beyond-window new ticket with previous_ticket_id) is the first entry. The map
is a registry: adding a consumer is a registry change reviewed like any other, never
an edit to relay logic.

## Consequences
- The relay stays ordering-agnostic: dispatch happens after append+mark, adds no new
  failure mode to relaying itself (a dispatch failure parks in the queue's dead-letter
  path, it does not un-relay the row).
- Inline ephemeral consumers (RealtimePusher) are unchanged and remain legitimate for
  ephemeral work; this ADR is the durable path.
- FS-02's "catches up from the backlog" is satisfied by queue durability plus
  re-drive, and must be proven by test: kill the consumer between persist and reopen,
  re-drive, assert the reopen lands exactly once.
