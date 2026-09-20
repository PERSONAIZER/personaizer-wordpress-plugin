<?php
namespace Personaizer\Site;

use Personaizer\Content\Markdown;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * This site, described by itself — in the exact shape PERSONAIZER's website extractor produces, so the backend
 * cannot tell (and does not care) whether a brand's facts came from a scrape or from here.
 *
 * A scraper infers a brand's name, languages, currency, logo and colours from rendered HTML. WordPress KNOWS
 * them, so they are handed over as facts: no scraping, and it works for sites a cloud scraper can never reach
 * (intranet, staging, *.local). The homepage text is what the persona's voice and purpose are written from — and
 * being inside the site we can hand over more than a homepage: the About page and a summary of the catalog.
 *
 * Everything here is already public on the site's own pages. Nothing private is exposed.
 */
final class Profile {

    /** Plenty for an identity; the backend caps this material at ~6k chars anyway. */
    const HOMEPAGE_CHAR_CAP = 6000;

    /** The front page's share, so a chatty front page can't crowd out the About page and the catalog summary. */
    const FRONT_PAGE_CHAR_CAP = 3000;

    /**
     * @return array{brand_name:string,audience_languages:string[],logo_url:?string,accent_colors:string[],
     *               currency:?string,description:?string,homepage_markdown:string}
     */
    public static function build() {
        return array(
            'brand_name'         => (string) get_bloginfo( 'name' ),
            'audience_languages' => Languages::audience(),
            'logo_url'           => self::logo_url(),
            'accent_colors'      => self::accent_colors(),
            'currency'           => self::currency(),
            'description'        => self::nullable( (string) get_bloginfo( 'description' ) ),   // the tagline
            'homepage_markdown'  => self::identity_material(),
        );
    }

    private static function identity_material() {
        $parts = array_filter( array( self::homepage_text(), self::about_text(), self::catalog_summary() ) );
        return self::cap( trim( implode( "\n\n", $parts ) ), self::HOMEPAGE_CHAR_CAP );
    }

    private static function homepage_text() {
        $front_id = (int) get_option( 'page_on_front' );
        $text     = '';
        if ( get_option( 'show_on_front' ) === 'page' && $front_id ) {
            $post = get_post( $front_id );
            if ( $post ) $text = self::render( $post );
        }
        if ( trim( $text ) === '' ) {
            $parts = array();
            foreach ( get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 5, 'no_found_rows' => true ) ) as $post ) {
                $parts[] = self::render( $post );
            }
            $text = implode( "\n\n", $parts );
        }
        return self::cap( trim( $text ), self::FRONT_PAGE_CHAR_CAP );
    }

    private static function about_text() {
        // Matches "About", "About us", "Über uns"… whatever the owner titled it, by search.
        $pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 1, 'no_found_rows' => true, 's' => 'about' ) );
        return $pages ? self::render( $pages[0] ) : '';
    }

    private static function catalog_summary() {
        if ( ! Streams::has_woocommerce() ) return '';
        $terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 12, 'orderby' => 'count', 'order' => 'DESC' ) );
        if ( is_wp_error( $terms ) || empty( $terms ) ) return '';

        $lines = array();
        foreach ( $terms as $term ) $lines[] = '- ' . $term->name . ' (' . (int) $term->count . ')';

        $examples = array();
        foreach ( get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 8, 'fields' => 'ids', 'no_found_rows' => true ) ) as $id ) {
            $title = get_the_title( $id );
            if ( $title !== '' ) $examples[] = $title;
        }

        $out = "# What this store sells\n\n" . implode( "\n", $lines );
        if ( $examples ) $out .= "\n\nExample products: " . implode( ', ', $examples ) . '.';
        return $out;
    }

    private static function currency() {
        return function_exists( 'get_woocommerce_currency' ) ? self::nullable( (string) get_woocommerce_currency() ) : null;
    }

    private static function logo_url() {
        $id = (int) get_theme_mod( 'custom_logo' );
        if ( $id ) {
            $url = wp_get_attachment_image_url( $id, 'full' );
            if ( $url ) return $url;
        }
        $icon = get_site_icon_url( 512 );
        return $icon ? $icon : null;
    }

    /** The theme's palette, the owner's choices first, hex only, at most six. WordPress's stock palette says nothing about the brand and is never used. */
    private static function accent_colors() {
        if ( ! function_exists( 'wp_get_global_settings' ) ) return array();
        $palette = wp_get_global_settings( array( 'color', 'palette' ) );
        $entries = array();
        foreach ( array( 'custom', 'theme' ) as $origin ) {
            if ( ! empty( $palette[ $origin ] ) && is_array( $palette[ $origin ] ) ) $entries = array_merge( $entries, $palette[ $origin ] );
        }
        if ( empty( $entries ) && isset( $palette[0] ) ) $entries = $palette;   // some themes return a flat list

        $colors = array();
        foreach ( $entries as $entry ) {
            $color = is_array( $entry ) && isset( $entry['color'] ) ? $entry['color'] : null;
            if ( is_string( $color ) && preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $color ) ) $colors[] = strtolower( $color );
            if ( count( $colors ) >= 6 ) break;
        }
        return array_values( array_unique( $colors ) );
    }

    private static function render( WP_Post $post ) {
        $body  = Markdown::from_html( (string) apply_filters( 'the_content', $post->post_content ) );
        $title = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return $title !== '' ? "# {$title}\n\n{$body}" : $body;
    }

    private static function cap( $text, $max ) {
        return mb_strlen( $text, 'UTF-8' ) > $max ? mb_substr( $text, 0, $max, 'UTF-8' ) : $text;
    }

    private static function nullable( $value ) {
        $value = trim( $value );
        return $value === '' ? null : $value;
    }
}
