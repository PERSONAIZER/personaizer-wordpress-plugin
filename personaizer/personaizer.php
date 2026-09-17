<?php
/**
 * Plugin Name: PERSONAIZER
 * Plugin URI:  https://personaizer.com/wordpress
 * Description: Connect your site to PERSONAIZER in one click — the AI chat widget goes live and your pages, posts and products stay in sync with it.
 * Version:     2.1.1
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author:      PERSONAIZER
 * Author URI:  https://personaizer.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: personaizer
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The running version, and this file's own path.
 *
 * The version is duplicated from the plugin header deliberately. WordPress reads the header by scanning the
 * file, but comparing ourselves against an update manifest happens on ordinary page loads, where re-parsing
 * the header would be wasted work. build-zip.sh refuses to package when the constant, the header and
 * readme.txt's Stable tag disagree, so the copy cannot drift in silence.
 */
define( 'PERSONAIZER_VERSION', '2.1.1' );
define( 'PERSONAIZER_PLUGIN_FILE', __FILE__ );

/**
 * Hosted chat-widget script. Defaults to PRODUCTION — every real install talks to prod,
 * so the shipped plugin must not carry dev/local URLs. The public Persona ID is appended
 * as ?k=<id>.
 *
 * For our own dev/local testing, override it BEFORE plugins load by adding a line to
 * wp-config.php (this guard leaves a pre-defined constant untouched), e.g.:
 *   define( 'PERSONAIZER_WIDGET_URL', 'https://personaizerdevstore2.blob.core.windows.net/platform-builds-public/chat.js' );
 * To test UNRELEASED chat.js changes, take it from local Core's embedded copy instead — the blob holds
 * whatever was last uploaded, which will not have your edits:
 *   define( 'PERSONAIZER_WIDGET_URL', 'http://localhost:5180/api/widget/chat.js' );
 *
 * The prod URL becomes https://cdn.personaizer.com/... once that CNAME is live (ENG-190).
 */
if ( ! defined( 'PERSONAIZER_WIDGET_URL' ) ) {
    define( 'PERSONAIZER_WIDGET_URL', 'https://personaizerprodstore.blob.core.windows.net/platform-builds-public/chat.js' );
}

require_once __DIR__ . '/includes/class-personaizer-data.php';
require_once __DIR__ . '/includes/class-personaizer-site-profile.php';
require_once __DIR__ . '/includes/class-personaizer-api.php';
require_once __DIR__ . '/includes/class-personaizer-daily.php';
require_once __DIR__ . '/includes/class-personaizer-content-sync.php';
require_once __DIR__ . '/includes/class-personaizer-backfill.php';
require_once __DIR__ . '/includes/class-personaizer-manifest.php';
Personaizer_Daily::boot();
Personaizer_Backfill::boot();
Personaizer_Manifest::boot();

// Self-hosted update channel: teaches WordPress to offer updates for a plugin installed from a zip.
// The WordPress.org build ships WITHOUT this file (the directory serves updates there), so load it only
// when present rather than hard-requiring it — build-zip.sh --org drops it for the submission package.
if ( file_exists( __DIR__ . '/includes/class-personaizer-updater.php' ) ) {
    require_once __DIR__ . '/includes/class-personaizer-updater.php';
    Personaizer_Updater::boot();
}

/**
 * Namespaced error-log line, emitted only when WP_DEBUG is on. Diagnostics for the developer; silent on
 * production sites (WordPress.org asks plugins not to write to the log unconditionally).
 */
function personaizer_debug_log( $message ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[personaizer] ' . $message );
    }
}

/**
 * Where the chat WIDGET's REST calls (/v1/chat + SSE) are sent. chat.js bakes a default base in at
 * upload time; we override it via PersonAIzerConfig.apiBase so the widget targets local/dev/prod the
 * same way the dashboard's own web widget does — instead of being frozen to whatever the hosted
 * chat.js file was built against. Defaults to PERSONAIZER_API_URL: in deployed environments ONE
 * ingress host fronts both Core (/v1/knowledge, /v1/persona) and Sessions (/v1/chat), so a single
 * knob steers data-sync AND chat together.
 *
 * It's a separate constant only so a split surface can still be pointed somewhere sane: the widget needs
 * BOTH /v1/persona/profile (Core) and /v1/chat (Sessions), which no single local port serves. Run the
 * local gateway (docker-compose/docker-compose.gateway.yml) — the twin of the deployed /v1 ingress — and
 * one base covers both, so this constant can simply stay at its PERSONAIZER_API_URL default:
 *   define( 'PERSONAIZER_API_URL', 'http://localhost:8080' );
 * Pointing it at a bare local Sessions (:5080) makes chat work but silently drops the persona's whole
 * server-side widget config — appearance, greeting, FAQ — since that lives on Core.
 */
if ( ! defined( 'PERSONAIZER_WIDGET_API_BASE' ) ) {
    define( 'PERSONAIZER_WIDGET_API_BASE', PERSONAIZER_API_URL );
}

/**
 * The PERSONAIZER dashboard this site's owner uses — the target of every "open your dashboard" link
 * (and the default host of the Connect consent screen). Defaults to PRODUCTION; override for dev/local
 * so the plugin's links follow the environment its API is pointed at:
 *   define( 'PERSONAIZER_APP_URL', 'http://localhost:3000' );
 */
if ( ! defined( 'PERSONAIZER_APP_URL' ) ) {
    define( 'PERSONAIZER_APP_URL', 'https://personaizer.com' );
}

/**
 * The dashboard consent screen for one-click Connect. The plugin sends the owner here with a PKCE
 * challenge + its callback; the owner picks the brand this site feeds, a widget persona and the streams to
 * sync, and we're redirected back with a single-use code the plugin's server exchanges (at
 * PERSONAIZER_API_URL/api/integrations/connect/token) for the integration credential (plus the persona's id
 * and Identity Secret when one was picked). Follows PERSONAIZER_APP_URL unless set outright.
 */
if ( ! defined( 'PERSONAIZER_CONNECT_URL' ) ) {
    define( 'PERSONAIZER_CONNECT_URL', rtrim( PERSONAIZER_APP_URL, '/' ) . '/connect' );
}

/** The owner's dashboard, or a specific persona's editor tab when we know which persona is linked. */
function personaizer_app_url( $path = '' ) {
    return rtrim( PERSONAIZER_APP_URL, '/' ) . $path;
}

/**
 * The streams this site can teach: one per content type. A stream is the unit the owner switches on and off on
 * personaizer.com (the backend files each stream into its own source — `<host>-<stream>` — so "stop using my
 * products, keep my pages" is a thing the owner can express, without deleting anything).
 *
 * Each stream also declares what a source of it HOLDS on personaizer.com: 'catalog' for typed entries with
 * attributes, variants and price (WooCommerce products), 'files' for a record uploaded as a text document
 * (pages, posts, any custom type). The backend files the stream by what this says, never by the stream's
 * name, and refuses to switch on a stream that declares nothing.
 *
 * @return array<string,array{label:string,post_type:string,type:string}> keyed by stream id.
 */
function personaizer_streams() {
    $streams = array(
        'pages' => array( 'label' => 'Pages', 'post_type' => 'page', 'type' => 'files' ),
        'posts' => array( 'label' => 'Posts', 'post_type' => 'post', 'type' => 'files' ),
    );
    // A custom type is just another stream — same source shape, same controls. Nothing about this is special
    // cased, which is the point: a site with a Recipes type gets Recipes beside Pages, and the ~95% without
    // one never learn the concept exists.
    $extra = personaizer_extra_post_types();

    // Labels are a plugin's free choice, so two of them can land on the same word — "Templates" is the
    // common one. Two identically named rows leave an owner unable to tell which they are switching off,
    // so qualify a label with its type slug, but only when it actually collides: the ordinary
    // one-custom-type site keeps a clean "Recipes".
    $labels = array( 'Pages', 'Posts' );
    if ( class_exists( 'WooCommerce' ) ) {
        $labels[] = 'Products';
    }
    foreach ( $extra as $type ) {
        $labels[] = $type->labels->name;
    }
    $seen = array_count_values( $labels );

    foreach ( $extra as $type ) {
        $label = $type->labels->name;
        $streams[ $type->name ] = array(
            'label'     => $seen[ $label ] > 1 ? $label . ' (' . $type->name . ')' : $label,
            'post_type' => $type->name,
            // A custom type is WordPress content: each record is pushed as a text document, like a page or a post.
            'type'      => 'files',
        );
    }
    if ( class_exists( 'WooCommerce' ) ) {
        // The only catalog stream: a product is pushed as a typed entry with its attributes, variants and price.
        $streams['products'] = array( 'label' => 'Products', 'post_type' => 'product', 'type' => 'catalog' );
    }
    return $streams;
}

/**
 * The streams switched ON — read from the integration on personaizer.com, where the owner switches them.
 *
 * Never a local option: a local copy would be a second source of truth, free to drift, and the plugin would
 * confidently push into a stream the owner closed an hour ago. The integration read is cached for a minute; when
 * personaizer.com can't be reached the last state it DID report stands in, so a blip never reads as "every
 * stream off" (which would silently drop edits on the floor with nothing to retry them).
 *
 * @return string[] stream ids.
 */
function personaizer_current_streams() {
    $integration = personaizer_integration();
    if ( ! is_array( $integration ) ) return array();
    $out = array();
    foreach ( $integration['streams'] as $stream => $state ) {
        if ( ! empty( $state['enabled'] ) ) $out[] = $stream;
    }
    return $out;
}

/**
 * This site's integration as personaizer.com last reported it — live when reachable, otherwise the last good
 * answer. Null only before the first successful read after connecting.
 *
 * @return array|null See Personaizer_Api::get_integration().
 */
function personaizer_integration( $force = false ) {
    if ( ! personaizer_api()->is_configured() ) return null;
    $live = personaizer_api()->get_integration( $force );
    if ( is_array( $live ) ) {
        update_option( 'personaizer_integration_state', $live, false );
        return $live;
    }
    $last = get_option( 'personaizer_integration_state', null );
    return is_array( $last ) ? $last : null;
}

/** The stream a WordPress post type belongs to. Every synced type has one. */
function personaizer_stream_for_post_type( $post_type ) {
    if ( $post_type === 'page' ) return 'pages';
    if ( $post_type === 'post' ) return 'posts';
    if ( $post_type === 'product' ) return 'products';
    return $post_type; // custom types are their own stream
}

/**
 * Removals that happened while a stream was frozen, waiting for that stream to sync again.
 *
 * The gap this closes: a frozen stream pushes nothing, so trashing a page while it's frozen never reaches
 * the AI, and the catch-up walk on resume can't repair it — that walk visits PUBLISHED posts, so a page
 * that no longer exists is precisely what it cannot see. The doc would outlive the page forever.
 *
 * Why a queue rather than a reconcile that lists the AI's docs and drops whatever has no matching post:
 * WordPress TELLS us the moment a post is trashed or unpublished even while the stream is frozen — we
 * simply had nowhere to put the fact. Inferring it back later is a far more dangerous instrument than it
 * looks, because it deduces deletion from two lists that can each lie:
 *
 *   - "what the site has" can under-report, and then every surviving doc looks like an orphan. WPML forces
 *     suppress_filters off so get_posts() answers for ONE language; deactivating WooCommerce unregisters
 *     `product` outright. Either turns a routine sweep into a mass delete.
   (The stream manifest — Personaizer_Manifest — is the one place deletion IS deduced, and it runs on the
 *   backend behind its own rails: a manifest is sent only for a stream this site can enumerate fully, and
 *   a manifest that would orphan a large share of a stream is held until the next one agrees.)
 *
 * Deletion is not reversible on our side. So we record what we witnessed instead of deducing what we
 * didn't. Every note is still re-checked against the live site before it's acted on (see flush).
 *
 * Shape: [ stream => [ external_id => post_id ] ] — keyed by external_id so re-queuing dedupes, and the
 * post id is kept so the flush can ask WordPress whether the note is still true.
 */
function personaizer_pending_removals() {
    $queue = get_option( 'personaizer_pending_removals', array() );
    return is_array( $queue ) ? $queue : array();
}

