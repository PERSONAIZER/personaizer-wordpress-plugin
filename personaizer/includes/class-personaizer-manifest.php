<?php
/**
 * The stream manifest — how a stream stays 1:1 with the site even when hooks are missed.
 *
 * The sync is event-driven: save a product, push that product. That is the right shape for keeping the
 * AI current, and it is also the shape that quietly drifts. Events go missing — WP-Cron never fires, the
 * API times out, the plugin was deactivated while a product was trashed. Nothing in a push-only design can
 * notice any of that, because "synced" only ever meant "we attempted a send".
 *
 * So, once a day (and after a backfill, and on demand), this walks each stream the owner switched on, builds
 * the payload every published item WOULD be pushed as, fingerprints it, and hands the whole list to
 * personaizer.com as the stream's manifest. The backend answers with the ids it is missing or holds a
 * different fingerprint for — those are pushed again through the ordinary retry queue — and removes what
 * this site no longer lists. It applies rails of its own before deleting anything: a manifest that would
 * orphan more than a quarter of a stream is held until the next manifest agrees with it, because "the site
 * has fewer items" is more often a broken enumeration than a real mass deletion.
 *
 * Two rules on this side keep that honest:
 *   - A stream's manifest is sent only when this site can enumerate that stream FULLY right now. A post type
 *     that is not registered at the moment (WooCommerce deactivated) is skipped entirely — an empty
 *     manifest would be a lie, not a fact.
 *   - The walk runs in batches on WP-Cron under a time budget, like the backfill. Fingerprinting means
 *     running the full mapper per item (terms, images, variants), and a 10 000-product catalog does not
 *     fit in one request.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Personaizer_Manifest {

    /** Cron hook. Each run does one slice of the walk and re-arms itself while work remains. */
    const HOOK   = 'personaizer_manifest';
    const STATE  = 'personaizer_manifest_state';
    const RESULT = 'personaizer_manifest_result';

    const POST_BATCH    = 50;
    const PRODUCT_BATCH = 50;
    const BUDGET_SECONDS = 20;
    const STALL_GRACE_SECONDS = 300;

    /** The backend's cap on one manifest. A stream past it is reported, not sent. */
    const MAX_ITEMS = 100000;

    public static function boot() {
        add_action( self::HOOK, [ __CLASS__, 'run' ] );
        add_action( Personaizer_Daily::HOOK, [ __CLASS__, 'start' ] );
    }

    /** Clear the schedule on deactivate so a disabled plugin never keeps walking. */
    public static function on_deactivate() {
        wp_clear_scheduled_hook( self::HOOK );
    }

    /**
     * Begin a manifest walk over every stream that is switched on. Safe to call repeatedly — a walk already
     * in flight is left alone (it will cover the same streams).
     */
    public static function start() {
        if ( ! personaizer_api()->is_configured() ) return;
        $state = get_option( self::STATE, array() );
        if ( is_array( $state ) && ! empty( $state['started_at'] ) && empty( $state['finished_at'] ) ) {
            self::rearm( 0 );   // in flight and maybe stalled — make sure a tick is armed
            return;
        }

        $streams = self::walkable_streams();
        if ( empty( $streams ) ) return;

        update_option( self::STATE, array(
            'streams'       => $streams,           // stream ids still to walk, in order
            'stream'        => null,             // the stream being walked
            'offset'      => 0,
            'total'       => 0,
            'items'       => array(),          // [ external_id => fingerprint ] of the stream being walked
            'started_at'  => time(),
            'finished_at' => 0,
        ), false );
        self::rearm( 0 );
    }

    /**
     * The streams to walk: switched on per the integration, AND enumerable on this site right now. A stream whose
     * post type is not registered (WooCommerce deactivated, a plugin removed) is left out rather than
     * reported empty.
     *
     * @return string[]
     */
    private static function walkable_streams() {
        $streams = personaizer_streams();
        $out   = array();
        foreach ( personaizer_current_streams() as $stream ) {
            if ( ! isset( $streams[ $stream ] ) ) continue;
            if ( $stream === 'products' ) {
                if ( ! function_exists( 'wc_get_products' ) ) continue;
            } elseif ( ! post_type_exists( $streams[ $stream ]['post_type'] ) ) {
                continue;
            }
            $out[] = $stream;
        }
        return $out;
    }

    /** Work through slices until the time budget runs out, then re-arm if anything is left. */
    public static function run() {
        $state = get_option( self::STATE, array() );
        if ( ! is_array( $state ) || empty( $state['started_at'] ) || ! empty( $state['finished_at'] ) ) return;
        if ( ! personaizer_api()->is_configured() ) return;

        // A watchdog before touching anything: if this request dies mid-walk the walk resumes in minutes
        // instead of never (same reasoning as Personaizer_Backfill::run).
        self::rearm( self::STALL_GRACE_SECONDS );

        $started = microtime( true );
        do {
            $more = self::step( $state );
            update_option( self::STATE, $state, false );
        } while ( $more && ( microtime( true ) - $started ) < self::BUDGET_SECONDS );

        if ( $more ) {
            self::rearm( 0 );
        } else {
            $state['finished_at'] = time();
            update_option( self::STATE, $state, false );
            wp_clear_scheduled_hook( self::HOOK );
        }
    }

    /** One slice: fingerprint a batch of the current stream, or send its manifest, or move to the next stream. @return bool more to do */
    private static function step( array &$state ) {
        if ( $state['stream'] === null ) {
            if ( empty( $state['streams'] ) ) return false;
            $stream = array_shift( $state['streams'] );
            $state['stream']   = $stream;
            $state['offset'] = 0;
            $state['total']  = self::count( $stream );
            $state['items']  = array();
            if ( $state['total'] > self::MAX_ITEMS ) {
                self::record( $stream, array( 'error' => sprintf( 'This stream has %d items — more than one manifest can carry.', $state['total'] ) ) );
                $state['stream'] = null;
            }
            return true;
        }

        $stream = $state['stream'];
        if ( $state['offset'] < $state['total'] ) {
            $slice = self::fingerprint_slice( $stream, (int) $state['offset'] );
            if ( $slice === null ) {
                // An empty page below the total: the stream shrank since we counted, or the query failed. Either
                // way there is nothing more to read — send what we have; the backend's rails cover a short list.
                $state['offset'] = $state['total'];
            } else {
                foreach ( $slice as $id => $fp ) $state['items'][ $id ] = $fp;
                $state['offset'] += count( $slice );
            }
            return true;
        }

        self::send( $stream, $state['items'] );
        $state['stream']  = null;
        $state['items'] = array();
        return true;
    }

    /**
     * Fingerprint one page of a stream's published items.
     *
     * @return array<string,string>|null [ external_id => fingerprint ], or null when the page is empty.
     */
    private static function fingerprint_slice( $stream, $offset ) {
        $out = array();
        if ( $stream === 'products' ) {
            $sync = personaizer_woocommerce_sync();
            if ( ! $sync ) return null;
            $ids = wc_get_products( array(
                'status' => 'publish', 'limit' => self::PRODUCT_BATCH, 'offset' => $offset,
                'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids',
            ) );
            if ( empty( $ids ) ) return null;
            foreach ( $ids as $id ) {
                $product = wc_get_product( $id );
                if ( ! $product || $product->get_status() !== 'publish' ) continue;
                $item = $sync->payload_for( $product );
                $out[ $item['id'] ] = $item['fingerprint'];
            }
            return $out;
        }

        $type = personaizer_streams()[ $stream ]['post_type'];
        $ids  = get_posts( array(
            'post_type' => $type, 'post_status' => 'publish', 'fields' => 'ids',
            'posts_per_page' => self::POST_BATCH, 'offset' => $offset,
            'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true,
        ) );
        if ( empty( $ids ) ) return null;
        $sync = personaizer_sync();
        foreach ( $ids as $id ) {
            $post = get_post( $id );
            if ( ! $post ) continue;
            $payload = $sync->payload_for( $post );
            if ( $payload === null ) continue;
            $out[ $payload['id'] ] = personaizer_payload_hash( $payload );
        }
        return $out;
    }

    private static function count( $stream ) {
        if ( $stream === 'products' ) return personaizer_published_count( 'product' );
        return personaizer_published_count( personaizer_streams()[ $stream ]['post_type'] );
    }

    /**
     * Send a stream's manifest and act on the answer: everything the backend is missing or holds stale goes
     * into the retry queue, which the catch-up tick pushes through the ordinary sync paths.
     */
    private static function send( $stream, array $items ) {
        $manifest = array();
        foreach ( $items as $id => $fp ) $manifest[] = array( 'id' => (string) $id, 'fingerprint' => (string) $fp );

        $result = personaizer_api()->send_manifest( $stream, time(), $manifest );
        if ( is_wp_error( $result ) ) {
            personaizer_debug_log( 'manifest for ' . $stream . ' failed: ' . $result->get_error_message() );
            self::record( $stream, array( 'error' => $result->get_error_message() ) );
            return;
        }

        $queued = 0;
        foreach ( array_merge( $result['missing'], $result['stale'] ) as $external_id ) {
            $post_id = self::post_id_for( $external_id );
            if ( $post_id > 0 ) {
                personaizer_remember_retry( $stream, $external_id, $post_id );
                $queued++;
            }
        }
        if ( $queued > 0 ) personaizer_arm_catch_up();

        self::record( $stream, array(
            'generation'      => $result['generation'],
            'present'         => $result['present'],
            'missing'         => count( $result['missing'] ),
            'stale'           => count( $result['stale'] ),
            'orphans'         => $result['orphans'],
            'orphans_deleted' => $result['orphans_deleted'],
            'orphans_held'    => $result['orphans_held'],
            'busy'            => $result['busy'],
        ) );
    }

    /** The WordPress post id behind one of OUR external ids (wp-<type>-<id> / wc-product-<id>), or 0. */
    private static function post_id_for( $external_id ) {
        if ( preg_match( '/^(?:wp-[a-z0-9_-]+|wc-product)-(\d+)$/', (string) $external_id, $m ) ) {
            return (int) $m[1];
        }
        return 0;
    }

    /** Remember a stream's last outcome for the settings page. */
    private static function record( $stream, array $outcome ) {
        $all = get_option( self::RESULT, array() );
        if ( ! is_array( $all ) ) $all = array();
        $outcome['at'] = time();
        $all[ $stream ]  = $outcome;
        update_option( self::RESULT, $all, false );
    }

    /** The last outcome per stream, for the settings page. @return array<string,array> */
    public static function results() {
        $all = get_option( self::RESULT, array() );
        return is_array( $all ) ? $all : array();
    }

    /** @return array{running:bool,stream:?string,done:int,total:int} */
    public static function progress() {
        $state = get_option( self::STATE, array() );
        if ( ! is_array( $state ) || empty( $state['started_at'] ) || ! empty( $state['finished_at'] ) ) {
            return array( 'running' => false, 'stream' => null, 'done' => 0, 'total' => 0 );
        }
        return array( 'running' => true, 'stream' => $state['stream'], 'done' => (int) $state['offset'], 'total' => (int) $state['total'] );
    }

    private static function rearm( $delay ) {
        wp_clear_scheduled_hook( self::HOOK );
        wp_schedule_single_event( time() + (int) $delay, self::HOOK );
    }
}
