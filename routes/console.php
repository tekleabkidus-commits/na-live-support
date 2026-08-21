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

// Doc 05 FR-22: the reply-window sweep's 5-minute drift bound (event-driven
// updates bypass the cadence). Step-8 gap closed at step 11 — see BUILD-STATUS.
Schedule::command('telegram:window-sweep')->everyFiveMinutes()->withoutOverlapping();

// Doc 05 FR-29/FS-02: getWebhookInfo poll + silent-broken detection, 5-minute
// cadence per the document; event-driven health rides Doc 04's tracker.
Schedule::command('telegram:health-poll')->everyFiveMinutes()->withoutOverlapping();

// Doc 07 NFR-07/SS-04 (S07 step 15): the read-model consistency checker —
// every view ZSET compared against its direct scoped query, hourly, with
// drift ALERTING through the command's own non-zero exit + error log (the
// SS-04 "week with zero drift alerts" measurement runs on exactly this).
Schedule::command('inbox:consistency-check')->hourly()->withoutOverlapping();

// Doc 08 NFR-05: the WEEKLY rollup checker (drift ≤ 0; non-decay drift
// alerts; contacts_30d_count window decay heals silently — the command's
// docblock carries the reasoning).
Schedule::command('customers:consistency-check')->weekly()->withoutOverlapping();
Schedule::command('ai:partitions-maintain')->monthlyOn(1, '02:10')->timezone('UTC'); // Doc 09 (SCHEMA AMENDMENT 3): the ai_decisions window
Schedule::job(new App\Jobs\Ai\ReduceAiDecisionPayloads)->dailyAt('03:10')->timezone('UTC'); // Doc 09 SEC-06: the 30-day payload reduction (privacy by schedule)
Schedule::command('ai:embed-ticket-summaries')->dailyAt('02:40')->timezone('UTC'); // Doc 09 T-05: nightly resolved-ticket summary embeddings (Copilot similar tickets)