/** Note that a post is gone, to be applied by the next flush. */
function personaizer_remember_removal( $stream, $external_id, $post_id ) {
    $queue = personaizer_pending_removals();
    $queue[ $stream ][ $external_id ] = (int) $post_id;
    update_option( 'personaizer_pending_removals', $queue, false );
    personaizer_arm_removal_flush();
}

/**
 * Apply this request's queued removals at the very end of it, once, under a time budget.
 *
 * Deleting inline from the trash hook is what this replaces, and the reason is bulk: one blocking API
 * round-trip per post means trashing 200 products is 200 sequential requests inside a single WordPress
 * request, which dies on max_execution_time part-way through. Every un-fired delete is then lost with no
 * retry anywhere, and the AI keeps recommending products the shop no longer sells.
 *
 * Queuing first turns that into N cheap option writes plus a couple of batched calls at shutdown, and
 * whatever the budget does not cover simply stays queued for the next cron tick. Shutdown runs after the
 * response is sent, so a single deletion still reaches the AI within its own request without the owner
 * waiting on it.
 *
 * Armed only from remember_removal(), so a request that deletes nothing pays nothing — not even the
 * option read (the queue is stored with autoload off).
 */
function personaizer_arm_removal_flush() {
    static $armed = false;
    if ( $armed ) return;
    $armed = true;
    add_action( 'shutdown', function () {
        personaizer_flush_removals( null, 5 );
    }, 5 );
}

/**
 * Apply the queued removals for streams that just started syncing again.
 *
 * Each note is re-checked against the live site first: a page trashed and then restored while the stream was
 * frozen is still queued, and acting on a note written days ago would delete a page that is live right
 * now. That check is what makes over-queuing harmless, which in turn lets the recording side stay dumb —
 * it can note anything that looks like a removal without having to be right.
 *
 * @param string[]|null $streams Stream ids to apply, or null for every stream holding notes.
 * @param float          $budget_seconds Stop after this long and leave the rest queued; 0 = no limit.
 * @return int Docs asked to be removed.
 */
function personaizer_flush_removals( ?array $streams = null, $budget_seconds = 0 ) {
    $queue = personaizer_pending_removals();
    if ( $streams === null ) $streams = array_keys( $queue );

    $started = microtime( true );
    $removed = 0;

    foreach ( $streams as $stream ) {
        if ( empty( $queue[ $stream ] ) ) continue;

        $ids = array();
        foreach ( (array) $queue[ $stream ] as $external_id => $post_id ) {
            $post = get_post( (int) $post_id );
            if ( $post && $post->post_status === 'publish' ) {
                unset( $queue[ $stream ][ $external_id ] );   // it came back — the note is stale
                continue;
            }
            $ids[] = (string) $external_id;
        }

        // The ids travel in the query string, so a long queue would build a URL past the ~8KB most servers
        // accept and the whole flush would fail as a bad request.
        foreach ( array_chunk( $ids, 100 ) as $chunk ) {
            $result = personaizer_api()->delete_docs( $chunk );
            if ( is_wp_error( $result ) ) {
                // Keep every note we could not apply — the next tick retries. Re-deleting a doc that
                // already went is a no-op, so replaying a partly-applied flush is safe.
                personaizer_debug_log( 'pending removals flush failed: ' . $result->get_error_message() );
                update_option( 'personaizer_pending_removals', $queue, false );
                return $removed;
            }
            foreach ( $chunk as $external_id ) unset( $queue[ $stream ][ $external_id ] );
            $removed += count( $chunk );

            // A catalog-sized queue must not take the request down with it — stopping mid-way is safe
            // precisely because what is left is still recorded.
            if ( $budget_seconds > 0 && ( microtime( true ) - $started ) >= $budget_seconds ) {
                update_option( 'personaizer_pending_removals', $queue, false );
                return $removed;
            }
        }
        if ( empty( $queue[ $stream ] ) ) unset( $queue[ $stream ] );
    }

    update_option( 'personaizer_pending_removals', $queue, false );
    return $removed;
}

// Whatever a shutdown budget could not finish drains on the backfill tick — the same cron the catch-up
// walk already rides, so a queue left by a bulk delete cannot sit forever waiting for another deletion
// to arm a flush.
add_action( Personaizer_Backfill::HOOK, function () {
    if ( personaizer_api()->is_configured() ) personaizer_flush_removals( null, 10 );
}, 5 );

/**
 * Items the account's plan had no room for, waiting for space to free up.
 *
 * The gap this closes: when a catalog is bigger than the plan's knowledge quota, every push past the
 * ceiling is rejected as a whole (HTTP 402), and the backfill advances past those items forever — so the
 * AI ends up knowing the first plan's-worth and silently blind to the rest, recoverable only if the owner
 * notices and hits Resync. We remember exactly which items didn't fit, so the moment the plan gains room
 * (an upgrade) they replay automatically.
 *
 * Unlike pending_removals this is safe to over- OR under-record, because replay is ADDITIVE: a stray note
 * just re-pushes a doc the AI already has (a no-op on our side), and the set is self-correcting through
 * the normal sync paths — every item that LANDS is forgotten, every item still rejected for quota is
 * re-remembered. So a network blip during replay loses nothing: the item is neither pushed nor forgotten,
 * it simply stays queued.
 *
 * Shape mirrors pending_removals: [ stream => [ external_id => post_id ] ] — keyed by external_id so
 * re-queuing dedupes, post_id kept so replay can re-map the live item.
 */
function personaizer_pending_overflow() {
    $queue = get_option( 'personaizer_pending_overflow', array() );
    return is_array( $queue ) ? $queue : array();
}

/** Note that an item didn't fit the plan's knowledge quota, to be replayed when the plan gains room. */
function personaizer_remember_overflow( $stream, $external_id, $post_id ) {
    $queue = personaizer_pending_overflow();
    $queue[ $stream ][ (string) $external_id ] = (int) $post_id;
    update_option( 'personaizer_pending_overflow', $queue, false );
}

/**
 * Drop items from the queue once they've landed — called from the sync paths on a SUCCESSFUL push, so the
 * set empties itself as space frees up and no separate reconciliation is ever needed.
 *
 * @param string   $stream
 * @param string[] $external_ids
 */
function personaizer_forget_overflow( $stream, array $external_ids ) {
    $queue = personaizer_pending_overflow();
    if ( empty( $queue[ $stream ] ) ) return;   // nothing queued for this stream — the common case, skip the write
    $changed = false;
    foreach ( $external_ids as $external_id ) {
        if ( isset( $queue[ $stream ][ (string) $external_id ] ) ) {
            unset( $queue[ $stream ][ (string) $external_id ] );
            $changed = true;
        }
    }
    if ( ! $changed ) return;
    if ( empty( $queue[ $stream ] ) ) unset( $queue[ $stream ] );
    update_option( 'personaizer_pending_overflow', $queue, false );
}

/**
 * A stable fingerprint of exactly what we would send for one item.
 *
 * Sent with every push and stored by the backend on the doc; the stream manifest (Personaizer_Manifest) sends
 * the same fingerprints again and the backend answers which items it holds a different one for. Because it
 * hashes the mapper's ACTUAL OUTPUT, it changes
 * whenever anything that reaches the AI changes — the merchant edits a price, or WE change how a product
 * is mapped. That second case is what makes it worth having: when the attribute-key fix started emitting
 * Georgian facets that had been silently dropped, every product's payload changed, so every product's
 * fingerprint changed, and a comparison would have reported "393 out of date" instead of leaving someone
 * to notice by reading documents one at a time.
 *
 * Key order must not affect the result — PHP preserves insertion order in arrays and json_encode follows
 * it, so two identical payloads built in a different order would otherwise hash differently and every item
 * would look permanently stale. Hence the recursive sort of associative keys (lists keep their order,
 * which is meaningful for images/variants).
 */
function personaizer_normalize_for_hash( $value ) {
    if ( ! is_array( $value ) ) return $value;
    $is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
    $out = array();
    foreach ( $value as $k => $v ) {
        $out[ $k ] = personaizer_normalize_for_hash( $v );
    }
    if ( ! $is_list ) ksort( $out );
    return $out;
}

/** md5 of the canonical payload. Not a security hash — just a cheap, stable equality check. */
function personaizer_payload_hash( $payload ) {
    return md5( (string) wp_json_encode( personaizer_normalize_for_hash( $payload ) ) );
}

/**
 * Items a push FAILED on for a reason that isn't the plan being full — waiting to be re-tried.
 *
 * Distinct from pending_overflow on purpose. Overflow means "your plan is full", is shown to the owner as
 * such, and only replays once the plan gains room. This queue means "we could not write this item", which
 * has nothing to do with quota and must retry regardless of headroom: an API timeout, a transient 5xx, or
 * one item the server rejected outright.
 *
 * The gap it closes: a batch push is atomic server-side, so ONE bad or slow item used to take its whole
 * batch (up to 20 products) down with it — counted as `failed`, logged, and then never touched again until
 * someone noticed and hit Resync by hand. Items were being lost silently. Now a failed batch is retried
 * item-by-item (see Personaizer_WooCommerce_Sync::push), so a bad item only ever costs itself, and even
 * that one is remembered here rather than dropped.
 *
 * Same shape and same self-correcting contract as pending_overflow: [ stream => [ external_id => post_id ] ],
 * additive replay, forgotten the moment an item lands, so over-recording is harmless.
 */
function personaizer_pending_retry() {
    $queue = get_option( 'personaizer_pending_retry', array() );
    return is_array( $queue ) ? $queue : array();
}

/** Note that an item failed to write, to be re-tried on the next catch-up tick. */
function personaizer_remember_retry( $stream, $external_id, $post_id ) {
    $queue = personaizer_pending_retry();
    $queue[ $stream ][ (string) $external_id ] = (int) $post_id;
    update_option( 'personaizer_pending_retry', $queue, false );
}

/** Drop items from the retry queue once they've landed — called from every successful push. */
function personaizer_forget_retry( $stream, array $external_ids ) {
    $queue = personaizer_pending_retry();
    if ( empty( $queue[ $stream ] ) ) return;   // nothing queued for this stream — the common case, skip the write
    $changed = false;
    foreach ( $external_ids as $external_id ) {
        if ( isset( $queue[ $stream ][ (string) $external_id ] ) ) {
            unset( $queue[ $stream ][ (string) $external_id ] );
            $changed = true;
        }
    }
    if ( ! $changed ) return;
    if ( empty( $queue[ $stream ] ) ) unset( $queue[ $stream ] );
    update_option( 'personaizer_pending_retry', $queue, false );
}

/** How many items failed to write and are waiting to be re-tried, across every stream. */
function personaizer_retry_count() {
    $n = 0;
    foreach ( personaizer_pending_retry() as $items ) {
        $n += count( (array) $items );
    }
    return $n;
}

/**
 * Re-push everything in the retry queue, for streams still syncing.
 *
 * Deliberately NOT gated on plan headroom (unlike the overflow catch-up): these items failed for reasons
 * unrelated to quota, so waiting for an upgrade that may never come would strand them forever. The normal
 * sync paths re-classify on the way through — an item that turns out to be a quota problem moves itself to
 * the overflow queue, one that lands is forgotten, one that fails again simply stays queued.
 *
 * @param string[]|null $streams Stream ids to replay, or null for every stream with something queued.
 * @return int items pushed.
 */
function personaizer_flush_retry( $streams = null ) {
    $queue = personaizer_pending_retry();
    if ( empty( $queue ) ) return 0;

    $active = personaizer_current_streams();
    $scope  = $streams === null ? array_keys( $queue ) : (array) $streams;
    $pushed = 0;

    foreach ( $scope as $stream ) {
        if ( empty( $queue[ $stream ] ) || ! in_array( $stream, $active, true ) ) continue;
        $post_ids = array_map( 'intval', array_values( $queue[ $stream ] ) );
        if ( $stream === 'products' ) {
            $sync = personaizer_woocommerce_sync();
            if ( $sync ) $pushed += $sync->sync_ids( $post_ids );
        } else {
            $pushed += personaizer_sync()->sync_ids( $post_ids );
        }
    }
    return $pushed;
}

/** How many items are waiting for plan space, across every stream. */
function personaizer_overflow_count() {
    $n = 0;
    foreach ( personaizer_pending_overflow() as $items ) {
        $n += count( (array) $items );
    }
    return $n;
}

