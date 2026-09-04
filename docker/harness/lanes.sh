#!/bin/sh
# HARNESS: the outbound LANES worker for the fixture tenant (Doc 04 T-05: one
# `lanes:work` process per tenant — the multi-tenant wiring is Doc 18's). The
# dev stack had NO lanes process at all (filed at S13 step 8b): without it an
# outbound row stays `queued` forever and no push ever reaches the visitor.
set -eu
cd /app
for i in $(seq 1 60); do
  ID=$(php artisan tinker --execute='echo (string) DB::connection("pgsql_platform")->table("tenants")->where("name","Fixture Operator A")->value("id");' 2>/dev/null | tail -1 | tr -d '[:space:]')
  if [ -n "$ID" ] && [ "${#ID}" -eq 36 ]; then break; fi
  sleep 5
done
[ -n "${ID:-}" ] || { echo "harness-lanes: no fixture tenant (run BrowserFixtureSeeder)"; exit 1; }
echo "harness-lanes: consuming lanes for tenant $ID"
exec php artisan lanes:work --tenant="$ID"
