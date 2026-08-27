#!/usr/bin/env bash
# Package the WordPress plugin — the artifact a site owner installs via
# WP Admin → Plugins → Add New → Upload Plugin (and the same one we'd submit to wordpress.org).
#
#   ./build-zip.sh              # PROD build  → dist/  (self-hosted release)
#   ./build-zip.sh --dev        # DEV  build  → dist/  (points at dev-api)
#   ./build-zip.sh --org        # WORDPRESS.ORG submission build → dist/  (updater stripped)
#   ./build-zip.sh ~/Desktop    # PROD build  → a directory of your choosing
#   ./build-zip.sh --dev ~/foo  # DEV  build  → there
#
# Output goes to dist/ by default — a gitignored folder, so the location is consistent
# (not scattered across $TMPDIR / your Desktop) but the binary is never committed. A committed zip drifts
# from source and you end up testing the wrong version; this rebuilds it fresh every time.
#
# The ONLY difference between the prod and dev packages is the baked-in backend URLs. The repo source
# ALWAYS defaults to production (a guard enforces it) — `--dev` rewrites those three URLs in a throwaway
# staged copy AFTER the guard, so the source stays clean and a dev build can never leak into a release.
# A DEV build is a local test artifact: install it by hand, never publish it.
#
# `--org` builds the wordpress.org submission package: identical to prod, minus the self-hosted updater
# (the directory serves updates there, so shipping our own update channel is a hard rejection).
set -euo pipefail

# ── Args: --dev/--prod flag (default prod) + optional output dir ───────────────
ENV="prod"
OUT_DIR=""
for arg in "$@"; do
    case "$arg" in
        --dev)  ENV="dev" ;;
        --prod) ENV="prod" ;;
        --org)  ENV="org" ;;
        -*)     echo "error: unknown flag '$arg' (use --dev, --prod or --org)" >&2; exit 1 ;;
        *)      OUT_DIR="$arg" ;;
    esac
done

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC_DIR="$HERE/personaizer-chat"
SLUG="personaizer-chat"
OUT_DIR="${OUT_DIR:-$HERE/dist}"

[ -d "$SRC_DIR" ] || { echo "error: $SRC_DIR not found" >&2; exit 1; }
mkdir -p "$OUT_DIR"

# WordPress reads the version from the plugin header — make the filename agree with it.
VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\(.*\)$/\1/p' "$SRC_DIR/$SLUG.php" | head -1 | tr -d '[:space:]')"
[ -n "$VERSION" ] || { echo "error: no 'Version:' header in $SLUG.php" >&2; exit 1; }
SUFFIX=""
[ "$ENV" = "dev" ] && SUFFIX="-dev"
[ "$ENV" = "org" ] && SUFFIX="-org"
ZIP_PATH="$OUT_DIR/$SLUG-$VERSION$SUFFIX.zip"

# readme.txt's "Stable tag" is the version a human reads as current — and, self-hosted, the only place an
# owner can check what they're about to install against what they're running. WordPress itself reads the
# PHP header, so a drift between the two is invisible until someone is debugging the wrong version.
STABLE="$(sed -n 's/^Stable tag:[[:space:]]*\(.*\)$/\1/p' "$SRC_DIR/readme.txt" | head -1 | tr -d '[:space:]')"
[ "$STABLE" = "$VERSION" ] \
    || { echo "error: readme.txt Stable tag ($STABLE) != plugin header Version ($VERSION)" >&2; exit 1; }

# PERSONAIZER_VERSION is what the running plugin compares against the update manifest. If it lagged the
# header, an installed site would keep re-offering an update it already has (or miss one it needs).
CONST_VERSION="$(sed -n "s/^define( 'PERSONAIZER_VERSION', '\([^']*\)' );.*$/\1/p" "$SRC_DIR/$SLUG.php" | head -1)"
[ "$CONST_VERSION" = "$VERSION" ] \
    || { echo "error: PERSONAIZER_VERSION ($CONST_VERSION) != plugin header Version ($VERSION)" >&2; exit 1; }
echo "✓ version $VERSION agrees across header, PERSONAIZER_VERSION and readme Stable tag"

# Where a PUBLISHED prod zip will live (the manifest advertises this URL). Dev builds don't get a manifest.
DIST_BASE="${PERSONAIZER_DIST_BASE:-https://personaizerprodstore.blob.core.windows.net/platform-builds-public/wordpress}"

