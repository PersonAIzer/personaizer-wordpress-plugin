<?php
/**
 * This site, described by itself.
 *
 * PERSONAIZER normally learns a brand by scraping its homepage and asking an LLM to infer the facts
 * out of rendered HTML — the name, the language, the currency. WordPress does not need to be guessed
 * at: it knows all of them, exactly, and hands them over for free. So the plugin answers the same
 * seven questions the scraper exists to answer, and the persona is built from facts instead of
 * inferences — no scraping credits, no API key, and it works for sites a cloud scraper can never
 * reach (intranet, staging, *.local).
 *
 * The shape below is deliberately the SCRAPER'S result shape, not one of our own invention. That is
 * what makes the source swappable: the backend pipeline can't tell — and doesn't care — which of the
 * two produced it.
 *
 * Everything here is already public on the site's own pages. Nothing private is exposed.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Personaizer_Site_Profile {

    /** Plenty for an identity; the backend caps this material at ~6k chars anyway. */
    const HOMEPAGE_CHAR_CAP = 6000;

    /**
     * Per-part budget for the front page.
     *
     * The whole blob is capped at HOMEPAGE_CHAR_CAP, so a chatty front page would otherwise consume
     * all of it and crowd out the About page and the catalog summary — the two parts that most often
     * say what the business actually IS. Bounding it here keeps every part represented.
     */
    const FRONT_PAGE_CHAR_CAP = 3000;

    /**
     * @return array{brand_name:string,description:string,detected_language:string,currency:string,
     *               logo_url:string,accent_colors:array,homepage_markdown:string}
     */
    public static function build() {
        return array(
            'brand_name'        => (string) get_bloginfo( 'name' ),
            'description'       => (string) get_bloginfo( 'description' ),   // the tagline
            'detected_language' => (string) get_bloginfo( 'language' ),      // e.g. en-US
            'currency'          => self::currency(),
            'logo_url'          => self::logo_url(),
            'accent_colors'     => self::accent_colors(),
            'homepage_markdown' => self::identity_material(),
        );
    }

    /**
     * The material the persona's tone, description and purpose are written from.
     *
     * A scraper only ever saw the homepage, which on a WooCommerce site is often a hero image and
     * three blog posts — nothing that says "this shop sells hoodies, music and decor". We're inside
     * the site, so we can hand over what actually defines it: the front page, the About page if there
     * is one, and a summary of the catalog. That's material no homepage scrape could produce, and it
     * is the difference between a persona that knows what it's selling and one that doesn't.
     *
     * Still a single blob under the scraper's `homepage_markdown` field — keeping ONE contract for
     * both producers is what lets the backend stay unaware of which one it's talking to.
     */
    private static function identity_material() {
        $parts = array_filter( array(
            self::homepage_text(),
            self::about_text(),
            self::catalog_summary(),
        ) );
        $text = trim( implode( "\n\n", $parts ) );
        return strlen( $text ) > self::HOMEPAGE_CHAR_CAP
            ? substr( $text, 0, self::HOMEPAGE_CHAR_CAP )
            : $text;
    }

    /**
     * The About page, when the site has something recognisable as one. Usually the clearest statement
     * of who the business is — exactly what an identity wants and a homepage often buries.
     */
    private static function about_text() {
        $pages = get_posts( array(
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            // Matches "About", "About us", "Über uns"… whatever the owner titled it, by slug.
            's'              => 'about',
        ) );
        return $pages ? self::render( $pages[0] ) : '';
    }

    /**
     * What the shop sells, by category, with a few example products.
     *
     * Deliberately a summary and not a catalog dump: the persona needs to know its trade, not recite
     * inventory — the products themselves arrive as typed docs with live price and stock, which is a
     * far better source for "how much is the blue hoodie" than anything baked into a system prompt.
     */
    private static function catalog_summary() {
        if ( ! class_exists( 'WooCommerce' ) ) return '';

        $terms = get_terms( array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'number'     => 12,
            'orderby'    => 'count',
            'order'      => 'DESC',
        ) );
        if ( is_wp_error( $terms ) || empty( $terms ) ) return '';

        $lines = array();
        foreach ( $terms as $term ) {
            $lines[] = '- ' . $term->name . ' (' . (int) $term->count . ')';
        }

        $names = get_posts( array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 8,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ) );
        $examples = array();
        foreach ( $names as $id ) {
            $title = get_the_title( $id );
            if ( $title !== '' ) $examples[] = $title;
        }

        $out = "# What this store sells\n\n" . implode( "\n", $lines );
        if ( $examples ) $out .= "\n\nExample products: " . implode( ', ', $examples ) . '.';
        return $out;
    }

    /** WooCommerce is the authority on what this store charges in; a non-shop has no currency. */
    private static function currency() {
        return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
    }

    /**
     * The brand's logo. Load-bearing well beyond decoration: onboarding feeds it to avatar
     * generation, falls back to it as the avatar itself, and prints it on the 3D model's shirt — so
     * returning nothing costs the persona its whole visual identity.
     *
     * Two sources, best first, because owners set one or the other and rarely both:
     *   1. Site Identity → Logo (`custom_logo`) — the real logo, full resolution.
     *   2. Site Identity → Site Icon (`get_site_icon_url`) — the favicon/app icon. Square and small,
     *      but WordPress generates it from an upload of at least 512px, so it's a genuine brand mark;
     *      it's also precisely the apple-touch-icon a scraper would dig out of <head>, except we read
     *      it from the database instead of inferring it.
     * Empty only when the owner set neither — in which case there is no logo on the site to scrape
     * either, so nothing is lost against the old path.
     */
    private static function logo_url() {
        $id = (int) get_theme_mod( 'custom_logo' );
        if ( $id ) {
            $url = wp_get_attachment_image_url( $id, 'full' );
            if ( $url ) return $url;
        }
        $icon = get_site_icon_url( 512 );
        return $icon ? $icon : '';
    }

    /**
     * The theme's own colour palette, most prominent first.
     *
     * Block themes declare this in theme.json and WordPress exposes it via global settings — the
     * closest thing to a machine-readable brand palette that exists here, and better than the
     * scraper's alternative of eyeballing a theme-color meta tag. Classic themes usually declare
     * nothing, in which case we send none and the owner picks a colour in the Widget tab (which has a
     * live preview) — a better outcome than a confidently wrong guess.
     *
     * @return string[] hex colours
     */
    private static function accent_colors() {
        if ( ! function_exists( 'wp_get_global_settings' ) ) return array();

        $palette = wp_get_global_settings( array( 'color', 'palette' ) );
        // Origins, narrowest first: what the owner chose beats what the theme shipped, which beats
        // WordPress's stock palette (which says nothing about this brand — so it is never used).
        $entries = array();
        foreach ( array( 'custom', 'theme' ) as $origin ) {
            if ( ! empty( $palette[ $origin ] ) && is_array( $palette[ $origin ] ) ) {
                $entries = array_merge( $entries, $palette[ $origin ] );
            }
        }
        if ( empty( $entries ) && isset( $palette[0] ) ) {
            $entries = $palette;   // some themes return a flat list
        }

        $colors = array();
        foreach ( $entries as $entry ) {
            $color = is_array( $entry ) && isset( $entry['color'] ) ? $entry['color'] : null;
            // Hex only. A palette may hold gradients / CSS vars / rgba() — the widget wants a colour
            // it can paint with, so anything else is dropped rather than passed on to fail later.
            if ( is_string( $color ) && preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $color ) ) {
                $colors[] = strtolower( $color );
            }
            if ( count( $colors ) >= 6 ) break;
        }
        return array_values( array_unique( $colors ) );
    }

    /**
     * The front page as plain text — the material the identity's tone and description come from.
     *
     * Mirrors the content sync's rendering (expand blocks/shortcodes, strip tags) so the persona is
     * built from what a visitor actually reads. Falls back to the newest posts when the site shows a
     * blog on its front page, since that IS its front page.
     */
    private static function homepage_text() {
        $front_id = (int) get_option( 'page_on_front' );
        $text     = '';

        if ( get_option( 'show_on_front' ) === 'page' && $front_id ) {
            $post = get_post( $front_id );
            if ( $post ) $text = self::render( $post );
        }

        if ( trim( $text ) === '' ) {
            $recent = get_posts( array(
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => 5,
                'no_found_rows'  => true,
            ) );
            $parts = array();
            foreach ( $recent as $post ) $parts[] = self::render( $post );
            $text = implode( "\n\n", $parts );
        }

        $text = trim( $text );
        return strlen( $text ) > self::FRONT_PAGE_CHAR_CAP
            ? substr( $text, 0, self::FRONT_PAGE_CHAR_CAP )
            : $text;
    }

    private static function render( WP_Post $post ) {
        $html = apply_filters( 'the_content', $post->post_content );
        // Decode entities after stripping tags: WordPress stores curly quotes and dashes as &#8217;
        // and friends, and stripping tags leaves them encoded. An LLM reading "It&#8217;s" is reading
        // noise, so hand it the characters the visitor actually sees.
        $text = html_entity_decode( (string) wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $body = trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );
        $title = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return $title !== '' ? "# {$title}\n\n{$body}" : $body;
    }
}
