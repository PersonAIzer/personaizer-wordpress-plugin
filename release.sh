#!/bin/bash
# Cut a GitHub release: build the zip + update manifest, then publish both as assets of one release.
#
# Prerequisites: gh CLI authenticated (gh auth status)
#
# Usage:
#   ./release.sh                 # release the version in the plugin header
#   ./release.sh --notes-file X  # take the release body from a file instead of the readme changelog
#
# GitHub Releases is the ONLY channel. The download page clients are pointed at and the update manifest
# installed sites poll are the same release, so a published version cannot be visible in one place and
# missing from the other — which is exactly what happened while distribution lived on a blob container
# that a second script had to remember to upload to.
#
# BOTH assets matter, which is why this is a script and not a `gh release create` you type by hand:
#
#   personaizer-<version>.zip   what a site installs.
#   personaizer.json            what the updater polls, via the /releases/latest/download/ permalink.
#
# Ship the zip without the manifest and every installed site keeps being offered the PREVIOUS version —
# silently, because the old release still answers that permalink. There is no error anywhere.
#
# The updater also requires the package to sit on the manifest's own host (Personaizer_Updater::
# trusted_package). Both are release assets on github.com, so that holds by construction.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SLUG="personaizer"

NOTES_FILE=""
while [ $# -gt 0 ]; do
    case "$1" in
        --notes-file) NOTES_FILE="${2:-}"; shift 2 ;;
        *) echo "error: unknown argument '$1'" >&2; exit 1 ;;
    esac
done

command -v gh >/dev/null 2>&1 || { echo "error: gh CLI not found" >&2; exit 1; }
gh auth status >/dev/null 2>&1 || { echo "error: gh is not authenticated (gh auth login)" >&2; exit 1; }

VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\(.*\)$/\1/p' "$HERE/$SLUG/$SLUG.php" | head -1 | tr -d '[:space:]')"
[ -n "$VERSION" ] || { echo "error: no 'Version:' header in $SLUG.php" >&2; exit 1; }
TAG="v$VERSION"

# A release is cut from what is pushed, not from what is sitting in the working tree — the tag would
# otherwise point at a commit that does not contain the code in the zip.
[ -z "$(git -C "$HERE" status --porcelain)" ] \
    || { echo "error: working tree is dirty — commit first, the tag must match the zip" >&2; exit 1; }
git -C "$HERE" diff --quiet @{u}..HEAD 2>/dev/null \
    || { echo "note: HEAD differs from upstream — push before releasing" >&2; }

gh release view "$TAG" >/dev/null 2>&1 \
    && { echo "error: $TAG already exists. Bump the version instead of re-cutting a release —" >&2
         echo "       sites cache the manifest, and rewriting a published version is how they end up" >&2
         echo "       running bytes that no longer match the tag." >&2; exit 1; }

# ── Build ─────────────────────────────────────────────────────────────────────
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
"$HERE/build-zip.sh" "$STAGE"

ZIP_PATH="$STAGE/$SLUG-$VERSION.zip"
MANIFEST_PATH="$STAGE/$SLUG.json"
[ -f "$ZIP_PATH" ] && [ -f "$MANIFEST_PATH" ] \
    || { echo "error: expected artifacts missing after build" >&2; exit 1; }

# ── Notes: this version's readme changelog entry, unless one was supplied ──────
if [ -z "$NOTES_FILE" ]; then
    NOTES_FILE="$STAGE/notes.md"
    awk -v ver="= $VERSION =" '
        $0 == ver { on = 1; next }
        on && /^= [0-9]/ { exit }
        on { sub(/^\* /, ""); print }
    ' "$HERE/$SLUG/readme.txt" > "$NOTES_FILE"
    printf '\nInstall via WordPress admin: Plugins → Add New → Upload Plugin.\n' >> "$NOTES_FILE"
fi

echo ""
echo "Releasing $TAG"
echo "  zip       $(basename "$ZIP_PATH")"
echo "  manifest  $(basename "$MANIFEST_PATH")"
echo ""

gh release create "$TAG" "$ZIP_PATH" "$MANIFEST_PATH" \
    --title "PERSONAIZER $VERSION" \
    --notes-file "$NOTES_FILE"

echo ""
echo "Published $TAG. Sites pick it up on their next update check (~twice daily),"
echo "or immediately via Dashboard -> Updates -> Check again."
