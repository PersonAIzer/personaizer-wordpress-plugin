<?php
/**
 * The initial "teach my AI everything I already have" push, run in the background.
 *
 * Why this exists: connecting turns sync ON, and the existing content has to reach the persona
 * somehow. Doing that inline is not an option — one HTTP round-trip per post at a 30s timeout means
 * a 100-post site blows PHP's max_execution_time before it finishes, and the owner sees a white
 * screen instead of a working bot. So we walk the library a batch at a time on WP-Cron, each tick
 * re-scheduling the next until the site is drained.
 *
 * The progress it records is also the admin screen's status ("syncing 40/120"), so this class is
 * both the worker and the source of truth for what the owner sees.
 *
 * Ongoing edits do NOT come through here — those are the save_post / product hooks, which are
 * already incremental. This is strictly the one-time catch-up.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Personaizer_Backfill {

    /** Cron hook. Each run does one batch and re-arms itself while work remains. */
    const HOOK  = 'personaizer_backfill';
    const STATE = 'personaizer_backfill_state';

    /** Posts are one request each — keep the batch small enough to finish well inside a cron tick. */
    const POST_BATCH = 10;
    /** Products go up in a single bulk request, so a bigger batch is cheaper, not dearer. */
    const PRODUCT_BATCH = 20;

    /**
     * How long one tick may keep working, in seconds.
     *
     * This exists because of a WordPress fact that bites hard: wp_cron refuses to spawn more than
     * once every WP_CRON_LOCK_TIMEOUT (60s). One batch per tick therefore means one batch per MINUTE
     * — 28 items took ~3 minutes, and a 500-post site would have taken the better part of an hour.
     * So a tick keeps taking batches until its budget runs out. 20s leaves comfortable room under a
     * default 30s max_execution_time.
     */
    const BUDGET_SECONDS = 20;

    /**
     * How long to let a tick run before the watchdog assumes it died and resumes the walk.
     *
     * Must exceed the worst honest tick: BUDGET_SECONDS of batching plus one in-flight request at the
     * API client's own 30s timeout. Comfortably past that, so a slow-but-alive tick is never double-run,
     * while a dead one is picked back up in minutes rather than never.
     */
    const STALL_GRACE_SECONDS = 300;

    public static function boot() {
        add_action( self::HOOK, [ __CLASS__, 'run' ] );
        // Last-resort net: if the walk is unfinished and somehow has no tick armed at all (cron cleared
        // by another plugin, a migration, a crash before the watchdog was set), pick it back up.
        add_action( 'personaizer_reconcile', [ __CLASS__, 'resume_if_stalled' ] );
    }

    /**
     * Resume an unfinished walk that has no tick scheduled. Cheap and idempotent — does nothing when the
     * walk is finished, never started, or already armed.
     */
    public static function resume_if_stalled() {
        $state = get_option( self::STATE, [] );
        if ( ! is_array( $state ) || empty( $state['started_at'] ) || ! empty( $state['finished_at'] ) ) return;
        if ( wp_next_scheduled( self::HOOK ) ) return;
        self::rearm( 0 );
    }

    /** Clear the schedule on deactivate so a disabled plugin never keeps pushing. */
    public static function on_deactivate() {
        wp_clear_scheduled_hook( self::HOOK );
    }

    /**
     * Begin (or restart) a catch-up. Safe to call repeatedly — it resets progress and
     * re-arms the cron, which is exactly what "Resync everything now" should do.
     *
     * @param string[]|null $lanes Lane ids to walk, or null for every lane that is currently syncing.
     *                             Scoped runs exist because switching ONE lane back to "keep up to date"
     *                             shouldn't re-walk a 500-product catalog that was never stale.
     */
    public static function start( ?array $lanes = null ) {
        // A run already in flight (the initial connect walk) must never be NARROWED to the lane that just
        // changed — that would silently abandon the rest of the catch-up, and the owner would be left with
        // a half-taught AI and a progress bar claiming it finished. Widening to every syncing lane is a
        // superset of both scopes, and re-walking an unchanged post is a no-op on our side (same content
        // hash → no re-embed), so the cost is round trips, not money or correctness.
        $scope = $lanes;
        if ( $scope !== null && self::progress()['running'] ) {
            $scope = null;
        }

        update_option( self::STATE, [
            'lanes'           => $scope,
            'posts_total'     => self::count_posts( $scope ),
            'posts_offset'    => 0,
            'products_total'  => self::count_products( $scope ),
            'products_offset' => 0,
            'failed'          => 0,
            'started_at'      => time(),
            'finished_at'     => 0,
        ], false );
        delete_option( 'personaizer_last_error' );   // a fresh run gets a fresh verdict
        // rearm, not schedule: a watchdog left over from a previous walk is an "already scheduled" event,
        // and schedule() would yield to it — so an explicit "Resync everything" click would sit idle for
        // minutes instead of starting. A deliberate click always wins.
        self::rearm( 0 );
    }

    /**
     * Work through batches until the time budget runs out, then re-arm if anything is left.
     *
     * Progress is saved after every batch, not just at the end: if PHP is killed mid-tick (a hard
     * max_execution_time, a fatal), the next tick resumes from the last completed batch instead of
     * re-pushing everything from zero.
     */
    public static function run() {
        $state = get_option( self::STATE, [] );
        if ( ! is_array( $state ) || empty( $state['started_at'] ) || ! empty( $state['finished_at'] ) ) {
            return;
        }

        // Products FIRST, then content. On a plan too small to hold everything, whichever lane walks
        // first wins the quota — and for a store the catalog is what the chat is for. Walking content
        // first (the old order) let blog posts outrank the products a customer actually asks about, so a
        // constrained shop ended up with an AI that knew its "Hello World" post and zero of its catalog.
        // run_products no-ops instantly when there's no WooCommerce or the lane is off, so a content-only
        // site is unaffected — this only reorders when products actually exist.
        // Arm a WATCHDOG before touching anything. The re-arm at the bottom only runs if this request
        // survives — and a walk is exactly the kind of work that doesn't: a hard max_execution_time, an
        // OOM, the host killing a slow request, or one Core call sitting on its own 30s timeout while the
        // tick budget is 20s. Nothing else in the plugin ever schedules this hook, so losing that bottom
        // line meant the walk stalled at whatever offset it reached and stayed there forever, with the
        // panel still reporting "Syncing…". Scheduling ahead makes the worst case a delayed resume
        // instead of a dead one; the bottom of this method replaces it with an immediate tick when we do
        // survive, so normal progress is unaffected.
        self::rearm( self::STALL_GRACE_SECONDS );

        $started = microtime( true );
        do {
            $did = self::run_products( $state );
            if ( ! $did ) {
                $did = self::run_posts( $state );
            }
            update_option( self::STATE, $state, false );
        } while ( $did && ( microtime( true ) - $started ) < self::BUDGET_SECONDS );

        if ( $did ) {
            self::rearm( 0 );   // out of budget, not out of work — come back next tick
        } else {
            $state['finished_at'] = time();
            update_option( self::STATE, $state, false );
            wp_clear_scheduled_hook( self::HOOK );   // done — drop the watchdog
        }
    }

    /** @return bool true when this tick consumed a batch (i.e. there may be more). */
    private static function run_posts( array &$state ) {
        // A state written before scoped runs existed has no 'lanes' key — null means every syncing lane,
        // which is exactly what those runs meant.
        $types = self::enabled_post_types( $state['lanes'] ?? null );
        if ( empty( $types ) || $state['posts_offset'] >= $state['posts_total'] ) return false;

        // Offset paging is stable here: we order by ID and nothing in this loop changes
        // post_status, so rows can't shift underneath us mid-walk.
        $ids = get_posts( [
            'post_type'      => $types,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => self::POST_BATCH,
            'offset'         => (int) $state['posts_offset'],
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ] );
        if ( empty( $ids ) ) {
            // Usually honest (the catalog shrank since we counted). But an empty page at an offset BELOW
            // the total also happens when the query itself hiccups, and jumping the cursor to the end
            // would then skip every remaining item while reporting a clean finish. Advance (never spin),
            // and book the remainder as failed so the panel shows a gap and offers a Resync instead of
            // quietly claiming everything landed.
            $skipped = max( 0, (int) $state['posts_total'] - (int) $state['posts_offset'] );
            if ( $skipped > 0 ) {
                $state['failed'] += $skipped;
                personaizer_debug_log( 'backfill: posts query returned nothing at offset '
                    . (int) $state['posts_offset'] . ' of ' . (int) $state['posts_total'] . ' — ' . $skipped . ' unaccounted' );
            }
            $state['posts_offset'] = $state['posts_total'];
            return false;
        }

        // Advance by what we ATTEMPTED (so a rejected batch can't wedge the walk in a retry loop),
        // but count what actually landed — the difference is what the owner needs told about.
        $ok = personaizer_sync()->sync_ids( $ids );
        $state['posts_offset'] += count( $ids );
        $state['failed']       += count( $ids ) - $ok;
        return true;
    }

    /** @return bool true when this tick consumed a batch. */
    private static function run_products( array &$state ) {
        if ( ! self::products_in_scope( $state['lanes'] ?? null ) ) return false;
        $sync = personaizer_woocommerce_sync();
        if ( ! $sync || $state['products_offset'] >= $state['products_total'] ) return false;

        $ids = wc_get_products( [
            'status'  => 'publish',
            'limit'   => self::PRODUCT_BATCH,
            'offset'  => (int) $state['products_offset'],
            'orderby' => 'ID',
            'order'   => 'ASC',
            'return'  => 'ids',
        ] );
        if ( empty( $ids ) ) {
            // See run_posts(): an empty page below the total may be a shrunken catalog OR a failed query.
            // Advance so the walk can't spin, but record the remainder rather than silently skipping it.
            $skipped = max( 0, (int) $state['products_total'] - (int) $state['products_offset'] );
            if ( $skipped > 0 ) {
                $state['failed'] += $skipped;
                personaizer_debug_log( 'backfill: products query returned nothing at offset '
                    . (int) $state['products_offset'] . ' of ' . (int) $state['products_total'] . ' — ' . $skipped . ' unaccounted' );
            }
            $state['products_offset'] = $state['products_total'];
            return false;
        }

        $ok = $sync->sync_ids( $ids );
        $state['products_offset'] += count( $ids );
        $state['failed']          += count( $ids ) - $ok;
        return true;
    }

    /**
     * Arm the next tick, due immediately by default — the very next page request then runs it,
     * instead of the owner staring at "0 of 28" while a future-dated event waits for a cron spawn
     * that only comes once a minute anyway.
     */
    private static function schedule( $delay = 0 ) {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_single_event( time() + (int) $delay, self::HOOK );
        }
    }

    /**
     * Replace whatever tick is armed with one at <code>$delay</code>.
     *
     * schedule() above deliberately yields to an existing event, which is right when arming from
     * nothing — but wrong here: the watchdog set at the top of run() IS an existing event, so a plain
     * schedule(0) at the bottom would decline to bring the next tick forward and every batch would
     * crawl at watchdog pace. Clearing first makes the intent explicit — this delay wins.
     */
    private static function rearm( $delay ) {
        wp_clear_scheduled_hook( self::HOOK );
        wp_schedule_single_event( time() + (int) $delay, self::HOOK );
    }

    /**
     * The post types to walk: the ones actually syncing, narrowed to the run's scope.
     *
     * The scope is lane ids and the option is post types, so it maps through personaizer_lanes() rather
     * than assuming lane id === post type — that holds for custom types but not for pages or posts.
     *
     * @param string[]|null $scope Lane ids, or null for no narrowing.
     */
    private static function enabled_post_types( ?array $scope = null ) {
        $types = get_option( 'personaizer_sync_post_types', [] );
        $types = is_array( $types ) ? array_values( array_filter( array_map( 'sanitize_key', $types ) ) ) : [];
        if ( $scope === null ) return $types;

        $allowed = [];
        foreach ( personaizer_lanes() as $lane => $meta ) {
            if ( in_array( $lane, $scope, true ) && ! empty( $meta['post_type'] ) ) {
                $allowed[] = $meta['post_type'];
            }
        }
        // Intersect rather than trust the scope: a lane can be in scope but no longer syncing (switched off
        // between the save and the cron tick), and walking it would re-attach the source it just left.
        return array_values( array_intersect( $types, $allowed ) );
    }

    /** Whether this run should walk the WooCommerce catalog. @param string[]|null $scope Lane ids. */
    private static function products_in_scope( ?array $scope = null ) {
        if ( get_option( 'personaizer_sync_products', '' ) !== '1' || ! class_exists( 'WooCommerce' ) ) return false;
        return $scope === null || in_array( 'products', $scope, true );
    }

    private static function count_posts( ?array $scope = null ) {
        $total = 0;
        foreach ( self::enabled_post_types( $scope ) as $type ) {
            $counts = wp_count_posts( $type );
            if ( $counts && isset( $counts->publish ) ) $total += (int) $counts->publish;
        }
        return $total;
    }

    private static function count_products( ?array $scope = null ) {
        if ( ! self::products_in_scope( $scope ) ) return 0;
        $counts = wp_count_posts( 'product' );
        return $counts && isset( $counts->publish ) ? (int) $counts->publish : 0;
    }

    /**
     * Progress for the admin screen. `failed` is load-bearing: the API rejects a batch as a whole
     * (quota, plan caps), and a run that quietly reported "28/28 done" while nothing was written is
     * worse than one that says so.
     *
     * @return array{running:bool,done:int,total:int,failed:int}
     */
    public static function progress() {
        $state = get_option( self::STATE, [] );
        if ( ! is_array( $state ) || empty( $state['started_at'] ) ) {
            return [ 'running' => false, 'done' => 0, 'total' => 0, 'failed' => 0 ];
        }
        $total = (int) $state['posts_total'] + (int) $state['products_total'];
        $done  = (int) $state['posts_offset'] + (int) $state['products_offset'];
        return [
            'running' => empty( $state['finished_at'] ) && $done < $total,
            'done'    => $done,
            'total'   => $total,
            'failed'  => (int) ( $state['failed'] ?? 0 ),
        ];
    }
}