/**
 * Replay the overflow queue for streams that are switched on.
 *
 * Re-pushes each remembered item through its normal sync path; the push forgets what lands and
 * re-remembers what still doesn't fit, so the queue self-corrects. Only streams switched on are touched —
 * a push into a stream the owner switched off is refused anyway.
 *
 * @param string[]|null $streams Stream ids to replay, or null for every stream with something queued.
 * @return int items pushed.
 */
function personaizer_flush_overflow( $streams = null ) {
    $queue = personaizer_pending_overflow();
    if ( empty( $queue ) ) return 0;

    $active = personaizer_current_streams();
    $scope  = $streams === null ? array_keys( $queue ) : (array) $streams;
    $pushed = 0;

    foreach ( $scope as $stream ) {
        if ( empty( $queue[ $stream ] ) || ! in_array( $stream, $active, true ) ) continue;
        $post_ids = array_map( 'intval', array_values( $queue[ $stream ] ) );
        if ( $stream === 'products' ) {
            $sync = personaizer_woocommerce_sync();
            if ( $sync ) $pushed += $sync->sync_ids( $post_ids );
        } else {
            $pushed += personaizer_sync()->sync_ids( $post_ids );
        }
    }
    return $pushed;
}

/** True when the plan has knowledge-unit room to accept more (or is unlimited). Unreachable/unknown → false. */
function personaizer_has_knowledge_headroom( $limits ) {
    if ( ! is_array( $limits ) ) return false;         // couldn't read the plan — don't replay blindly
    if ( $limits['ku_limit'] === null ) return true;   // unlimited
    return $limits['ku_used'] < $limits['ku_limit'];
}

/** The dashboard page where the owner upgrades their plan. */
function personaizer_upgrade_url() {
    return personaizer_app_url( '/subscription' );
}

/**
 * The catch-up: re-push what failed to write, and — when the plan has room — what didn't fit.
 *
 * Runs on the daily tick (hands-off), armed on demand right after the owner reopens the plugin, and armed
 * by the stream manifest when personaizer.com reports items missing or stale. Additive only, so it can never
 * fight the manifest's deletions.
 */
function personaizer_catch_up() {
    // Failed writes retry FIRST, and unconditionally: they are not a quota problem, so gating them on
    // headroom (as the overflow replay below is) would strand them behind an upgrade that may never come.
    // This is what makes "nothing is ever lost" true rather than aspirational — every item that failed to
    // write gets another attempt on every tick until it lands.
    if ( personaizer_retry_count() > 0 ) {
        personaizer_flush_retry();
    }

    if ( personaizer_overflow_count() === 0 ) return;
    // Read live (not the 5-min cache): this fires right after an upgrade, and a stale "full" reading
    // from just before it would skip the very heal it was armed to do.
    if ( ! personaizer_has_knowledge_headroom( personaizer_api()->get_limits( true ) ) ) return;
    personaizer_flush_overflow();
}

/** Arm a one-off background catch-up — non-blocking, so the next request runs it instead of the page load. */
function personaizer_arm_catch_up() {
    if ( ! wp_next_scheduled( 'personaizer_catch_up' ) ) {
        wp_schedule_single_event( time(), 'personaizer_catch_up' );
    }
}
add_action( Personaizer_Daily::HOOK, 'personaizer_catch_up' );
add_action( 'personaizer_catch_up', 'personaizer_catch_up' );

/**
 * Every stream's real state, for the settings page: switched on or off (the owner's choice on personaizer.com),
 * how much is on each side, and what the last manifest found.
 *
 * `known` and `count` are deliberately separate and deliberately both shown. While a stream syncs they agree
 * and the distinction is invisible. The moment it stops they diverge — delete a product and the site has 16
 * while the AI still has 17 — and that gap IS the state the owner came to this screen to understand.
 *
 * @param array|null $integration From personaizer_integration(); null when personaizer.com never answered.
 * @return array<string,array{label:string,enabled:bool,offered:bool,count:int,known:?int,ready:?int,overflow:int,reconciliation:?array}>
 */
function personaizer_stream_states( $integration ) {
    $known    = is_array( $integration ) ? $integration['streams'] : array();
    $counts   = personaizer_syncable_counts();
    $overflow = personaizer_pending_overflow();
    $out      = array();

    foreach ( personaizer_streams() as $stream => $meta ) {
        $row = isset( $known[ $stream ] ) ? $known[ $stream ] : null;
        $out[ $stream ] = array(
            'label'          => $meta['label'],
            // Off when the owner switched it off; also off when they never switched it on (no source yet).
            'enabled'        => $row !== null && ! empty( $row['enabled'] ),
            // Has a source at all — the difference between "switched off" and "never switched on".
            'offered'        => $row !== null,
            'count'          => (int) ( $counts[ $stream ] ?? 0 ),
            // doc_count when the stream has a source; 0 when it has none (the AI genuinely holds nothing here);
            // null only when personaizer.com never answered, and callers render "—" for that.
            'known'          => $row !== null ? (int) $row['doc_count'] : ( is_array( $integration ) ? 0 : null ),
            'ready'          => $row !== null ? (int) $row['ready_count'] : ( is_array( $integration ) ? 0 : null ),
            'overflow'       => isset( $overflow[ $stream ] ) ? count( (array) $overflow[ $stream ] ) : 0,
            'reconciliation' => $row !== null ? $row['reconciliation'] : null,
        );
    }
    return $out;
}

/** Shared API client — stateless, so one instance is enough. */
function personaizer_api() {
    static $api = null;
    if ( $api === null ) {
        $api = new Personaizer_Api();
    }
    return $api;
}

/** Shared content-sync instance — registers the hooks once. */
function personaizer_sync() {
    static $sync = null;
    if ( $sync === null ) {
        $sync = new Personaizer_Content_Sync( personaizer_api() );
    }
    return $sync;
}
add_action( 'plugins_loaded', 'personaizer_sync' );
register_deactivation_hook( __FILE__, [ 'Personaizer_Daily', 'on_deactivate' ] );
register_deactivation_hook( __FILE__, [ 'Personaizer_Backfill', 'on_deactivate' ] );
register_deactivation_hook( __FILE__, [ 'Personaizer_Manifest', 'on_deactivate' ] );
// The catch-up is a one-off event, but a deactivate between arming and firing would leave it
// scheduled — clear it so a disabled plugin never wakes to push.
register_deactivation_hook( __FILE__, function () { wp_clear_scheduled_hook( 'personaizer_catch_up' ); } );

/** WooCommerce catalog sync — wired only when WooCommerce is active (progressive enhancement). */
function personaizer_woocommerce_sync() {
    static $sync = null;
    if ( ! class_exists( 'WooCommerce' ) ) return null;
    require_once __DIR__ . '/includes/class-personaizer-woocommerce-sync.php';
    if ( $sync === null ) {
        $sync = new Personaizer_WooCommerce_Sync( personaizer_api() );
    }
    return $sync;
}
add_action( 'plugins_loaded', 'personaizer_woocommerce_sync', 20 );

// "Resync everything now" — the manual re-run of the catch-up that connecting starts for you.
// One action for posts AND products: the owner thinks "re-teach my AI", not "which stream?". It only
// arms the background worker, so it returns instantly no matter how big the site is.
/**
 * The version WordPress reads from the plugin file ON DISK.
 *
 * Deliberately a separate reading from PERSONAIZER_VERSION: get_plugin_data() opens and parses the file,
 * while the constant is baked into whatever bytecode PHP is executing. That difference is the only way
 * from inside PHP to tell "updated" from "updated but still running the old code".
 */
function personaizer_installed_version() {
    if ( ! function_exists( 'get_plugin_data' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    // No translation, no markup parsing — this is a version string, not something to display richly.
    $data = get_plugin_data( PERSONAIZER_PLUGIN_FILE, false, false );
    return isset( $data['Version'] ) ? (string) $data['Version'] : '';
}

/**
 * Drop this plugin's files from PHP's bytecode cache so the next request compiles what's on disk.
 *
 * Per-file invalidation rather than opcache_reset(): a reset throws away every other site sharing the
 * pool, which is not ours to do, and is disabled far more often than the targeted call.
 *
 * Best-effort by design — hosts that set opcache.restrict_api block this for WordPress entirely (which
 * is why WordPress's own wp_opcache_invalidate() can fail too). The banner that offers this is the real
 * safety net; this is the part that saves a click when it happens to be permitted.
 *
 * @return bool True when at least one file was actually invalidated.
 */
function personaizer_flush_own_opcache() {
    if ( ! function_exists( 'opcache_invalidate' ) ) return false;
    $dir   = plugin_dir_path( PERSONAIZER_PLUGIN_FILE );
    $files = array_merge( (array) glob( $dir . '*.php' ), (array) glob( $dir . 'includes/*.php' ) );
    $any   = false;
    foreach ( $files as $file ) {
        if ( is_string( $file ) && @opcache_invalidate( $file, true ) ) $any = true;
    }
    return $any;
}

/**
 * Invalidate our bytecode as soon as an update finishes installing.
 *
 * WordPress core already does this (wp_opcache_invalidate() since 5.5) — repeating it costs nothing and
 * covers the case where core's call is filtered out. Note this runs inside the OLD code; the point is the
 * NEXT request, which is exactly when it matters. Unattended auto-updates are the reason this is here:
 * a manual updater might notice something is off, a 3am cron update never will.
 */
add_action( 'upgrader_process_complete', function ( $upgrader, $hook_extra ) {
    if ( empty( $hook_extra['type'] ) || $hook_extra['type'] !== 'plugin' ) return;
    $self = plugin_basename( PERSONAIZER_PLUGIN_FILE );
    $ours = ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) )
        ? in_array( $self, $hook_extra['plugins'], true )
        : ( ! empty( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $self );
    if ( $ours ) personaizer_flush_own_opcache();
}, 10, 2 );

// "Try to clear it now" — the banner's one action.
add_action( 'admin_post_personaizer_flush_opcache', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
    cheik_admin_referer( 'personaizer_flush_opcache' );
    // Nothing is reported back on purpose: this request is still running the STALE code, so it cannot
    // observe its own success. The reload lands on fresh code if it worked — and if the banner is still
    // there afterwards, the host blocked it and the answer is a real cache clear or a PHP restart.
    personaizer_flush_own_opcache();
    wp_safe_redirect( add_query_arg( [ 'page' => 'personaizer' ], admin_url( 'admin.php' ) ) );
    exit;
} );

// "Check now" — start a stream manifest walk. It runs on WP-Cron in slices (a 10 000-product catalog does
// not fingerprint inside one request); the settings page shows its progress and, when it lands, what
// personaizer.com found — and the retry queue pushes whatever was missing or stale.
add_action( 'admin_post_personaizer_check', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
    cheik_admin_referer( 'personaizer_check' );
    Personaizer_Manifest::start();
    wp_safe_redirect( add_query_arg( [ 'page' => 'personaizer' ], admin_url( 'admin.php' ) ) );
    exit;
} );

add_action( 'admin_post_personaizer_resync', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
    cheik_admin_referer( 'personaizer_resync' );
    Personaizer_Backfill::start();
    // No "you clicked it" flag in the URL: the sync line on the page reads the real progress and says
    // either "Syncing… 4 of 28" or "Synced 1 second ago". Both are an honest answer to the click, and
    // a flag would only let the page claim work was happening after it had already finished.
    wp_safe_redirect( add_query_arg( [ 'page' => 'personaizer' ], admin_url( 'admin.php' ) ) );
    exit;
} );

// "Disconnect" — unlink this site without deleting the plugin.
//
// The consent screen tells owners they can disconnect at any time, so there has to be a way to do it
// that isn't "delete the plugin and hope". personaizer.com is told first, so the integration there freezes
// (its streams switch off — nothing is deleted); then everything WE stored on THIS site goes. The persona
// and everything it learned stay on personaizer.com, so reconnecting resumes rather than restarts.
add_action( 'admin_post_personaizer_disconnect', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
    cheik_admin_referer( 'personaizer_disconnect' );
    // Best-effort: a site that can't reach personaizer.com right now must still be able to let go locally;
    // the owner can freeze or delete the integration from the dashboard.
    $told = personaizer_api()->disconnect();
    if ( is_wp_error( $told ) ) personaizer_debug_log( 'disconnect: personaizer.com not told — ' . $told->get_error_message() );
    Personaizer_Data::clear();
    wp_safe_redirect( add_query_arg(
        [ 'page' => 'personaizer', 'pz_disconnected' => '1' ],
        admin_url( 'admin.php' )
    ) );
    exit;
} );

