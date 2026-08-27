<?php
/**
 * Reconciliation — compare what the AI holds against what this site actually has.
 *
 * The sync is event-driven: save a product, push that product. That is the right shape for keeping the
 * AI current, and it is also the shape that quietly drifts. Events go missing — WP-Cron never fires, the
 * API times out, a mapping bug drops a field, a doc is removed on the other side. Nothing in a push-only
 * design can notice any of that, because "synced" only ever meant "we attempted a send". A catalog can be
 * months stale and still count as 393 of 393.
 *
 * So this compares. For every published item in a syncing lane it builds the payload it WOULD send, takes
 * its fingerprint, and puts that beside the fingerprint of what actually landed last time and the list of
 * ids the AI reports holding. Three answers fall out:
 *
 *   missing   — the site has it, the AI does not
 *   stale     — both have it, but the content differs
 *   orphaned  — the AI has it, the site does not (only ever ids WE minted)
 *
 * The comparison writes nothing. That is deliberate: an owner should be able to ask "is my AI correct?"
 * without that question changing anything. Fixing is a separate, explicit step.
 *
 * Why this is cheap where "Resync everything" is expensive: a resync re-sends every item over many cron
 * ticks and hopes. A comparison is one API call per lane plus local queries, then a push of only what
 * differs — usually a handful. It finishes inside a normal admin request, so it never depends on WP-Cron,
 * which is the single least reliable part of this whole pipeline.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Personaizer_Reconcile {

    /** Per-item fingerprint of the payload that last successfully landed. */
    const META_HASH = '_personaizer_sync_hash';

    /**
     * Ceiling on how many items one comparison will fingerprint.
     *
     * Fingerprinting means running the full mapper per item (terms, images, variants), so cost grows with
     * the catalog. A few thousand is comfortably inside a normal admin request; past that we stop and say
     * so rather than let the page hang. Reported honestly as "checked N of M" instead of a false all-clear.
     */
    const MAX_ITEMS = 5000;

    /**
     * Compare every syncing lane. Read-only.
     *
     * @return array|WP_Error {
     *     @type array $lanes    Per-lane detail, keyed by lane id.
     *     @type int   $missing  Total items the AI is missing.
     *     @type int   $stale    Total items whose content differs.
     *     @type int   $orphaned Total docs the AI holds that the site no longer has.
     *     @type int   $in_sync  Total items that match.
     *     @type bool  $capped   True when MAX_ITEMS stopped the walk (results are partial).
     * }
     */
    public static function compare() {
        $api = personaizer_api();
        if ( ! $api->is_configured() ) {
            return new WP_Error( 'personaizer_not_connected', 'This site is not connected to a persona yet.' );
        }

        $report = array(
            'lanes' => array(), 'missing' => 0, 'stale' => 0,
            'orphaned' => 0, 'in_sync' => 0, 'capped' => false, 'checked' => 0,
        );

        foreach ( personaizer_current_lanes() as $lane ) {
            $source = personaizer_lane_source( $lane );
            $remote = $api->list_doc_ids( $source );
            if ( is_wp_error( $remote ) ) return $remote;   // can't compare against an unknown remote

            $remote_set = array_flip( $remote );
            $local      = self::local_items( $lane, $report );

            $missing = array();
            $stale   = array();
            $in_sync = 0;
            foreach ( $local as $ext => $info ) {
                if ( ! isset( $remote_set[ $ext ] ) ) {
                    $missing[] = $info['post_id'];
                } elseif ( $info['hash'] !== $info['synced'] ) {
                    // Also catches never-recorded ('' !== hash) — an item synced by an older version of
                    // this plugin, before fingerprints existed. Re-pushing it is harmless and makes the
                    // next comparison meaningful, so treating "unknown" as "stale" is the safe direction.
                    $stale[] = $info['post_id'];
                } else {
                    $in_sync++;
                }
            }

            // Orphans, but ONLY ids this plugin mints. A lane's source is shared: it also holds files the
            // owner uploaded in the dashboard and pages the onboarding scrape created under their own ids.
            // "Not on this site" is true of every one of those, and they cannot be re-synced from anywhere,
            // so anything outside our own prefix is none of our business.
            $prefix   = self::id_prefix( $lane );
            $orphaned = array();
            foreach ( $remote as $ext ) {
                if ( strpos( $ext, $prefix ) !== 0 ) continue;
                if ( ! isset( $local[ $ext ] ) ) $orphaned[] = $ext;
            }

            $report['lanes'][ $lane ] = array(
                'label'    => self::lane_label( $lane ),
                'source'   => $source,
                'missing'  => $missing,
                'stale'    => $stale,
                'orphaned' => $orphaned,
                'in_sync'  => $in_sync,
            );
            $report['missing']  += count( $missing );
            $report['stale']    += count( $stale );
            $report['orphaned'] += count( $orphaned );
            $report['in_sync']  += $in_sync;
        }

        return $report;
    }

    /**
     * Push everything a comparison found missing or stale.
     *
     * Only ever ADDS or UPDATES. Orphan removal is not done here — deleting is the one irreversible thing
     * this plugin can ask the API to do, so it stays a separate, explicitly-chosen action rather than a
     * side effect of "fix my sync".
     *
     * @return array|WP_Error {@type int $pushed, @type int $attempted}
     */
    public static function fix() {
        $report = self::compare();
        if ( is_wp_error( $report ) ) return $report;

        $pushed = 0;
        $attempted = 0;
        foreach ( $report['lanes'] as $lane => $detail ) {
            $ids = array_merge( $detail['missing'], $detail['stale'] );
            if ( empty( $ids ) ) continue;
            $attempted += count( $ids );

            if ( $lane === 'products' ) {
                $sync = personaizer_woocommerce_sync();
                if ( $sync ) $pushed += $sync->sync_ids( $ids );
            } else {
                $pushed += personaizer_sync()->sync_ids( $ids );
            }
        }
        return array( 'pushed' => $pushed, 'attempted' => $attempted );
    }

    // ── Local side ────────────────────────────────────────────────────────────────────

    /**
     * Every published item in a lane, with the fingerprint it WOULD send now and the one that last landed.
     *
     * @return array<string,array{post_id:int,hash:string,synced:string}> keyed by external id.
     */
    private static function local_items( $lane, array &$report ) {
        $out = array();

        if ( $lane === 'products' ) {
            $sync = personaizer_woocommerce_sync();
            if ( ! $sync || ! function_exists( 'wc_get_products' ) ) return $out;

            // Paged rather than one big query: a large catalog held entirely in memory as WC_Product
            // objects is how a comparison turns into a fatal on a small host.
            $page = 1;
            while ( true ) {
                $ids = wc_get_products( array(
                    'status' => 'publish', 'limit' => 200, 'page' => $page,
                    'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids',
                ) );
                if ( empty( $ids ) ) break;
                foreach ( $ids as $id ) {
                    if ( $report['checked'] >= self::MAX_ITEMS ) { $report['capped'] = true; return $out; }
                    $product = wc_get_product( $id );
                    if ( ! $product ) continue;
                    $ext = 'wc-product-' . (int) $id;
                    $out[ $ext ] = array(
                        'post_id' => (int) $id,
                        'hash'    => personaizer_payload_hash( $sync->payload_for( $product ) ),
                        'synced'  => (string) get_post_meta( (int) $id, self::META_HASH, true ),
                    );
                    $report['checked']++;
                }
                if ( count( $ids ) < 200 ) break;
                $page++;
            }
            return $out;
        }

        $post_type = self::post_type_for( $lane );
        if ( $post_type === '' ) return $out;
        $content = personaizer_sync();

        $paged = 1;
        while ( true ) {
            $ids = get_posts( array(
                'post_type' => $post_type, 'post_status' => 'publish', 'fields' => 'ids',
                'posts_per_page' => 200, 'paged' => $paged, 'orderby' => 'ID', 'order' => 'ASC',
                'no_found_rows' => true,
            ) );
            if ( empty( $ids ) ) break;
            foreach ( $ids as $id ) {
                if ( $report['checked'] >= self::MAX_ITEMS ) { $report['capped'] = true; return $out; }
                $post = get_post( $id );
                if ( ! $post ) continue;
                $ext = 'wp-' . $post->post_type . '-' . $post->ID;
                $out[ $ext ] = array(
                    'post_id' => (int) $id,
                    'hash'    => personaizer_payload_hash( $content->payload_for( $post ) ),
                    'synced'  => (string) get_post_meta( (int) $id, self::META_HASH, true ),
                );
                $report['checked']++;
            }
            if ( count( $ids ) < 200 ) break;
            $paged++;
        }
        return $out;
    }

    /** The external-id prefix this plugin mints for a lane — the boundary of what we may call an orphan. */
    private static function id_prefix( $lane ) {
        if ( $lane === 'products' ) return 'wc-product-';
        return 'wp-' . self::post_type_for( $lane ) . '-';
    }

    private static function post_type_for( $lane ) {
        $lanes = personaizer_lanes();
        return isset( $lanes[ $lane ]['post_type'] ) ? (string) $lanes[ $lane ]['post_type'] : '';
    }

    private static function lane_label( $lane ) {
        $lanes = personaizer_lanes();
        return isset( $lanes[ $lane ]['label'] ) ? (string) $lanes[ $lane ]['label'] : ucfirst( $lane );
    }
}
