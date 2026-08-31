#!/usr/bin/env bash
# Package testing/dev-override.php as an installable WordPress plugin.
#
#   testing/build-dev-override-zip.sh          # → dist/personaizer-dev-override.zip
#   testing/build-dev-override-zip.sh ~/Desktop
#
# Only needed when the test host gives you no way to write wp-content/mu-plugins/ — a TasteWP *temp*
# (free) site has no dashboard file manager and no SFTP, so the mu-plugin route (dev-override.php's
# option A) is simply unavailable there. Installing the same file as an ordinary plugin is the way in.
#
# The zip carries dev-override.php UNCHANGED — it is already a valid plugin (it has a Plugin Name
# header), so there is one source of truth for the dev URLs and no second copy to drift.
#
# Output goes to the gitignored dist/, same as build-zip.sh and for the same reason: a committed zip
# goes stale and you end up testing the wrong thing.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$HERE/dev-override.php"
SLUG="personaizer-dev-override"
OUT_DIR="${1:-$HERE/../dist}"

[ -f "$SRC" ] || { echo "error: $SRC not found" >&2; exit 1; }

# WordPress installs whatever top-level directory the zip contains, so stage the file inside a folder
# rather than zipping it bare from testing/ — a bare .php at the zip root installs under a name taken
# from the archive, which is not what the header says it is.
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$SLUG"
cp "$SRC" "$STAGE/$SLUG/$SLUG.php"

mkdir -p "$OUT_DIR"
OUT_DIR="$(cd "$OUT_DIR" && pwd)"
ZIP_PATH="$OUT_DIR/$SLUG.zip"

# zip(1) where available, else python's zipfile — the same fallback build-zip.sh uses, because a plain
# Git-Bash-on-Windows checkout has python but no zip(1).
rm -f "$ZIP_PATH"
if command -v zip >/dev/null 2>&1; then
    ( cd "$STAGE" && zip -q -r -9 "$ZIP_PATH" "$SLUG" )
else
    python - "$STAGE" "$ZIP_PATH" "$SLUG" <<'PYZ'
import os, sys, zipfile
stage, out, slug = sys.argv[1], sys.argv[2], sys.argv[3]
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for root, _, files in os.walk(os.path.join(stage, slug)):
        for f in sorted(files):
            full = os.path.join(root, f)
            z.write(full, os.path.relpath(full, stage).replace(os.sep, "/"))
PYZ
fi

echo "✓ built $(basename "$ZIP_PATH")"
if command -v cygpath >/dev/null 2>&1; then echo "  zip  $(cygpath -w "$ZIP_PATH")"; else echo "  zip  $ZIP_PATH"; fi
echo ""
echo "Install it BEFORE the PERSONAIZER plugin — WordPress includes active plugins in ACTIVATION"
echo "order, so the override only wins if it is activated first. Verify at PERSONAIZER -> System info:"
echo "  API base : https://dev-api.personaizer.com"
