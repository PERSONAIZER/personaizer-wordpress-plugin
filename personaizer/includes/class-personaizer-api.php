<?php
/**
 * Thin HTTP client for the PERSONAIZER connector API.
 *
 * Authenticates with the CONNECTOR key (ck_…) that Connect handed this site — the credential of this site's
 * connector on personaizer.com, which owns the knowledge lanes it syncs. Server-side only; it must never be
 * printed into a page (the widget uses the public Persona ID instead).
 *
 * Every write goes to a LANE: /v1/connector/lanes/{lane}/… — the backend files it into that lane's source.
 * The plugin never names a source; which lanes are on is the owner's choice on personaizer.com, read back
 * from GET /v1/connector.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Base URL of the PERSONAIZER API (Core). Defaults to PRODUCTION.
 * Override in wp-config.php for dev/local testing (before plugins load):
 *   define( 'PERSONAIZER_API_URL', 'https://dev-api.personaizer.com' );
 */
if ( ! defined( 'PERSONAIZER_API_URL' ) ) {
    define( 'PERSONAIZER_API_URL', 'https://api.personaizer.com' );
}

class Personaizer_Api {

    /** How long a connector read is trusted before the next call re-reads it. */
    const CONNECTOR_TTL = MINUTE_IN_SECONDS;

    /** @return string|null the connector key, or null when not connected. */
    private function connector_key() {
        $key = trim( (string) get_option( 'personaizer_connector_key', '' ) );
        return $key !== '' ? $key : null;
    }

    public function is_configured() {
        return $this->connector_key() !== null;
    }

    private function base() {
        return rtrim( PERSONAIZER_API_URL, '/' );
    }

    /**
     * Headers every connector call carries. The plugin version lets the backend see which release a site
     * runs — the one fact support needs first when a sync misbehaves.
     */
    private function headers( array $extra = array() ) {
        return array_merge( array(
            'X-Api-Key'                   => $this->connector_key(),
            'X-Personaizer-Plugin-Version' => PERSONAIZER_VERSION,
        ), $extra );
    }

