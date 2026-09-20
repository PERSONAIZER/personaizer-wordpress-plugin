<?php
namespace Personaizer\Content;

use Personaizer\Site\Streams;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * A page, post or custom-type post as the record a FILES stream carries: its text as markdown, its permalink and
 * its images. No request, no side effects — the full-list check fingerprints THIS, so the comparison is against
 * what the AI actually received: rendered content (shortcodes and blocks expanded) and resolved image URLs. A
 * theme change that alters how content renders therefore shows up as "out of date", which reading post_modified
 * alone would never reveal.
 */
final class PostPayload {

    /** The API holds at most this many per document (Knowledge:Acceptor:MaxLibraryEntries) and refuses a longer list. */
    const MAX_IMAGES = 15;

    /** `wp-<post_type>-<ID>` — the site's stable record id. */
    public static function external_id( WP_Post $post ) {
        return 'wp-' . $post->post_type . '-' . $post->ID;
    }

    /** The post id inside one of our external ids, or null. */
    public static function post_id_of( $external_id ) {
        return preg_match( '/^wp-[a-z0-9_-]+-(\d+)$/', (string) $external_id, $m ) ? (int) $m[1] : null;
    }

    /**
     * @return array{id:string,fingerprint:string,title:string,content:string,links:array,images:array}|null
     *         Null for a post type that has no stream.
     */
    public static function build( WP_Post $post ) {
        if ( Streams::for_post_type( $post->post_type ) === null ) return null;
        $title  = self::title( $post );
        $record = array(
            'id'      => self::external_id( $post ),
            'title'   => $title,
            'content' => '# ' . $title . "\n\n" . Markdown::from_html( (string) apply_filters( 'the_content', $post->post_content ) ),
            'links'   => array( array( 'url' => get_permalink( $post ), 'is_primary' => true ) ),
            'images'  => self::images( $post ),
        );
        $record['fingerprint'] = Fingerprint::of( $record );
        return $record;
    }

    private static function title( WP_Post $post ) {
        $title = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return $title !== '' ? $title : ( ucfirst( $post->post_type ) . ' ' . $post->ID );
    }

    /**
     * The featured image (primary) plus inline <img> in the rendered content — absolute http(s) URLs only, cut at
     * the API's per-document cap (a longer list is refused, not trimmed). The first image is the primary: the
     * featured image when there is one, else the first inline image.
     *
     * Descriptions: PERSONAIZER runs vision on the PRIMARY image only when it arrives without a description, and folds
     * the caption into what the record is searched on — so the primary always goes empty (a WordPress alt is rarely a
     * caption, and often just the filename). The others are never analysed, so their alt text is the only words they
     * will ever have and rides along — unless it is the filename WordPress pasted in on upload.
     *
     * @return array<int,array{url:string,description:string,is_primary:bool}>
     */
    private static function images( WP_Post $post ) {
        $images = array();
        $seen   = array();

        $thumb_id = get_post_thumbnail_id( $post->ID );
        if ( $thumb_id ) {
            $url = wp_get_attachment_image_url( $thumb_id, 'full' );
            if ( $url ) {
                $images[]     = array( 'url' => $url, 'description' => '', 'is_primary' => true );
                $seen[ $url ] = true;
            }
        }

        $html = (string) apply_filters( 'the_content', $post->post_content );
        if ( preg_match_all( '/<img\b[^>]*?\bsrc\s*=\s*([\'"])(.*?)\1[^>]*>/i', $html, $tags, PREG_SET_ORDER ) ) {
            foreach ( $tags as $tag ) {
                if ( count( $images ) >= self::MAX_IMAGES ) break;
                $url = html_entity_decode( $tag[2], ENT_QUOTES );
                if ( ! preg_match( '#^https?://#i', $url ) || isset( $seen[ $url ] ) ) continue;
                $seen[ $url ] = true;
                $primary = empty( $images );
                $alt     = '';
                if ( ! $primary && preg_match( '/\balt\s*=\s*([\'"])(.*?)\1/i', $tag[0], $a ) ) {
                    $alt = self::alt_or_nothing( html_entity_decode( $a[2], ENT_QUOTES ), $url );
                }
                $images[] = array( 'url' => $url, 'description' => $alt, 'is_primary' => $primary );
            }
        }
        return $images;
    }

    /** The alt text, unless it is just the image's filename (WordPress pastes that in on upload). Pure: tested on its own. */
    public static function alt_or_nothing( $alt, $url ) {
        $alt = trim( (string) $alt );
        if ( $alt === '' ) return '';
        $file = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_FILENAME ) );
        $norm = static function ( $s ) { return preg_replace( '/[^\p{L}\p{N}]+/u', ' ', mb_strtolower( $s, 'UTF-8' ) ); };
        // "shape_3.png" or "shape_3" vs file shape_3.png, "LED" vs file LED-1024x513.png: the same words, so no words.
        $words = trim( $norm( preg_replace( '/\.[a-z0-9]{2,5}$/i', '', $alt ) ) );
        $stem  = preg_replace( '/-\d+x\d+$/', '', $file );
        if ( $words === '' || $words === trim( $norm( $file ) ) || $words === trim( $norm( $stem ) ) ) return '';
        return $alt;
    }
}
