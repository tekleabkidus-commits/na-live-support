# S21 STEP 0 — owner ruling R-18's gate: the AC-03 soak, root-caused

Captured evidence for R-18: *"Root-causing the AC-03 soak (deterministic result +
`make test`/CI reconciled) and the RISK 0b capture protocol are STEP 0 of the next
build session; no Doc 06 plan may be approved before the soak is deterministic."*

Every run in this directory was captured by `ci/capture.sh` **before** any filtering,
per the standing order in RISK 0b. The soak under test is
`tests/Property/LaneOrderingSoakTest.php` (path verified in the repo; group `soak`).

---

> ## STATUS: STEP 0 IS NOT COMPLETE
> R-18 requires the soak to be **deterministic** before a Doc 06 plan may be
> approved. It is not. Three runs of the fixed test on unmodified product code
> went **PASS / FAIL / PASS** (`soak-09`, `soak-10`, `soak-11`), and a second,
> independent defect — the thread **ordering key** — was found underneath the
> first. Both root causes are named below; only the first is fixed. See
> *"Determinism — NOT ACHIEVED"* and *"0a-bis"*.

---

## 0a — THE FIRST ROOT CAUSE (fixed)

**The soak could not tell "never delivered" apart from "delivered out of order", and
reported the first as the second.**

The mechanism is three lines of arithmetic:

1. A message that is never sent keeps `external_message_id = NULL`.
2. The receipt walk parsed it as `(int) str_replace('fake-out-', '', (string) null)`,
   and `(int) ''` is **0**.
3. Walking a lane, the first undelivered row scores `0 > -1` and passes; every
   later one scores `0 <= 0` and counts as an **order violation**.

So any lane holding two or more undelivered messages is reported as an ordering
violation — and with all 50 lanes affected the test prints exactly
**`50 violations`**, which is the historical red, to the number.

The old drain loop then supplied the undelivered messages. It exited on
`attempts === 0`, treating *"no lane was eligible this cycle"* as *"drained"*.
Those are different states: every head can legitimately be inside its backoff
window at the same instant. On that exit the loop `break`s with work outstanding
and the property is evaluated over messages that were never sent.

**Consequence: the historical "50 order violations" was never evidence about FIFO.**
It was a delivery/drain failure wearing an ordering failure's label — which is
precisely why it was never root-caused. The error message pointed at the wrong
subsystem, and the ordering code it accused was not the code that failed.

### Proof — positive control, on the real instrument

`OutboundLanes::push()` was temporarily stubbed to a no-op so that nothing could
ever be sent, and the **unmodified** soak was run:

| run | test | result |
|---|---|---|
| `soak-06.txt` | the ORIGINAL soak | `violations=50 kinds={"body-order":0,"receipt-order":50}` → *"Failed asserting that 50 is identical to 0"* |

with the truth in the same capture: `deliveryStates={"queued":10000}`,
`nullExternalId=10000`, `trueSent(db)=0`. **Not one message was delivered, and the
test blamed ordering.** The historical signature reproduced exactly.

The sabotage was reverted byte-identically (`git diff` empty) before anything else ran.

### The fix — completeness before order

The ordering property itself is **unchanged**; nothing was loosened, no assertion
was relaxed, no timeout raised, no message count reduced. What changed:

- A zero-attempt cycle is no longer read as "drained". It is only drained when
  every message is actually sent. Otherwise it counts as idle, bounded by a budget
  **derived from the product's own constant** — `LaneWorker::HEAD_BLOCK_CAP_SECONDS`
  (600 s) over the loop's 31 s/cycle, ≈ 22 cycles — after which the drain is
  declared `STALLED`.
- **Delivery is asserted before order.** A stall fails as a stall, naming the
  undelivered count; undelivered rows can no longer be counted as order violations.
- A NULL external id is an explicit named failure, never silently coerced to 0.
- The state dump prints **before every assertion that can abort the test**, because
  Pest truncates long assertion messages on screen (`'at cycle 23…t FIFO'`), so the
  message alone cannot be the evidence channel.

Same sabotage, fixed test:

| run | test | result |
|---|---|---|
| `soak-08.txt` | the FIXED soak | `drainExit=STALLED breakCycle=23 stallBudget=22`, `trueSent(db)=0 total=10000 nullExternalId=10000`, `deliveryStates={"queued":10000}` |

The failure now names the actual defect on the first line, instead of sending the
next session after an ordering bug that does not exist.

### Determinism — NOT ACHIEVED. Read this before quoting anything above.

> **Correction, S21 continuation.** Everything below this line was written at
> 13:47 local, *during* run `soak-09`. Two runs finished after it and the
> section it replaces was never updated, so the original text claimed a
> determinism that its own directory disproves. The claim *"across these runs
> the soak never went red on its own"* was **false within 25 minutes of being
> written**. It is corrected here rather than deleted, because how a false
> green survives is the thing this project keeps having to learn.

The fixed-test runs on unmodified product code are:

| run | result | diagnostics |
|---|---|---|
| `soak-09` | **PASS** | `violations=0` — fully drained, `trueSent=10000`, `nullExternalId=0` |
| `soak-10` | **FAIL** | `violations=3 kinds={"body-order":3,"receipt-order":0}` — fully drained, `trueSent=10000`, `nullExternalId=0` |
| `soak-11` | **PASS** | `violations=0` — fully drained, `trueSent=10000`, `nullExternalId=0` |

Three further runs were declared **in advance** (count fixed before running, so the
result could not be shopped for) and run with the instrumented soak:

| run | result | clock instrumentation |
|---|---|---|
| `soak-12` | **PASS** | `clockBacksteps=3 maxBackstepMs=435.108 clockSkewMs=-387.710`, `violations=0` |
| `soak-13` | **PASS** | `clockBacksteps=1 maxBackstepMs=9.132 clockSkewMs=-80.328`, `violations=0` |
| `soak-14` | **PASS** | `clockBacksteps=2 maxBackstepMs=7.457 clockSkewMs=-93.825`, `violations=0` |

Record on the fixed test, unmodified product code: **5 PASS / 1 FAIL in 6 runs.**

Three greens do **not** make it deterministic — one recorded red stands, and three
passes after it is exactly the pattern that must not be read as proof. What the three
runs *did* establish is far more useful than a green: **the host clock steps backward
on every single run** (3, 1 and 2 times), which converts the back-step story from a
hypothesis into a measured property of this environment.

They also explain themselves. Every measured backstep was far below the ~1 s gap
between two messages *of the same lane*, so each was absorbed — proven as the
THRESHOLD case in the probe. A violation requires a backstep **exceeding the same-lane
gap**, which the soak now measures directly as `laneBacksteps` / `maxLaneBackstepMs`
rather than leaving to inference.

### The second host — CI Stage 3b, and what it does and does not settle

The soak's first run on a **GitHub-hosted runner** (`runnervmvrwv9`, tree `186f58e` =
`d1c11f4`), captured by the same `ci/capture.sh` and downloaded from the job artifact
`ac03-soak-capture-31169076186-1` (filed here as
`ci-soak-31169076186-stage3b.txt`):

```
[SOAK DIAG] drainExit=loop-condition breakCycle=0 cycles=205 injected=5 stallBudget=22
[SOAK DIAG] sentCount(var)=10000 trueSent(db)=10000 total=10000 nullExternalId=0
[SOAK DIAG] deliveryStates={"sent":10000}
[SOAK DIAG] clockBacksteps=0 maxBackstepMs=0.000 clockSkewMs=-0.000
[SOAK DIAG] laneBacksteps=0 maxLaneBackstepMs=0.000  <- the body-order precondition
[SOAK DIAG] violations=0 kinds={"body-order":0,"receipt-order":0}
PASS — 1 passed (10211 assertions), 143.33s
```

**The environmental contrast is sharp and is the point.** This WSL2 host recorded
backsteps on *every* run (3 / 1 / 2, max 435 ms, net skew −388 / −80 / −94 ms). The
GitHub runner recorded **zero**, with wall and monotonic clocks agreeing to
`-0.000 ms` across the whole compose phase. That is exactly what
`hv_utils.timesync_implicit=1` predicts as a host-specific property, and it is now
measured on two hosts rather than asserted about one.

