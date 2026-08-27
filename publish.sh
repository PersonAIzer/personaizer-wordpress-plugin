#!/bin/bash
# Publish the WordPress plugin release: build the zip + manifest, then upload both to the public build
# container that already serves chat.js.
#
# Prerequisites: az CLI logged in (az login)
#
# Usage:
#   ./publish.sh dev
#   ./publish.sh prod
#
# Two things here are load-bearing and easy to get wrong by hand, which is why this is a script:
#
#   1. ORDER. The manifest advertises the zip's URL. Publish the manifest first and every installed site is
#      immediately offered a download that 404s — WordPress reports a failed update on sites that were
#      working fine a moment ago. Zip first, always, and abort if it doesn't land.
#
#   2. CACHING. The manifest is the thing sites poll; a long cache means a release nobody sees for hours,
#      including a security fix. It gets a short max-age. The zips are immutable by construction (the
#      version is in the filename), so they get the long one.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Colors (inlined — this repo has no shared deploy/lib/ to source, unlike personaizer-backend)
GREEN='\033[0;32m'
RED='\033[0;31m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

SLUG="personaizer-chat"
CONTAINER="platform-builds-public"
PREFIX="wordpress"

ENV=${1:-}
if [[ -z "$ENV" || ! "$ENV" =~ ^(dev|prod)$ ]]; then
    echo "Usage: $0 <dev|prod>"
    [[ "${PERSONAIZER_CONFIRMED:-}" != "1" ]] && read -p "Press Enter to close..." || true
    exit 1
fi

if [[ "$ENV" == "dev" ]]; then
    STORAGE_ACCOUNT="personaizerdevstore2"
elif [[ "$ENV" == "prod" ]]; then
    if [[ "${PERSONAIZER_CONFIRMED:-}" != "1" ]]; then
        echo -e "${RED}${BOLD}WARNING: This publishes to PRODUCTION.${NC}"
        echo "Every site running this plugin will be offered the new version."
        read -p "Type 'yes' to continue: " CONFIRM
        [[ "$CONFIRM" == "yes" ]] || { echo "Aborted."; exit 1; }
    fi
    STORAGE_ACCOUNT="personaizerprodstore"
fi

BASE_URL="https://${STORAGE_ACCOUNT}.blob.core.windows.net/${CONTAINER}/${PREFIX}"

# ── Build ─────────────────────────────────────────────────────────────────────
# Built with the TARGET environment's URL baked into the manifest: the updater only trusts a package served
# from the manifest's own host, so a prod manifest advertising a dev zip would simply be ignored by every
# site — silently. Building per-environment is what keeps those two in agreement.
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

echo ""
echo "========================================"
echo "  Publish WordPress plugin ($ENV)"
echo "========================================"
echo "  Storage account:  $STORAGE_ACCOUNT"
echo "  Destination:      $BASE_URL/"
echo ""

PERSONAIZER_DIST_BASE="$BASE_URL" "$SCRIPT_DIR/build-zip.sh" "$STAGE" || {
    echo -e "${RED}${BOLD}Build failed — nothing published.${NC}"
    [[ "${PERSONAIZER_CONFIRMED:-}" != "1" ]] && read -p "Press Enter to close..." || true
    exit 1
}

VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\(.*\)$/\1/p' "$SCRIPT_DIR/$SLUG/$SLUG.php" | head -1 | tr -d '[:space:]')"
ZIP_PATH="$STAGE/$SLUG-$VERSION.zip"
MANIFEST_PATH="$STAGE/$SLUG.json"

[ -f "$ZIP_PATH" ] && [ -f "$MANIFEST_PATH" ] || {
    echo -e "${RED}${BOLD}Expected artifacts missing after build.${NC}"
    exit 1
}

# ── 1. The zip, first ─────────────────────────────────────────────────────────
# Immutable: the version is in the filename, so a given URL's bytes never change. Re-publishing the same
# version overwrites it, which is why a released version should never be rebuilt — bump instead.
echo ""
echo -e "${CYAN}Uploading $SLUG-$VERSION.zip ...${NC}"
if ! az storage blob upload \
    --account-name "$STORAGE_ACCOUNT" \
    --container-name "$CONTAINER" \
    --name "$PREFIX/$SLUG-$VERSION.zip" \
    --file "$ZIP_PATH" \
    --overwrite \
    --auth-mode key \
    --content-type "application/zip" \
    --content-cache-control "public, max-age=31536000, immutable" \
    --only-show-errors; then
    echo -e "${RED}${BOLD}Zip upload failed — manifest NOT published, so no site is affected.${NC}"
    [[ "${PERSONAIZER_CONFIRMED:-}" != "1" ]] && read -p "Press Enter to close..." || true
    exit 1
fi

# ── 2. The manifest, second — this is what goes live ──────────────────────────
echo -e "${CYAN}Uploading $SLUG.json ...${NC}"
if ! az storage blob upload \
    --account-name "$STORAGE_ACCOUNT" \
    --container-name "$CONTAINER" \
    --name "$PREFIX/$SLUG.json" \
    --file "$MANIFEST_PATH" \
    --overwrite \
    --auth-mode key \
    --content-type "application/json" \
    --content-cache-control "public, max-age=300" \
    --only-show-errors; then
    echo -e "${RED}${BOLD}Manifest upload failed.${NC} The zip is published but unadvertised — re-run to finish."
    [[ "${PERSONAIZER_CONFIRMED:-}" != "1" ]] && read -p "Press Enter to close..." || true
    exit 1
fi

echo ""
echo -e "${GREEN}${BOLD}Published $SLUG $VERSION ($ENV).${NC}"
echo "  manifest  $BASE_URL/$SLUG.json"
echo "  package   $BASE_URL/$SLUG-$VERSION.zip"
echo ""
echo "  Sites pick this up on their next update check (~twice daily), or immediately"
echo "  via Dashboard -> Updates -> Check again."
echo ""
[[ "${PERSONAIZER_CONFIRMED:-}" != "1" ]] && read -p "Press Enter to close..." || true