// Row actions on the Plugins screen — the shortcuts owners expect to find next to a chat plugin
// (and where anyone hunting for a reset looks first).
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
    $mine = [
        '<a href="' . esc_url( admin_url( 'admin.php?page=personaizer' ) ) . '">Settings</a>',
        '<a href="' . esc_url( admin_url( 'admin.php?page=personaizer&pz_view=system#pz-sysinfo' ) ) . '">System Info</a>',
    ];
    if ( personaizer_api()->is_configured() ) {
        $mine[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=personaizer_disconnect' ), 'personaizer_disconnect' ) ) . '"'
            . ' onclick="return confirm(\'Disconnect this site from PERSONAIZER?\\n\\nThe chat widget stops appearing, syncing stops, and this site\\\'s keys are removed. Your persona and everything it learned stay safe on personaizer.com.\');"'
            . ' style="color:#b32d2e;">Disconnect</a>';
    }
    return array_merge( $mine, $links );
} );

// ── One-click Connect (OAuth Authorization-Code + PKCE) ───────────────────────
// The owner clicks Connect → picks the brand, a widget persona and the streams on personaizer.com → we
// exchange a single-use, PKCE-bound code server-to-server for this site's integration credential. No client
// secret ships in the plugin — PKCE is the public-client auth.

/** This site's connect callback — the redirect_uri the code is bound to (and re-sent at exchange). */
function personaizer_connect_callbaik_url() {
    return admin_url( 'admin-post.php?action=personaizer_connect_callback' );
}

/** base64url without padding. */
function personaizer_b64url( $bin ) {
    return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
}

/**
 * What this site could teach — every stream with its label and published count. Shown on the consent screen
 * BEFORE approving ("it will learn 7 pages, 3 posts and 18 products") and reported to personaizer.com after
 * every connect and daily, so the owner switches streams on from a list that reflects the site as it is now.
 *
 * @return array<int,array{stream:string,label:string,count:int}>
 */
function personaizer_inventory() {
    $out = array();
    foreach ( personaizer_streams() as $stream => $meta ) {
        $out[] = array(
            'stream' => $stream,
            'label'  => $meta['label'],
            'count'  => personaizer_published_count( $meta['post_type'] ),
            'type'   => $meta['type'],
        );
    }
    return $out;
}

/** @return array<string,int> stream => published count. */
function personaizer_syncable_counts() {
    $counts = array();
    foreach ( personaizer_inventory() as $row ) $counts[ $row['stream'] ] = $row['count'];
    return $counts;
}

/** Report the inventory to personaizer.com — after a connect, and daily so a new custom type shows up there. */
function personaizer_report_inventory() {
    if ( ! personaizer_api()->is_configured() ) return;
    $result = personaizer_api()->report_inventory( personaizer_inventory() );
    if ( is_wp_error( $result ) ) personaizer_debug_log( 'inventory report failed: ' . $result->get_error_message() );
}
add_action( Personaizer_Daily::HOOK, 'personaizer_report_inventory' );

// wp_safe_redirect() only follows redirects to the current site by default — allow-list the PERSONAIZER
// app host so the (external, by design) redirect below still actually leaves the site instead of silently
// landing on home_url().
add_filter( 'allowed_redirect_hosts', function ( $hosts ) {
    $hosts[] = wp_parse_url( PERSONAIZER_APP_URL, PHP_URL_HOST );
    return $hosts;
} );

// Start: mint a PKCE verifier/challenge + state, stash the verifier server-side, send the owner to consent.
add_action( 'admin_post_personaizer_connect_start', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
    cheik_admin_referer( 'personaizer_connect' );

    $verifier  = personaizer_b64url( random_bytes( 48 ) );                  // 64 chars — within PKCE 43..128
    $challenge = personaizer_b64url( hash( 'sha256', $verifier, true ) );   // S256
    $state     = personaizer_b64url( random_bytes( 16 ) );

    // The verifier NEVER leaves our server — only its challenge travels. Keyed by state for the callback.
    set_transient( 'personaizer_pkce_' . $state, $verifier, 10 * MINUTE_IN_SECONDS );

    // Top-level nav to the (external) consent screen — safe because PERSONAIZER_APP_URL's host is
    // allow-listed above.
    // site_url lets the consent screen find or create the brand for this site and offer "create a persona
    // for this site"; inventory lets it show WHAT will be learned — the thing being consented to — as real
    // numbers the owner ticks, rather than this plugin deciding for them after the fact.
    $args = array(
        'redirect_uri'   => personaizer_connect_callbaik_url(),
        'code_challenge' => $challenge,
        'state'          => $state,
        'platform'       => 'wordpress',
        'site'           => rawurlencode( get_bloginfo( 'name' ) ),
        'site_url'       => rawurlencode( home_url() ),
        'inventory'      => rawurlencode( wp_json_encode( personaizer_inventory() ) ),
    );

    // Reconnecting is a different job from connecting: this site already IS a integration there. Telling the
    // screen so lets it open on the integration's brand and streams as they are right now, and mark the widget
    // persona that is live here — instead of offering to build a second one.
    if ( personaizer_api()->is_configured() ) {
        $args['connected'] = '1';
        $args['integration'] = get_option( 'personaizer_integration_id', '' );
        $args['scope']     = implode( ',', personaizer_current_streams() );
        $args['persona']   = get_option( 'personaizer_persona_id', '' );
    }

    wp_safe_redirect( add_query_arg( $args, PERSONAIZER_CONNECT_URL ) );
    exit;
} );

// Callback: consent redirected back with ?code&state. The `state` (matched against our transient) is the
// CSRF guard — the external redirect can't carry a WP nonce. Exchange the code + verifier server-to-server.
add_action( 'admin_post_personaizer_connect_callback', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

    $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
    $code  = isset( $_GET['code'] )  ? sanitize_text_field( wp_unslash( $_GET['code'] ) )  : '';
    $ok    = false;

    $verifier = $state !== '' ? get_transient( 'personaizer_pkce_' . $state ) : false;
    if ( $verifier && $code !== '' ) {
        delete_transient( 'personaizer_pkce_' . $state );  // single-use on our side too

        $resp = wp_remote_post( rtrim( PERSONAIZER_API_URL, '/' ) . '/api/integrations/connect/token', array(
            'timeout' => 20,
            'headers' => array( 'Content-Type' => 'application/json' ),
            // The Core /api surface is snake_case (its own clients rely on a camel->snake interceptor the
            // plugin doesn't have), so send snake_case keys explicitly.
            //
            // site_profile is this site describing itself — the same seven facts PERSONAIZER would
            // otherwise scrape its homepage and run an LLM to infer. Sent here because this is the one
            // server-to-server hop in the flow: it needs no size budget, no auth of its own (the code
            // proves us), and nothing about it is visible to the browser. It's used only if a persona
            // was created for this site moments ago; reconnecting to an existing one ignores it.
            'body'    => wp_json_encode( array(
                'code'          => $code,
                'code_verifier' => $verifier,
                'redirect_uri'  => personaizer_connect_callbaik_url(),
                'site_profile'  => Personaizer_Site_Profile::build(),
            ) ),
        ) );

        if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
            $data = json_decode( wp_remote_retrieve_body( $resp ), true );
            if ( ! empty( $data['integration_id'] ) && ! empty( $data['integration_key'] ) ) {
                update_option( 'personaizer_integration_id', sanitize_text_field( $data['integration_id'] ) );
                update_option( 'personaizer_integration_key', sanitize_text_field( $data['integration_key'] ) );
                update_option( 'personaizer_brand_id', sanitize_text_field( (string) ( $data['brand_id'] ?? '' ) ) );
                // The widget persona is optional — a site may sync knowledge without embedding a chat. A
                // reconnect without one removes the widget; the owner chose that on the consent screen.
                $old_persona = get_option( 'personaizer_persona_id', '' );
                if ( ! empty( $data['persona_id'] ) ) {
                    update_option( 'personaizer_persona_id', sanitize_text_field( $data['persona_id'] ) );
                    if ( ! empty( $data['identity_secret'] ) ) {
                        update_option( 'personaizer_identity_secret', sanitize_text_field( $data['identity_secret'] ) );
                        // Recognition (sending a signed-in visitor's name/email/phone to the AI) stays OFF until the
                        // owner turns it on in Settings — explicit opt-in, per WordPress.org privacy guidance.
                    }
                    Personaizer_Api::forget_profile( $data['persona_id'], PERSONAIZER_API_URL );
                } else {
                    delete_option( 'personaizer_persona_id' );
                    delete_option( 'personaizer_identity_secret' );
                }
                if ( $old_persona !== '' ) Personaizer_Api::forget_profile( $old_persona, PERSONAIZER_API_URL );

                // The streams the owner ticked are already switched on there; read them back, tell personaizer.com
                // what this site holds, and start teaching. The manifest that follows the backfill is what makes
                // the streams 1:1 with the site.
                Personaizer_Data::clear_retired();
                personaizer_api()->forget_integration();
                personaizer_integration( true );
                personaizer_report_inventory();
                Personaizer_Backfill::start();
                $ok = true;
            }
        }
    }

    wp_safe_redirect( add_query_arg(
        array( 'page' => 'personaizer', $ok ? 'pz_connected' : 'pz_connect_error' => '1' ),
        admin_url( 'admin.php' )
    ) );
    exit;
} );

// ── Register settings ─────────────────────────────────────────────────────────

add_action( 'admin_init', function () {
    // ── Only real settings are registered here. ──
    //
    // The connection itself — integration id + key, persona id, identity secret — is deliberately NOT
    // registered. Connect provisions them with update_option(); they were never things an owner chooses. And
    // registering them would force us to render them: options.php walks every option in the group and
    // does `update_option($option, null)` for any that isn't in $_POST, so a registered-but-unrendered
    // credential is silently DELETED the first time someone hits Save — disconnecting the site.
    //
    // Appearance/behaviour (theme, position, accent, title, auto-open, nudge) aren't here either: they
    // live on the persona (its Widget tab). Neither are the streams: which streams sync is the owner's choice on
    // personaizer.com (the integration), read back live — a local copy would be a second source of truth.
    //
    // The on/off switch for recognising signed-in customers IS a choice. The Identity Secret it uses
    // is not — Connect provisions that (see the note above about why it must stay unregistered).
    register_setting( 'personaizer_chat', 'personaizer_identify_users', [
        'sanitize_callback' => function ( $value ) { return $value === '1' ? '1' : ''; },
        'default'           => '',
    ] );
} );

// ── Top-level sidebar menu ────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
    $svg = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"><path fill="#a7aaad" d="M12 2C6.477 2 2 6.253 2 11.5c0 2.394.924 4.582 2.443 6.244L2.5 21.5l4.16-1.38A10.06 10.06 0 0 0 12 21c5.523 0 10-4.253 10-9.5S17.523 2 12 2Z"/><circle cx="8" cy="11.5" r="1.2" fill="#060a16"/><circle cx="12" cy="11.5" r="1.2" fill="#060a16"/><circle cx="16" cy="11.5" r="1.2" fill="#060a16"/></svg>' );
    add_menu_page( 'PERSONAIZER', 'PERSONAIZER', 'manage_options', 'personaizer', 'personaizer_chat_page', $svg, 30 );
} );

// The settings page's CSS/JS, registered/enqueued (not raw <style>/<script> echoes in the page callback)
// so WordPress's own dependency, caching and cache-busting rules apply. Scoped to just this page via the
// admin_enqueue_scripts hook suffix — 'toplevel_page_personaizer' matches the slug passed to
// add_menu_page() above.
add_action( 'admin_enqueue_scripts', function ( $hook_suffix ) {
    if ( $hook_suffix !== 'toplevel_page_personaizer' ) return;

    wp_enqueue_style(
        'personaizer-admin-page',
        plugins_url( 'assets/admin-page.css', PERSONAIZER_PLUGIN_FILE ),
        [],
        PERSONAIZER_VERSION
    );

    wp_register_script(
        'personaizer-admin-page',
        plugins_url( 'assets/admin-page.js', PERSONAIZER_PLUGIN_FILE ),
        [],
        PERSONAIZER_VERSION,
        [ 'in_footer' => true ]
    );
    wp_enqueue_script( 'personaizer-admin-page' );
} );

