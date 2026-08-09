<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|------------------------------------------------------------------------------
| Scheduled foundation jobs (Doc 01)
|------------------------------------------------------------------------------
|
| The scaffolded `inspire` command is gone — a platform whose console prints
| inspirational quotes but not its own maintenance schedule has its priorities
| inverted. Everything here runs platform-scope on the scheduler container.
|
| EC-10's pairing is deliberate: maintain keeps the partition window rolling
| monthly; check verifies DAILY that it worked and that nothing has landed in
| the DEFAULT partition. The monitor's non-zero exit is the alert channel until
| Doc 18 wires real alerting.
|
*/

Schedule::command('partitions:maintain')->monthlyOn(1, '02:00')->timezone('UTC');
Schedule::command('partitions:check')->dailyAt('02:30')->timezone('UTC');

// FS-05: Redis quota counters reconciled against the durable ledger.
Schedule::command('quota:reconcile')->hourly();

// Doc 02 FS-05: expired API tokens pruned nightly (platform role — the app
// role cannot DELETE on that table, by grant). 03:00, after the partition
// jobs, so the maintenance window stays one contiguous block.
Schedule::command('tokens:prune')->dailyAt('03:00')->timezone('UTC');

// Doc 03 FR-09: expired overrides tidied (revoke + event + audit). Enforcement
// never waits for this — the resolver's DB-time check already denies; the
// cadence is bookkeeping latency only (AC-07's event within ≤15 min).
Schedule::command('rbac:expire-overrides')->everyFifteenMinutes();

// Doc 06 T-03: the 1-minute auto-close sweep. withoutOverlapping because a slow
// pass stacking behind itself would double-visit candidates; each visit is
// individually safe (in-transaction re-check), so overlap costs noise, not
// correctness — but noise every minute forever is still noise.
Schedule::command('tickets:timers-sweep')->everyMinute()->withoutOverlapping();

// Doc 06 T-04: unmerge snapshots pruned after their 24 h window. Hourly is hygiene,
// not correctness — FS-04's window check refuses a stale-but-unpruned row identically.
Schedule::command('tickets:merge-snapshots-prune')->hourly();

// Doc 06 FS-05: the nightly invariant auditor — exists precisely because it should
// never fire. A non-zero exit IS the page (the PartitionsCheck idiom); the critical
// log entry carries the row ids.
Schedule::command('tickets:invariant-audit')->dailyAt('02:30');
