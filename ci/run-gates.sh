#!/usr/bin/env bash
# Runs every gate in registries/gates.yaml, in order, with a one-line
# PASS/FAIL per gate and a final summary a non-programmer can read.
# This IS `make gates`. Exit 1 if any gate fails.
#
# Skips: SKIP_GATES="gate-id,gate-id" is honored ONLY after gate-skip-guard
# validates the signed change files (Doc 18 FR-08).
set -u

GATES_FILE="registries/gates.yaml"
PASS=0; FAIL=0; SKIP=0
FAILED_GATES=()

# If skips are requested, the guard must bless them before anything is skipped.
if [ -n "${SKIP_GATES:-}" ]; then
  if ! bash ci/gate-skip-guard.sh; then
    echo "gates: FAIL — skip requested without valid signed change file; nothing was run" >&2
    exit 1
  fi
fi

is_skipped() {
  case ",${SKIP_GATES:-}," in
    *",$1,"*) return 0 ;;
    *) return 1 ;;
  esac
}

# Parse (gate_id, command) pairs from the registry.
mapfile -t LINES < <(awk '
  /^[[:space:]]*-[[:space:]]*gate_id:/ { gsub(/^[[:space:]]*-[[:space:]]*gate_id:[[:space:]]*/, ""); id=$0 }
  /^[[:space:]]*command:/ { gsub(/^[[:space:]]*command:[[:space:]]*/, ""); print id "\t" $0 }
' "$GATES_FILE")

if [ ${#LINES[@]} -eq 0 ]; then
  echo "gates: FAIL — no gates found in $GATES_FILE" >&2
  exit 1
fi

echo "Running ${#LINES[@]} gates (registries/gates.yaml order):"
echo "-----------------------------------------------------------------"

for line in "${LINES[@]}"; do
  gate_id="${line%%$'\t'*}"
  command="${line#*$'\t'}"
  if is_skipped "$gate_id"; then
    printf "  %-25s SKIPPED (signed change file in ci/skips/)\n" "$gate_id"
    SKIP=$((SKIP+1))
    continue
  fi
  OUTPUT=$(eval "$command" 2>&1)
  STATUS=$?
  if [ $STATUS -eq 0 ]; then
    printf "  %-25s PASS\n" "$gate_id"
    # Surface WARN lines even on pass — bootstrap-mode gates must be heard.
    echo "$OUTPUT" | grep -E 'WARN' | tail -2 | sed 's/^/      /' || true
    PASS=$((PASS+1))
  else
    printf "  %-25s FAIL\n" "$gate_id"
    echo "$OUTPUT" | sed 's/^/      /'
    FAIL=$((FAIL+1))
    FAILED_GATES+=("$gate_id")
  fi
done

echo "-----------------------------------------------------------------"
echo "Gate summary: $PASS passed, $FAIL failed, $SKIP skipped."
if [ $FAIL -ne 0 ]; then
  echo "FAILED: ${FAILED_GATES[*]}" >&2
  exit 1
fi
exit 0
