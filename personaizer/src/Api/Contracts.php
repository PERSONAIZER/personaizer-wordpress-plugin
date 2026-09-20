<?php
namespace Personaizer\Api;

/**
 * The wire shapes of PERSONAIZER's push surface, read into the arrays the rest of the plugin works with.
 *
 * This is the ONLY file that knows what the API's JSON looks like. Every reader here is exercised against the
 * backend's own recorded exchanges (fixtures/v1-integration/*.json, copied from the backend repo by
 * tools/sync-fixtures.sh), so a change on the other side fails a test in this repo before it fails a site.
 * Pure functions, no WordPress — that is what lets the tests run without a WordPress install.
 */
final class Contracts {

    // Problem codes the sync layer acts on. Everything else is "an error", retried later.
    const CLOSED = 'integration.closed';           // disconnected, or the stream is off / was never on — drop and re-sync
    const QUOTA  = 'limits.quota_exceeded';         // the plan is full — keep the records for after an upgrade
    const STALE_GENERATION = 'integration.reconcile_stale_generation';

    // ── connect ──

    /** @return array{connect_id:string} */
    public static function start_response( array $body ) {
        return array( 'connect_id' => (string) ( $body['connect_id'] ?? '' ) );
    }

    /** @return array{integration_id:string,integration_key:string,brand_id:string,persona_id:string,identity_secret:string} */
    public static function token_response( array $body ) {
        return array(
            'integration_id'  => (string) ( $body['integration_id'] ?? '' ),
            'integration_key' => (string) ( $body['integration_key'] ?? '' ),
            'brand_id'        => (string) ( $body['brand_id'] ?? '' ),
            'persona_id'      => (string) ( $body['persona_id'] ?? '' ),
            'identity_secret' => (string) ( $body['identity_secret'] ?? '' ),
        );
    }

    // ── sync ──

    /**
     * @return array{
     *   status:string,
     *   brand:array{id:string,name:string},
     *   persona:?array{id:string,name:string,avatar_url:string,building:bool},
     *   plan:array{name:string,knowledge_units_used:float,knowledge_units_limit:?float},
     *   streams:array<string,array{enabled:bool,type:string,source_id:string,document_count:int,ready_count:int,last_reconcile:?array}>
     * }
     */
    public static function sync_response( array $body ) {
        $brand   = is_array( $body['brand'] ?? null ) ? $body['brand'] : array();
        $persona = is_array( $body['persona'] ?? null ) ? $body['persona'] : null;
        $plan    = is_array( $body['plan'] ?? null ) ? $body['plan'] : array();

        $streams = array();
        foreach ( (array) ( $body['streams'] ?? array() ) as $row ) {
            if ( ! is_array( $row ) || empty( $row['stream_key'] ) ) continue;
            $r = is_array( $row['last_reconcile'] ?? null ) ? $row['last_reconcile'] : null;
            $streams[ (string) $row['stream_key'] ] = array(
                'enabled'        => ! empty( $row['enabled'] ),
                'type'           => (string) ( $row['type'] ?? '' ),
                'source_id'      => (string) ( $row['source_id'] ?? '' ),
                'document_count' => (int) ( $row['document_count'] ?? 0 ),
                'ready_count'    => (int) ( $row['ready_count'] ?? 0 ),
                'last_reconcile' => $r === null ? null : array(
                    'generation'   => (int) ( $r['generation'] ?? 0 ),
                    'missing'      => (int) ( $r['missing'] ?? 0 ),
                    'stale'        => (int) ( $r['stale'] ?? 0 ),
                    'held_orphans' => (int) ( $r['held_orphans'] ?? 0 ),
                    'at'           => (string) ( $r['at'] ?? '' ),
                ),
            );
        }

        return array(
            'status'  => (string) ( $body['status'] ?? '' ),
            'brand'   => array( 'id' => (string) ( $brand['id'] ?? '' ), 'name' => (string) ( $brand['name'] ?? '' ) ),
            'persona' => $persona === null ? null : array(
                'id'         => (string) ( $persona['id'] ?? '' ),
                'name'       => (string) ( $persona['name'] ?? '' ),
                'avatar_url' => (string) ( $persona['avatar_url'] ?? '' ),
                'building'   => ! empty( $persona['building'] ),
            ),
            'plan' => array(
                'name'                  => (string) ( $plan['name'] ?? '' ),
                'knowledge_units_used'  => (float) ( $plan['knowledge_units_used'] ?? 0 ),
                // null (not 0) = unlimited — an absent ceiling must never read as "no room".
                'knowledge_units_limit' => isset( $plan['knowledge_units_limit'] ) && $plan['knowledge_units_limit'] !== null
                    ? (float) $plan['knowledge_units_limit'] : null,
            ),
            'streams' => $streams,
        );
    }

