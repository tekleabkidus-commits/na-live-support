# S20 — the AC-03 soak's test-isolation defect (OPEN)

Captured evidence for BUILD-STATUS's S20 **RISK 0**. Kept in the repo because the
defect is unresolved and the next session should not have to reproduce it blind.

## What is wrong

Running the full Pest suite **with** `tests/Property/LaneOrderingSoakTest.php` makes the
soak die mid-body and every `Performance` test after it fail with
`SQLSTATE[42P01] relation "…" does not exist`. Running it **without** the soak passes.
The soak run **alone** is itself flaky — see the second problem below.

| Run | Command | Result |
|---|---|---|
| `RUN-NO-SOAK.txt` | `pest --exclude-group=soak` | **648 passed**, 11 skipped, 0 failed |
| `RUN-WITH-SOAK-2.txt` | `pest` (idle machine, nothing else running) | **7 failed** — the soak + all 6 Performance tests |
| `FULL-RUN-2.txt` | `pest` (earlier, same shape) | **31 failed**, cascading from `Feature/Rbac` onward |

## What is ruled out

- **Not a dropped schema.** The database still has its 53 tables after a failing run.
- **Not a concurrent `migrate:fresh`.** `TestCase::migrateOnce()` is guarded by a
  process-static; nothing else in the test path calls `migrate:fresh` (only
  `.github/workflows/ci.yml`, which is sequential), and no other pest process was running
  (`ps` inside the container was clean).
- **Not host load.** `RUN-WITH-SOAK-2` was taken on an idle machine.

## Prime suspect (hypothesis, NOT a finding)

The soak is the only test that calls `commitFixtures()` — it commits the harness's
per-test transaction because 10k writes across partitioned tables exceed
`max_locks_per_transaction` in a single transaction. The interaction between that commit,
the harness's `setUp`/`tearDown` transaction handling, and the separate `pgsql_platform`
connection used by `cleanupTenants()` is the place to look first. Note the soak's own
visible error is raised *in its `finally`* (`delete from "message_drafts"`), which masks
whatever failed in the body — instrument the body before trusting that message.

## Containment currently in place

`make test` runs `pest --exclude-group=soak`; `make soak` runs the soak alone.

**Second, separate problem: the soak is flaky even alone.** On a clean database it has
passed twice (209 assertions, zero violations) and failed once with **50 violations —
every lane**, which is systematic. Likely the drain loop exiting early and leaving
messages unsent (null external ids then fail the monotonicity check), unconfirmed.
**So AC-03's soak half is NOT proven and is recorded PARTIAL**; AC-03's
head-failure/lane-skip half is proven by `LaneFifoTest` in the default suite.

## A trap that cost time

The first recurrence was captured through `| tail -4`, which destroyed the failure detail
and left only a summary. When chasing this, redirect the **whole** run to a file.