// ── Settings page ─────────────────────────────────────────────────────────────

/**
 * The admin screen. Deliberately NOT a settings page.
 *
 * Everything the owner configures — look, greeting, FAQ, the persona itself — lives on
 * personaizer.com, so this screen answers three questions at a glance and then gets out of the way:
 * is it connected, what does my AI know, and where do I go to change things.
 *
 * Credentials appear nowhere. Connect provisions them and nothing here needs them, so showing an
 * owner three secrets they never chose was pure noise — see the register_setting() note for the
 * WordPress quirk that used to force them on screen.
 */
function personaizer_chat_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $id              = get_option( 'personaizer_persona_id', '' );
    $identify_users  = get_option( 'personaizer_identify_users', '' ) === '1';
    // Connected = this site is a integration on personaizer.com. The widget persona is a separate, optional
    // choice made on the consent screen: a site can sync knowledge without embedding a chat.
    $active          = personaizer_api()->is_configured();
    $has_widget      = $active && $id !== '';

    // Name/avatar of the widget persona (cached 5 min). Null when the API is unreachable — the
    // screen degrades to "Connected" without a name rather than blocking on it.
    $profile   = $has_widget ? personaizer_api()->get_profile() : null;
    $pz_name   = $profile['name'] ?? '';
    $pz_avatar = $profile['avatar_url'] ?? '';

    $progress  = Personaizer_Backfill::progress();
    $last_sync = (int) get_option( 'personaizer_last_sync', 0 );

    // The integration as personaizer.com sees it — read ONCE for the whole page (rows, the summary, the plan
    // card). Live when reachable, the last good answer otherwise; $pz_reachable tells the two apart so the
    // page never presents a stale number as a fresh one.
    $pz_integration = $active ? personaizer_integration() : null;
    $pz_reachable = $active && ! is_wp_error( personaizer_api()->get_integration() );

    // If items are waiting for plan space or a retry, arm a background catch-up. Non-blocking (a one-off
    // cron event), so the page still renders instantly; the handler re-checks real headroom before pushing,
    // so arming while still over-limit costs nothing but means an upgrade heals the moment the owner reopens this.
    if ( $active && ( personaizer_overflow_count() > 0 || personaizer_retry_count() > 0 ) ) {
        personaizer_arm_catch_up();
    }
    ?>
    <div class="pz-page">

        <?php
        // STALE BYTECODE WARNING.
        //
        // PERSONAIZER_VERSION comes from the code PHP is actually executing; WordPress reads the version
        // by parsing the plugin file off disk. They can only disagree when OPcache is still serving a
        // previous build — the files updated, the running code did not.
        //
        // This is worth a banner because the failure is otherwise invisible and total: the plugin reports
        // the new version, the update looks successful, and every fix in it silently does nothing. Finding
        // that out by reasoning backwards from wrong data costs hours; the two numbers already know.
        $pz_on_disk = personaizer_installed_version();
        if ( $pz_on_disk !== '' && $pz_on_disk !== PERSONAIZER_VERSION ) : ?>
            <div class="pz-quota" style="margin-bottom:14px;">
                <div class="pz-quota-head">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#e8b339" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                    <div class="pz-quota-head-text">
                        <strong>This site is running an older copy of the plugin than is installed</strong>
                        <span>
                            Version <?php echo esc_html( $pz_on_disk ); ?> is installed, but PHP is still running
                            <?php echo esc_html( PERSONAIZER_VERSION ); ?> from its cache — so nothing in the update is
                            taking effect yet. Clear your site&apos;s cache, or ask your host to restart PHP.
                        </span>
                    </div>
                </div>
                <div class="pz-quota-actions">
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=personaizer_flush_opcache' ), 'personaizer_flush_opcache' ) ); ?>" class="pz-save-btn">Try to clear it now</a>
                </div>
            </div>
        <?php endif; ?>

        <!-- ── Header ── -->
        <div class="pz-header">
            <div class="pz-header-left">
                <a href="https://personaizer.com" target="_blank" rel="noopener" class="pz-logo">
                    <div class="pz-logo-icon">✦</div>
                    <span class="pz-logo-name">PERSONAIZER</span>
                </a>
                <div class="pz-header-divider"></div>
                <span class="pz-header-subtitle">Chat Widget</span>
            </div>
            <div class="pz-header-right">
                <?php if ( $has_widget ) : ?>
                <div class="pz-status-pill active"><span class="pz-status-dot"></span> Live on your site</div>
                <?php elseif ( $active ) : ?>
                <div class="pz-status-pill active"><span class="pz-status-dot"></span> Connected</div>
                <?php else : ?>
                <div class="pz-status-pill inactive"><span class="pz-status-dot"></span> Not connected</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Main content ── -->
        <div class="pz-content">


            <?php if ( isset( $_GET['pz_disconnected'] ) ) : ?>
            <div class="pz-notice good">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2dbd4e" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9 12l2 2 4-4"/></svg>
                Disconnected. This site&apos;s keys were removed, the widget is no longer shown and syncing has stopped — your persona and everything it learned are untouched on personaizer.com.
            </div>
            <?php endif; ?>

            <?php if ( isset( $_GET['pz_connect_error'] ) ) : ?>
            <div class="pz-notice bad">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#e65a5a" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Connection didn&apos;t complete. Please try again.
            </div>
            <?php endif; ?>

            <?php if ( ! $active ) : ?>

                <?php // An install upgraded from 1.x still holds its persona but no integration: 2.0 syncs as a
                      // integration, so the site has to be approved once more. Say so, rather than presenting a
                      // site that was working yesterday as one that was never connected. ?>
                <?php if ( $id !== '' ) : ?>
                <div class="pz-notice bad">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#e65a5a" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    PERSONAIZER 2.0 connects your site differently — click Connect once more to approve it again. Your persona and everything it learned are untouched.
                </div>
                <?php endif; ?>

                <!-- ══ Not connected: one screen, one action ══ -->
                <div class="pz-card">
                    <div class="pz-card-head">
                        <div class="pz-card-head-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#7dd3fc" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21 2-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0 3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                        </div>
                        <div class="pz-card-head-text">
                            <h3>Add an AI chat to your site</h3>
                            <p>One click — we build your AI, set up the keys, and teach it your content</p>
                        </div>
                    </div>
                    <div class="pz-card-body">
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=personaizer_connect_start' ), 'personaizer_connect' ) ); ?>"
                           class="pz-save-btn">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                            Connect to PERSONAIZER
                        </a>
                        <p class="pz-hint" style="margin-top:10px;">
                            No account yet? You can create one on the way — it&apos;s part of the same step.
                        </p>
                    </div>
                </div>

            <?php else : ?>

                <?php
                // ══ The hero IS the status ══
                //
                // One card, two states. The previous version showed a "Setting up…" panel stacked above
                // a hero announcing "Live — answering visitors right now", which is two cards
                // contradicting each other on the same screen. A persona is either still being
                // assembled or it's ready; the same region says which.
                //
                // `building` + `stage` are the server's own verdict, never inferred here: the persona
                // gets its name one stage BEFORE its portrait, so watching the name declares victory
                // while the picture is still rendering, and a failed build never renames at all — a
                // name-watcher would spin forever.
                $pz_building = ! empty( $profile['building'] );
                $pz_step     = personaizer_build_step( $profile['stage'] ?? '' );
                ?>

                <div class="pz-card">
                    <?php if ( ! $has_widget ) : ?>
                    <div class="pz-hero">
                        <div class="pz-hero-avatar">✦</div>
                        <div class="pz-hero-text">
                            <h2><?php echo esc_html( $pz_integration['brand']['display_name'] ?: ( $pz_integration['brand']['slug'] ?? 'Your brand' ) ); ?></h2>
                            <p>Connected — this site keeps your brand&apos;s knowledge up to date. No chat widget is embedded here; reconnect to pick one.</p>
                        </div>
                        <div class="pz-hero-actions">
                            <a href="<?php echo esc_url( personaizer_app_url( '/knowledge' ) ); ?>" target="_blank" rel="noopener" class="pz-save-btn" style="padding:6px 14px;font-size:12px;">
                                Open PERSONAIZER
                            </a>
                        </div>
                    </div>
                    <?php else : ?>
                    <div class="pz-hero">
                        <?php if ( $pz_avatar ) : ?>
                            <?php
                            // Click to see it properly. The portrait is the one thing on this page an
                            // owner might actually want to look at, and 52px is a thumbnail of a
                            // 1024px image — so open the real thing rather than making them dig the URL
                            // out of the page source. A plain link, not a lightbox: it's a picture, the
                            // browser already has a viewer for those, and it costs no script.
                            //
                            // decoding=async keeps a heavy PNG off the main thread while the rest of
                            // the page paints. It does NOT make the download smaller — that's a server
                            // problem (this file is ~1.3MB for a 52px slot; see the note in the README).
                            ?>
                            <a href="<?php echo esc_url( $pz_avatar ); ?>" target="_blank" rel="noopener"
                               class="pz-hero-avatar-link" title="View full size">
                                <img class="pz-hero-avatar<?php echo $pz_building ? ' pz-hero-avatar-wip' : ''; ?>"
                                     src="<?php echo esc_url( $pz_avatar ); ?>" alt=""
                                     width="52" height="52" decoding="async" />
                            </a>
                        <?php else : ?>
                            <div class="pz-hero-avatar<?php echo $pz_building ? ' pz-hero-avatar-wip' : ''; ?>">
                                <?php echo esc_html( $pz_name !== '' ? mb_strtoupper( mb_substr( $pz_name, 0, 1 ) ) : '✦' ); ?>
                            </div>
                        <?php endif; ?>

                        <div class="pz-hero-text">
                            <h2><?php echo esc_html( $pz_name !== '' ? $pz_name : 'Your AI' ); ?></h2>

                            <?php if ( $pz_building ) : ?>
                                <p><?php echo esc_html( $pz_step['label'] ); ?>…</p>
                                <div class="pz-bar" role="progressbar"
                                     aria-valuenow="<?php echo (int) $pz_step['percent']; ?>" aria-valuemin="0" aria-valuemax="100">
                                    <span style="width:<?php echo (int) $pz_step['percent']; ?>%"></span>
                                </div>
                                <p class="pz-hero-note">
                                    Your chat is already live — this only makes it smarter. You can leave this page.
                                </p>
                            <?php else : ?>
                                <p><span class="pz-live-dot"></span> Live on your site — answering visitors right now.</p>
                            <?php endif; ?>
                        </div>

                        <div class="pz-hero-actions">
                            <a href="<?php echo esc_url( home_url() ); ?>" target="_blank" rel="noopener" class="pz-ext-link">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                Preview
                            </a>
                            <a href="<?php echo esc_url( personaizer_app_url( '/persona/' . rawurlencode( $id ) ) ); ?>" target="_blank" rel="noopener" class="pz-save-btn" style="padding:6px 14px;font-size:12px;">
                                Open PERSONAIZER
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ( $pz_integration !== null && $pz_integration['status'] === 'disconnected' ) : ?>
                <div class="pz-notice bad" style="margin-top:12px;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#e65a5a" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    This site was disconnected on personaizer.com — nothing syncs until you reconnect. Nothing was deleted.
                </div>
                <?php endif; ?>

                <form method="post" action="options.php">
                    <?php settings_fields( 'personaizer_chat' ); ?>

                    <p class="pz-section-label">What your AI learns from this site</p>
                    <div class="pz-card">
                        <div class="pz-card-body">
                            <?php
                            // Read-only here, on purpose. Which streams sync is the owner's choice on personaizer.com
                            // (the site's integration page), where every source of the brand sits side by side —
                            // this screen reports the choice and what each stream holds, and links there to change it.
                            $pz_stream_states = personaizer_stream_states( $pz_integration );
                            foreach ( $pz_stream_states as $pz_stream => $pz_state ) {
                                personaizer_stream_row( $pz_stream, $pz_state );
                            }
                            ?>
                            <p class="pz-signpost" style="margin:10px 0 14px;">
                                Switch streams on or off on personaizer.com —
                                <a href="<?php echo esc_url( personaizer_app_url( '/knowledge' ) ); ?>" target="_blank" rel="noopener">manage what your AI uses →</a>
                            </p>

                            <?php
                            // The ONLY place the content sync speaks.
                            //
                            // A banner used to announce "Teaching your AI — runs in the background"
                            // whenever ?pz_resyncing=1 was in the URL. But that flag records a CLICK,
                            // not a state: a resync of this size finishes in about half a second, so
                            // the banner was still claiming work was underway directly above this line
                            // saying "Synced 1 second ago". A second voice on the same fact can only
                            // ever agree or lie. This line reads the real progress, so it's the one
                            // that stays — and it inherits the reassurance the banner was carrying.
                            ?>
                            <?php
                            // One honest status, from LIVE signals — what the AI actually holds (per-stream
                            // doc counts) vs the site, plus the overflow queue and the plan's room — never a
                            // stale counter. Five states, never success AND failure at once:
                            //   • running    → progress only
                            //   • plan full  → the amber card (a ceiling — items are waiting)
                            //   • behind     → "partially synced" + Resync (a gap the queue doesn't explain,
                            //                   e.g. a transient failure — one click re-tries everything)
                            //   • over limit → amber note (caught up, but past the ceiling — new is frozen; the
                            //                   downgrade case: nothing waiting, nothing lost)
                            //   • caught up  → green "Synced"
                            // The old red "N couldn't be synced" note is gone: it read a cumulative backfill
                            // counter that stayed stale after items healed, so it resurfaced under a real sync.
                            $pz_overflow = personaizer_overflow_count();

                            // One live gap, reachable only (a transient API outage must not read as "behind"):
                            //   switched-on streams → $pz_synced / $pz_total: the card's "X of Y", and whether the AI
                            //                    is genuinely BEHIND the site ($pz_behind). This is the truth —
                            //                    the overflow queue is only bookkeeping and CAN go stale (a
                            //                    deleted item never cleared), so "26 of 26" must never say
                            //                    "waiting"; the card is gated on $pz_behind, not the raw count.
                            $pz_total = 0; $pz_synced = 0;
                            // ready = docs the backend has finished processing (embedded → answerable).
                            $pz_ready = 0; $pz_ready_avail = $pz_reachable;
                            if ( $pz_reachable ) {
                                foreach ( $pz_stream_states as $ls ) {
                                    if ( empty( $ls['enabled'] ) ) continue;
                                    $s = ( $ls['known'] === null ) ? 0 : min( (int) $ls['known'], (int) $ls['count'] );
                                    $pz_total += (int) $ls['count']; $pz_synced += $s;
                                    if ( $ls['ready'] === null ) { $pz_ready_avail = false; }
                                    else { $pz_ready += min( (int) $ls['ready'], (int) $ls['count'] ); }
                                }
                            }
                            $pz_behind  = $pz_reachable && $pz_synced < $pz_total;   // AI missing some items of a switched-on stream
                            // Uploaded but not yet searchable: the AI HAS these items ($pz_synced) but only
                            // $pz_ready of them have finished processing. This is the honest "still working"
                            // state the flat "Synced — new edits sync automatically" used to paper over.
                            $pz_processing = ( $pz_ready_avail && $pz_ready < $pz_synced ) ? ( $pz_synced - $pz_ready ) : 0;

                            // The plan's room. Read LIVE when settled (like the doc-count badges above), so a
                            // just-changed limit — e.g. a downgrade you applied seconds ago in the admin panel —
                            // reflects on the next reload instead of lagging the 5-min cache. Skipped while
                            // syncing: that state shows progress only, and the page self-reloads every few
                            // seconds then, so a live call each tick would be wasteful.
                            // over_limit = usage past the ceiling with nothing waiting — the "upgraded, synced
                            // everything, then downgraded" case: existing docs are kept and served, only NEW
                            // content is frozen out.
                            $pz_limits     = $progress['running'] ? null : personaizer_api()->get_limits( true );
                            $pz_plan       = ( is_array( $pz_limits ) && $pz_limits['plan_name'] !== '' ) ? $pz_limits['plan_name'] : '';
                            $pz_over_limit = is_array( $pz_limits ) && $pz_limits['ku_limit'] !== null && $pz_limits['ku_used'] > $pz_limits['ku_limit'];

                            if ( $progress['running'] ) : ?>
                                <div class="pz-sync-state">
                                    <svg class="pz-spin" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#7dd3fc" stroke-width="2.5"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                                    Syncing… <?php echo (int) $progress['done']; ?> of <?php echo (int) $progress['total']; ?> — this runs in the background, so you can leave this page.
                                </div>
                            <?php elseif ( $pz_overflow > 0 && ( ! $pz_reachable || $pz_behind ) ) :
                                // Plan full AND the AI is genuinely behind the site (or unreachable, so we can't
                                // tell — trust the queue then). $pz_synced / $pz_total / $pz_plan come from up top.
                                // Gating on $pz_behind is what stops a STALE overflow entry (e.g. a deleted item)
                                // rendering the contradictory "26 of 26 … the rest are waiting".
                            ?>
                                <div class="pz-quota">
                                    <div class="pz-quota-head">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#e8b339" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                                        <div class="pz-quota-head-text">
                                            <?php if ( $pz_reachable ) : ?>
                                                <strong>Your AI has learned <?php echo (int) $pz_synced; ?> of your <?php echo (int) $pz_total; ?> item<?php echo $pz_total === 1 ? '' : 's'; ?></strong>
                                            <?php else : ?>
                                                <strong><?php echo (int) $pz_overflow; ?> item<?php echo $pz_overflow === 1 ? '' : 's'; ?> couldn&apos;t be synced</strong>
                                            <?php endif; ?>
                                            <span>Your <?php echo $pz_plan !== '' ? esc_html( $pz_plan ) . ' plan' : 'plan'; ?> is full, so the rest are waiting. Upgrade and they sync themselves — nothing is deleted or lost.</span>
                                        </div>
                                        <?php if ( $pz_plan !== '' ) : ?><span class="pz-plan-tag"><?php echo esc_html( $pz_plan ); ?></span><?php endif; ?>
                                    </div>

                                    <?php if ( $pz_reachable && $pz_total > 0 ) :
                                        $pz_pct = (int) round( $pz_synced / $pz_total * 100 );
                                    ?>
                                    <div class="pz-quota-meter">
                                        <span class="pz-quota-bar gap"><span style="width:<?php echo (int) $pz_pct; ?>%"></span></span>
                                    </div>
                                    <?php endif; ?>

                                    <?php // Just the one honest action. "Delete items to free space" — the backend's
                                          // generic quota advice — is nonsense here: the knowledge IS the site's own
                                          // pages/products, so removing it to fit the plan defeats the whole point, and
                                          // it flatly contradicts "nothing is deleted or lost" three lines up. ?>
                                    <div class="pz-quota-actions">
                                        <a href="<?php echo esc_url( personaizer_upgrade_url() ); ?>" target="_blank" rel="noopener" class="pz-save-btn">
                                            Upgrade plan
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                                        </a>
                                    </div>
                                </div>
                            <?php elseif ( $pz_behind ) : ?>
                                <?php // Behind, but the plan has room (nothing queued for quota) — something
                                      // didn't land (a transient failure, or content that failed before it was
                                      // tracked). Say so plainly and offer the one fix that re-tries everything. ?>
                                <?php // A NONCED LINK, not a form: this whole status sits inside the settings
                                      // <form action="options.php">, and a nested <form> is invalid HTML — the
                                      // browser drops it and the button submits the OUTER form (→ options.php).
                                      // The footer "Reconnect" is a nonced admin-post link for the same reason. ?>
                                <div class="pz-sync-state partial">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#e8b339" stroke-width="2.5"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                                    Your AI has <?php echo (int) $pz_synced; ?> of your <?php echo (int) $pz_total; ?> items — some didn&apos;t sync.
                                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=personaizer_resync' ), 'personaizer_resync' ) ); ?>" class="pz-linkbtn" style="margin-left:4px;text-decoration:underline;">Resync everything</a>
                                </div>
                                <?php
                                // WHY it didn't sync, in the API's own words. The plugin has always recorded this
                                // (personaizer_last_error) and never shown it, so "some didn't sync" was a dead end:
                                // the only way to learn the reason was to switch WP_DEBUG on in wp-config.php and
                                // read debug.log — which is not a reasonable thing to ask of someone running a live
                                // store. The reason is the whole difference between "click Resync again" and "this
                                // one item will never sync until something changes".
                                $pz_err = get_option( 'personaizer_last_error', array() );
                                $pz_retry = personaizer_retry_count();
                                if ( is_array( $pz_err ) && ! empty( $pz_err['message'] ) ) : ?>
                                    <div class="pz-sync-state" style="opacity:.85;margin-top:2px;">
                                        <span style="opacity:.7;">Last error:</span>
                                        <?php echo esc_html( $pz_err['message'] ); ?>
                                        <?php if ( ! empty( $pz_err['at'] ) ) : ?>
                                            <span style="opacity:.6;">(<?php echo esc_html( human_time_diff( (int) $pz_err['at'] ) ); ?> ago)</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php // Items queued for automatic retry — proof they are tracked, not lost. ?>
                                <?php if ( $pz_retry > 0 ) : ?>
                                    <div class="pz-sync-state" style="opacity:.85;margin-top:2px;">
                                        <span style="opacity:.7;"><?php echo (int) $pz_retry; ?> item<?php echo $pz_retry === 1 ? '' : 's'; ?> queued for automatic retry — nothing is lost.</span>
                                    </div>
                                <?php endif; ?>
                            <?php elseif ( $pz_over_limit ) : ?>
                                <?php // Caught up, but past the ceiling — the downgrade case. Nothing is waiting
                                      // and nothing is lost; usage just exceeds the plan, so NEW content is frozen
                                      // until they free space or upgrade. Calm amber, not the red of a failure. ?>
                                <div class="pz-quota">
                                    <div class="pz-quota-head">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#e8b339" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                                        <div class="pz-quota-head-text">
                                            <strong>You&apos;re over your <?php echo $pz_plan !== '' ? esc_html( $pz_plan ) . ' plan' : 'plan'; ?>&apos;s limit</strong>
                                            <span>Your AI knows everything on your site right now, but you can&apos;t add new content until you upgrade or remove some. Nothing is lost.</span>
                                        </div>
                                        <?php if ( $pz_plan !== '' ) : ?><span class="pz-plan-tag"><?php echo esc_html( $pz_plan ); ?></span><?php endif; ?>
                                    </div>
                                    <div class="pz-quota-actions">
                                        <a href="<?php echo esc_url( personaizer_upgrade_url() ); ?>" target="_blank" rel="noopener" class="pz-save-btn">
                                            Upgrade plan
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                                        </a>
                                    </div>
                                </div>
                            <?php elseif ( $pz_processing > 0 ) : ?>
                                <?php // Everything is uploaded and the AI holds it, but some docs are still
                                      // being processed on our side (embedding, and building product schemas)
                                      // so they aren't answerable yet. Show the real split — this is the state
                                      // the flat "Synced — new edits sync automatically" used to hide. ?>
                                <div class="pz-sync-state">
                                    <svg class="pz-spin" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#7dd3fc" stroke-width="2.5"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                                    <?php echo (int) $pz_ready; ?> of <?php echo (int) $pz_synced; ?> items ready — the rest are still being processed and become searchable within a few minutes.
                                </div>
                            <?php elseif ( $last_sync > 0 ) : ?>
                                <div class="pz-sync-state">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#2dbd4e" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>
                                    Synced <?php echo esc_html( human_time_diff( $last_sync, time() ) ); ?> ago — new edits sync automatically.
                                </div>
                            <?php else : ?>
                                <div class="pz-sync-state">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#6c7aa3" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/></svg>
                                    Nothing synced yet.
                                </div>
                            <?php endif; ?>

                            <?php
                            // Recognition sits in the SAME card as the knowledge streams, under a divider.
                            // Both answer one question — "what does it know about this site and the people
                            // on it?" — and a whole card plus a shouty label for one toggle was the page
                            // shouting about its own structure instead of the owner's decisions.
                            ?>
                            <div class="pz-card-split">
                                <input type="hidden" name="personaizer_identify_users" value="" />
                                <label class="pz-toggle-row" for="pz_identify">
                                    <div class="pz-toggle">
                                        <input type="checkbox" id="pz_identify" name="personaizer_identify_users" value="1" <?php checked( $identify_users ); ?>>
                                        <span class="pz-toggle-track"></span>
                                        <span class="pz-toggle-knob"></span>
                                    </div>
                                    <?php // Says what it actually does. The old copy promised only "greet them by name" while the
                                          // page quietly sent each signed-in customer's email and phone too — so an owner couldn't
                                          // disclose it in their privacy policy, because nothing told them it was happening. They
                                          // are the data controller here; the gap was theirs to answer for and ours to have made. ?>
                                    <div class="pz-toggle-copy">
                                        <strong>Recognize signed-in customers</strong>
                                        <span>Their name, email and phone from their account go to your AI, so it greets them
                                        and your team sees who they are — and they're never asked for details you already hold.
                                        Mention this in your privacy policy. Anonymous visitors are unaffected.</span>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>

                    <?php // One line, not a card: it's a signpost, and a card would imply something to do here. ?>
                    <p class="pz-signpost">
                        Colors, greeting and FAQ live on the persona, with a live preview —
                        <a href="<?php echo esc_url( personaizer_app_url( '/persona/' . rawurlencode( $id ) . '?tab=widget' ) ); ?>" target="_blank" rel="noopener">open the Widget tab →</a>
                    </p>

                    <div class="pz-save-row">
                        <button type="submit" class="pz-save-btn">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            Save
                        </button>
                        <span class="pz-footer-links">
                            Need help? <a href="https://personaizer.com" target="_blank" rel="noopener">personaizer.com</a>
                        </span>
                    </div>
                </form>

                <?php
                // ── Footer: the rare things, named for what they are ──
                //
                // This replaced an "Advanced" drawer. Half of what lived in there — persona id, secret
                // key, identity secret — only existed to stop options.php nulling them on Save; now that
                // they're unregistered, they have no reason to be on screen at all, and nobody has to be
                // told they own three credentials they never chose. Custom post types moved up into the
                // knowledge card, where they're shown only to the sites that have any.
                //
                // What's left is genuinely rare, so it's small and last — but named. "Advanced" tells you
                // nothing; "Reconnect", "Disconnect", "System info" tell you exactly what's behind them.
                ?>
                <div class="pz-tools">
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=personaizer_connect_start' ), 'personaizer_connect' ) ); ?>"
                       class="pz-tool-link" title="Point this site at a different persona">Reconnect</a>
                    <span class="pz-tool-sep">·</span>

                    <?php // Its own form: destructive, and the settings form posts to options.php. ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;"
                          onsubmit="return confirm('Disconnect this site from PERSONAIZER?\n\nThe chat widget stops appearing and this site\'s keys are removed. Your persona and everything it learned stay safe on personaizer.com.');">
                        <?php wp_nonce_field( 'personaizer_disconnect' ); ?>
                        <input type="hidden" name="action" value="personaizer_disconnect" />
                        <button type="submit" class="pz-tool-link pz-tool-danger">Disconnect this site</button>
                    </form>
                    <span class="pz-tool-sep">·</span>

                    <?php // Its own form too — arms the background worker, so it returns instantly. ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                        <?php wp_nonce_field( 'personaizer_resync' ); ?>
                        <input type="hidden" name="action" value="personaizer_resync" />
                        <button type="submit" class="pz-tool-link" title="Push everything again from scratch">Resync everything</button>
                    </form>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                        <?php wp_nonce_field( 'personaizer_check' ); ?>
                        <input type="hidden" name="action" value="personaizer_check" />
                        <button type="submit" class="pz-tool-link" title="Compare what the AI holds against this site, push what differs, and drop what no longer exists here">Check what&apos;s out of date</button>
                    </form>
                </div>

                <?php
                // What the last check found, per stream — the manifest's answer from personaizer.com. A doc count
                // can only ever say how many items exist; this says whether they are RIGHT, which is the question
                // an owner actually has. Missing and stale items are already queued to push; orphans were removed
                // there (or held for a second confirming check when there were too many to trust at once).
                $pz_check = Personaizer_Manifest::progress();
                if ( $pz_check['running'] ) : ?>
                    <div class="pz-sync-state" style="margin-top:8px;">
                        <svg class="pz-spin" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#7dd3fc" stroke-width="2.5"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                        Checking<?php if ( $pz_check['stream'] ) : ?> <?php echo esc_html( $pz_stream_states[ $pz_check['stream'] ]['label'] ?? $pz_check['stream'] ); ?> — <?php echo (int) $pz_check['done']; ?> of <?php echo (int) $pz_check['total']; ?><?php endif; ?>… this runs in the background.
                    </div>
                <?php endif;
                foreach ( Personaizer_Manifest::results() as $pz_stream => $pz_res ) :
                    if ( ! isset( $pz_stream_states[ $pz_stream ] ) ) continue;
                    $pz_label = $pz_stream_states[ $pz_stream ]['label'];
                    $pz_when  = ! empty( $pz_res['at'] ) ? human_time_diff( (int) $pz_res['at'] ) . ' ago' : '';
                    if ( ! empty( $pz_res['error'] ) ) : ?>
                        <div class="pz-sync-state partial" style="margin-top:8px;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#e8b339" stroke-width="2.5"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                            <?php echo esc_html( $pz_label ); ?>: couldn&apos;t check — <?php echo esc_html( $pz_res['error'] ); ?> <span style="opacity:.6;">(<?php echo esc_html( $pz_when ); ?>)</span>
                        </div>
                    <?php continue; endif;
                    $pz_diff = (int) $pz_res['missing'] + (int) $pz_res['stale'];
                    $pz_clean = $pz_diff === 0 && (int) $pz_res['orphans'] === 0;
                ?>
                    <div class="pz-sync-state" style="margin-top:8px;">
                        <?php if ( $pz_clean ) : ?>
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#2dbd4e" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>
                            <?php echo esc_html( $pz_label ); ?>: in sync — <?php echo (int) $pz_res['present']; ?> item<?php echo (int) $pz_res['present'] === 1 ? '' : 's'; ?> checked <span style="opacity:.6;">(<?php echo esc_html( $pz_when ); ?>)</span>
                        <?php else : ?>
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#e8b339" stroke-width="2.5"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                            <?php echo esc_html( $pz_label ); ?>: <?php echo (int) $pz_res['present'] - $pz_diff; ?> up to date
                            <?php if ( (int) $pz_res['missing'] > 0 ) : ?> · <strong><?php echo (int) $pz_res['missing']; ?> missing</strong><?php endif; ?>
                            <?php if ( (int) $pz_res['stale'] > 0 ) : ?> · <strong><?php echo (int) $pz_res['stale']; ?> out of date</strong><?php endif; ?>
                            <?php if ( (int) $pz_res['orphans_deleted'] > 0 ) : ?> · <?php echo (int) $pz_res['orphans_deleted']; ?> removed (no longer on this site)<?php endif; ?>
                            <?php if ( (int) $pz_res['orphans_held'] > 0 ) : ?> · <?php echo (int) $pz_res['orphans_held']; ?> no longer on this site — removed if the next check agrees<?php endif; ?>
                            <?php if ( ! empty( $pz_res['busy'] ) ) : ?> · removal postponed, PERSONAIZER was busy<?php endif; ?>
                            <?php if ( $pz_diff > 0 ) : ?> — pushing them now<?php endif; ?>
                            <span style="opacity:.6;">(<?php echo esc_html( $pz_when ); ?>)</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <details class="pz-sysinfo" id="pz-sysinfo" <?php echo isset( $_GET['pz_view'] ) ? 'open' : ''; ?>>
                    <summary>System info</summary>
                    <textarea readonly rows="6" class="pz-input pz-input-mono"
                              style="resize:vertical;line-height:1.5;margin-top:10px;"
                              onclick="this.select();"><?php echo esc_textarea( personaizer_system_info() ); ?></textarea>
                    <p class="pz-hint">Click to select, then copy — this answers most support questions on its own. No secrets are included.</p>
                </details>

            <?php endif; ?>

            <?php
            // ONE refresh for the whole page, decided once.
            //
            // Three things can be in flight — the persona building (ours), the content backfill (this
            // site's cron), and the backend still processing already-pushed docs ($pz_processing: embedding
            // and building product schemas, so "N of M ready" ticks up on its own). Each could otherwise
            // arm its own timer, so this keeps it to a single reload. It's also what MOVES the backfill on a
            // quiet site: WP-Cron only spawns on a page request, so the poll that watches the work is the
            // poll that drives it. It stops arming itself the moment there's nothing left to watch —
            // including once every pushed doc has finished processing.
            //
            // The actual setTimeout/reload lives in the statically enqueued assets/admin-page.js (see the
            // admin_enqueue_scripts hook above) — this only tells it, via an inline config object, whether
            // to arm itself. The stream on/off toggle script in that same file needs no such flag; it degrades
            // to a no-op when the page has no .pz-stream rows.
            if ( $active && ( ! empty( $profile['building'] ) || $progress['running'] || ! empty( $pz_processing ) || Personaizer_Manifest::progress()['running'] ) ) {
                wp_add_inline_script(
                    'personaizer-admin-page',
                    'window.PersonaizerAdminPage = ' . wp_json_encode( [ 'autoReload' => true ] ) . ';',
                    'before'
                );
            }
            ?>
        </div>
    </div>
    <?php
}

