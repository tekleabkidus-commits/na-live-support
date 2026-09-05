#!/usr/bin/env bash
# widget-bundle-budget gate (Doc 13 NFR-01 / R-05 / AC-09's CI half; ADR 0020).
#
# The loader ≤ 5 KB gzipped, the core panel bundle ≤ 50 KB gzipped, and the Help
# and forms chunks are LAZY (separate files the core never imports statically) —
# read from the widget's OWN Vite manifest (public/w/.vite/manifest.json), never from
# a hand-kept number. Sizes are measured with gzip -9 on the emitted files, the
# same bytes the CDN would serve. A budget miss is a red build (R-05: bundle
# growth is a gate, not a review item).
#
# CONTROL: WIDGET_BUDGET_CONTROL=1 shrinks the core budget to 1 KB and must FAIL.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MANIFEST="$ROOT/public/w/.vite/manifest.json"
LOADER_BUDGET=$((5 * 1024))
CORE_BUDGET=$((50 * 1024))
if [ "${WIDGET_BUDGET_CONTROL:-0}" = "1" ]; then CORE_BUDGET=1024; fi

COMPOSE="docker compose -f docker/compose.yaml"
if [ ! -f "$MANIFEST" ] && $COMPOSE exec -T app test -f public/w/.vite/manifest.json 2>/dev/null; then
  # CI Stage 2 / a host without the widget build: the image built it (docker/app/Dockerfile) — copy it out.
  mkdir -p "$ROOT/public/w" && $COMPOSE exec -T app sh -c 'cd public/w && tar cf - .' | tar xf - -C "$ROOT/public/w"
fi
if [ ! -f "$MANIFEST" ]; then
  echo "widget-bundle-budget: FAIL — $MANIFEST missing; run 'npm run build:widget (root) / npm run build (widget/)' first (the gate reads Vite's own manifest)" >&2
  exit 1
fi

gz() { gzip -9 -c "$1" | wc -c; }
entry_file() { node -e "const m=require(process.argv[1]); const e=Object.values(m).find(x=>x.isEntry&&x.name===process.argv[2]); if(!e){process.exit(2)} console.log(e.file)" "$MANIFEST" "$1"; }

LOADER_FILE="$ROOT/public/w/$(entry_file loader)"
CORE_FILE="$ROOT/public/w/$(entry_file panel)"
LOADER_GZ=$(gz "$LOADER_FILE"); CORE_GZ=$(gz "$CORE_FILE")
# NFR-01 (S13 step 13, advisor rulings 2026-09-05): the LOADER-DRAWN LAUNCHER document (widget/launcher/launcher.html,
# the template the shim route fills and SERVES from the platform origin at GET /w/panel/{key}?frame=launcher) paints
# the bubble before any core bundle. Accounting as ruled: a SERVED document is its own resource — ≤ 2 KB gz on its own
# (the served figure is asserted on the real response by WidgetLauncherDocumentTest; the template measured here is
# the stack-less proxy under the same bound) — and does NOT count against the loader's 5 KB (NFR-01's own law, met
# by the loader alone, headroom printed).
LAUNCHER_DOC="$ROOT/widget/launcher/launcher.html"
LAUNCHER_BUDGET=2048
[ -f "$LAUNCHER_DOC" ] || { echo "widget-bundle-budget: FAIL — $LAUNCHER_DOC missing (the launcher document template)" >&2; exit 1; }
LAUNCHER_GZ=$(gz "$LAUNCHER_DOC")
FAIL=0
printf '  loader  %6d B gz (budget %d; headroom %d B)\n' "$LOADER_GZ" "$LOADER_BUDGET" "$((LOADER_BUDGET - LOADER_GZ))"
printf '  launcher document (template proxy) %4d B gz (budget %d; the SERVED figure is WidgetLauncherDocumentTest'"'"'s)\n' "$LAUNCHER_GZ" "$LAUNCHER_BUDGET"
printf '  core    %6d B gz (budget %d)\n' "$CORE_GZ" "$CORE_BUDGET"
[ "$LOADER_GZ" -le "$LOADER_BUDGET" ] || { echo "  loader over budget" >&2; FAIL=1; }
[ "$LAUNCHER_GZ" -le "$LAUNCHER_BUDGET" ] || { echo "  launcher document over its own 2 KB budget" >&2; FAIL=1; }
[ "$CORE_GZ" -le "$CORE_BUDGET" ] || { echo "  core over budget" >&2; FAIL=1; }

# Lazy chunks: Help and forms must be DYNAMIC imports of the core — read from the
# manifest's own `dynamicImports` (present) and `imports` (absent) lists, never a grep.
lazy_check() { # $1 = manifest key suffix (the chunk's src path), $2 = label
  node -e '
    const m = require(process.argv[1]); const suffix = process.argv[2];
    const core = Object.values(m).find(x => x.isEntry && x.name === "panel");
    const key = Object.keys(m).find(k => k.endsWith(suffix));
    if (!key) { console.error("  lazy chunk " + suffix + " is not in the manifest (never emitted)"); process.exit(2); }
    const stat = (core.imports || []).includes(key), dyn = (core.dynamicImports || []).includes(key);
    if (stat || !dyn) { console.error("  " + suffix + " is a STATIC import of the core (imports=" + stat + ", dynamicImports=" + dyn + ")"); process.exit(3); }
    console.log(m[key].file);
  ' "$MANIFEST" "$1"
}
for pair in "src/panel/tabs/Help.tsx:help" "src/panel/forms/FormRenderer.tsx:forms"; do
  suffix="${pair%%:*}"; label="${pair##*:}"
  if f=$(lazy_check "$suffix"); then
    printf '  lazy    %-6s %6d B gz (dynamic import of the core)\n' "$label" "$(gz "$ROOT/public/w/$f")"
  else
    FAIL=1
  fi
done

if [ "$FAIL" -ne 0 ]; then echo "widget-bundle-budget: FAIL (Doc 13 NFR-01)" >&2; exit 1; fi
echo "widget-bundle-budget: PASS (loader ${LOADER_GZ} B, core ${CORE_GZ} B gz; help/forms lazy)"
