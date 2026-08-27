<?php
/**
 * Self-hosted update channel.
 *
 * This plugin ships as a zip rather than through wordpress.org, so nothing would otherwise tell a site that a
 * newer version exists — an owner sits on whatever they first installed until somebody emails them, which for
 * a plugin that talks to our API means old clients accumulate against a moving server.
 *
 * WordPress has a first-class seam for exactly this. The update transient it rebuilds a couple of times a day
 * is filterable, and a plugin may add its own entry to it. Do that and every native affordance keeps working
 * unchanged: the "update available" row on the Plugins screen, the one-click Update button, background
 * auto-updates, and the "View details" modal. We add an entry; we do not reimplement any of that.
 *
 * The manifest is a STATIC JSON file sitting beside the zip on the public build container, not an API
 * endpoint, because:
 *   - every install polls it twice a day forever. That is the shape a CDN exists for and a poor use of an
 *     App Service request;
 *   - it keeps updating possible while the API is down — and "the API is down" is precisely the moment the
 *     fix we need to ship is sitting in an update;
 *   - it needs no credentials, so a site that has disconnected still receives security updates.
 *
 * Trust boundary: WordPress will download and install whatever `package` URL we hand it, with no signature
 * check of its own. So the package must come from the SAME host that served the manifest (see
 * trusted_package). Without that rule, a mis-served or tampered manifest could aim a site's one-click Update
 * at an arbitrary zip — the one genuinely dangerous failure mode in this file.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Where this plugin looks for its own updates: a static manifest beside the release zips, on the same
 * public build container that serves chat.js. Defaults to PRODUCTION; override from wp-config.php to
 * follow a dev release line:
 *   define( 'PERSONAIZER_UPDATE_MANIFEST_URL', 'https://personaizerdevstore2.blob.core.windows.net/platform-builds-public/wordpress/personaizer.json' );
 * Defined in THIS file (not the main plugin) so the WordPress.org build — which omits this file — ships no
 * update-mechanism code at all. Any manifest's packages must live on the manifest's own host (see
 * trusted_package below); that same-origin rule is the security boundary for self-hosted updates.
 */
if ( ! defined( 'PERSONAIZER_UPDATE_MANIFEST_URL' ) ) {
    define( 'PERSONAIZER_UPDATE_MANIFEST_URL', 'https://personaizerprodstore.blob.core.windows.net/platform-builds-public/wordpress/personaizer.json' );
}

class Personaizer_Updater {

    const SLUG = 'personaizer';

    /** Cache key for the fetched manifest. A SITE transient: update data is network-wide on multisite. */
    const CACHE_KEY = 'personaizer_update_manifest';

    /** How stale our copy of the manifest may get. WP only checks for updates ~twice a day anyway. */
    const CACHE_TTL = 21600;          // 6 hours

    /** Failures are cached too, briefly, so an unreachable CDN can't add latency to every admin page load. */
    const CACHE_TTL_FAILURE = 3600;   // 1 hour

