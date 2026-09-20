<?php
namespace Personaizer\Site;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * What this site could sync: its STREAMS. Pages, posts, every public custom post type, and products when
 * WooCommerce is active. Each is keyed by its post type (products → `products`), labelled as WordPress labels it,
 * and declares what a source of it holds on PERSONAIZER: `files` (one text record per post) or `catalog` (typed
 * product entries). Which streams are ON is never decided here — the owner switches them on personaizer.com and
 * the plugin reads that back (Sync\State).
 */
final class Streams {

    const FILES   = 'files';
    const CATALOG = 'catalog';

    /** @return array<string,array{label:string,post_type:string,type:string}> keyed by stream key. */
    public static function all() {
        $streams = array(
            'pages' => array( 'label' => 'Pages', 'post_type' => 'page', 'type' => self::FILES ),
            'posts' => array( 'label' => 'Posts', 'post_type' => 'post', 'type' => self::FILES ),
        );

        // Two custom types can share a label ("Templates" is the common one); qualify with the slug only on collision.
        $extra  = self::extra_post_types();
        $labels = array( 'Pages', 'Posts' );
        if ( self::has_woocommerce() ) $labels[] = 'Products';
        foreach ( $extra as $type ) $labels[] = $type->labels->name;
        $seen = array_count_values( $labels );

        foreach ( $extra as $type ) {
            $label = $type->labels->name;
            $streams[ $type->name ] = array(
                'label'     => $seen[ $label ] > 1 ? $label . ' (' . $type->name . ')' : $label,
                'post_type' => $type->name,
                'type'      => self::FILES,
            );
        }
        if ( self::has_woocommerce() ) {
            $streams['products'] = array( 'label' => 'Products', 'post_type' => 'product', 'type' => self::CATALOG );
        }
        return $streams;
    }

    /** The stream a post type belongs to, or null when it has none. */
    public static function for_post_type( $post_type ) {
        foreach ( self::all() as $key => $stream ) {
            if ( $stream['post_type'] === $post_type ) return $key;
        }
        return null;
    }

    public static function has_woocommerce() {
        return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
    }

    /** Published records in a stream, as WordPress counts them. */
    public static function published_count( $stream ) {
        $all = self::all();
        if ( ! isset( $all[ $stream ] ) ) return 0;
        $counts = wp_count_posts( $all[ $stream ]['post_type'] );
        return isset( $counts->publish ) ? (int) $counts->publish : 0;
    }

    /**
     * The inventory the site reports: every stream with a label, its published count and its type. Sent on connect
     * and with every sync, so the owner switches streams on from a list that reflects the site as it is now.
     *
     * @return array<int,array{stream_key:string,label:string,count:int,type:string}>
     */
    public static function inventory() {
        $out = array();
        foreach ( self::all() as $key => $stream ) {
            $out[] = array(
                'stream_key' => $key,
                'label'      => $stream['label'],
                'count'      => self::published_count( $key ),
                'type'       => $stream['type'],
            );
        }
        return $out;
    }

    /**
     * Public custom post types worth syncing: page builders register public types for their own machinery
     * (templates, popups), and those are marked not searchable / not for menus — the two flags that separate
     * content from plumbing. Filterable by `personaizer_syncable_post_types`.
     *
     * @return \WP_Post_Type[]
     */
    public static function extra_post_types() {
        $extra = array();
        foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
            if ( in_array( $type->name, array( 'attachment', 'product', 'page', 'post' ), true ) ) continue;
            if ( ! empty( $type->exclude_from_search ) ) continue;
            if ( empty( $type->show_in_nav_menus ) ) continue;
            $extra[] = $type;
        }
        return apply_filters( 'personaizer_syncable_post_types', $extra );
    }
}
