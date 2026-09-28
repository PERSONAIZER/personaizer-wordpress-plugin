#!/usr/bin/env bash
# Package the WordPress plugin: the folder WordPress.org serves, and the zip a site owner can install by hand
# (WP Admin -> Plugins -> Add New -> Upload Plugin).
#
#   ./build-zip.sh              # -> dist/personaizer-chat-<version>.zip
#   ./build-zip.sh ~/Desktop    # -> a directory of your choosing
#
# Output goes to dist/ by default: a gitignored folder, so the location is consistent but the binary is never
# committed. A committed zip drifts from source and you end up testing the wrong version.
#
# There is one build: the package clients get. To test it against dev, install it on a test site that has
# testing/dev-override.php in mu-plugins (see RELEASING.md); the package itself always points at production.
#
# Updates come from WordPress.org only. The plugin has no update channel of its own (the directory rejects
# one), and a guard below keeps it that way.
set -euo pipefail

# ── Args: optional output dir ─────────────────────────────────────────────────
OUT_DIR=""
for arg in "$@"; do
    case "$arg" in
        -*) echo "error: unknown flag '$arg' (the only argument is an output directory)" >&2; exit 1 ;;
        *)  OUT_DIR="$arg" ;;
    esac
done

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SLUG="personaizer-chat"   # the WordPress.org slug: folder, main file and text domain
SRC_DIR="$HERE/$SLUG"
OUT_DIR="${OUT_DIR:-$HERE/dist}"

[ -d "$SRC_DIR" ] || { echo "error: $SRC_DIR not found" >&2; exit 1; }
mkdir -p "$OUT_DIR"

# WordPress reads the version from the plugin header — make the filename agree with it.
VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\(.*\)$/\1/p' "$SRC_DIR/$SLUG.php" | head -1 | tr -d '[:space:]')"
[ -n "$VERSION" ] || { echo "error: no 'Version:' header in $SLUG.php" >&2; exit 1; }
ZIP_PATH="$OUT_DIR/$SLUG-$VERSION.zip"

# readme.txt's "Stable tag" is the version a human reads as current — and, self-hosted, the only place an
# owner can check what they're about to install against what they're running. WordPress itself reads the
# PHP header, so a drift between the two is invisible until someone is debugging the wrong version.
STABLE="$(sed -n 's/^Stable tag:[[:space:]]*\(.*\)$/\1/p' "$SRC_DIR/readme.txt" | head -1 | tr -d '[:space:]')"
[ "$STABLE" = "$VERSION" ] \
    || { echo "error: readme.txt Stable tag ($STABLE) != plugin header Version ($VERSION)" >&2; exit 1; }

# PERSONAIZER_VERSION is what the running plugin reports (System info, API calls); keep it equal to the header.
CONST_VERSION="$(sed -n "s/^define( 'PERSONAIZER_VERSION', '\([^']*\)' );.*$/\1/p" "$SRC_DIR/$SLUG.php" | head -1)"
[ "$CONST_VERSION" = "$VERSION" ] \
    || { echo "error: PERSONAIZER_VERSION ($CONST_VERSION) != plugin header Version ($VERSION)" >&2; exit 1; }
echo "✓ version $VERSION agrees across header, PERSONAIZER_VERSION and readme Stable tag"

# ── Guard: the SOURCE must default to PRODUCTION ──────────────────────────────
# Dev URLs belong on the test site (testing/dev-override.php), never in the package. Comments may document
# the overrides, so assert on the define() defaults specifically.
check_default() {  # <file> <constant> <expected>
    grep -q "define( '$2', '$3' );" "$SRC_DIR/$1" \
        || { echo "error: $2 does not default to $3 — refusing to package" >&2; exit 1; }
}
check_default "$SLUG.php" PERSONAIZER_WIDGET_URL 'https://personaizerprodstore.blob.core.windows.net/platform-builds-public/chat.js'
check_default "$SLUG.php" PERSONAIZER_APP_URL 'https://personaizer.com'
check_default "$SLUG.php" PERSONAIZER_API_URL 'https://api.personaizer.com'
echo "✓ source defaults point at production"

# ── Stage ─────────────────────────────────────────────────────────────────────
# WordPress installs whatever top-level directory the zip contains, so the tree must be
# rooted at the slug folder, not at the files themselves.
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$SLUG"
(cd "$SRC_DIR" && tar -cf - \
    --exclude='.DS_Store' --exclude='Thumbs.db' --exclude='*.log' \
    --exclude='.git*' --exclude='node_modules' .) | (cd "$STAGE/$SLUG" && tar -xf -)

# ── Guard: no update channel of our own ───────────────────────────────────────
# WordPress.org serves updates; a plugin that hooks the update transients or the plugin-info API to point
# elsewhere is closed by the directory.
if grep -rEq 'pre_set_site_transient_update_plugins|site_transient_update_plugins|plugins_api' "$STAGE/$SLUG"; then
    echo "error: the build carries self-hosted update code, which WordPress.org forbids:" >&2
    grep -rEn 'pre_set_site_transient_update_plugins|site_transient_update_plugins|plugins_api' "$STAGE/$SLUG" >&2
    exit 1
fi

# ── Syntax check the STAGED tree (what actually ships) ──────────────────────
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
if [ -n "$PHP_BIN" ]; then
    while IFS= read -r f; do "$PHP_BIN" -l "$f" >/dev/null || exit 1; done \
        < <(find "$STAGE/$SLUG" -name '*.php')
    echo "✓ php syntax clean"
else
    echo "! php not found — skipping syntax check (set PHP_BIN=/path/to/php to enable)"
fi

# ── Zip (zip(1) where available, else python's zipfile) ───────────────────────
rm -f "$ZIP_PATH"
if command -v zip >/dev/null 2>&1; then
    (cd "$STAGE" && zip -q -r -9 "$ZIP_PATH" "$SLUG")
else
    python - "$STAGE" "$ZIP_PATH" "$SLUG" <<'PY'
import os, sys, zipfile
stage, out, slug = sys.argv[1], sys.argv[2], sys.argv[3]
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for root, _, files in os.walk(os.path.join(stage, slug)):
        for f in sorted(files):
            full = os.path.join(root, f)
            z.write(full, os.path.relpath(full, stage).replace(os.sep, "/"))
PY
fi

# ── Report ────────────────────────────────────────────────────────────────────
echo "✓ built $(basename "$ZIP_PATH") ($(du -h "$ZIP_PATH" | cut -f1))"
python - "$ZIP_PATH" <<'PY'
import sys, zipfile
with zipfile.ZipFile(sys.argv[1]) as z:
    names = z.namelist()
    roots = {n.split("/")[0] for n in names}
    assert roots == {"personaizer-chat"}, f"zip root must be the plugin folder, got {roots}"
    for n in sorted(names):
        print("   ", n)
PY

# Print a path a Windows file picker will accept, when we're on Git Bash.
echo ""
echo "Test: upload it on the test site (dev-override.php points it at dev). Publish: ./release.sh"
if command -v cygpath >/dev/null 2>&1; then echo "  zip  $(cygpath -w "$ZIP_PATH")"; else echo "  zip  $ZIP_PATH"; fi
