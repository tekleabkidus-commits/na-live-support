#!/usr/bin/env bash
# log-scrubber gate (Doc 02 SEC-08 / AC-08; C-13).
#
# Runs the two step-12 secret-hygiene suites: LogScrubberTest (the rendered
# log sink — tap pin, scrub liveness, trace ids, the seeded-credential sweep)
# and SecretsNeverSerializeTest (jobs / outbox / audit / relayed events sinks
# and the model serialization pins).
#
# THIS GATE FAILS CLOSED (owner ruling, step 12). If the suites cannot run AT
# ALL — stack down, suite files missing, pest erroring rather than failing —
# the gate exits nonzero. A scrubber gate that skips on infrastructure
# trouble is decoration: "did not run" must never read as "no secrets leak".
# Skipping it legitimately goes through ci/skips/ and the gate-skip-guard,
# like every other gate.
#
# Container-aware for the same reason as isolation-suite.sh: the suites are
# container-only (owner ruling D-4 — the test database is Postgres and the
# hostnames only resolve inside the stack).
set -u

SUITES="tests/Security/LogScrubberTest.php tests/Security/SecretsNeverSerializeTest.php"

for suite in $SUITES; do
  if [ ! -f "$suite" ]; then
    echo "log-scrubber: FAIL — $suite does not exist; the gate cannot run and therefore fails (fail-closed)." >&2
    exit 1
  fi
done

# Already inside the stack (CI runs the pipeline in the app image; `make shell`
# puts a developer here too): run directly.
if [ -f /.dockerenv ] || [ -n "${RUNNING_IN_CONTAINER:-}" ]; then
  ./vendor/bin/pest $SUITES
  exit $?
fi

# On the host: reach into the running stack. No host-side fallback — the
# suites would fail on hostname resolution and that failure would look like a
# secret leak rather than a wiring problem.
COMPOSE="docker compose -f docker/compose.yaml -f docker/compose.dev.yaml"

if ! $COMPOSE ps --status running --services 2>/dev/null | grep -q '^app$'; then
  echo "log-scrubber: FAIL — the stack is not running, so the secret-hygiene suites cannot execute." >&2
  echo "  This gate fails closed: 'did not run' is never 'passed' (Doc 02 SEC-08)." >&2
  echo "  Start it with: make up" >&2
  exit 1
fi

$COMPOSE exec -T app ./vendor/bin/pest $SUITES
