#!/usr/bin/env bash
# ticket-status-writer gate (Doc 06 NFR-03, T-01).
#
# "The state machine has one server-side writer (transition service); no status
# writes elsewhere (CI grep + repository encapsulation)." NFR-03 asks for TWO
# independent proofs and this is the grep half; the structural half is
# tests/Feature/Tickets/StatusWriterEncapsulationTest.php, which reasons about
# the file set rather than about lines.
#
# WHY BOTH. A grep sees a shape it was taught; a structural test sees a file it
# was not told about. Each catches what the other misses, and the day someone
# sets a ticket status in a controller "just this once" is the day FR-24's
# timestamps, the outbox event and the FR-10 invariant silently stop happening
# together — which nothing else in the suite would notice, because the ticket
# would look perfectly fine afterwards.
#
# THIS GATE'S FIRST VERSION WAS BLIND, AND THE FIX IS THE LESSON.
# It required the ticket context and the status assignment on ONE line
# (`Ticket::…'status' =>`). The real repository builds its payload first
# (`$update = ['status' => …]`) and calls `->update($update)` two statements
# later, so the gate passed while failing to see the one writer it exists to
# police — a green that proved nothing. S19's lesson #7 in a new costume: when a
# check cannot see the thing it guards, the CHECK is what is broken.
#
# So detection is now per FILE, not per line: a file writes ticket status if it
# assigns a `status` array key or property ANYWHERE (A) and references the ticket
# model or table ANYWHERE (B). The tests prove the pattern actually matches the
# sanctioned writer — a gate that cannot find the known positive is rejected.
#
# CONSEQUENCE WORTH KNOWING: a future file that writes some OTHER table's
# `status` (ticket_bulk_jobs, say) must not also reference the Ticket model, or
# it trips this. That is a design constraint, and the right one — that state
# belongs in its own repository.
#
# Reads (`where('status', …)`, `$ticket->status()`) are not assignments and do
# not trip A. A gate that fired on reads would be silenced within a week.
#
# Exit 0 = pass, 1 = violations.
set -u

WRITER="app/Domain/Tickets/Storage/TicketRepository.php"

if [ ! -d app ]; then
  echo "ticket-status-writer: PASS (no application code yet)"
  exit 0
fi

# (A) an assignment to a `status` key or property
ASSIGN="(['\"]status['\"][[:space:]]*=>|->status[[:space:]]*=[^=>])"
# (B) a ticket context
CONTEXT="(Ticket::|table\(['\"]tickets['\"]\)|UPDATE[[:space:]]+tickets[[:space:]])"

VIOLATIONS=""
while IFS= read -r file; do
  [ -z "$file" ] && continue
  [ "$file" = "$WRITER" ] && continue
  if grep -qE "$CONTEXT" "$file" 2>/dev/null; then
    VIOLATIONS="${VIOLATIONS}${file}"$'\n'
  fi
done < <(grep -rlE --include='*.php' "$ASSIGN" app 2>/dev/null || true)

# Self-check: the sanctioned writer MUST be detectable by the same patterns.
# Without this, deleting or renaming the repository would leave the gate green
# and blind — exactly the failure the first version shipped with.
if [ -f "$WRITER" ]; then
  if ! grep -qE "$ASSIGN" "$WRITER" || ! grep -qE "$CONTEXT" "$WRITER"; then
    echo "ticket-status-writer: FAIL — the sanctioned writer ${WRITER} no longer matches this gate's own patterns, so the gate is blind. Fix the patterns, not this message." >&2
    exit 1
  fi
fi

if [ -n "${VIOLATIONS%$'\n'}" ]; then
  echo "${VIOLATIONS%$'\n'}" | while IFS= read -r file; do
    echo "ticket-status-writer: status write outside the transition path: $file" >&2
  done
  COUNT=$(echo "${VIOLATIONS%$'\n'}" | wc -l)
  echo "ticket-status-writer: FAIL ($COUNT file(s) writing ticket status outside ${WRITER}). Doc 06 T-01/NFR-03: TicketTransitionService is the sole status writer and reaches the column only through that repository." >&2
  exit 1
fi

echo "ticket-status-writer: PASS (the only ticket status writer is ${WRITER}, and this gate can see it)"
exit 0
