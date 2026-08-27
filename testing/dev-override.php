<?php
/**
 * Plugin Name: PERSONAIZER Dev Override (testing only)
 * Description: Points the PERSONAIZER Chat plugin at the DEV backend instead of production. Delete this file to return to prod.
 *
 * READY TO USE. Copy this file so it sits DIRECTLY at  wp-content/mu-plugins/dev-override.php
 * (create the mu-plugins folder if missing). WordPress only auto-loads .php files placed DIRECTLY in
 * mu-plugins/ — a file in a sub-folder is silently ignored, which looks exactly like "the override did
 * nothing." mu-plugins also load BEFORE regular plugins, so these defines win over the plugin's
 * production defaults (it sets them with if(!defined()) guards). A "Code Snippets" plugin will NOT work
 * — it runs after plugins load, too late.
 *
 * When loaded, this appears under Plugins -> Must-Use as "PERSONAIZER Dev Override (testing only)".
 * Delete the file to return the site to production defaults.
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