# ── Guard: the SOURCE must default to PRODUCTION (both build modes) ────────────
# Local/dev URLs belong in wp-config.php or a --dev build, never in the source. Comments may document
# the dev overrides, so assert on the define() defaults specifically. --dev rewrites the STAGED copy below,
# never the source — so this guard holds regardless of mode, and a release can never carry dev URLs.
check_default() {  # <file> <constant> <expected>
    grep -q "define( '$2', '$3' );" "$SRC_DIR/$1" \
        || { echo "error: $2 does not default to $3 — refusing to package" >&2; exit 1; }
}
check_default "$SLUG.php"            PERSONAIZER_WIDGET_URL 'https://personaizerprodstore.blob.core.windows.net/platform-builds-public/chat.js'
check_default "$SLUG.php"            PERSONAIZER_APP_URL    'https://personaizer.com'
check_default includes/class-personaizer-api.php PERSONAIZER_API_URL 'https://api.personaizer.com'
echo "✓ source defaults point at production"

# ── Stage ─────────────────────────────────────────────────────────────────────
# WordPress installs whatever top-level directory the zip contains, so the tree must be
# rooted at "personaizer-chat/" — not at the files themselves.
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$SLUG"
(cd "$SRC_DIR" && tar -cf - \
    --exclude='.DS_Store' --exclude='Thumbs.db' --exclude='*.log' \
    --exclude='.git*' --exclude='node_modules' .) | (cd "$STAGE/$SLUG" && tar -xf -)

# ── DEV: rewrite the three backend URLs in the staged copy (never the source) ──
if [ "$ENV" = "dev" ]; then
    dp="$STAGE/$SLUG"
    sed -i "s|personaizerprodstore.blob.core.windows.net|personaizerdevstore2.blob.core.windows.net|g" "$dp/$SLUG.php"
    sed -i "s|define( 'PERSONAIZER_APP_URL', 'https://personaizer.com' );|define( 'PERSONAIZER_APP_URL', 'https://dev.personaizer.com' );|" "$dp/$SLUG.php"
    sed -i "s|define( 'PERSONAIZER_API_URL', 'https://api.personaizer.com' );|define( 'PERSONAIZER_API_URL', 'https://dev-api.personaizer.com' );|" "$dp/includes/class-personaizer-api.php"
    # Assert the rewrite actually landed — if a constant was renamed the sed silently no-ops, and a
    # "dev" build that still pointed at prod would be a maddening thing to debug on a test site.
    grep -q "define( 'PERSONAIZER_API_URL', 'https://dev-api.personaizer.com' );" "$dp/includes/class-personaizer-api.php" \
        || { echo "error: dev rewrite of PERSONAIZER_API_URL failed — did the define change?" >&2; exit 1; }
    grep -q "define( 'PERSONAIZER_APP_URL', 'https://dev.personaizer.com' );" "$dp/$SLUG.php" \
        || { echo "error: dev rewrite of PERSONAIZER_APP_URL failed — did the define change?" >&2; exit 1; }
    # The updater (self-hosted builds only) holds the update-manifest URL — rewrite its host too so a dev
    # build follows the dev release line instead of polling the prod manifest.
    [ -f "$dp/includes/class-personaizer-updater.php" ] \
        && sed -i "s|personaizerprodstore.blob.core.windows.net|personaizerdevstore2.blob.core.windows.net|g" "$dp/includes/class-personaizer-updater.php"
    echo "✓ dev build — rewrote API→dev-api.personaizer.com, dashboard→dev.personaizer.com, widget→dev blob"
fi

# ── ORG: strip the self-hosted updater ────────────────────────────────────────
# wordpress.org serves updates itself; a plugin that ships its own update channel (the
# pre_set_site_transient_update_plugins / plugins_api filters) is a hard rejection. The main file loads the
# updater only when the file is present, so dropping the file is enough — then assert nothing update-related
# survived in what actually ships.
if [ "$ENV" = "org" ]; then
    dp="$STAGE/$SLUG"
    rm -f "$dp/includes/class-personaizer-updater.php"
    [ ! -f "$dp/includes/class-personaizer-updater.php" ] \
        || { echo "error: could not drop the updater from the org build" >&2; exit 1; }
    if grep -rEq 'pre_set_site_transient_update_plugins|PERSONAIZER_UPDATE_MANIFEST_URL' "$dp"; then
        echo "error: org build still carries self-hosted update code — wordpress.org would reject it:" >&2
        grep -rEn 'pre_set_site_transient_update_plugins|PERSONAIZER_UPDATE_MANIFEST_URL' "$dp" >&2
        exit 1
    fi
    echo "✓ org build — self-hosted updater removed (wordpress.org serves updates)"
fi

# ── Syntax check the STAGED tree (what actually ships, incl. any dev rewrite) ──
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

# ── DEV stops here: a local test artifact, no manifest, not for publishing ─────
if [ "$ENV" = "dev" ]; then
    echo ""
    echo "DEV TEST BUILD — points at dev-api.personaizer.com. Install by hand; do NOT publish."
    if command -v cygpath >/dev/null 2>&1; then echo "  zip  $(cygpath -w "$ZIP_PATH")"; else echo "  zip  $ZIP_PATH"; fi
    exit 0
fi

