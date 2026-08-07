# 0010 — The ordering key's leading component is database time

## Status
Accepted — owner ruling R-19, 2026-08-07. Supersedes nothing; narrows C-11's
"UUIDv7 monotonicity" alternative.

## Context
`messages.created_at` is the leading component of FR-07's display order and T-08's
cursor. It was written by Eloquent from the app-server wall clock, truncated to whole
seconds by the Postgres grammar's `Y-m-d H:i:s`. The migration declared `DEFAULT now()`,
but Eloquent supplied the value on every insert so the default never applied. A backward
clock step larger than the gap between two same-lane messages inverts a customer's
thread; under Octane no backward step is needed at all, because ordinary skew between
two app servers is enough, and whole-second truncation turns millisecond skew into a
whole-second inversion.

## Decision
Postgres supplies `created_at` via `clock_timestamp()` (per-row, per-statement) for
ordering- and metric-bearing columns. The application stops sending the value. Full
microsecond precision is retained. UUIDv7 `id` remains the same-microsecond tiebreak.

## Consequences
- Write path: `created_at` leaves the insert payload; the written value is read back
  (RETURNING or refresh) wherever the caller needs it — code that used a locally computed
  timestamp to build a cursor or an event payload must use the returned one.
- Composite PK `(id, created_at)` and partition routing are unaffected in shape; the
  column default satisfies the NOT NULL partition-key requirement at insert.
- Mixed precision across the cutover: existing rows are whole-second, new rows are
  microsecond. Comparison is unaffected. No backfill.
- `LaneOrderingForcedBackstepTest`'s two controls are characterisation tests of the OLD
  behaviour. They are INVERTED in the same commit — asserting that a forced clock
  excursion no longer inverts any lane — and thereby become the regression proof.
  Deleting or relaxing them is a gate-weakening change requiring the signed process.
- Doc 06 builds its FR-24 timestamps this way from the start.
- **Scope assessment for the two other ordered/partitioned `created_at` columns**,
  against R-19's scope sentence ("every column that participates in an ordering key, a
  cursor, or a reported metric"; non-ordering, non-metric timestamps reviewed at Doc
  18/19). Both were checked, not assumed:
  - `conversations.created_at` — **OUT OF SCOPE.** `ConversationRepository` contains no
    `orderBy` at all and never references the column; no cursor and no metric reads it.
    UNBLOCKER: Doc 06's ticket lists and Doc 07's Unified Inbox will sort conversations.
    The first sort or cursor that uses `conversations.created_at` brings it into scope
    and must convert it in the same session.
  - `events.created_at` — **DEFERRED, with an unblocker, not dismissed.** It is the
    partition key of the append-only domain-event stream, and it is written from the
    relay's app clock. Nothing orders by it today: `events` is only ever INSERTed
    (`OutboxRelay`), and the relay's own cursor is `outbox.id` (UUIDv7), not a timestamp.
    So it is out of scope by R-19's wording, but it carries the highest latent risk of
    the three, because a replay or analytics consumer (Docs 14/15) would naturally order
    an event stream by time and would silently inherit this defect. UNBLOCKER: convert it
    at the FIRST ordered read or cursor over `events`, or when Doc 14/15 begins,
    whichever is sooner. The change is mechanically identical to this one — one
    `ALTER ... SET DEFAULT` plus one column grant — so deferring costs nothing but
    forgetting would cost the same debugging session twice.
- **The default is enforced by GRANT, not by discipline (owner addendum R-19a).** A
  column default is overridable, so `app` loses column-level INSERT on `created_at`:
  `REVOKE INSERT ON messages FROM app` then
  `GRANT INSERT (<every column except created_at>) ON messages TO app` — the ADR 0003
  pattern one level finer, in the column-list form already used for `tenant_keys`. A
  role that cannot name the column cannot supply it; the default still fills it. This
  is physics per CLAUDE.md §1, not a convention. `platform_admin` retains table-level
  INSERT so fixtures that must write historical rows (retention, partition routing)
  keep that capability; any future fixture needing a chosen `created_at` writes through
  `pgsql_platform`. The grant is checked on the parent partitioned table, which is
  where every write goes.
- New tables inherit this rule from their first migration (advisor addendum R-19b):
  ordering- and metric-bearing timestamps ship with the clock_timestamp() default and
  the R-19a column grant from birth, with the schema-derived guard extended per table.
  Doc 06's tables are the first application.
