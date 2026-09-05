#!/usr/bin/env bash
# Doc 13 §12 — the widget timing harness (S13 step 12). Figures print on every run; the assertion holds the
# spec numbers only with --assert AND on wall power (R-D13-6 — otherwise "timing not taken"). Needs the harness stack.
set -eu
cd "$(dirname "$0")/.."
node ci/widget-loader-timing.mjs "$@"
