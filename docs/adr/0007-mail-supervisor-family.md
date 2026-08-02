# ADR 0007 — A dedicated `mail` queue family for authentication email

**Status:** accepted
**Date:** 2026-08-02
**Owner trigger:** Doc 02 step 8 plan approval (FR-16/FR-17 reset & invite email, FS-02)

## Context

Doc 02 FS-02 requires password-reset and invitation email to be **queued** with
retry ×6 backoff, and requires the backlog to be observable when the email
provider is down. CLAUDE.md §3 fixes Horizon's per-family supervisors —
telegram-in/out, webchat-in/out, media, ai, sms, automation, reports,
outbox-relay, audit — and none of those families is email. Queued mail has to
ride *some* queue, so the constitution's list needed either a new family or a
tenant: riding `automation` was the alternative considered.

## Decision

**A new `mail` queue family with its own Horizon supervisor, owned by Doc 02.**

Owner ruling, recorded verbatim in intent:

1. **Reset email is the account-recovery path** — ruling Q1's escape hatch for
   a multi-tenant password collision, and EC-11's guaranteed self-unlock for a
   victim whose account an attacker is deliberately locking. A recovery email
   queued behind bulk work (a reports run, an automation sweep) turns "check
   your inbox" into an outage of the one path that must never be blocked.
2. **FS-02's admin-visible depth requires its own queue.** "Email provider
   down → resets/invites queued, queue depth visible" is only a meaningful
   signal if the queue contains nothing but mail; a shared family's depth
   conflates a mail backlog with whatever else the family carries.

The family is named `mail` **generically**: future notification email (Doc 07
in-app digests, Doc 14's email half if any) rides the same family rather than
each document minting its own. Doc 02 owns the family's existence; later
documents add consumers, not supervisors.

## Consequences

- `config/horizon.php` gains the `mail` supervisor, same shape as its siblings.
- Queued Mailables in this codebase set `onQueue('mail')`; the queue lives on
  the durable Redis connection like every other queue (CLAUDE.md §3 —
  `redis-durable`, `appendonly yes`), because a reset email accepted and then
  lost to an eviction is exactly the failure FS-02 describes.
- FS-02's "queue depth visible to admins" is satisfied by Horizon's per-queue
  depth for now; the tenant-admin surface is deferred with the platform
  console (recorded in BUILD-STATUS and traceability).
- No new dependency: Laravel's queued mail over the existing Horizon stack.
