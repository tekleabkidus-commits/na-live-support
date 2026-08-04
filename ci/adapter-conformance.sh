#!/usr/bin/env bash
# adapter-conformance gate (Doc 04 T-09, AC-01; risk R-04).
#
# Runs the adapter conformance suite in tests/Contract/AdapterConformance — the
# executable form of the FR-08 Channel Adapter contract. Today it drives the fake
# reference adapter through the full matrix (all capability combinations, error
# classifications, dedupe stability, ordering purity, note isolation at the contract
# boundary). Doc 05's Telegram adapter and Doc 13's widget adapter each add a subclass
# of AdapterConformanceCase; an adapter with no passing subclass here cannot ship
# (AC-01 "conformance suite green for every shipped adapter").
#
# The suite never depends on a mock HTTP service: it drives adapters as code-level
# objects (the S0 mock services are stand-ins for channel APIs, a different thing).
#
# Same container-boundary handling as ci/isolation-suite.sh: Pest resolves app services
# only inside the stack, and a gate that did not run must never look like a pass.
set -u

PEST_ARGS="tests/Contract/AdapterConformance"

if [ -f /.dockerenv ] || [ -n "${RUNNING_IN_CONTAINER:-}" ]; then
  ./vendor/bin/pest $PEST_ARGS
  exit $?
fi

COMPOSE="docker compose -f docker/compose.yaml -f docker/compose.dev.yaml"

if ! $COMPOSE ps --status running --services 2>/dev/null | grep -q '^app$'; then
  echo "adapter-conformance: FAIL — the stack is not running, so the conformance suite cannot execute." >&2
  echo "  A gate that did not run is not a passing gate (CLAUDE.md §8)." >&2
  echo "  Start it with: make up" >&2
  exit 1
fi

$COMPOSE exec -T app ./vendor/bin/pest $PEST_ARGS
