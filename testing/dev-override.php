<?php
/**
 * Plugin Name: PERSONAIZER Dev Override (testing only)
 * Description: Points the PERSONAIZER plugin at the DEV backend instead of production. Remove to return to prod.
 * Version: 1.0.0
 *
 * Use this with a PROD plugin zip (README option B) — the point of that option is to install the exact
 * artifact a real owner installs and steer it from outside. `build-zip.sh --dev` is the alternative,
 * and it bakes dev URLs into the package itself.
 *
 * Two ways to install it, depending on whether the test host lets you touch the filesystem:
 *
 *   A. mu-plugin (preferred).  Copy this file so it sits DIRECTLY at  wp-content/mu-plugins/dev-override.php
 *      (create the folder if missing). WordPress only auto-loads .php files placed DIRECTLY in
 *      mu-plugins/ — a file in a sub-folder is silently ignored, which looks exactly like "the override
 *      did nothing." mu-plugins load BEFORE regular plugins, so these defines always win. A "Code
 *      Snippets" plugin will NOT work — it runs after plugins load, too late. On TasteWP this needs the
 *      free WP File Manager plugin; a temp (free) site has no dashboard file manager and no SFTP.
 *
 *   B. Ordinary plugin, when you cannot reach the filesystem at all (a TasteWP temp site).
 *      Run  testing/build-dev-override-zip.sh  and upload the result via Plugins → Add New → Upload.
 *      This works only because the PERSONAIZER plugin declares every constant behind an if(!defined())
 *      guard AND WordPress includes active plugins in ACTIVATION order — so this one must be ACTIVATED
 *      BEFORE the PERSONAIZER plugin. Activate it second and it is a silent no-op. Alphabetical order is
 *      not what decides this, so do not rely on the name.
 *
 * Either way, verify in PERSONAIZER → System info that the API base reads dev-api.personaizer.com.
 * When loaded as a mu-plugin it appears under Plugins → Must-Use.
 *
 * These are dev HOSTNAMES, not secrets — the repo already carries them in appsettings.Development.json
 * and deploy/sessions/dev/ingress-v1.yaml. They live only on the test SITE, never in the shipped plugin.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Dev API base — the single /v1 ingress (deploy/sessions/dev/ingress-v1.yaml) that path-routes BOTH
// Core (/v1/persona, /v1/knowledge, /api/integrations/connect, /api/subscription/limits) AND Sessions
// (/v1/chat). The plugin only knows ONE host, so both the content sync AND the widget's chat ride this.
define( 'PERSONAIZER_API_URL', 'https://dev-api.personaizer.com' );

// Dev dashboard — Connect redirects the owner to its /connect consent screen.
define( 'PERSONAIZER_APP_URL', 'https://dev.personaizer.com' );

// Dev widget script (chat.js) on the dev public blob. NOTE: this serves whatever chat.js was last
// deployed to dev; to test UNRELEASED chat.js changes, point this at local Core instead.
define( 'PERSONAIZER_WIDGET_URL', 'https://personaizerdevstore2.blob.core.windows.net/platform-builds-public/chat.js' );

// Optional — also exercise the self-hosted update channel against the dev manifest.
define( 'PERSONAIZER_UPDATE_MANIFEST_URL', 'https://personaizerdevstore2.blob.core.windows.net/platform-builds-public/wordpress/personaizer.json' );

/**
 * Test hosts often leave PHP's display_errors on (TasteWP does). A notice printed inside a REST or AJAX
 * response lands AHEAD of the JSON and makes it unparseable, which surfaces as the plugin's admin screens
 * failing for no visible reason — "Connection didn't complete" being the usual one. WP_DEBUG_DISPLAY is
 * read during WordPress bootstrap, long before any plugin, so it can only be set in wp-config.php; this
 * covers everything from plugin load onward, which is where the plugin's own endpoints live.
 */
@ini_set( 'display_errors', 0 );
