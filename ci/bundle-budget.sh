#!/usr/bin/env bash
# bundle-budget gate (Doc 07 NFR-04 / R-03, S07 step 15).
#
# "JS bundle (inbox route) <= 350 KB gz initial; panel tabs lazy-loaded."
# The INITIAL set is computed from Vite's own manifest, never eyeballed: the
# app entry plus the inbox Workspace page chunk, closed over their STATIC
# import chains (dynamic imports are the lazy set by definition). Every file
# in the closure is gzipped and summed; the sum must stay under the spec
# number. The lazy half (R-03): the closure must keep growing lazily — any
# chunk reachable ONLY via dynamicImports stays out of the initial sum, and
# the gate prints both sets so a chunk silently going static is visible in
# the diff of its output.
#
# Runs against the CONTAINER's baked build when the stack is up (CI Stage 3,
# local `make gates`); falls back to the host's public/build when no stack
# is reachable (a host-only lint run). No stack and no host build = FAIL —
# a budget that cannot find the thing it budgets must say so, not pass.
set -u

BUDGET_BYTES=$((350 * 1024))
COMPOSE="docker compose -f docker/compose.yaml -f docker/compose.dev.yaml"

workdir=$(mktemp -d)
trap 'rm -rf "$workdir"' EXIT

if $COMPOSE exec -T app test -f public/build/manifest.json 2>/dev/null; then
  $COMPOSE exec -T app sh -c 'cd public/build && tar cf - manifest.json assets' | tar xf - -C "$workdir" 2>/dev/null
  SOURCE="container"
elif [ -f public/build/manifest.json ]; then
  cp -r public/build/manifest.json public/build/assets "$workdir"/
  SOURCE="host"
else
  echo "bundle-budget: FAIL — no public/build/manifest.json in the container or on the host; nothing to budget." >&2
  exit 1
fi

python3 - "$workdir" "$BUDGET_BYTES" <<'PY'
import gzip, json, os, sys

root, budget = sys.argv[1], int(sys.argv[2])
manifest = json.load(open(os.path.join(root, "manifest.json")))

entries = [k for k, v in manifest.items() if v.get("isEntry")]
inbox = [k for k in manifest if k.endswith("Pages/Inbox/Workspace.vue")]
if not entries or not inbox:
    print(f"bundle-budget: FAIL — manifest is missing the app entry or the inbox page chunk (entries={entries}, inbox={inbox})", file=sys.stderr)
    sys.exit(1)

initial, seen = [], set()
def walk(key):
    if key in seen or key not in manifest:
        return
    seen.add(key)
    initial.append(key)
    for imported in manifest[key].get("imports", []):  # STATIC only — dynamicImports are the lazy set
        walk(imported)

for key in entries + inbox:
    walk(key)

total = 0
rows = []
for key in initial:
    path = os.path.join(root, manifest[key]["file"])
    if not manifest[key]["file"].endswith(".js"):
        continue
    size = len(gzip.compress(open(path, "rb").read(), 9))
    total += size
    rows.append((size, manifest[key]["file"]))

lazy = sorted(set(sum((manifest[k].get("dynamicImports", []) for k in initial), [])) - seen)

for size, name in sorted(rows, reverse=True):
    print(f"bundle-budget:   {size/1024:8.1f} KB gz  {name}")
print(f"bundle-budget: lazy (excluded, loads on demand): {len(lazy)} chunk(s): {', '.join(lazy) if lazy else 'none'}")
print(f"bundle-budget: initial JS for the inbox route: {total/1024:.1f} KB gz (budget {budget/1024:.0f} KB)")

if total > budget:
    print(f"bundle-budget: FAIL — the inbox route's initial JS exceeds NFR-04's 350 KB gz.", file=sys.stderr)
    sys.exit(1)
print(f"bundle-budget: PASS (source: {os.environ.get('BUNDLE_SOURCE', 'build')})")
PY
status=$?
[ $status -eq 0 ] && echo "bundle-budget: measured from the ${SOURCE} build"
exit $status
