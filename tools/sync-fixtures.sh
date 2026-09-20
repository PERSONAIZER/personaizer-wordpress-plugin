#!/usr/bin/env bash
# Copy the backend's recorded contract snapshots into this repo (or, with --check, verify they are identical).
#
# The backend's V1IntegrationContractTests records every exchange of the push surface — request, status, response —
# under Tests/Api/Knowledge/Integration/Fixtures/v1-integration. tests/ContractsTest.php reads the copies here, so
# the plugin's readers are exercised against exactly what the API sends. Run this after the backend regenerates them.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="${PERSONAIZER_BACKEND:-$HERE/../personaizer-backend}/personaizer-core/Tests/Api/Knowledge/Integration/Fixtures/v1-integration"
DST="$HERE/fixtures/v1-integration"

[ -d "$SRC" ] || { echo "error: backend fixtures not found at $SRC (set PERSONAIZER_BACKEND)" >&2; exit 1; }

if [ "${1:-}" = "--check" ]; then
    if diff -r --exclude=.gitattributes "$SRC" "$DST" >/dev/null; then
        echo "✓ fixtures match the backend"
    else
        echo "error: fixtures differ from the backend — run tools/sync-fixtures.sh and re-run the tests" >&2
        diff -r --exclude=.gitattributes "$SRC" "$DST" >&2 || true
        exit 1
    fi
    exit 0
fi

mkdir -p "$DST"
rm -f "$DST"/*.json
cp "$SRC"/*.json "$DST"/
echo "✓ copied $(ls "$DST"/*.json | wc -l | tr -d ' ') fixtures from $SRC"
