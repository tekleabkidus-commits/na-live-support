#!/usr/bin/env bash
# CAPTURE-FIRST — the standing order from RISK 0b / governance incident #6.
#
# WHY THIS EXISTS. Twice in this project an intermittent failure was chased
# through `| grep` or `| tail -4`, the pipe threw away everything except a
# summary line, and the only copy of the evidence died with the terminal. Both
# times the next session had to reproduce the failure blind. An intermittent
# failure you failed to capture is an intermittent failure you get to hunt
# again from zero.
#
# THE RULE. Any diagnostic run is captured FIRST — the WHOLE run, stdout and
# stderr together, written to a file under docs/evidence/ — and only THEN
# filtered, summarised or re-run.
#
# HOW THIS ENFORCES IT. The full stream is written to the evidence file with
# `tee`, so filtering happens on this script's *stdout*, downstream of the
# file. `ci/capture.sh soak ./vendor/bin/pest | tail -4` is now a SAFE thing
# to type: tail sees four lines, the file still holds every byte. That is the
# whole design — it makes the careless thing harmless instead of forbidding it.
#
# USAGE
#   ci/capture.sh <label> <command> [args...]
#   CAPTURE_SET=S21-step0 ci/capture.sh soak-run ./vendor/bin/pest --group=soak
#
#   <label>       short kebab-case name for this run; becomes the filename
#   CAPTURE_SET   subdirectory under docs/evidence/ (default: "manual")
#
# Runs are never overwritten: a repeat label gets -01, -02, -03… so "run it
# five times and compare" cannot silently destroy run 1. Exits with the wrapped
# command's own exit code, so `make` and `&&` chains still behave.

set -uo pipefail

if [ "$#" -lt 2 ]; then
  echo "capture: usage: ci/capture.sh <label> <command> [args...]" >&2
  echo "capture: refusing to run — a capture with no command captures nothing." >&2
  exit 64
fi

LABEL="$1"; shift

case "$LABEL" in
  */*|.*|"") echo "capture: label must be a plain kebab-case name, got '$LABEL'." >&2; exit 64 ;;
esac

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SET_NAME="${CAPTURE_SET:-manual}"
OUT_DIR="$REPO_ROOT/docs/evidence/$SET_NAME"

mkdir -p "$OUT_DIR"

# Never overwrite a previous run of the same label.
SEQ=1
while [ -e "$(printf '%s/%s-%02d.txt' "$OUT_DIR" "$LABEL" "$SEQ")" ]; do
  SEQ=$((SEQ + 1))
done
OUT_FILE="$(printf '%s/%s-%02d.txt' "$OUT_DIR" "$LABEL" "$SEQ")"

STARTED_AT="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
START_EPOCH="$(date '+%s')"
GIT_SHA="$(git -C "$REPO_ROOT" rev-parse --short HEAD 2>/dev/null || echo 'unknown')"
GIT_DIRTY="clean"
if [ -n "$(git -C "$REPO_ROOT" status --porcelain 2>/dev/null)" ]; then GIT_DIRTY="DIRTY"; fi

# The header goes to the file only: a header on stdout would corrupt the output
# of anything that pipes this script into a parser.
{
  echo "# CAPTURED RUN — ci/capture.sh (RISK 0b capture-first protocol)"
  echo "# label:      $LABEL"
  echo "# set:        $SET_NAME"
  echo "# command:    $*"
  echo "# started:    $STARTED_AT"
  echo "# git:        $GIT_SHA ($GIT_DIRTY working tree)"
  echo "# host:       $(hostname)"
  echo "# ---------------------------------------------------------------- 8< ----"
} > "$OUT_FILE"

# THE CAPTURE. stderr folded into stdout so interleaving is preserved exactly as
# it happened; tee appends the whole stream to the file and passes it through.
"$@" 2>&1 | tee -a "$OUT_FILE"
STATUS="${PIPESTATUS[0]}"

ENDED_AT="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
DURATION=$(( $(date '+%s') - START_EPOCH ))

{
  echo "# ---------------------------------------------------------------- >8 ----"
  echo "# ended:      $ENDED_AT"
  echo "# duration:   ${DURATION}s"
  echo "# EXIT:       $STATUS"
} >> "$OUT_FILE"

# The path goes to STDERR, so it survives `| tail -4` and cannot pollute stdout.
echo "capture: full output -> docs/evidence/$SET_NAME/$(basename "$OUT_FILE")  (exit $STATUS, ${DURATION}s)" >&2

exit "$STATUS"
