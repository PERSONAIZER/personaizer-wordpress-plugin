#!/bin/bash
# Release the version in the plugin header, in one go:
#
#   1. WordPress.org (SVN) — what sites install and update from. trunk/ becomes exactly this build,
#      tags/<version>/ is copied from it, and the listing images in .wordpress-org/ go to assets/.
#   2. GitHub release — the archive: the same zip, for anyone installing by hand.
#
# Usage:
#   ./release.sh                 # release the version in the plugin header
#   ./release.sh --dry-run       # build and stage the SVN changes, show them, commit nothing
#   ./release.sh --notes-file X  # take the GitHub release body from a file instead of the readme changelog
#
# Prerequisites:
#   - svn on PATH (e.g. TortoiseSVN with "command line client tools")
#   - gh authenticated (gh auth status)
#   - the SVN password set on WordPress.org: Profile -> Account & Security (not the login password)
#
# WordPress.org rebuilds the download on every commit (up to ~6 hours to show), so commit only a finished,
# version-bumped release: header Version, PERSONAIZER_VERSION and readme "Stable tag" (build-zip.sh checks
# they agree). "Stable tag" is what tells WordPress.org which tag sites get.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SLUG="personaizer-chat"
SVN_URL="https://plugins.svn.wordpress.org/$SLUG"
SVN_USER="personaizer"
SVN_DIR="${PERSONAIZER_SVN_DIR:-$HERE/.svn-checkout}"

NOTES_FILE=""
DRY_RUN=""
while [ $# -gt 0 ]; do
    case "$1" in
        --notes-file) NOTES_FILE="${2:-}"; shift 2 ;;
        --dry-run)    DRY_RUN=1; shift ;;
        *) echo "error: unknown argument '$1'" >&2; exit 1 ;;
    esac
done

command -v svn >/dev/null 2>&1 || { echo "error: svn not found (install TortoiseSVN with the command line tools)" >&2; exit 1; }
command -v gh  >/dev/null 2>&1 || { echo "error: gh CLI not found" >&2; exit 1; }
gh auth status >/dev/null 2>&1 || { echo "error: gh is not authenticated (gh auth login)" >&2; exit 1; }

VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\(.*\)$/\1/p' "$HERE/$SLUG/$SLUG.php" | head -1 | tr -d '[:space:]')"
[ -n "$VERSION" ] || { echo "error: no 'Version:' header in $SLUG.php" >&2; exit 1; }
TAG="v$VERSION"

# A release is cut from what is committed: the git tag must point at the code in the zip.
[ -z "$(git -C "$HERE" status --porcelain)" ] \
    || { echo "error: working tree is dirty — commit first, the tag must match the zip" >&2; exit 1; }
git -C "$HERE" diff --quiet @{u}..HEAD 2>/dev/null \
    || { echo "error: HEAD differs from upstream — push first" >&2; exit 1; }
gh release view "$TAG" >/dev/null 2>&1 \
    && { echo "error: $TAG already exists on GitHub — bump the version instead of re-cutting a release" >&2; exit 1; }

# ── Build ─────────────────────────────────────────────────────────────────────
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
"$HERE/build-zip.sh" "$STAGE"
ZIP_PATH="$STAGE/$SLUG-$VERSION.zip"
[ -f "$ZIP_PATH" ] || { echo "error: $ZIP_PATH missing after build" >&2; exit 1; }
python - "$ZIP_PATH" "$STAGE/unzipped" <<'PY'
import sys, zipfile
zipfile.ZipFile(sys.argv[1]).extractall(sys.argv[2])
PY

# ── WordPress.org SVN ─────────────────────────────────────────────────────────
if [ -d "$SVN_DIR/.svn" ]; then
    (cd "$SVN_DIR" && svn revert -R -q . && svn update -q)
    # Drop anything a previous dry run left unversioned.
    (cd "$SVN_DIR" && svn status | sed -n 's/^?[[:space:]]*//p' | while IFS= read -r p; do rm -rf "$p"; done)
else
    svn checkout -q "$SVN_URL" "$SVN_DIR"
fi
cd "$SVN_DIR"
[ -e "tags/$VERSION" ] && { echo "error: tags/$VERSION already exists on WordPress.org — bump the version" >&2; exit 1; }

# trunk/ = this build, exactly: files the build no longer has are removed from SVN too.
mkdir -p trunk assets
find trunk -mindepth 1 -maxdepth 1 -exec rm -rf {} +
cp -R "$STAGE/unzipped/$SLUG/." trunk/
cp -R "$HERE/.wordpress-org/." assets/
svn add --force -q trunk assets
svn status | sed -n 's/^![[:space:]]*//p' | while IFS= read -r p; do svn rm -q "$p"; done
for f in assets/*.png; do if [ -e "$f" ]; then svn propset -q svn:mime-type image/png "$f"; fi; done
for f in assets/*.jpg; do if [ -e "$f" ]; then svn propset -q svn:mime-type image/jpeg "$f"; fi; done
svn cp -q trunk "tags/$VERSION"

echo ""
echo "WordPress.org changes for $VERSION:"
svn status | grep -vE "^A[[:space:]]*[[:space:]]tags[/\\]$VERSION[/\\]" || true

if [ -n "$DRY_RUN" ]; then
    echo ""
    echo "Dry run: nothing committed or published. The staged checkout is in $SVN_DIR."
    exit 0
fi

svn commit -m "Release $VERSION" --username "$SVN_USER"
cd "$HERE"

# ── GitHub release (archive) ──────────────────────────────────────────────────
if [ -z "$NOTES_FILE" ]; then
    NOTES_FILE="$STAGE/notes.md"
    awk -v ver="= $VERSION =" '
        $0 == ver { on = 1; next }
        on && /^= [0-9]/ { exit }
        on { sub(/^\* /, ""); print }
    ' "$HERE/$SLUG/readme.txt" > "$NOTES_FILE"
    printf '\nInstall from WordPress: Plugins → Add New → search "PERSONAIZER", or https://wordpress.org/plugins/%s/\n' "$SLUG" >> "$NOTES_FILE"
fi
gh release create "$TAG" "$ZIP_PATH" --title "PERSONAIZER $VERSION" --notes-file "$NOTES_FILE"

echo ""
echo "Released $VERSION."
echo "  WordPress.org: https://wordpress.org/plugins/$SLUG/ (the download can take up to ~6 hours to update)"
echo "  GitHub:        $(gh release view "$TAG" --json url -q .url)"