/**
 * One stream row, read-only: on or off (the owner's switch on personaizer.com), what the AI holds against
 * what the site has, and — when the last check found a gap — what it found.
 *
 * @param string $stream  Stream id.
 * @param array  $state From personaizer_stream_states().
 */
function personaizer_stream_row( $stream, array $state ) {
    ?>
    <div class="pz-stream<?php echo $state['enabled'] ? '' : ' pz-stream-off'; ?>">
        <div class="pz-stream-head">
            <span class="pz-switch pz-switch-static" aria-hidden="true">
                <input type="checkbox" <?php checked( $state['enabled'] ); ?> disabled />
                <span class="pz-switch-track"><span class="pz-switch-knob"></span></span>
            </span>
            <span class="pz-stream-name"><?php echo esc_html( $state['label'] ); ?></span>
            <?php // What the AI HOLDS, against what the site has. Caught up ⇒ one number. Behind (a gap —
                  // plan full, or a stream the site moved past) ⇒ "held / total" in amber, so the shortfall
                  // reads at a glance right where someone is looking. "—" only when personaizer.com never
                  // answered — never the site's number, which is the guess this field exists to avoid.
                  $kc_gap = $state['enabled'] && $state['known'] !== null && (int) $state['known'] < (int) $state['count']; ?>
            <span class="pz-know-count<?php echo $kc_gap ? ' pz-know-gap' : ''; ?>"<?php echo $state['known'] === null ? ' title="Couldn&apos;t reach PERSONAIZER just now"' : ''; ?>><?php
                if ( $state['known'] === null ) {
                    echo '&mdash;';
                } elseif ( $kc_gap ) {
                    echo (int) $state['known'] . ' / ' . (int) $state['count'];
                } else {
                    echo (int) $state['known'];
                }
            ?></span>
        </div>
        <div class="pz-stream-sub">
            <?php if ( ! $state['enabled'] ) : ?>
                <span class="pz-stream-note"><?php echo $state['offered']
                    ? 'Switched off on personaizer.com — your AI keeps what it already learned and ignores it; nothing is deleted.'
                    : 'Not switched on yet — your AI doesn&apos;t use these.'; ?></span>
            <?php elseif ( is_array( $state['reconciliation'] ) ) :
                $r = $state['reconciliation'];
                $gap = (int) ( $r['missing'] ?? 0 ) + (int) ( $r['stale'] ?? 0 ) + (int) ( $r['held_orphans'] ?? 0 );
                if ( $gap > 0 ) : ?>
                <span class="pz-stream-note">Last check: <?php
                    $parts = array();
                    if ( (int) ( $r['missing'] ?? 0 ) > 0 ) $parts[] = (int) $r['missing'] . ' missing';
                    if ( (int) ( $r['stale'] ?? 0 ) > 0 ) $parts[] = (int) $r['stale'] . ' out of date';
                    if ( (int) ( $r['held_orphans'] ?? 0 ) > 0 ) $parts[] = (int) $r['held_orphans'] . ' awaiting removal';
                    echo esc_html( implode( ' · ', $parts ) );
                ?></span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Turn the onboarding job's stage into something a shop owner would say, plus a rough percentage.
 *
 * The server reports six machine stages; nobody needs six. They collapse to three honest beats —
 * personality, portrait, content — because that's what's actually being made, and a bar that tracks
 * real stages beats a spinner that means nothing. The percentages are coarse on purpose: they mark
 * which beat we're on, and pretending to know more than that would be theatre.
 *
 * @return array{label:string,percent:int}
 */
function personaizer_build_step( $stage ) {
    switch ( $stage ) {
        case 'scraping':
        case 'synthesizing':
            return array( 'label' => 'Writing its personality from your site', 'percent' => 30 );
        case 'building_persona':
            return array( 'label' => 'Writing its personality from your site', 'percent' => 50 );
        case 'generating_avatar':
            return array( 'label' => 'Painting its portrait', 'percent' => 70 );
        case 'ingesting_knowledge':
        case 'activating':
            return array( 'label' => 'Learning your content', 'percent' => 90 );
        default:
            // 'queued', 'waiting', or a stage a newer backend added — say the true, vague thing.
            return array( 'label' => 'Getting started', 'percent' => 12 );
    }
}

/** Published items of a post type — the number the owner recognises as "my content". */
function personaizer_published_count( $type ) {
    $counts = wp_count_posts( $type );
    return $counts && isset( $counts->publish ) ? (int) $counts->publish : 0;
}

/**
 * A copy-pasteable diagnostic. When an owner says "it's not working", this is the one thing that
 * answers most of the follow-up questions at once: which environment, which persona, what synced,
 * what failed. Secrets are reported as set/missing — never printed.
 */
function personaizer_system_info() {
    $id        = get_option( 'personaizer_persona_id', '' );
    $profile   = $id ? personaizer_api()->get_profile() : null;
    $integration = personaizer_api()->is_configured() ? personaizer_api()->get_integration() : null;

    // A live round-trip: proves DNS, TLS, routing and the integration key in one line. Cheap — the integration
    // call underneath is transient-cached.
    $reachable = $integration === null ? 'not connected'
        : ( is_wp_error( $integration ) ? 'NOT reachable — ' . $integration->get_error_message() : 'reachable' );

    // Version from our own plugin header, so the diagnostic can't quietly lie about which build ran.
    $header = get_file_data( __FILE__, [ 'Version' => 'Version' ] );

    // Deliberately slim: only what is UNIQUE to this plugin and answers "is it working?" at a glance.
    // WordPress / PHP / WooCommerce versions live in Tools → Site Health → Info, so they aren't repeated
    // here; the connection + WP-Cron lines are the ones nothing else in WP can tell you.
    $lines = [
        'PERSONAIZER ' . ( $header['Version'] ?: '?' ),
        '',
        'Integration  : ' . ( get_option( 'personaizer_integration_id', '' ) ?: 'not connected' )
            . ( is_array( $integration ) ? '  (' . $integration['status'] . ', brand ' . ( $integration['brand']['slug'] ?: $integration['brand']['id'] ) . ')' : '' ),
        'Streams on   : ' . ( is_array( $integration ) ? ( implode( ', ', personaizer_current_streams() ) ?: 'none' ) : '?' ),
        'Widget     : ' . ( $id !== '' ? $id . ( $profile ? '  (' . $profile['name'] . ')' : '' ) : 'no persona' ),
        'API base   : ' . PERSONAIZER_API_URL,
        'API status : ' . $reachable,
        'WP-Cron    : ' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'DISABLED — syncing won\'t run on its own' : 'enabled' ),
    ];
    return implode( "\n", $lines );
}