# ── ORG stops here: the wordpress.org submission zip. No manifest — the directory serves updates. ──
if [ "$ENV" = "org" ]; then
    echo ""
    echo "WORDPRESS.ORG SUBMISSION BUILD — updater stripped; the directory serves updates."
    echo "First release: upload this zip at https://wordpress.org/plugins/developers/add/. After approval, push to SVN."
    if command -v cygpath >/dev/null 2>&1; then echo "  zip  $(cygpath -w "$ZIP_PATH")"; else echo "  zip  $ZIP_PATH"; fi
    exit 0
fi

# ── Update manifest (PROD builds only) ────────────────────────────────────────
# The file installed sites poll to learn a new version exists. Generated here, from the same readme and
# header the zip was just built from, so the advertised version/requirements cannot disagree with what is
# actually inside the package. Publish it beside the zip; both must sit on the same host.
MANIFEST_PATH="$OUT_DIR/$SLUG.json"
python - "$SRC_DIR/readme.txt" "$SRC_DIR/$SLUG.php" "$MANIFEST_PATH" "$VERSION" "$DIST_BASE/$SLUG-$VERSION.zip" <<'PY'
import html, json, re, sys, time

readme_path, plugin_path, out_path, version, download_url = sys.argv[1:6]
readme = open(readme_path, encoding="utf-8").read()
plugin = open(plugin_path, encoding="utf-8").read()

def readme_field(name, default=""):
    m = re.search(r"^%s:\s*(.+)$" % re.escape(name), readme, re.M)
    return m.group(1).strip() if m else default

def header_field(name, default=""):
    m = re.search(r"^\s*\*\s*%s:\s*(.+)$" % re.escape(name), plugin, re.M)
    return m.group(1).strip() if m else default

def inline(s):
    s = html.escape(s)
    s = re.sub(r"\[([^\]]+)\]\(([^)]+)\)", r'<a href="\2">\1</a>', s)
    s = re.sub(r"\*\*(.+?)\*\*", r"<strong>\1</strong>", s)
    return s

def to_html(text):
    """readme.txt markup -> the small HTML subset the 'View details' modal renders."""
    out, bullets, para = [], [], []
    def flush_para():
        if para:
            out.append("<p>%s</p>" % " ".join(para).strip())
            del para[:]
    def flush_bullets():
        if bullets:
            out.append("<ul>%s</ul>" % "".join("<li>%s</li>" % b for b in bullets))
            del bullets[:]
    for raw in text.split("\n"):
        line = raw.strip()
        if not line:
            flush_bullets(); flush_para(); continue
        heading = re.match(r"^=+\s*(.+?)\s*=+$", line)
        if heading:
            flush_bullets(); flush_para()
            out.append("<h4>%s</h4>" % inline(heading.group(1)))
        elif line.startswith("* "):
            flush_para()
            bullets.append(inline(line[2:].strip()))
        else:
            flush_bullets()
            para.append(inline(line))
    flush_bullets(); flush_para()
    return "\n".join(out)

sections = dict(re.findall(r"^==\s*(.+?)\s*==[ \t]*\n(.*?)(?=^==\s|\Z)", readme, re.M | re.S))

manifest = {
    "name": header_field("Plugin Name", "PersonAIzer Chat & Search"),
    "slug": "personaizer-chat",
    "version": version,
    "requires": readme_field("Requires at least"),
    "requires_php": readme_field("Requires PHP"),
    "tested": readme_field("Tested up to"),
    "last_updated": time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime()),
    "homepage": header_field("Plugin URI", "https://personaizer.com/wordpress"),
    "author": header_field("Author", "PersonAIzer"),
    "download_url": download_url,
    "sections": {
        "description": to_html(sections.get("Description", "").strip()),
        "changelog": to_html(sections.get("Changelog", "").strip()),
    },
}
for required in ("requires", "requires_php", "tested"):
    if not manifest[required]:
        sys.exit("error: readme.txt is missing the field behind '%s'" % required)

with open(out_path, "w", encoding="utf-8") as f:
    json.dump(manifest, f, indent=2)
    f.write("\n")
# ASCII only: Python's stdout is cp1252 on Windows and would die on a tick mark.
print("wrote %s (advertises %s)" % (out_path.rsplit("/", 1)[-1], manifest["download_url"]))
PY

# Print paths a Windows file picker will accept, when we're on Git Bash.
echo ""
echo "Publish BOTH to $DIST_BASE/ :"
if command -v cygpath >/dev/null 2>&1; then
    echo "  zip      $(cygpath -w "$ZIP_PATH")"
    echo "  manifest $(cygpath -w "$MANIFEST_PATH")"
else
    echo "  zip      $ZIP_PATH"
    echo "  manifest $MANIFEST_PATH"
fi
echo ""
echo "Upload the zip FIRST — the manifest advertises it, so publishing the manifest"
echo "first would offer every site a download that 404s."
