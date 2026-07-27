#!/usr/bin/env bash
# gate-skip-guard (Doc 18 FR-08 / T-02).
#
# Skipping a gate is a formal act, not an environment variable someone sets
# quietly. A skip is requested by listing gate ids in SKIP_GATES (comma-
# separated). EVERY requested skip must be backed by a signed change file:
#
#     ci/skips/<gate-id>.md   containing all of:
#         gate_id: <gate-id>
#         reason: <non-empty>
#         approved_by: <name>
#         expires: YYYY-MM-DD   (a future date)
#         signature: <non-empty>
#
# Failures:
#   - SKIP_GATES names a gate with no (or an incomplete/expired) change file
#   - a change file exists for a gate that is NOT being skipped and is
#     expired (stale skips must be deleted, not accumulated)
#
# Exit 0 = pass, 1 = violations.
set -u

SKIPS_DIR="ci/skips"
FAIL=0
TODAY=$(date +%F)

require_field() { # file field
  grep -qE "^$2:[[:space:]]*[^[:space:]]" "$1"
}

IFS=',' read -ra REQUESTED <<< "${SKIP_GATES:-}"
for gate in "${REQUESTED[@]}"; do
  gate=$(echo "$gate" | tr -d '[:space:]')
  [ -z "$gate" ] && continue
  f="$SKIPS_DIR/$gate.md"
  if [ ! -f "$f" ]; then
    echo "gate-skip-guard: SKIP_GATES requests '$gate' but no signed change file at $f" >&2
    FAIL=1
    continue
  fi
  for field in gate_id reason approved_by expires signature; do
    if ! require_field "$f" "$field"; then
      echo "gate-skip-guard: $f is missing required field '$field'" >&2
      FAIL=1
    fi
  done
  if require_field "$f" expires; then
    EXP=$(grep -E '^expires:' "$f" | head -1 | sed 's/expires:[[:space:]]*//')
    if [[ ! "$EXP" > "$TODAY" ]]; then
      echo "gate-skip-guard: $f expired on $EXP (today: $TODAY) — an expired skip is not a skip" >&2
      FAIL=1
    fi
  fi
done

# Stale skip files: expired files must be removed even if unused.
if [ -d "$SKIPS_DIR" ]; then
  for f in "$SKIPS_DIR"/*.md; do
    [ -e "$f" ] || continue
    EXP=$(grep -E '^expires:' "$f" | head -1 | sed 's/expires:[[:space:]]*//' || true)
    if [ -n "$EXP" ] && [[ ! "$EXP" > "$TODAY" ]]; then
      echo "gate-skip-guard: stale expired skip file $f (expired $EXP) — delete it" >&2
      FAIL=1
    fi
  done
fi

if [ "$FAIL" -ne 0 ]; then
  echo "gate-skip-guard: FAIL — a gate skip without a valid signed change file is a build failure (Doc 18 FR-08)" >&2
  exit 1
fi

if [ -n "${SKIP_GATES:-}" ]; then
  echo "gate-skip-guard: PASS (requested skips are backed by valid signed change files)"
else
  echo "gate-skip-guard: PASS (no gate skips requested)"
fi
exit 0