/**
 * Public post types other than the ones with a first-class row (and never attachments/products).
 *
 * `public => true` alone is not the line between a site's content and a plugin's furniture. Page builders
 * register their template stores as public because a preview has to render at a front-end URL — Elementor's
 * saved layouts, ElementsKit parts, Royal templates, header/footer builders. Offer those as streams and an
 * Elementor site shows a wall of rows whose contents are shortcodes and layout markup, not answers.
 *
 * Two flags say it for us, and a builder type trips at least one:
 *
 *   - `exclude_from_search` — WordPress's own way of saying a visitor should never land on one. This is the
 *     test SEO plugins use for the same question, and it catches the template libraries.
 *   - `show_in_nav_menus` — whether the type is somewhere a visitor navigates TO. Both default to following
 *     `public`, so a real content type inherits the right answer without its author thinking about it;
 *     turning either off is a deliberate statement that the type is machinery. Elementor's floating buttons
 *     set only this one (they leave `exclude_from_search` at its inherited false), which is why one flag was
 *     not enough.
 *
 * A site with a genuine type we still get wrong can say so with the filter — nothing here is a guess the
 * owner is stuck with.
 */
function personaizer_extra_post_types() {
    $extra = [];
    foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $type ) {
        if ( in_array( $type->name, [ 'attachment', 'product', 'page', 'post' ], true ) ) continue;
        if ( ! empty( $type->exclude_from_search ) ) continue;
        if ( empty( $type->show_in_nav_menus ) ) continue;
        $extra[] = $type;
    }
    /**
     * Filters the custom post types offered as sync streams.
     *
     * @param WP_Post_Type[] $extra Post type objects, minus pages/posts/products and anything the flags
     *                              above marked as builder machinery.
     */
    return apply_filters( 'personaizer_syncable_post_types', $extra );
}