    public static function boot() {
        // Deliberately unconditional — updates do not depend on the site being connected to a persona.
        add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'inject' ] );
        add_filter( 'plugins_api', [ __CLASS__, 'details' ], 20, 3 );
    }

    /** `personaizer/personaizer.php` — the key WordPress files every plugin under. */
    private static function basename() {
        return plugin_basename( PERSONAIZER_PLUGIN_FILE );
    }

    // ── The update check ──────────────────────────────────────────────────────

    /**
     * Add this plugin to the update transient WordPress is about to store.
     *
     * Both branches matter. `response` is what surfaces an available update; `no_update` is what lets the
     * Plugins screen say "you have the latest version" and lets auto-update settings render for us at all —
     * a plugin absent from both looks to WordPress like it has no update source.
     */
    public static function inject( $transient ) {
        if ( ! is_object( $transient ) ) return $transient;

        $manifest = self::manifest();
        if ( ! $manifest ) return $transient;

        $info     = self::transient_entry( $manifest );
        $basename = self::basename();

        if ( version_compare( $manifest['version'], PERSONAIZER_VERSION, '>' ) ) {
            if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
                $transient->response = [];
            }
            $transient->response[ $basename ] = $info;
            unset( $transient->no_update[ $basename ] );
        } else {
            if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
                $transient->no_update = [];
            }
            $transient->no_update[ $basename ] = $info;
        }

        return $transient;
    }

    /** The compact object the update transient holds (both for `response` and `no_update`). */
    private static function transient_entry( array $manifest ) {
        $entry = new stdClass();
        $entry->slug         = self::SLUG;
        $entry->plugin       = self::basename();
        $entry->new_version  = $manifest['version'];
        $entry->package      = $manifest['download_url'];
        $entry->url          = $manifest['homepage'];
        $entry->tested       = $manifest['tested'];
        $entry->requires     = $manifest['requires'];
        $entry->requires_php = $manifest['requires_php'];
        return $entry;
    }

    // ── The "View details" modal ──────────────────────────────────────────────

    /**
     * Answer WordPress's plugin-information request for OUR slug only.
     *
     * Returning anything other than the untouched `$result` for a slug that isn't ours would hijack the
     * modal for other plugins — hence the strict guard before we do anything at all.
     */
    public static function details( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) return $result;
        if ( empty( $args->slug ) || $args->slug !== self::SLUG ) return $result;

        $manifest = self::manifest();
        if ( ! $manifest ) return $result;

        $info = new stdClass();
        $info->name           = $manifest['name'];
        $info->slug           = self::SLUG;
        $info->version        = $manifest['version'];
        $info->author         = $manifest['author'];
        $info->homepage       = $manifest['homepage'];
        $info->requires       = $manifest['requires'];
        $info->requires_php   = $manifest['requires_php'];
        $info->tested         = $manifest['tested'];
        $info->last_updated   = $manifest['last_updated'];
        $info->download_link  = $manifest['download_url'];
        $info->trunk          = $manifest['download_url'];
        $info->sections       = $manifest['sections'];   // description / changelog, as HTML
        return $info;
    }

    // ── Manifest fetch + cache ────────────────────────────────────────────────

    /**
     * The manifest, from cache when we have it.
     *
     * WordPress's own "Check again" button sets `force-check`; honour it, otherwise an owner told to look for
     * an update would keep being handed our cached copy and reasonably conclude the update never shipped.
     *
     * @return array|null Validated manifest, or null when unavailable/untrustworthy.
     */
    private static function manifest() {
        $force = ! empty( $_GET['force-check'] ) && is_admin();

        if ( ! $force ) {
            $cached = get_site_transient( self::CACHE_KEY );
            if ( is_array( $cached ) ) return $cached;
            if ( $cached === 'unavailable' ) return null;   // negative cache — see CACHE_TTL_FAILURE
        }

        $url  = self::manifest_url();
        $resp = wp_remote_get( $url, [
            'timeout' => 8,     // short: this runs inside admin page loads
            'headers' => [ 'Accept' => 'application/json' ],
        ] );

        if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
            set_site_transient( self::CACHE_KEY, 'unavailable', self::CACHE_TTL_FAILURE );
            return null;
        }

        $manifest = self::validate( json_decode( wp_remote_retrieve_body( $resp ), true ), $url );
        if ( ! $manifest ) {
            set_site_transient( self::CACHE_KEY, 'unavailable', self::CACHE_TTL_FAILURE );
            return null;
        }

        set_site_transient( self::CACHE_KEY, $manifest, self::CACHE_TTL );
        return $manifest;
    }

    /** Where to look. Overridable in wp-config.php so a dev site can follow a dev release line. */
    private static function manifest_url() {
        return PERSONAIZER_UPDATE_MANIFEST_URL;
    }

    /**
     * Accept a manifest only if it is complete and its package is trustworthy.
     *
     * Everything is defaulted or dropped rather than trusted: this JSON is remote input that ends in an
     * admin screen (and, for `download_url`, in an installer).
     */
    private static function validate( $raw, $manifest_url ) {
        if ( ! is_array( $raw ) ) return null;

        $version  = isset( $raw['version'] ) ? trim( (string) $raw['version'] ) : '';
        $download = isset( $raw['download_url'] ) ? trim( (string) $raw['download_url'] ) : '';
        if ( $version === '' || $download === '' ) return null;
        if ( ! self::trusted_package( $download, $manifest_url ) ) return null;

        $sections = [];
        if ( ! empty( $raw['sections'] ) && is_array( $raw['sections'] ) ) {
            foreach ( $raw['sections'] as $key => $html ) {
                // The modal renders these, so they go through the same filter WordPress uses for post
                // content rather than being echoed raw.
                $sections[ sanitize_key( $key ) ] = wp_kses_post( (string) $html );
            }
        }

        return [
            'name'         => isset( $raw['name'] ) ? sanitize_text_field( (string) $raw['name'] ) : 'PERSONAIZER',
            'version'      => sanitize_text_field( $version ),
            'download_url' => esc_url_raw( $download ),
            'homepage'     => isset( $raw['homepage'] ) ? esc_url_raw( (string) $raw['homepage'] ) : PERSONAIZER_APP_URL,
            'author'       => isset( $raw['author'] ) ? wp_kses_post( (string) $raw['author'] ) : 'PERSONAIZER',
            'requires'     => isset( $raw['requires'] ) ? sanitize_text_field( (string) $raw['requires'] ) : '',
            'requires_php' => isset( $raw['requires_php'] ) ? sanitize_text_field( (string) $raw['requires_php'] ) : '',
            'tested'       => isset( $raw['tested'] ) ? sanitize_text_field( (string) $raw['tested'] ) : '',
            'last_updated' => isset( $raw['last_updated'] ) ? sanitize_text_field( (string) $raw['last_updated'] ) : '',
            'sections'     => $sections,
        ];
    }

    /**
     * The package must live on the same host that served the manifest.
     *
     * WordPress performs no signature check on a self-hosted package — it downloads the URL we give it and
     * unpacks it over the live plugin directory. Same-origin is what keeps a compromised or mistakenly
     * edited manifest from redirecting that installer somewhere else, and it holds for dev overrides too
     * (point the manifest at the dev container and its packages must also be on the dev container).
     */
    private static function trusted_package( $package_url, $manifest_url ) {
        $package_host  = wp_parse_url( $package_url, PHP_URL_HOST );
        $manifest_host = wp_parse_url( $manifest_url, PHP_URL_HOST );
        if ( ! $package_host || ! $manifest_host ) return false;
        return strtolower( $package_host ) === strtolower( $manifest_host );
    }
}
