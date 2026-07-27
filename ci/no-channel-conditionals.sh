#!/usr/bin/env bash
# no-channel-conditionals gate (Doc 04 T-09; invariant N-20).
#
# Domain code must never branch on the channel name — all channel behavior
# lives behind the Channel Adapter contract. This greps domain code for
# conditionals mentioning a concrete channel ('telegram', 'webchat', 'sms').
#
# Scanned: app/Domain app/Jobs app/Listeners app/Support (domain code).
# NOT scanned: the adapters themselves (app/Adapters later, per-channel by
# definition), config/, registries/ (registration is data, not branching).
#
# Exit 0 = pass, 1 = violations.
set -u

DIRS=()
for d in app/Domain app/Jobs app/Listeners app/Support; do
  [ -d "$d" ] && DIRS+=("$d")
done

if [ ${#DIRS[@]} -eq 0 ]; then
  echo "no-channel-conditionals: PASS (no domain code yet)"
  exit 0
fi

# Conditional keywords followed (same line) by a quoted concrete channel name.
PATTERN="(if|elseif|else if|switch|match|case)[^\n]*['\"](telegram|webchat|sms)['\"]"

MATCHES=$(grep -rnE --include='*.php' "$PATTERN" "${DIRS[@]}" 2>/dev/null || true)

if [ -n "$MATCHES" ]; then
  echo "$MATCHES" | while IFS= read -r line; do
    echo "no-channel-conditionals: $line" >&2
  done
  COUNT=$(echo "$MATCHES" | wc -l)
  echo "no-channel-conditionals: FAIL ($COUNT violation(s)). Channel behavior belongs behind the Channel Adapter contract (Doc 04 T-09, invariant N-20)." >&2
  exit 1
fi

echo "no-channel-conditionals: PASS (no channel branching in domain code)"
exit 0
