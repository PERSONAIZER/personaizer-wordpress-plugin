<?php
/**
 * General WordPress content sync → the site's integration streams on PERSONAIZER.
 *
 * Pushes posts / pages / public custom post types into their stream (one stream per post type; the backend
 * files each stream into its own source). Products (WooCommerce) are handled separately by the typed catalog
 * mapper.
 *
 * Mechanism: WordPress hooks (never raw DB / polling).
 *   - wp_after_insert_post  → create/update  (fires AFTER meta+terms are saved,
 *                             unlike raw save_post which fires before)
 *   - trashed_post / before_delete_post → remove; or, when the stream is off,
 *                             remember it for the resume (see remember_removal)
 *   - the daily tick (Personaizer_Manifest) → the stream manifest catches whatever the hooks missed
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Personaizer_Content_Sync {

    /** @var Personaizer_Api */
    private $api;

    public function __construct( Personaizer_Api $api ) {
        $this->api = $api;

        add_action( 'wp_after_insert_post', [ $this, 'on_post_saved' ], 20, 4 );
        add_action( 'trashed_post', [ $this, 'on_post_removed' ] );
        add_action( 'before_delete_post', [ $this, 'on_post_removed' ] );
    }

    /** Post types whose stream the owner switched on (read from the integration, never from a local option). */
    private function enabled_types() {
        $types = array();
        $streams = personaizer_streams();
        foreach ( personaizer_current_streams() as $stream ) {
            if ( $stream !== 'products' && isset( $streams[ $stream ] ) ) $types[] = $streams[ $stream ]['post_type'];
        }
        return $types;
    }

    /** Stable, URL-safe external id — the key the upsert dedupes/updates on. */
    private function external_id( WP_Post $post ) {
        return 'wp-' . $post->post_type . '-' . $post->ID;
    }

    /**
     * wp_after_insert_post: meta + terms are final here (unlike save_post).
     * Only 'publish' content belongs in the AI's knowledge — anything else is removed.
     */
    public function on_post_saved( $post_id, $post, $update = null, $post_before = null ) {
        if ( ! $this->api->is_configured() ) return;
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        if ( ! ( $post instanceof WP_Post ) ) {
            $post = get_post( $post_id );
        }
        if ( ! $post ) return;

        if ( ! in_array( $post->post_type, $this->enabled_types(), true ) ) {
            // Stream off. An EDIT needs nothing remembered — the catch-up walk on resume re-reads the post
            // as it stands then. An UNPUBLISH does: that walk only visits published posts, so it is exactly
            // blind to this, and the doc would outlive the page forever.
            if ( $post->post_status !== 'publish' ) {
                $this->remember_removal( $post );
            }
            return;
        }

        if ( $post->post_status !== 'publish' ) {
            $this->forget( $post );
            $this->api->delete_docs( [ $this->external_id( $post ) ] );
            return;
        }
        $this->sync_post( $post );
    }

    public function on_post_removed( $post_id ) {
        if ( ! $this->api->is_configured() ) return;
        $post = get_post( $post_id );
        if ( ! $post ) return;
        $this->forget( $post );
        // Always queued, never sent inline — see personaizer_arm_removal_flush() for why bulk deletes
        // make a per-post API call unsafe. A stream that is off took this path already; now every removal does.
        $this->remember_removal( $post );
    }

    /**
     * A post is leaving the AI (unpublished / trashed / deleted), so it must also leave the overflow
     * queue — otherwise a deleted item stays queued as "waiting for plan space" forever, since it can
     * never be re-pushed to clear itself (it no longer exists).
     */
    private function forget( WP_Post $post ) {
        $stream = personaizer_stream_for_post_type( $post->post_type );
        if ( isset( personaizer_streams()[ $stream ] ) ) {
            personaizer_forget_overflow( $stream, [ $this->external_id( $post ) ] );
            // Same reasoning as the overflow queue: a post that is gone can never re-push to clear itself,
            // so leaving it queued would retry a deleted item on every tick, forever.
            personaizer_forget_retry( $stream, [ $this->external_id( $post ) ] );
        }
    }

    /**
     * Queue a removal to be applied by the next flush.
     *
     * Products are skipped deliberately: they are a stream, but they carry their own id shape
     * (wc-product-99, not wp-product-99) and their own hook, so Personaizer_WooCommerce_Sync queues them.
     * Minting an id here would enqueue a delete for a doc that does not exist and miss the one that does.
     */
    private function remember_removal( WP_Post $post ) {
        $stream = personaizer_stream_for_post_type( $post->post_type );
        if ( $stream === 'products' ) return;
        // A post type we never sync at all (attachments, menu items, a theme's internal types) has no stream
        // and no doc — nothing to remember.
        if ( ! isset( personaizer_streams()[ $stream ] ) ) return;
        personaizer_remember_removal( $stream, $this->external_id( $post ), $post->ID );
    }

    /**
     * The exact payload this post would be pushed as — no request, no side effects.
     *
     * The stream manifest fingerprints THIS rather than the post row, so the comparison is against what the
     * AI actually received: rendered content (shortcodes and blocks expanded, tags stripped) and the
     * resolved image URLs. A theme or plugin that changes how content renders therefore shows up as
     * "out of date", which reading post_modified alone would never reveal.
     *
     * @return array|null Null for a post type with no stream (nothing would be sent).
     */
    public function payload_for( WP_Post $post ) {
        $stream = personaizer_stream_for_post_type( $post->post_type );
        if ( ! isset( personaizer_streams()[ $stream ] ) ) return null;
        return array(
            'id'        => $this->external_id( $post ),
            'stream'      => $stream,
            'title'     => $this->post_title( $post ),
            'markdown'  => $this->render_content( $post ),
            'permalink' => get_permalink( $post ),
            'images'    => $this->collect_images( $post ),
        );
    }

    /** @return bool true when the doc actually reached the persona. */
    private function sync_post( WP_Post $post ) {
        $payload = $this->payload_for( $post );
        if ( $payload === null ) {
            return false;
        }
        $stream   = $payload['stream'];
        $ext    = $payload['id'];
        $result = $this->api->upsert_text(
            $stream,
            $ext,
            $payload['title'],
            $payload['markdown'],
            personaizer_payload_hash( $payload ),
            $payload['permalink'],
            $payload['images']
        );

        if ( is_wp_error( $result ) ) {
            if ( Personaizer_Api::is_stream_closed( $result ) ) {
                // The owner shut this stream on personaizer.com (or disconnected). Not a failure of this post:
                // drop it from every queue rather than retry a closed door on each edit.
                personaizer_forget_overflow( $stream, array( $ext ) );
                personaizer_forget_retry( $stream, array( $ext ) );
                return false;
            }
            // The plan being full isn't "this post is broken" — remember it so the after-upgrade catch-up
            // replays it. Anything else is a real failure, and it gets remembered too: a transient timeout
            // or a one-off rejection used to be logged and then forgotten, which is how a site ends up
            // permanently reading "4 of 5 pages" with no way back short of a manual Resync. Queued here, it
            // is re-tried on every catch-up tick until it lands.
            if ( Personaizer_Api::is_quota_error( $result ) ) {
                personaizer_remember_overflow( $stream, $ext, $post->ID );
            } else {
                personaizer_remember_retry( $stream, $ext, $post->ID );
            }
            personaizer_debug_log( 'content sync failed for post ' . $post->ID . ': ' . $result->get_error_message() );
            return false;
        }
        // Landed — if it had been waiting for plan space or a retry, it isn't anymore.
        personaizer_forget_overflow( $stream, array( $ext ) );
        personaizer_forget_retry( $stream, array( $ext ) );
        return true;
    }

    private function post_title( WP_Post $post ) {
        $title = get_the_title( $post );
        return $title !== '' ? $title : ( ucfirst( $post->post_type ) . ' ' . $post->ID );
    }

    /** Render the post to clean text: blocks/shortcodes expanded, tags stripped, entities decoded. */
    private function render_content( WP_Post $post ) {
        $html = apply_filters( 'the_content', $post->post_content );
        // Entities must be decoded AFTER stripping tags. WordPress stores typographic characters as
        // &#8217; / &amp; / &nbsp;, and leaving them encoded puts that noise into the knowledge doc —
        // where it gets embedded, retrieved, and quoted back at a customer.
        $text = html_entity_decode( (string) wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );
        return '# ' . $this->post_title( $post ) . "\n\n" . $text;
    }

    /**
     * Collect the post's images for the doc's image library: the featured image
     * (primary) plus inline <img> in the rendered content. Only absolute http(s)
     * URLs; relative / data: URIs are skipped. Capped so image-heavy posts can't
     * flood the library (the API caps again server-side).
     *
     * @return array<int,array{url:string,description:string,is_primary:bool}>
     */
    private function collect_images( WP_Post $post ) {
        $images = array();
        $seen   = array();
        $cap    = 20;

        // Featured image → the doc's primary/preview image.
        $thumb_id = get_post_thumbnail_id( $post->ID );
        if ( $thumb_id ) {
            $url = wp_get_attachment_image_url( $thumb_id, 'full' );
            if ( $url ) {
                $alt = trim( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) );
                $images[]     = array( 'url' => $url, 'description' => $alt, 'is_primary' => true );
                $seen[ $url ] = true;
            }
        }

        // Inline <img> in the rendered content.
        $html = apply_filters( 'the_content', $post->post_content );
        if ( preg_match_all( '/<img\b[^>]*?\bsrc\s*=\s*([\'"])(.*?)\1[^>]*>/i', $html, $tags, PREG_SET_ORDER ) ) {
            foreach ( $tags as $tag ) {
                if ( count( $images ) >= $cap ) break;
                $url = html_entity_decode( $tag[2], ENT_QUOTES );
                if ( ! preg_match( '#^https?://#i', $url ) ) continue;
                if ( isset( $seen[ $url ] ) ) continue;
                $seen[ $url ] = true;
                $alt = '';
                if ( preg_match( '/\balt\s*=\s*([\'"])(.*?)\1/i', $tag[0], $a ) ) {
                    $alt = trim( html_entity_decode( $a[2], ENT_QUOTES ) );
                }
                $images[] = array( 'url' => $url, 'description' => $alt, 'is_primary' => false );
            }
        }
        return $images;
    }

    /**
     * Push the given post ids. The batch entry point for Personaizer_Backfill and the manifest walk — they
     * own the paging, this owns how one post becomes a knowledge doc.
     *
     * @param int[] $ids
     * @return int the number actually pushed.
     */
    public function sync_ids( array $ids ) {
        if ( ! $this->api->is_configured() ) return 0;
        $count = 0;
        foreach ( $ids as $id ) {
            $post = get_post( $id );
            if ( ! $post ) continue;
            if ( $post->post_status !== 'publish' ) {
                // Not published — must not push it (that would orphan a doc). Remove it from the AI + the
                // queue (matches on_post_removed). Relevant to flush_overflow, which passes specific ids
                // without the published-only filter the backfill uses.
                $this->forget( $post );
                $this->api->delete_docs( [ $this->external_id( $post ) ] );
                continue;
            }
            if ( $this->sync_post( $post ) ) $count++;
        }
        return $count;
    }
}