// ── Logged-in customer identity (Part B) ──────────────────────────────────────
// Signs a short-lived HS256 JWT for the CURRENT logged-in user with the account's Identity Secret,
// so the widget can prove who they are without the secret ever reaching the browser. Minted PER
// REQUEST (never baked into cacheable HTML — that would serve one user's token to another). The
// server verifies it and trusts `sub`; a bad/absent token simply falls back to an anonymous visitor.

add_action( 'rest_api_init', function () {
    register_rest_route( 'personaizer/v1', '/identity-token', [
        'methods'             => 'GET',
        'callback'            => 'personaizer_identity_token',
        // Cookie-authenticated + logged-in only. WordPress enforces the X-WP-Nonce for cookie auth,
        // so a cross-site page can't mint a token for the visitor.
        'permission_callback' => function () { return is_user_logged_in(); },
    ] );
} );

function personaizer_identity_token() {
    $secret = trim( (string) get_option( 'personaizer_identity_secret', '' ) );
    if ( $secret === '' || get_option( 'personaizer_identify_users', '' ) !== '1' ) {
        return new WP_Error( 'personaizer_identity_off', 'Customer identity is not enabled.', [ 'status' => 404 ] );
    }
    $user = wp_get_current_user();
    if ( ! $user || ! $user->ID ) {
        return new WP_Error( 'personaizer_not_logged_in', 'Not signed in.', [ 'status' => 401 ] );
    }

    $now   = time();
    $token = personaizer_sign_jwt( [
        'sub' => (string) $user->ID,   // stable per-user id (email can change)
        'iat' => $now,
        'exp' => $now + 600,           // 10 minutes — short window, refreshed per request
    ], $secret );

    return new WP_REST_Response( [ 'token' => $token ], 200 );
}

// The current logged-in user's display attributes. Untrusted context — the server treats these as
// display/CRM only, NEVER as identity (identity is the signed `sub`). Baked into the boot config so
// they're present when chat.js captures them. WooCommerce billing phone included when set.
function personaizer_current_user_attributes() {
    $user = wp_get_current_user();
    if ( ! $user || ! $user->ID ) return [];
    return array_filter( [
        'name'  => $user->display_name,
        'email' => $user->user_email,
        'phone' => (string) get_user_meta( $user->ID, 'billing_phone', true ),
    ], static function ( $v ) { return $v !== '' && $v !== null; } );
}

// Minimal HS256 JWT signer. The signing key is the Identity Secret string verbatim — the server
// verifies with the UTF-8 bytes of the same secret (docs/projects/sessions/architecture/identity.md).
function personaizer_sign_jwt( array $payload, string $secret ) {
    $b64url  = static function ( $data ) { return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); };
    $header  = $b64url( wp_json_encode( [ 'alg' => 'HS256', 'typ' => 'JWT' ] ) );
    $body    = $b64url( wp_json_encode( $payload ) );
    $signing = $header . '.' . $body;
    $sig     = $b64url( hash_hmac( 'sha256', $signing, $secret, true ) );
    return $signing . '.' . $sig;
}

// ── Inject widget on the frontend ─────────────────────────────────────────────

add_action( 'wp_footer', function () {
    $id = get_option( 'personaizer_persona_id', '' );
    if ( empty( $id ) ) return;

    // Appearance/behavior (theme, position, accent, title, auto-open, nudge) are NOT injected here anymore —
    // chat.js reads them from the persona's server-side widget config via /v1/persona/profile. Leaving them out
    // of PersonAIzerConfig lets that config take effect (host config still wins if a developer sets
    // window.PersonAIzerConfig manually).
    $cfg = [];

    // Steer the widget's /v1 calls at our configured gateway (overrides the base baked into chat.js
    // at upload time) so it works against any environment — like the dashboard's own web widget.
    $cfg['apiBase'] = rtrim( PERSONAIZER_WIDGET_API_BASE, '/' );

    // Tell PERSONAIZER the widget is running inside this plugin, so conversations show as "WordPress" in the
    // owner's inbox instead of a generic website embed. Purely descriptive — the server only accepts its own
    // short allowlist for this key and never treats it as a credential.
    $cfg['integration'] = 'wordpress';

    // Recognize a signed-in customer (opt-in + Identity Secret set). Split in two for cache safety:
    //   • userAttributes (name/email — display only) are baked into the boot config so chat.js captures
    //     them at start. WP full-page caches bypass logged-in requests, so this renders per-user; and
    //     since attributes are NOT identity, a mis-cached copy is at worst a display glitch, never
    //     impersonation.
    //   • the identity TOKEN is fetched per request via a provider function (never in the HTML), so even
    //     a mis-cached page can't hand one customer's token to another. The server verifies it, trusts `sub`.
    $identify = is_user_logged_in()
        && get_option( 'personaizer_identify_users', '' ) === '1'
        && trim( (string) get_option( 'personaizer_identity_secret', '' ) ) !== '';
    if ( $identify ) {
        $attrs = personaizer_current_user_attributes();
        if ( $attrs ) $cfg['userAttributes'] = $attrs;
    }

    // Registered/enqueued (not a raw <script> echo) so it plays by WordPress's own dependency and
    // caching rules. 'strategy' => 'async' (WP 6.3+) keeps it non-blocking; the script_loader_tag
    // filter below guarantees the same on older cores, where that args key is silently ignored.
    wp_register_script(
        'personaizer-chat-widget',
        PERSONAIZER_WIDGET_URL . '?k=' . rawurlencode( $id ),
        array(),
        PERSONAIZER_VERSION,
        array( 'strategy' => 'async', 'in_footer' => true )
    );
    wp_add_inline_script( 'personaizer-chat-widget', 'window.PersonAIzerConfig = ' . wp_json_encode( $cfg ) . ';', 'before' );

    if ( $identify ) {
        wp_add_inline_script(
            'personaizer-chat-widget',
            sprintf(
                '(function(c){c.identityTokenProvider=function(){' .
                'return fetch(%s,{headers:{"X-WP-Nonce":%s},credentials:"same-origin"})' .
                '.then(function(r){return r.ok?r.json():null;}).then(function(d){return d?d.token:null;})' .
                '.catch(function(){return null;});};})(window.PersonAIzerConfig);',
                wp_json_encode( esc_url_raw( rest_url( 'personaizer/v1/identity-token' ) ) ),
                wp_json_encode( wp_create_nonce( 'wp_rest' ) )
            ),
            'before'
        );
    }

    wp_enqueue_script( 'personaizer-chat-widget' );
} );

// Belt-and-braces async: WP < 6.3 ignores the 'strategy' arg above, so force the attribute onto the
// tag directly rather than depend on core version for a load-order property that matters either way.
add_filter( 'script_loader_tag', function ( $tag, $handle ) {
    if ( $handle === 'personaizer-chat-widget' && strpos( $tag, ' async' ) === false ) {
        $tag = str_replace( ' src=', ' async src=', $tag );
    }
    return $tag;
}, 10, 2 );
