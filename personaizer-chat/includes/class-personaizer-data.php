<?php
/**
 * Everything this plugin stores, and how to remove it.
 *
 * Two callers need the same answer to "what did we leave on this site?": the Disconnect action (owner
 * unlinks but keeps the plugin) and uninstall.php (owner deletes the plugin). Keeping the list in one
 * place is the point — a forgotten option here is a credential that outlives the thing that created it.
 *
 * Deliberately inert: no hooks, no bootstrap. uninstall.php runs WITHOUT the plugin loaded, so this
 * file has to be safe to require on its own.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Personaizer_Data {

    /** Every option the plugin persists in wp_options. */
    const OPTIONS = [
        // Connection — provisioned by Connect.
        'personaizer_persona_id',
        'personaizer_secret_key',
        'personaizer_identity_secret',
        'personaizer_identify_users',
        'personaizer_connected_at',
        // What to sync + how it's going.
        'personaizer_sync_post_types',
        'personaizer_sync_products',
        'personaizer_backfill_state',
        'personaizer_pending_removals',
        'personaizer_pending_overflow',
        'personaizer_pending_retry',
        'personaizer_last_sync',
        'personaizer_last_error',
        // Legacy appearance/behavior — no longer settings (they live on the persona now, in its Widget
        // tab). Still listed so an upgraded install doesn't leave orphaned rows behind.
        'personaizer_position',
        'personaizer_theme',
        'personaizer_accent',
        'personaizer_title',
        'personaizer_auto_open',
        'personaizer_nudge',
    ];

    /** Scheduled hooks the plugin owns. */
    const CRONS = [
        'personaizer_reconcile',
        'personaizer_backfill',
        'personaizer_overflow_catchup',
    ];

    /**
     * Forget this site's PERSONAIZER account entirely: credentials, sync state, caches, schedules.
     *
     * Does NOT touch anything on the PERSONAIZER side — the persona and its knowledge stay put, so
     * reconnecting later picks up where it left off. It only makes THIS site stop being connected.
     */
    public static function clear() {
        foreach ( self::OPTIONS as $option ) {
            delete_option( $option );
        }
        foreach ( self::CRONS as $hook ) {
            wp_clear_scheduled_hook( $hook );
        }
        // Per-item sync fingerprints (Personaizer_Reconcile::META_HASH). Written as post meta on every
        // synced product/page, so unlike the options above there is one per item — a disconnected site
        // would otherwise carry thousands of rows describing a connection it no longer has. Spelled
        // literally rather than via the class constant: uninstall.php runs outside the plugin's normal
        // bootstrap, where that class isn't necessarily loaded.
        delete_post_meta_by_key( '_personaizer_sync_hash' );
        self::clear_transients();
    }

    /**
     * Drop cached persona profiles and the update manifest. Cache keys embed a hash of persona id + API
     * base, so there's no single name to delete — clear by prefix.
     *
     * Both flavours are swept: ordinary transients (the profile cache) and SITE transients (the updater's
     * manifest, which is site-wide because WordPress's update data is). Sweeping by prefix rather than
     * naming keys is what keeps this honest — a new cache added elsewhere is covered the day it ships,
     * which a hand-maintained list would not be.
     */
    private static function clear_transients() {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s"
                . " OR option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_personaizer_' ) . '%',
                $wpdb->esc_like( '_transient_timeout_personaizer_' ) . '%',
                $wpdb->esc_like( '_site_transient_personaizer_' ) . '%',
                $wpdb->esc_like( '_site_transient_timeout_personaizer_' ) . '%'
            )
        );
    }
}