    /**
     * The connected persona's public display info (name + avatar), so the admin screen can say
     * "Ana is live on your site" instead of printing a GUID at the owner. Identified by the PUBLIC
     * Persona ID — no key involved.
     *
     * Cached, because this runs on every admin page view — but for how long depends on what came
     * back. A persona still named after the domain is mid-build (the onboarding job renames it to the
     * brand when it finishes), and the admin screen polls for exactly that moment; a 5-minute cache
     * would leave it insisting "writing its personality" for minutes after it was done. So a
     * placeholder is held briefly and a finished persona is held long — the poll converges, and a
     * settled site still costs one request per 5 minutes.
     *
     * @return array{name:string,avatar_url:string,building:bool,stage:string}|null null when there is no widget persona or it is unreachable.
     */
    public function get_profile() {
        $persona_id = trim( (string) get_option( 'personaizer_persona_id', '' ) );
        if ( $persona_id === '' ) return null;

        $key    = 'personaizer_profile_' . md5( $persona_id . '|' . $this->base() );
        $cached = get_transient( $key );
        if ( $cached !== false ) {
            return is_array( $cached ) ? $cached : null;
        }

        $response = wp_remote_get( $this->base() . '/v1/persona/profile', [
            'timeout' => 8,
            'headers' => [ 'X-Persona-Id' => $persona_id, 'X-Personaizer-Plugin-Version' => PERSONAIZER_VERSION ],
        ] );

        $profile = null;
        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( is_array( $data ) && ! empty( $data['name'] ) ) {
                $profile = [
                    'name'       => (string) $data['name'],
                    // The /v1 surface is snake_case.
                    'avatar_url' => isset( $data['avatar_url'] ) ? (string) $data['avatar_url'] : '',
                    // The server's own answer to "is this persona finished, and what's it doing?" —
                    // not ours to infer. See profile_ttl(): it also decides how long to trust this.
                    'building'   => ! empty( $data['building'] ),
                    'stage'      => isset( $data['build_stage'] ) ? (string) $data['build_stage'] : '',
                ];
            }
        }
        set_transient( $key, $profile === null ? 'miss' : $profile, self::profile_ttl( $profile ) );
        return $profile;
    }

    /**
     * Seconds to trust a profile for: short while the server says the persona is still being built (nearly
     * everything about it changes during that window), long once it has settled.
     */
    private static function profile_ttl( $profile ) {
        if ( $profile === null ) return 5 * MINUTE_IN_SECONDS;
        return ! empty( $profile['building'] ) ? 5 : 5 * MINUTE_IN_SECONDS;
    }

    /** Drop the cached profile — call after (re)connecting to a different persona. */
    public static function forget_profile( $persona_id, $base ) {
        delete_transient( 'personaizer_profile_' . md5( $persona_id . '|' . rtrim( $base, '/' ) ) );
    }

    /**
     * This site's connector as personaizer.com sees it: status, the brand it feeds, and its lanes — each with
     * `enabled` (the owner's switch), the docs it holds / has ready, and the last manifest's outcome.
     *
     * This is the ONLY source of truth for which lanes sync. The owner switches lanes on personaizer.com;
     * a local copy would be a second truth free to drift, and the plugin would confidently push into a lane
     * the owner switched off an hour ago. Cached for a minute (it is read on every sync hook), refreshed
     * outright by forget_connector() after anything that changes it.
     *
     * @param bool $force Skip the cache and read live.
     * @return array{id:string,status:string,brand:array{id:string,slug:string,display_name:string},lanes:array<string,array{enabled:bool,source:string,doc_count:int,ready_count:int,reconciliation:?array}>}|WP_Error
     */
    public function get_connector( $force = false ) {
        $key = $this->connector_key();
        if ( $key === null ) {
            return new WP_Error( 'personaizer_no_key', 'This site is not connected to PERSONAIZER.' );
        }

        $cache = 'personaizer_connector_' . md5( $key . '|' . $this->base() );
        if ( ! $force ) {
            $hit = get_transient( $cache );
            if ( is_array( $hit ) ) return $hit;
        }

        $response = wp_remote_get( $this->base() . '/v1/connector', [ 'timeout' => 15, 'headers' => $this->headers() ] );
        if ( is_wp_error( $response ) ) return $response;
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'personaizer_http_' . $code, self::friendly_error( $code, wp_remote_retrieve_body( $response ) ), array( 'status' => $code ) );
        }

        $body      = json_decode( wp_remote_retrieve_body( $response ), true );
        $connector = ( is_array( $body ) && isset( $body['connector'] ) && is_array( $body['connector'] ) ) ? $body['connector'] : null;
        if ( $connector === null ) {
            return new WP_Error( 'personaizer_bad_body', 'PERSONAIZER answered without a connector.' );
        }
        $brand = ( isset( $body['brand'] ) && is_array( $body['brand'] ) ) ? $body['brand'] : array();

        $lanes = array();
        foreach ( (array) ( $connector['lanes'] ?? array() ) as $row ) {
            if ( empty( $row['lane'] ) ) continue;
            $lanes[ (string) $row['lane'] ] = array(
                'enabled'        => ! empty( $row['enabled'] ),
                'source'         => (string) ( $row['source'] ?? '' ),
                'doc_count'      => (int) ( $row['doc_count'] ?? 0 ),
                'ready_count'    => (int) ( $row['ready_count'] ?? 0 ),
                'reconciliation' => ( isset( $row['reconciliation'] ) && is_array( $row['reconciliation'] ) ) ? $row['reconciliation'] : null,
            );
        }

        $state = array(
            'id'     => (string) ( $connector['id'] ?? '' ),
            'status' => (string) ( $connector['status'] ?? '' ),
            'brand'  => array(
                'id'           => (string) ( $brand['id'] ?? '' ),
                'slug'         => (string) ( $brand['slug'] ?? '' ),
                'display_name' => (string) ( $brand['display_name'] ?? '' ),
            ),
            'lanes'  => $lanes,
        );
        set_transient( $cache, $state, self::CONNECTOR_TTL );
        return $state;
    }

    /** Drop the cached connector — after connect, disconnect, or a write the server refused because a lane changed. */
    public function forget_connector() {
        $key = $this->connector_key();
        if ( $key !== null ) delete_transient( 'personaizer_connector_' . md5( $key . '|' . $this->base() ) );
    }

    /**
     * Tell personaizer.com what this site could sync — every lane with a label and a count — so the owner
     * can switch lanes on from a list that reflects the site as it is now (a custom post type registered
     * last week shows up; one whose plugin was removed does not).
     *
     * @param array<int,array{lane:string,label:string,count:int}> $lanes
     * @return true|WP_Error
     */
    public function report_inventory( array $lanes ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'personaizer_no_key', 'This site is not connected to PERSONAIZER.' );
        }
        $response = wp_remote_request( $this->base() . '/v1/connector/inventory', [
            'method'  => 'PUT',
            'timeout' => 15,
            'headers' => $this->headers( [ 'Content-Type' => 'application/json' ] ),
            'body'    => wp_json_encode( [ 'lanes' => array_values( $lanes ) ] ),
        ] );
        return $this->handle_response( $response, 'inventory', false );
    }

    /**
     * The account's knowledge-unit budget: how much the plan allows, how much is used, and the plan's
     * name — enough to tell the owner "you've hit your Free plan's limit, upgrade" and to gate the
     * after-upgrade catch-up on real headroom before it replays anything.
     *
     * Read with the connector key against /api/subscription/limits — a public-surface endpoint that accepts
     * any of the account's keys and resolves the owning account from it. Cached briefly: it's consulted on
     * every settings-page render and by the daily catch-up, and a plan's ceiling doesn't move minute to minute.
     *
     * @param bool $force Skip the cache and read live — used by the after-upgrade catch-up, which runs
     *                    rarely and must not act on a stale "full" reading from just before the upgrade.
     * @return array{ku_used:float,ku_limit:?float,plan_slug:string,plan_name:string}|null
     *         null when unconfigured or unreachable — a caller must read that as "don't know", never "0".
     */
    public function get_limits( $force = false ) {
        $key = $this->connector_key();
        if ( $key === null ) return null;

        $cache = 'personaizer_limits_' . md5( $key . '|' . $this->base() );
        if ( ! $force ) {
            $hit = get_transient( $cache );
            if ( $hit !== false ) {
                return is_array( $hit ) ? $hit : null;
            }
        }

        $response = wp_remote_get( $this->base() . '/api/subscription/limits', [ 'timeout' => 10, 'headers' => $this->headers() ] );

        $limits = null;
        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
            // The /api surface is snake_case on the wire.
            $data  = json_decode( wp_remote_retrieve_body( $response ), true );
            $usage = ( is_array( $data ) && isset( $data['usage'] ) && is_array( $data['usage'] ) ) ? $data['usage'] : null;
            $plan  = ( is_array( $data ) && isset( $data['plan'] ) && is_array( $data['plan'] ) ) ? $data['plan'] : array();
            if ( $usage !== null ) {
                $limits = array(
                    'ku_used'   => (float) ( $usage['knowledge_units_used'] ?? 0 ),
                    // null (not 0) = unlimited — an absent/blank ceiling must never read as "no room".
                    'ku_limit'  => ( isset( $usage['knowledge_units_limit'] ) && $usage['knowledge_units_limit'] !== null )
                        ? (float) $usage['knowledge_units_limit'] : null,
                    'plan_slug' => (string) ( $plan['slug'] ?? '' ),
                    'plan_name' => (string) ( $plan['name'] ?? '' ),
                );
            }
        }
        // Hold a miss briefly too, so a blip doesn't hammer the endpoint on every admin page view.
        set_transient( $cache, $limits === null ? 'miss' : $limits, 5 * MINUTE_IN_SECONDS );
        return $limits;
    }

    /**
     * Upsert a plain-text / markdown knowledge doc into a lane, by external id.
     * Idempotent server-side: same id updates in place, identical content is a no-op.
     *
     * @param string $lane        Lane id (pages / posts / a custom post type).
     * @param string $fingerprint This site's hash of the payload — the lane manifest compares it later.
     * @param array  $images      Image library entries [{url, description, is_primary}]; [] = no images.
     * @return true|WP_Error
     */
    public function upsert_text( $lane, $external_id, $title, $markdown, $fingerprint, $permalink = '', $images = array() ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'personaizer_no_key', 'This site is not connected to PERSONAIZER.' );
        }

        $boundary = wp_generate_password( 24, false );
        $eol      = "\r\n";
        $filename = $external_id . '.md';

        $body  = '';
        // file part (the post content, as a markdown text file)
        $body .= '--' . $boundary . $eol;
        $body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . $eol;
        $body .= 'Content-Type: text/markdown' . $eol . $eol;
        $body .= $markdown . $eol;

        $fields = [ 'id' => $external_id, 'title' => $title, 'fingerprint' => $fingerprint ];
        if ( $permalink !== '' ) {
            $fields['links'] = wp_json_encode( [ [ 'url' => $permalink, 'is_primary' => true ] ] );
        }
        // Image library (featured + inline). Always sent — even [] — so the plugin,
        // not the extractor's auto-find, is the source of truth for this doc's images.
        $fields['images'] = wp_json_encode( array_values( (array) $images ) );
        foreach ( $fields as $name => $value ) {
            $body .= '--' . $boundary . $eol;
            $body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
            $body .= $value . $eol;
        }
        $body .= '--' . $boundary . '--' . $eol;

        $response = wp_remote_post(
            $this->base() . '/v1/connector/lanes/' . rawurlencode( $lane ) . '/docs/upload',
            [
                'timeout' => 30,
                'headers' => $this->headers( [ 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ] ),
                'body'    => $body,
            ]
        );

        return $this->handle_response( $response, 'upload' );
    }

    /**
     * Bulk upsert TYPED product items (1–100) into a lane. Idempotent by each item's `id`; identical
     * content replays as a no-op. Each item carries its `fingerprint` (see personaizer_payload_hash()).
     *
     * @param string  $lane  Lane id — 'products'.
     * @param array[] $items Typed items ({id, fingerprint, title, categories, price, …} — never a `source`).
     * @return array{deferred:string[]}|WP_Error On success an array whose `deferred` holds the external
     *         ids the plan had no room for (empty = everything landed). WP_Error on failure — a 402 means
     *         nothing fit at all.
     */
    public function upsert_products( $lane, array $items ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'personaizer_no_key', 'This site is not connected to PERSONAIZER.' );
        }
        $items = array_values( $items );
        if ( empty( $items ) ) {
            return array( 'deferred' => array() );
        }

        $response = wp_remote_request(
            $this->base() . '/v1/connector/lanes/' . rawurlencode( $lane ) . '/docs',
            [
                'method'  => 'PUT',
                'timeout' => 30,
                'headers' => $this->headers( [ 'Content-Type' => 'application/json' ] ),
                'body'    => wp_json_encode( [ 'items' => $items ] ),
            ]
        );

        $result = $this->handle_response( $response, 'product upsert' );
        if ( $result !== true ) {
            return $result;   // WP_Error
        }
        // Partial-accept: the server writes what fits the plan's knowledge quota and returns the ids it
        // DEFERRED, so the caller can remember exactly those (not the whole batch) as waiting for space.
        // Body is snake_case on the /v1 surface; absent/empty `deferred` ⇒ everything landed.
        $body     = json_decode( wp_remote_retrieve_body( $response ), true );
        $deferred = ( is_array( $body ) && ! empty( $body['deferred'] ) )
            ? array_values( array_map( 'strval', (array) $body['deferred'] ) )
            : array();
        return array( 'deferred' => $deferred );
    }

    /**
     * Hand a lane's whole manifest to personaizer.com — every published item with its fingerprint — and learn
     * what still has to be pushed (missing / stale ids) while the backend removes what this site no longer
     * has. See Personaizer_Manifest for the walk that builds it.
     *
     * @param string                       $lane
     * @param int                          $generation Strictly increasing per lane (a timestamp).
     * @param array<int,array{id:string,fingerprint:string}> $items
     * @return array{generation:int,present:int,missing:string[],stale:string[],orphans:int,orphans_deleted:int,orphans_held:int,busy:bool}|WP_Error
     */
    public function send_manifest( $lane, $generation, array $items ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'personaizer_no_key', 'This site is not connected to PERSONAIZER.' );
        }
        $response = wp_remote_request(
            $this->base() . '/v1/connector/lanes/' . rawurlencode( $lane ) . '/manifest',
            [
                'method'  => 'PUT',
                'timeout' => 60,
                'headers' => $this->headers( [ 'Content-Type' => 'application/json' ] ),
                'body'    => wp_json_encode( [ 'generation' => (int) $generation, 'items' => array_values( $items ) ] ),
            ]
        );
        $result = $this->handle_response( $response, 'manifest', false );
        if ( $result !== true ) {
            return $result;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return new WP_Error( 'personaizer_bad_body', 'PERSONAIZER answered the manifest without a result.' );
        }
        return array(
            'generation'      => (int) ( $body['generation'] ?? $generation ),
            'present'         => (int) ( $body['present'] ?? 0 ),
            'missing'         => array_values( array_map( 'strval', (array) ( $body['missing'] ?? array() ) ) ),
            'stale'           => array_values( array_map( 'strval', (array) ( $body['stale'] ?? array() ) ) ),
            'orphans'         => (int) ( $body['orphans'] ?? 0 ),
            'orphans_deleted' => (int) ( $body['orphans_deleted'] ?? 0 ),
            'orphans_held'    => (int) ( $body['orphans_held'] ?? 0 ),
            'busy'            => ! empty( $body['busy'] ),
        );
    }

    /**
     * Remove docs of this connector's lanes by external id(s). Silently succeeds for ids
     * that aren't present, so it's safe to call unconditionally on delete.
     *
     * @param string[] $external_ids
     * @return true|WP_Error
     */
    public function delete_docs( array $external_ids ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'personaizer_no_key', 'This site is not connected to PERSONAIZER.' );
        }
        $external_ids = array_values( array_filter( array_map( 'strval', $external_ids ) ) );
        if ( empty( $external_ids ) ) {
            return true;
        }

        $ids = implode( ',', array_map( 'rawurlencode', $external_ids ) );
        $response = wp_remote_request(
            $this->base() . '/v1/connector/docs?ids=' . $ids,
            [ 'method' => 'DELETE', 'timeout' => 30, 'headers' => $this->headers() ]
        );

        return $this->handle_response( $response, 'delete', false );
    }

    /**
     * Tell personaizer.com this site let go. The connector freezes there (every lane off, nothing deleted);
     * the owner reconnects from here or deletes the connector on personaizer.com.
     *
     * @return true|WP_Error
     */
    public function disconnect() {
        if ( ! $this->is_configured() ) return true;
        $response = wp_remote_post( $this->base() . '/v1/connector/disconnect', [ 'timeout' => 15, 'headers' => $this->headers() ] );
        return $this->handle_response( $response, 'disconnect', false );
    }

    /**
     * @param bool $stamps_sync Whether a success counts as "content synced" for the admin screen's proof-of-life.
     * @return true|WP_Error
     */
    private function handle_response( $response, $op, $stamps_sync = true ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code >= 200 && $code < 300 ) {
            // Stamp the last successful CONTENT push — the admin screen turns this into "synced 2 minutes
            // ago", which is the whole proof-of-life the owner gets. Deletes, manifests and inventory don't
            // count: none of them says the catalog is current.
            if ( $stamps_sync ) {
                update_option( 'personaizer_last_sync', time(), false );
            }
            return true;
        }
        $raw      = (string) wp_remote_retrieve_body( $response );
        $body     = wp_strip_all_tags( $raw );
        $api_code = self::error_code( $raw );

        // The server refused because the CONNECTOR changed — a lane switched off, the site disconnected on
        // personaizer.com — not because of this item. The cached connector is stale by definition; drop it
        // so the very next hook reads the truth and stops pushing into a lane the owner closed.
        if ( self::is_lane_closed_code( $api_code ) ) {
            $this->forget_connector();
        }

        // Remember WHY, in the owner's words, so the admin screen can explain a stalled sync instead
        // of just showing a smaller number than expected.
        update_option( 'personaizer_last_error', [
            'message' => self::friendly_error( $code, $body ),
            'code'    => $api_code,
            'at'      => time(),
        ], false );

        return new WP_Error(
            'personaizer_api_' . $code,
            sprintf( 'PERSONAIZER %s failed (HTTP %d): %s', $op, $code, $body ),
            [ 'status' => $code, 'code' => $api_code ]
        );
    }

    /** The RFC7807 `code` from a problem body (e.g. "limits.quota_exceeded"), or '' when the body isn't ours. */
    private static function error_code( $body ) {
        $data = json_decode( (string) $body, true );
        return ( is_array( $data ) && ! empty( $data['code'] ) ) ? (string) $data['code'] : '';
    }

    /**
     * Was this failure the account's knowledge quota being full (HTTP 402 limits.quota_exceeded)?
     *
     * The one API rejection the sync layer treats specially: the items are fine, the plan is full, so
     * they're remembered for an automatic replay after the owner upgrades — never counted as broken.
     * Falls back to the bare 402 status, which on the knowledge surface only ever means quota.
     *
     * @param mixed $result A return value from any of the write methods above.
     */
    public static function is_quota_error( $result ) {
        if ( ! is_wp_error( $result ) ) return false;
        $data = $result->get_error_data();
        if ( ! is_array( $data ) ) return false;
        return ( ( $data['code'] ?? '' ) === 'limits.quota_exceeded' )
            || ( (int) ( $data['status'] ?? 0 ) === 402 );
    }

    /**
     * Was this failure the lane (or the whole connector) being closed on personaizer.com?
     *
     * Not an error to retry: the owner switched the lane off, or disconnected the site there. The item is
     * fine; the door is shut. Retrying would hammer a closed door on every edit, so the sync layer drops the
     * item from its queues and lets the next connector read decide what syncs.
     */
    public static function is_lane_closed( $result ) {
        if ( ! is_wp_error( $result ) ) return false;
        $data = $result->get_error_data();
        return is_array( $data ) && self::is_lane_closed_code( (string) ( $data['code'] ?? '' ) );
    }

    private static function is_lane_closed_code( $code ) {
        return in_array( $code, array( 'connector.lane_disabled', 'connector.lane_unknown', 'connector.disconnected' ), true );
    }

    /**
     * Turn an RFC7807 problem body into one sentence a site owner can act on. Falls back to the
     * status code when the body isn't ours (a proxy error page, say).
     */
    private static function friendly_error( $code, $body ) {
        // Auth first: the body's title for a 401 is just "Unauthorized", which tells the owner
        // nothing they can act on. The status code is the more informative signal here.
        if ( $code === 401 || $code === 403 ) {
            return 'Your connection key was rejected — reconnect this site.';
        }
        $data = json_decode( $body, true );
        if ( is_array( $data ) ) {
            // Per-item validation failures (422) carry the actionable reason as objects; they repeat per
            // item, so one is enough. Guard that errors[0] is actually an OBJECT: on a 402 quota problem
            // `errors` is a bare string[], and indexing a string with 'message' would return its first
            // character — so fall through to `detail` (the quota prose) instead.
            if ( isset( $data['errors'][0] ) && is_array( $data['errors'][0] ) && ! empty( $data['errors'][0]['message'] ) ) {
                return (string) $data['errors'][0]['message'];
            }
            if ( ! empty( $data['detail'] ) ) return (string) $data['detail'];
            if ( ! empty( $data['title'] ) )  return (string) $data['title'];
        }
        return sprintf( 'The PERSONAIZER API returned HTTP %d.', (int) $code );
    }
}
