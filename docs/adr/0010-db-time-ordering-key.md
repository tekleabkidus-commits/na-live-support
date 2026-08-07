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