    /** Whether the plan has room for more knowledge, as of a sync answer. Unknown reads as "yes". */
    public static function has_headroom( array $sync ) {
        $limit = $sync['plan']['knowledge_units_limit'];
        return $limit === null || $sync['plan']['knowledge_units_used'] < $limit;
    }

    // ── items ──

    /** @return array{written:string[],deferred:string[],rejected:array<string,array{code:string,message:string}>,deleted:int,deletes_busy:bool} */
    public static function items_response( array $body ) {
        $rejected = array();
        foreach ( (array) ( $body['rejected'] ?? array() ) as $row ) {
            if ( ! is_array( $row ) || ! isset( $row['id'] ) ) continue;
            $rejected[ (string) $row['id'] ] = array(
                'code'    => (string) ( $row['code'] ?? '' ),
                'message' => (string) ( $row['message'] ?? '' ),
            );
        }
        return array(
            'written'      => self::ids( $body['written'] ?? array() ),
            'deferred'     => self::ids( $body['deferred'] ?? array() ),
            'rejected'     => $rejected,
            'deleted'      => (int) ( $body['deleted'] ?? 0 ),
            'deletes_busy' => ! empty( $body['deletes_busy'] ),
        );
    }

    // ── reconcile ──

    /** @return array{generation:int,missing:string[],stale:string[],orphans_deleted:int,orphans_held:int,busy:bool} */
    public static function reconcile_response( array $body ) {
        return array(
            'generation'      => (int) ( $body['generation'] ?? 0 ),
            'missing'         => self::ids( $body['missing'] ?? array() ),
            'stale'           => self::ids( $body['stale'] ?? array() ),
            'orphans_deleted' => (int) ( $body['orphans_deleted'] ?? 0 ),
            'orphans_held'    => (int) ( $body['orphans_held'] ?? 0 ),
            'busy'            => ! empty( $body['busy'] ),
        );
    }

    // ── problems (RFC 7807) ──

    /** The `code` of a problem body, or '' when the body isn't ours (a proxy error page, say). */
    public static function problem_code( $body ) {
        return is_array( $body ) && ! empty( $body['code'] ) ? (string) $body['code'] : '';
    }

    /** One sentence a site owner can act on, from a problem body and its status. */
    public static function problem_message( $body, $status ) {
        // The body's title for a 401 is just "Unauthorized", which tells the owner nothing they can act on.
        if ( $status === 401 || $status === 403 ) {
            return 'Your connection key was rejected — reconnect this site.';
        }
        if ( is_array( $body ) ) {
            // A 422 carries its reasons per item as objects; one is enough. Guard that errors[0] IS an object —
            // on a 402 `errors` is a bare string[], and indexing a string with 'message' would give a character.
            if ( isset( $body['errors'][0] ) && is_array( $body['errors'][0] ) && ! empty( $body['errors'][0]['message'] ) ) {
                return (string) $body['errors'][0]['message'];
            }
            if ( ! empty( $body['detail'] ) ) return (string) $body['detail'];
            if ( ! empty( $body['title'] ) )  return (string) $body['title'];
        }
        return sprintf( 'The PERSONAIZER API returned HTTP %d.', (int) $status );
    }

    /** @return string[] */
    private static function ids( $value ) {
        $out = array();
        foreach ( (array) $value as $v ) {
            if ( is_scalar( $v ) && (string) $v !== '' ) $out[] = (string) $v;
        }
        return $out;
    }
}
