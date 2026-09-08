#!/usr/bin/env bash
# sms-no-free-text gate (Doc 14 BR-01 / FR-02 / §3 — "free-text SMS does not exist at any permission level").
#
# Runs the structural sweep tests/Security/SmsNoFreeTextTest.php: every class in the SMS DOOR
# namespaces (Pipeline, Consumers, Broadcasts, OptOut, Otp, Jobs/Sms, Requests/Sms, Controllers/Sms,
# the Inbox *Sms* controllers, Commands/Sms) and every route named sms.* / *.sms.* is swept for a
# string-typed body / text / message / content / msg / html parameter, property or rule; the
# POSITIVE CONTROL (a planted smuggling request under tests/Support) must be flagged. The suite is
# container-only (the route table needs the app's own bootstrap), so this script mirrors
# ci/isolation-suite.sh's side-of-the-boundary logic.
set -u

PEST_ARGS="tests/Security/SmsNoFreeTextTest.php"

if [ -f /.dockerenv ] || [ -n "${RUNNING_IN_CONTAINER:-}" ]; then
  ./vendor/bin/pest $PEST_ARGS
  exit $?
fi

COMPOSE="docker compose -f docker/compose.yaml -f docker/compose.dev.yaml"
if ! $COMPOSE ps --status running --services 2>/dev/null | grep -q '^app$'; then
  echo "sms-no-free-text: FAIL — the stack is not running, so the BR-01 sweep cannot execute (never treated as passing when it did not run)." >&2
  exit 1
fi
$COMPOSE exec -T app ./vendor/bin/pest $PEST_ARGS
