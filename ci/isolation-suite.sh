#!/usr/bin/env bash
# isolation-suite gate (Doc 01 AC-01/AC-02, NFR-01, SEC-01).
#
# The deploy gate Doc 01 §21 asks for: "Treat the isolation suite (AC-01/02) as a deploy
# gate from the very first environment — retrofit certainty is impossible."
#
# It runs the Pest `Isolation` suite, which contains the two-layer independence proof, the
# schema-generated cross-tenant test, the forged-tenant_id fuzz, the Octane leak checks, the
# suspended-tenant matrix and the outbox chaos tests.
#
# WHY A SCRIPT RATHER THAN THE COMMAND INLINE IN gates.yaml: the suite is container-only
# (owner ruling D-4 — SQLite cannot express RLS, so the test database is Postgres and the
# app hostname only resolves inside the stack), and where "inside the stack" is depends on
# who is running. This script works out which side of the boundary it is on.
set -u

PEST_ARGS="--testsuite=Isolation"

# Already inside the stack (CI runs the pipeline in the app image, and `make shell` puts a
# developer here too): run directly.
if [ -f /.dockerenv ] || [ -n "${RUNNING_IN_CONTAINER:-}" ]; then
  ./vendor/bin/pest $PEST_ARGS
  exit $?
fi

# On the host: reach into the running stack. No fallback to running Pest here — the suite
# would fail to resolve `postgres` and the failure would look like an isolation defect
# rather than a wiring one.
COMPOSE="docker compose -f docker/compose.yaml -f docker/compose.dev.yaml"

if ! $COMPOSE ps --status running --services 2>/dev/null | grep -q '^app$'; then
  echo "isolation-suite: FAIL — the stack is not running, so the isolation suite cannot execute." >&2
  echo "  This gate must never be treated as passing when it did not run (Doc 01 §21)." >&2
  echo "  Start it with: make up" >&2
  exit 1
fi

$COMPOSE exec -T app ./vendor/bin/pest $PEST_ARGS
