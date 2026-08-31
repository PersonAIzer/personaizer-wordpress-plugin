<?php
/**
 * WooCommerce catalog sync → PERSONAIZER TYPED commerce docs.
 *
 * Detection-gated (only wired when WooCommerce is active). Each product maps to a
 * typed knowledge item — price / original price / stock / SKU / attributes / images —
 * pushed via PUT /v1/knowledge/docs, so the AI can filter and recommend ("in stock
 * under 50?") rather than read flat text. Products share the site's source (the store);
 * their category is what routes them to the typed lane, unlike the null-category
 * general-content lane (handled by Personaizer_Content_Sync).
 *
 * Mechanism: WooCommerce CRUD hooks (never raw DB / polling).
 *   - woocommerce_update_product / woocommerce_new_product → create/update
 *   - woocommerce_product_set_stock / woocommerce_variation_set_stock → real-time stock
 *   - trashed_post / before_delete_post (product) → remove
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Personaizer_WooCommerce_Sync {

    const OPTION     = 'personaizer_sync_products';
    const MAX_BATCH  = 100;
    const MAX_IMAGES = 15;

    /** @var Personaizer_Api */
    private $api;

    public function __construct( Personaizer_Api $api ) {
        $this->api = $api;

        add_action( 'woocommerce_update_product', [ $this, 'on_product_saved' ], 20 );
        add_action( 'woocommerce_new_product', [ $this, 'on_product_saved' ], 20 );
        add_action( 'woocommerce_product_set_stock', [ $this, 'on_stock_changed' ], 20 );
        add_action( 'woocommerce_variation_set_stock', [ $this, 'on_variation_stock_changed' ], 20 );
        add_action( 'trashed_post', [ $this, 'on_post_removed' ] );
        add_action( 'before_delete_post', [ $this, 'on_post_removed' ] );
    }

    /** The owner turned product catalog sync on. */
    private function enabled() {
        return get_option( self::OPTION, '' ) === '1';
    }

    /** Sync only when enabled, keyed, and WooCommerce's product API is available. */
    private function ready() {
        return $this->enabled() && $this->api->is_configured() && function_exists( 'wc_get_product' );
    }

    /** Stable, URL-safe external id — the key the typed upsert dedupes/updates on. */
    private function external_id( $product_id ) {
        return 'wc-product-' . (int) $product_id;
    }

    /**
     * The store's source key — its OWN lane, separate from the site's pages and posts (see
     * personaizer_lanes()). A source is the unit a persona switches on and off, so sharing one key with the
     * content lanes made "stop using my products, keep my pages" impossible to express: unticking Products
     * could only stop the sync, and the AI kept selling from the catalog it already had.
     *
     * Same brand as the content lanes — one shop, one logo (see PERSONAIZER_BRAND_KEY on the push).
     */
    private function source() {
        return personaizer_lane_source( 'products' );
    }

    /**
     * A product was created/updated. Only published products belong in the catalog
     * knowledge — anything else (draft, private, pending) is removed.
     * NOTE: also fires on stock writes; the typed upsert is idempotent so replays are cheap.
     */
    public function on_product_saved( $product_id ) {
        if ( ! $this->api->is_configured() ) return;
        if ( wp_is_post_revision( $product_id ) ) return;

        // Lane frozen. An EDIT needs nothing remembered — the catch-up walk on resume re-reads the product.
        // An UNPUBLISH does: that walk only visits published products, so it is exactly blind to this, and
        // the doc would outlive the product forever. get_post_status keeps this off wc_get_product, because
        // every purchase in the shop reaches here via the stock hooks.
        if ( ! $this->enabled() ) {
            if ( get_post_status( $product_id ) !== 'publish' ) {
                personaizer_remember_removal( 'products', $this->external_id( $product_id ), $product_id );
            }
            return;
        }
        if ( ! function_exists( 'wc_get_product' ) ) return;
        $product = wc_get_product( $product_id );
        if ( ! $product ) return;

        if ( $product->get_status() !== 'publish' ) {
            // Leaving the AI ⇒ also leave the overflow queue, or a deleted product lingers as "waiting for
            // plan space" forever (it can never re-push to clear itself).
            personaizer_forget_overflow( 'products', [ $this->external_id( $product_id ) ] );
            personaizer_forget_retry( 'products', [ $this->external_id( $product_id ) ] );
            $this->api->delete_docs( [ $this->external_id( $product_id ) ] );
            return;
        }

        $item   = $this->map_product( $product );
        $result = $this->api->upsert_products( [ $item ] );
        if ( is_wp_error( $result ) ) {
            // Plan full (402, nothing fit) → remember for the after-upgrade replay; anything else is a real
            // failure and gets queued for retry. Without that, a single save that happened to hit a timeout
            // left the AI holding a stale copy of that product with nothing scheduled to correct it.
            if ( Personaizer_Api::is_quota_error( $result ) ) {
                personaizer_remember_overflow( 'products', $this->external_id( $product_id ), $product_id );
            } else {
                personaizer_remember_retry( 'products', $this->external_id( $product_id ), $product_id );
            }
            personaizer_debug_log( 'product sync failed for ' . $product_id . ': ' . $result->get_error_message() );
            return;
        }
        // Success — deferred (the item didn't fit) → remember; otherwise it landed → forget.
        $ext      = $this->external_id( $product_id );
        $deferred = ( is_array( $result ) && ! empty( $result['deferred'] ) ) ? array_map( 'strval', (array) $result['deferred'] ) : array();
        if ( in_array( $ext, $deferred, true ) ) {
            personaizer_remember_overflow( 'products', $ext, $product_id );
        } else {
            personaizer_forget_overflow( 'products', [ $ext ] );
            personaizer_forget_retry( 'products', [ $ext ] );
            // Landed — remember WHAT landed, so a later comparison can tell "already correct" from
            // "looks present but is out of date". Without this an item is only ever known to exist.
            personaizer_record_sync_hash( $product_id, personaizer_payload_hash( $item ) );
        }
    }

    /** Stock changed on a simple product (e.g. a purchase) — re-sync it. */
    public function on_stock_changed( $product ) {
        if ( ! ( $product instanceof WC_Product ) ) $product = wc_get_product( $product );
        if ( $product ) $this->on_product_saved( $product->get_id() );
    }

    /** Stock changed on a variation — re-sync the parent product it rolls up to. */
    public function on_variation_stock_changed( $variation ) {
        if ( ! ( $variation instanceof WC_Product ) ) $variation = wc_get_product( $variation );
        if ( ! $variation ) return;
        $parent_id = $variation->get_parent_id();
        if ( $parent_id ) $this->on_product_saved( $parent_id );
    }

    public function on_post_removed( $post_id ) {
        if ( ! $this->api->is_configured() ) return;
        if ( get_post_type( $post_id ) !== 'product' ) return;

        $external_id = $this->external_id( $post_id );
        personaizer_forget_overflow( 'products', [ $external_id ] );   // gone ⇒ not waiting
        personaizer_forget_retry( 'products', [ $external_id ] );

        // Queue rather than delete inline, even when the lane is syncing — see personaizer_arm_removal_flush().
        // An inline call is one blocking round-trip per product, so emptying a category of 200 is 200 of them
        // in a single request; it dies on max_execution_time and every delete it never reached is lost with
        // nothing to retry it. The flush this arms sends them batched, at shutdown, and keeps what it cannot
        // finish. Deliberately not gated on enabled(): a doc pushed before the lane was switched off still
        // exists, so a deletion while it sleeps must still be recorded.
        personaizer_remember_removal( 'products', $external_id, $post_id );
    }

    /**
     * The exact item this product would be pushed as — no request, no side effects.
     *
     * Reconciliation fingerprints THIS, not the WooCommerce row, so the comparison is against what the AI
     * would actually receive. That is what lets a change in the mapper itself (a bug fix that starts
     * emitting a previously-dropped attribute) register as "out of date" rather than staying invisible.
     */
    public function payload_for( WC_Product $product ) {
        return $this->map_product( $product );
    }

    /** Map a WooCommerce product to a typed knowledge item. */
    private function map_product( WC_Product $product ) {
        $id    = $product->get_id();
        $paths = $this->category_paths( $product );

        $attributes = $this->attributes( $product );
        // Every category name the product touches also rides along as a filterable attribute, so the
        // AI can narrow WITHIN a category domain ("dibond, inside sheet materials") without needing a
        // separate domain per leaf. The paths above decide which domains it belongs to; this decides
        // what it can be filtered by once it's there.
        $names = $this->category_names( $paths );
        if ( ! empty( $names ) ) $attributes['category'] = $names;

        $item = array(
            'id'          => $this->external_id( $id ),
            'title'       => $product->get_name(),
            'description' => $this->description( $product ),
            'source'      => $this->source(),
            // Its own source (so products switch off independently) but the SAME brand as the site's pages
            // and posts — one shop, one logo.
            'brand'       => personaizer_source_key(),
            'categories'  => $paths,
            'currency'    => get_woocommerce_currency(),
            'attributes'  => $attributes,
            'images'      => $this->images( $product ),
            'links'       => array( array( 'url' => get_permalink( $id ), 'is_primary' => true ) ),
        );

        // Every product ships as per-SKU `variants` — the backend rolls them up into the parent
        // "from" price / any-in-stock and projects the facet union for filtering. We deliberately
        // DON'T send parent price/in_stock (the backend derives them from the variants, which also
        // sidesteps the variable-product get_regular_price() = '' sale bug).
        if ( $product->is_type( 'variable' ) ) {
            $item['variants'] = $this->variants( $product );
        } else {
            // Simple product = one SKU. Send it as a single variant-of-one carrying its commerce AND
            // its global (taxonomy `pa_*`) attributes as per-SKU facets — the SAME axes that are
            // variations on a variable product — so `color`/`size` resolve to one `variant_<facet>`
            // key regardless of product type (consistency). Non-taxonomy custom attributes stay
            // doc-level (see attributes()).
            $item['variants'] = array( $this->simple_variant( $product ) );
        }
        return $item;
    }

    private function description( WC_Product $product ) {
        $text = $product->get_description();
        if ( $text === '' ) $text = $product->get_short_description();
        $text = wp_strip_all_tags( (string) apply_filters( 'the_content', $text ) );
        return trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );
    }

    /**
     * Every category path the product is filed under, each a root→leaf chain of names.
     *
     *     [ ["Outlet"], ["Sheet materials", "Dibond"] ]
     *
     * WooCommerce gives a product an unordered SET of product_cat terms and nothing in core says
     * which one is definitive — so we stop guessing and send all of them. Picking a "primary" was
     * always lossy: a product genuinely in two branches (an LED driver that is both lighting AND a
     * power supply) lost one branch, and with it that branch's filters. The platform decides which
     * nodes become searchable groups; the plugin's job is to report the truth.
     *
     * Only MAXIMAL paths are sent. If a merchant ticked both "Sheet materials" and its child
     * "Dibond", the parent adds nothing the child doesn't already imply, so it is dropped — the
     * ancestor is literally a prefix of the path we send.
     *
     * A product with no categories falls back to one "Products" path, so it is still reachable.
     *
     * @return array<int,string[]>
     */
    private function category_paths( WC_Product $product ) {
        $terms = get_the_terms( $product->get_id(), 'product_cat' );
        if ( ! is_array( $terms ) || empty( $terms ) ) {
            return array( array( 'products' ) );
        }

        // Build each assigned term's full chain, root first. get_ancestors() returns them
        // nearest-first, so reverse it.
        $paths     = array();
        $by_key    = array();
        $term_ids  = array();
        foreach ( $terms as $t ) {
            $chain = array();
            foreach ( array_reverse( get_ancestors( $t->term_id, 'product_cat' ) ) as $aid ) {
                $anc = get_term( (int) $aid, 'product_cat' );
                if ( $anc && ! is_wp_error( $anc ) ) $chain[] = sanitize_text_field( $anc->name );
            }
            $chain[] = sanitize_text_field( $t->name );

            $key = implode( "\x1f", $chain );
            if ( isset( $by_key[ $key ] ) ) continue;
            $by_key[ $key ] = true;
            $paths[]        = $chain;
            $term_ids[]     = (int) $t->term_id;
        }

        // Drop any path that another ticked term descends from — a ticked ancestor is redundant.
        $maximal = array();
        foreach ( $paths as $i => $chain ) {
            $covered = false;
            foreach ( $term_ids as $j => $other_id ) {
                if ( $i === $j ) continue;
                if ( in_array( $term_ids[ $i ], get_ancestors( $other_id, 'product_cat' ), true ) ) {
                    $covered = true;
                    break;
                }
            }
            if ( ! $covered ) $maximal[] = $chain;
        }

        return empty( $maximal ) ? $paths : $maximal;
    }

    /**
     * The distinct node names across the product's paths — the filterable `category` attribute.
     * Deduped, so a shared ancestor is listed once.
     *
     * @param array<int,string[]> $paths
     * @return string[]
     */
    private function category_names( array $paths ) {
        $names = array();
        foreach ( $paths as $chain ) {
            foreach ( $chain as $name ) $names[ $name ] = true;
        }
        return array_keys( $names );
    }

    /** Flat descriptive fields: sku (identifier) + the product's fixed, non-variation attributes. */
    private function attributes( WC_Product $product ) {
        $attrs = array();

        $sku = $product->get_sku();
        if ( $sku !== '' ) $attrs['sku'] = $sku;
        // NOTE: the WooCommerce product `type` (simple/variable/…) is deliberately NOT synced — it's a
        // storefront implementation detail (how the store models the product), not a product attribute a
        // shopper or the AI ever filters on. Whether a product varies is expressed by its `variants`.
        // Exact stock_quantity is deliberately NOT synced as an attribute: it's volatile
        // (changes on every purchase) and a normal attribute rides the backend's embed lane,
        // so syncing it would force a re-embed on every stock change. Availability instead
        // comes from the typed `in_stock` boolean, which the backend treats as a commerce
        // mirror EXCLUDED from embedding — so stock churn is a cheap column write, never a re-embed.

        $is_variable = $product->is_type( 'variable' );
        foreach ( $product->get_attributes() as $name => $attribute ) {
            if ( is_a( $attribute, 'WC_Product_Attribute' ) ) {
                // Variation axes (Color/Size on a variable product) are carried PER-SKU in `variants`,
                // never as doc attributes — that keeps volatile per-SKU facets out of the embed lane.
                if ( $attribute->get_variation() ) continue;
                // On a SIMPLE product its global (taxonomy `pa_*`) attributes are the same axes that
                // are variations on variable products — carry them per-SKU too (simple_variant()), so
                // `color`/`size` land under one `variant_<facet>` key for simple + variable alike.
                if ( ! $is_variable && $attribute->is_taxonomy() ) continue;
                $label   = wc_attribute_label( $attribute->get_name(), $product );
                $options = $attribute->is_taxonomy()
                    ? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) )
                    : $attribute->get_options();
            } else {
                $label   = $name;
                $options = is_array( $attribute ) ? $attribute : array( $attribute );
            }
            $key     = $this->attr_key( $label );
            $options = array_values( array_filter( array_map( 'strval', (array) $options ), 'strlen' ) );
            if ( $key !== '' && ! isset( $attrs[ $key ] ) && ! empty( $options ) ) {
                $attrs[ $key ] = $options;
            }
        }
        return $attrs;
    }

    /**
     * Per-SKU variant rows for a variable product. Each variation → its own price/original_price/
     * stock/sku/image + the variation-axis facets (color/size…). The backend rolls these into the
     * parent "from" price + any-in-stock and projects the facet union for per-SKU filtering.
     */
    private function variants( WC_Product $product ) {
        $variants = array();
        foreach ( $product->get_children() as $vid ) {
            $variation = wc_get_product( $vid );
            if ( ! $variation || ! $variation->exists() ) continue;

            $variant = array();

            $sku = $variation->get_sku();
            if ( $sku !== '' ) $variant['sku'] = $sku;

            $price = $this->to_decimal( $variation->get_price() );
            if ( $price !== null ) {
                $variant['price'] = $price;
                // Per-variation regular price is the pre-discount original (fixes the variable-product
                // sale bug: the PARENT get_regular_price() is empty, so only the variation carries it).
                $regular = $this->to_decimal( $variation->get_regular_price() );
                if ( $variation->is_on_sale() && $regular !== null && $regular > $price ) {
                    $variant['original_price'] = $regular;
                }
            }

            $variant['in_stock'] = (bool) $variation->is_in_stock();

            foreach ( $this->variation_facets( $variation ) as $key => $value ) {
                $variant[ $key ] = $value;
            }

            $img_id = $variation->get_image_id();
            if ( $img_id ) {
                $url = wp_get_attachment_image_url( $img_id, 'full' );
                if ( $url ) $variant['image'] = $url;
            }

            $variants[] = $variant;
        }
        return $variants;
    }

    /**
     * The variation's chosen axis values as flat facets, e.g. { color: ["Blue"], size: ["M"] }.
     * A blank value = "Any" (variation unconstrained on that axis) → skipped. Taxonomy slugs are
     * resolved to their human term name so filtering matches what shoppers type.
     */
    private function variation_facets( $variation ) {
        $facets = array();
        foreach ( $variation->get_variation_attributes() as $raw_name => $value ) {
            if ( $value === '' ) continue; // "Any" — no specific value on this axis
            $name = str_replace( 'attribute_', '', $raw_name );
            if ( taxonomy_exists( $name ) ) {
                $term = get_term_by( 'slug', $value, $name );
                if ( $term && ! is_wp_error( $term ) ) $value = $term->name;
            }
            $key = $this->attr_key( wc_attribute_label( $name ) );
            if ( $key !== '' ) $facets[ $key ] = array( strval( $value ) );
        }
        return $facets;
    }

    /**
     * A single variant-of-one for a SIMPLE product: its commerce (price / original / stock) + image,
     * plus its global (taxonomy) attributes as per-SKU facets. Mirrors how a variable product's
     * variations are built, so colour / size land under the SAME `variant_<facet>` key regardless of
     * product type. The backend rolls this up into the parent "from" price / any-in-stock.
     */
    private function simple_variant( WC_Product $product ) {
        $variant = array();

        $sku = $product->get_sku();
        if ( $sku !== '' ) $variant['sku'] = $sku;

        $price = $this->to_decimal( $product->get_price() );
        if ( $price !== null ) {
            $variant['price'] = $price;
            // On sale → the regular price is the pre-discount original (signals a discount).
            $regular = $this->to_decimal( $product->get_regular_price() );
            if ( $product->is_on_sale() && $regular !== null && $regular > $price ) {
                $variant['original_price'] = $regular;
            }
        }

        $variant['in_stock'] = (bool) $product->is_in_stock();

        foreach ( $this->taxonomy_facets( $product ) as $key => $value ) {
            $variant[ $key ] = $value;
        }

        $img_id = $product->get_image_id();
        if ( $img_id ) {
            $url = wp_get_attachment_image_url( $img_id, 'full' );
            if ( $url ) $variant['image'] = $url;
        }

        return $variant;
    }

    /**
     * A simple product's global (taxonomy `pa_*`) attributes as flat facets, e.g. { color: ["Gray"] }.
     * Uses the SAME key derivation as variation_facets() so a simple product's colour and a variable
     * product's colour share one facet key. Non-taxonomy (custom) attributes are NOT folded — they
     * stay doc-level via attributes() (they're descriptive, not variation axes).
     */
    private function taxonomy_facets( WC_Product $product ) {
        $facets = array();
        foreach ( $product->get_attributes() as $name => $attribute ) {
            if ( ! is_a( $attribute, 'WC_Product_Attribute' ) || ! $attribute->is_taxonomy() ) continue;
            $key     = $this->attr_key( wc_attribute_label( $attribute->get_name(), $product ) );
            $options = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) );
            $options = array_values( array_filter( array_map( 'strval', (array) $options ), 'strlen' ) );
            if ( $key !== '' && ! empty( $options ) ) $facets[ $key ] = $options;
        }
        return $facets;
    }

    /** Featured image (primary) + gallery images, capped. Absolute URLs only. */
    private function images( WC_Product $product ) {
        $images = array();
        $seen   = array();

        $primary_id = $product->get_image_id();
        if ( $primary_id ) {
            $url = wp_get_attachment_image_url( $primary_id, 'full' );
            if ( $url ) {
                $images[]     = array( 'url' => $url, 'description' => $product->get_name(), 'is_primary' => true );
                $seen[ $url ] = true;
            }
        }
        foreach ( (array) $product->get_gallery_image_ids() as $gid ) {
            if ( count( $images ) >= self::MAX_IMAGES ) break;
            $url = wp_get_attachment_image_url( $gid, 'full' );
            if ( $url && ! isset( $seen[ $url ] ) ) {
                $seen[ $url ] = true;
                $images[]     = array( 'url' => $url, 'description' => '', 'is_primary' => false );
            }
        }
        return $images;
    }

    /**
     * Slugify an attribute label into a stable key. Unicode-aware (\p{L}\p{N} under the /u
     * modifier) so labels in any script survive — a plain a-z0-9 ASCII class silently discarded
     * every byte of a non-Latin label (e.g. Georgian "სისქე"), collapsing it to an empty key that
     * attributes()/variation_facets()/taxonomy_facets() then drop outright. Only ASCII-labeled
     * attributes (or ones with a stray Latin unit letter, like "V" in a voltage spec) survived.
     * Core's own KnowledgeAttributeKeyNormalizer re-normalizes whatever key arrives (case-folding,
     * separator collapsing, cross-script collision handling) — this only has to stop destroying
     * the data before Core ever sees it, not fully canonicalize it.
     */
    private function attr_key( $label ) {
        $key = mb_strtolower( trim( (string) $label ), 'UTF-8' );
        $key = preg_replace( '/[^\p{L}\p{N}]+/u', '_', $key );
        return trim( (string) $key, '_' );
    }

    private function to_decimal( $value ) {
        if ( $value === '' || $value === null ) return null;
        return round( (float) $value, 2 );
    }

    /**
     * Push the given product ids as typed docs. The batch entry point for Personaizer_Backfill —
     * it owns the paging, this owns how a WC_Product becomes a catalog item. Chunked to MAX_BATCH
     * because the API takes 1–100 items per request.
     *
     * @param int[] $ids
     * @return int the number actually pushed.
     */
    public function sync_ids( array $ids ) {
        if ( ! $this->ready() ) return 0;

        $batch     = array();
        $batch_ids = array();   // post ids, parallel to $batch, so a quota rejection can remember them
        $count     = 0;
        foreach ( $ids as $id ) {
            $product = wc_get_product( $id );
            if ( ! $product ) continue;
            // Only PUBLISHED products belong in the AI. The backfill pre-filters to published, but
            // flush_overflow() passes specific ids straight through — so a stale overflow entry for a
            // TRASHED product would otherwise be mapped and re-pushed here, re-creating a doc with no live
            // product (the AI ends up knowing 15 while the store has 14). Mirror on_product_saved: drop it
            // from the AI + the queue instead of pushing it (this also cleans an orphan the next time flush
            // reaches its id).
            if ( $product->get_status() !== 'publish' ) {
                $ext = $this->external_id( $id );
                personaizer_forget_overflow( 'products', [ $ext ] );
                personaizer_forget_retry( 'products', [ $ext ] );
                $this->api->delete_docs( [ $ext ] );
                continue;
            }
            $batch[]     = $this->map_product( $product );
            $batch_ids[] = (int) $id;
            if ( count( $batch ) >= self::MAX_BATCH ) {
                $count += $this->push( $batch, $batch_ids );
                $batch     = array();
                $batch_ids = array();
            }
        }
        if ( ! empty( $batch ) ) {
            $count += $this->push( $batch, $batch_ids );
        }
        return $count;
    }

    /**
     * @param array $batch    Mapped items (each carrying its external id under 'id').
     * @param int[] $post_ids Product post ids parallel to $batch — needed to remember a quota rejection.
     * @return int items pushed (0 on failure — a failed batch must not count as synced).
     */
    private function push( array $batch, array $post_ids = array() ) {
        $res = $this->api->upsert_products( $batch );
        if ( is_wp_error( $res ) ) {
            // Whole-batch failure. A quota error here means NOTHING fit (402) → remember the whole batch
            // for the after-upgrade replay; any other error is a real failure to log.
            if ( Personaizer_Api::is_quota_error( $res ) ) {
                foreach ( $post_ids as $pid ) {
                    personaizer_remember_overflow( 'products', $this->external_id( $pid ), $pid );
                }
                personaizer_debug_log( 'product batch failed (quota): ' . $res->get_error_message() );
                return 0;
            }
            // NOT quota — so this is a transport/validation failure, and the write is atomic server-side:
            // one bad or slow item just took its whole batch down with it. Retry the items ONE BY ONE so
            // the blast radius collapses from "up to 20 products silently lost" to "the single item that is
            // actually broken". This is the same drop-the-bad-one-and-keep-going contract MVP's
            // upload_docs.py has always had for bulk uploads; the plugin was the surface still missing it.
            personaizer_debug_log( 'product batch failed: ' . $res->get_error_message() . ' — retrying item-by-item' );
            return $this->push_individually( $batch, $post_ids );
        }
        // Success, possibly partial: the server writes what fit and hands back the ids it deferred for
        // quota. Remember exactly those; forget the rest (they landed). This is what makes the cutoff
        // exact instead of losing a whole batch to one over-limit item.
        $deferred = ( is_array( $res ) && ! empty( $res['deferred'] ) ) ? array_flip( $res['deferred'] ) : array();
        $landed   = array();
        foreach ( $post_ids as $i => $pid ) {
            $ext = $this->external_id( $pid );
            if ( isset( $deferred[ $ext ] ) ) {
                personaizer_remember_overflow( 'products', $ext, $pid );
            } else {
                $landed[] = $ext;
                // $batch is built parallel to $post_ids by sync_ids(), so index i is this product's item.
                if ( isset( $batch[ $i ] ) ) {
                    personaizer_record_sync_hash( $pid, personaizer_payload_hash( $batch[ $i ] ) );
                }
            }
        }
        if ( $landed ) {
            personaizer_forget_overflow( 'products', $landed );
            personaizer_forget_retry( 'products', $landed );   // it wrote — so it is no longer owed a retry
        }
        return count( $landed );   // deferred items are not counted as synced (the backfill tallies them as failed)
    }

    /**
     * Re-push a failed batch one item at a time, so a single bad item costs only itself.
     *
     * Reached only after an atomic batch write already failed for a non-quota reason. Every item gets its
     * own request: the ones that were merely along for the ride land normally, and the one that is
     * genuinely broken (or the one whose size made the batch time out) is isolated. Whatever still fails is
     * REMEMBERED for the retry tick rather than dropped — losing an item silently is the failure mode this
     * whole path exists to end.
     *
     * @param array $batch    Mapped items, parallel to $post_ids.
     * @param int[] $post_ids Product post ids.
     * @return int items that actually landed.
     */
    private function push_individually( array $batch, array $post_ids ) {
        $landed = 0;
        foreach ( array_values( $batch ) as $i => $item ) {
            $pid = isset( $post_ids[ $i ] ) ? (int) $post_ids[ $i ] : 0;
            $ext = $pid ? $this->external_id( $pid ) : ( isset( $item['id'] ) ? $item['id'] : '' );

            $res = $this->api->upsert_products( array( $item ) );

            if ( is_wp_error( $res ) ) {
                // Quota is not brokenness — it belongs in the overflow queue, which replays after an
                // upgrade. Everything else goes to the retry queue, which replays unconditionally.
                if ( Personaizer_Api::is_quota_error( $res ) ) {
                    if ( $ext !== '' ) personaizer_remember_overflow( 'products', $ext, $pid );
                } else {
                    if ( $ext !== '' ) personaizer_remember_retry( 'products', $ext, $pid );
                    personaizer_debug_log( 'product ' . $pid . ' failed individually: ' . $res->get_error_message() );
                }
                continue;
            }

            $deferred = ( is_array( $res ) && ! empty( $res['deferred'] ) ) ? array_map( 'strval', (array) $res['deferred'] ) : array();
            if ( $ext !== '' && in_array( $ext, $deferred, true ) ) {
                personaizer_remember_overflow( 'products', $ext, $pid );
                continue;
            }
            if ( $ext !== '' ) {
                personaizer_forget_overflow( 'products', array( $ext ) );
                personaizer_forget_retry( 'products', array( $ext ) );
            }
            personaizer_record_sync_hash( $pid, personaizer_payload_hash( $item ) );
            $landed++;
        }
        return $landed;
    }
}
