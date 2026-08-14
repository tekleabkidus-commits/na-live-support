#!/usr/bin/env bash
# lighthouse-budget (Doc 07 NFR-01 / T-08, S07 step 15) — the CI Lighthouse
# budget on the inbox route, via the approved @lhci/cli.
#
# NFR-01: "cold load (authed) to interactive list <= 1.5 s p75 on mid-range
# hardware / 3G-fast". The run is DEV-HARDWARE-INDICATIVE like every other
# budget in this repo: the measured figure prints on every run, the
# assertion holds the spec number, and the L2 profile (real mid-range
# hardware fleet) is Doc 19's. Auth is real: ci/lhci-session.mjs logs the
# fixture agent in through the real form and hands the session to lhci.
set -eu

COOKIE=$(node ci/lhci-session.mjs)
if [ -z "$COOKIE" ]; then
  echo "lighthouse-budget: FAIL — could not mint an authenticated session" >&2
  exit 1
fi

export LHCI_COOKIE="$COOKIE"
npx lhci autorun --config=ci/lighthouserc.cjs