**What this settles: NOTHING about the clock. It is a NULL TEST.** The result is
`clockBacksteps=0` and `laneBacksteps=0` with `violations=0` — the third case, which
the decision tree did not enumerate. **The trigger was absent, so the hypothesis was
never exercised.** This run is not support for RISK 0c and must not be cited as such.

An earlier draft of this file argued the run "removes the outcome that would have
refuted it". That was inflated and is withdrawn. Violations are rare even on the host
that *does* step its clock — 1 red in 6 local runs — so a single green run had a low
chance of producing the refuting outcome whether or not the hypothesis is true. A test
that was unlikely to falsify the claim is not evidence for it.

The confirming observation is still outstanding: a run showing `laneBacksteps > 0`
**with** `violations > 0`. Until that exists, RISK 0c's mechanism is proven *by
construction* (the probe's positive control) and its role in the historical `soak-10`
red remains a **candidate, not a finding**.

**One caveat that cuts toward sensitivity, not away.** The runner completed the soak in
143 s against ~600 s locally, so its compose phase is ~4× faster and the gap between two
same-lane messages is correspondingly ~4× smaller. A smaller gap makes the soak *more*
easily inverted by a given backstep, not less. So the green is not explained by the
runner being a laxer instrument.

`Performance/RbacBudgetsTest` also passed on the runner, inside Stage 3's full suite —
consistent with the local flake being host-specific (p99 0.681–2.648 ms against a
1.5 ms ceiling over four isolated local runs).

So: **the soak is not deterministic, and AC-03's soak half remains unproven.**
`soak-10` is a genuine red on a fully-drained run — it is *not* the NULL-external-id
artefact that 0a root-caused (`nullExternalId=0`), and it is *not* a lane FIFO
failure (`receipt-order=0`). It is `body-order`: the **stored thread**, read in
FR-07 display order `(created_at, id)`, was not in compose order for 3 of 50 lanes.

The 0a fix is still correct and still worth having — it is what made `soak-10`
readable at all. It simply was not the whole defect.

### 0a-bis — what a `body-order` violation is, root-caused

Proved deterministically in `tests/Property/ThreadOrderingKeyProbeTest.php`,
captured in `orderkey-probe-01.txt`:

1. **`created_at` is an app-server wall clock truncated to whole seconds.**
   Eloquent writes timestamps with the Postgres grammar's `Y-m-d H:i:s`, so
   composing at `00:00:10.123456` stores `2026-01-01 00:00:10+00`. The column is
   `timestamptz` and the migration even declares `DEFAULT now()` — DB time — but
   Eloquent supplies the value on every insert, so the DB default never applies.
2. **Ties are resolved correctly.** `id` is a native `uuid` column (so no collation
   effect) holding a UUIDv7 from `HasUuids::newUniqueId()`, monotonic within a
   process. Two rows in the same second order correctly by `id`.
3. **A backward clock step defeats the key.** Positive control: four composes, the
   fourth after the clock steps back **200 ms** across a second boundary. The
   last-composed message is stored in **slot 1**:

```
[ORDERKEY PROBE] slot 0  ts=2026-01-01 00:00:10+00  body=a-first
[ORDERKEY PROBE] slot 1  ts=2026-01-01 00:00:10+00  body=d-fourth-after-backstep
[ORDERKEY PROBE] slot 2  ts=2026-01-01 00:00:11+00  body=b-second
[ORDERKEY PROBE] slot 3  ts=2026-01-01 00:00:11+00  body=c-third
[ORDERKEY PROBE] back-step — composed: a-first,b-second,c-third,d-fourth-after-backstep
[ORDERKEY PROBE] back-step — stored  : a-first,d-fourth-after-backstep,b-second,c-third
[ORDERKEY PROBE] INVERTED? true
```

   The negative control (forward-only clock, same second boundary) keeps compose
   order. So a backward clock step is a **sufficient** cause of `body-order`
   violations, and whole-second truncation is what turns a *sub-second* step into
   a *whole-second* inversion.

**This is a C-11 violation in the product.** C-11: *"Ordering decisions use DB time
or UUIDv7 monotonicity — never app-server wall clocks."* The thread ordering key's
leading component is exactly an app-server wall clock. Under Octane with more than
one app server, clock skew between servers reorders a customer's thread; no backward
step on a single host is even required.

**What is proven and what is not.** Proven: the mechanism, by construction, on the
real write path. **Not** proven: that this is what happened during `soak-10`
specifically — that run's evidence was captured before the instrument could name it.
The circumstantial support is that this host is WSL2 booted with
`hv_utils.timesync_implicit=1` and a Hyper-V TimeSync device, which steps the guest
clock against Windows, and that `soak-10` spent ~497 s of wall time beyond the
duration Pest measured (1058 s captured vs 561 s in-test), consistent with a host
suspend and resync. That is a candidate with a named mechanism, **not** a
confirmed cause of that run.

To close the gap the soak now measures the wall clock against the **monotonic**
clock while it composes and prints `clockBacksteps` / `maxBackstepMs` /
`clockSkewMs`, and a `body-order` lane is now **dumped by name** — first divergence
index plus each row's stored `created_at` — instead of only counted. The next red
answers the question by itself.

---

## 0b — `make test` / CI reconciled

The gap, exactly as found:

- `make test` = `pest --exclude-group=soak` — the soak never runs.
- CI Stage 3 ran bare `pest` — **the soak ran as an unnamed passenger**, inside the
  shared suite, which is the one configuration known to corrupt the tests after it
  — under a comment reading *"parity with local `make test`"*. That comment was
  false, and the falseness is what let a flaky proof survive a close-out.

Decision: **a dedicated CI job**, Stage 3b, `pest --group=soak` alone in its own
stack — the same shape as local `make soak`. Stage 3 now passes
`--exclude-group=soak` explicitly, so its parity comment is true. The soak job is
named in `deploy-staging`'s `needs:` so it is **blocking**, not a leaf job nobody's
release depends on, and its captured output uploads as an artifact on pass *or*
fail.

**What a developer running `make test` locally now does and does not exercise:**
`make test` runs the full suite minus the soak — AC-03's head-failure/lane-skip half
(`LaneFifoTest`) and the node-kill replay drill are exercised; **the 10 000-message
ordering soak is not**. It is exercised by `make soak` locally and by Stage 3b in CI.
"Is AC-03's ordering guarantee proven?" is answerable from **one** place: the Stage 3b
job and the artifact it uploads.

The soak is deliberately **not** registered in `registries/gates.yaml`: `make gates`
runs that list verbatim and a multi-minute soak does not belong in it. It is a
pipeline stage, and it is registered in traceability against Doc 04 AC-03.

---

## 0c — the capture-first protocol (governance incident #6)

`ci/capture.sh` writes the whole run — stdout and stderr interleaved — to a file
under `docs/evidence/` and only then passes it through, so filtering happens
*downstream of the file*. `ci/capture.sh soak … | tail -4` is now safe to type: the
terminal shows four lines, the file keeps every byte. Runs are never overwritten
(`-01`, `-02`, …) and the wrapped command's exit code is preserved.

`make soak` captures by default; `make capture LABEL=… CMD=…` wraps anything else.
Every diagnostic run in this session went through it — this directory is the proof.

---

## Still open (not fixed here, not hidden)

**The full-suite isolation defect** (RISK 0a's other half) is untouched. Running the
soak inside the shared suite still makes it and the Performance tests fail with
`relation "…" does not exist` against a database that demonstrably still has its
tables. Two things learned while root-causing the above, recorded so the next
session does not re-walk them:

- The prime suspect recorded in S20 — *"the soak is the only test that calls
  `commitFixtures()`"* — is **factually wrong**: twelve other test files call it and
  the suite is green without the soak. That hypothesis needs replacing, not pursuing.
- `search_path` is pinned to `public` on every connection in `config/database.php`,
  so the missing relations were a real absence at that moment, not a visibility
  trick. Combined with "53 tables present afterwards", that points at something that
  drops and recreates the schema mid-run rather than at a per-session setting.

Containment is unchanged and now explicit rather than implied: the soak runs alone,
locally and in CI.
