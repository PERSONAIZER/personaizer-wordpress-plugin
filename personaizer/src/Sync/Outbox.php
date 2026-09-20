<?php
namespace Personaizer\Sync;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The outbox: one row per record that still has to reach PERSONAIZER. Every hook, the backfill and the full-list
 * check only ever INSERT rows here; one worker drains them (Sync\Worker). Payloads are never stored — a row is
 * "push this record" and the record is built from the live post at drain time, so a row for a post that was
 * unpublished in the meantime becomes a delete, and ten edits of one page collapse into one row.
 *
 * A table, not a serialized option: hooks fire from concurrent requests and the worker from cron, and an array
 * read-modify-written from three places at once loses rows. UNIQUE (stream, external_id) keeps one row per
 * record; ON DUPLICATE KEY makes the enqueue idempotent.
 *
 * States: queued (push at next drain, once next_at has passed), deferred (the plan had no room — released when the
 * plan shows headroom), failed (refused for what it carried — retried daily, shown on the admin page).
 */
final class Outbox {

    const UPSERT = 'upsert';
    const DELETE = 'delete';

    const QUEUED   = 'queued';
    const DEFERRED = 'deferred';
    const FAILED   = 'failed';

    const SCHEMA_VERSION = 1;
    const SCHEMA_OPTION  = 'personaizer_outbox_schema';

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'personaizer_outbox';
    }

    /** Create the table (activation) — idempotent, and re-run on load when the schema version moved. */
    public static function install() {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            stream varchar(40) NOT NULL,
            external_id varchar(128) NOT NULL,
            op varchar(8) NOT NULL,
            post_id bigint(20) unsigned NULL,
            state varchar(10) NOT NULL DEFAULT 'queued',
            attempts tinyint unsigned NOT NULL DEFAULT 0,
            next_at datetime NULL,
            last_error text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY stream_record (stream, external_id),
            KEY stream_state (stream, state, next_at)
        ) {$charset};" );
        update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
    }

    /** A plugin updated in place never ran activation: make sure the table exists before the first write. */
    public static function ensure() {
        if ( (int) get_option( self::SCHEMA_OPTION, 0 ) !== self::SCHEMA_VERSION ) self::install();
    }

    public static function drop() {
        global $wpdb;
        $wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        delete_option( self::SCHEMA_OPTION );
    }

    // ── writes ──

    /** "Push this record" — one row per (stream, record); a later enqueue of the same record resets it to queued now. */
    public static function enqueue_upsert( $stream, $external_id, $post_id ) {
        self::upsert_row( $stream, $external_id, self::UPSERT, (int) $post_id );
    }

    /** "Remove this record." */
    public static function enqueue_delete( $stream, $external_id ) {
        self::upsert_row( $stream, $external_id, self::DELETE, null );
    }

    private static function upsert_row( $stream, $external_id, $op, $post_id ) {
        global $wpdb;
        self::ensure();
        $now = current_time( 'mysql', true );
        $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'INSERT INTO ' . self::table() . ' (stream, external_id, op, post_id, state, attempts, next_at, last_error, created_at, updated_at)
             VALUES (%s, %s, %s, %d, %s, 0, NULL, NULL, %s, %s)
             ON DUPLICATE KEY UPDATE op = VALUES(op), post_id = VALUES(post_id), state = VALUES(state), attempts = 0, next_at = NULL, last_error = NULL, updated_at = VALUES(updated_at)',
            $stream, $external_id, $op, $post_id, self::QUEUED, $now, $now
        ) );
    }

    /**
     * Enqueue many at once (the backfill, the full-list check's missing/stale) — one statement per 100.
     *
     * @param array<int,array{external_id:string,post_id:int}> $rows
     */
    public static function enqueue_upserts( $stream, array $rows ) {
        global $wpdb;
        self::ensure();
        $now = current_time( 'mysql', true );
        foreach ( array_chunk( $rows, 100 ) as $chunk ) {
            $values = array();
            $args   = array();
            foreach ( $chunk as $row ) {
                $values[] = '(%s, %s, %s, %d, %s, 0, NULL, NULL, %s, %s)';
                array_push( $args, $stream, $row['external_id'], self::UPSERT, (int) $row['post_id'], self::QUEUED, $now, $now );
            }
            $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'INSERT INTO ' . self::table() . ' (stream, external_id, op, post_id, state, attempts, next_at, last_error, created_at, updated_at) VALUES ' . implode( ', ', $values ) .
                ' ON DUPLICATE KEY UPDATE op = VALUES(op), post_id = VALUES(post_id), state = VALUES(state), attempts = 0, next_at = NULL, last_error = NULL, updated_at = VALUES(updated_at)',
                $args
            ) );
        }
    }

    // ── the worker's side ──

    /**
     * Rows ready to push in a stream: queued, and either never tried or past their backoff.
     *
     * @return array<int,object{id:int,stream:string,external_id:string,op:string,post_id:?int,attempts:int}>
     */
    public static function claim( $stream, $limit ) {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'SELECT id, stream, external_id, op, post_id, attempts FROM ' . self::table() .
            ' WHERE stream = %s AND state = %s AND (next_at IS NULL OR next_at <= %s) ORDER BY id ASC LIMIT %d',
            $stream, self::QUEUED, current_time( 'mysql', true ), (int) $limit
        ) );
    }

    /** Streams that have something ready to push. @return string[] */
    public static function streams_with_work() {
        global $wpdb;
        return (array) $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'SELECT DISTINCT stream FROM ' . self::table() . ' WHERE state = %s AND (next_at IS NULL OR next_at <= %s)',
            self::QUEUED, current_time( 'mysql', true )
        ) );
    }

    /** Landed: the rows are gone. */
    public static function ack( array $ids ) {
        self::delete_ids( $ids );
    }

    /** Refused for what it carried: kept as failed with the reason, retried by the daily tick. */
    public static function fail( array $ids, $error ) {
        self::set_state( $ids, self::FAILED, null, (string) $error );
    }

    /** The plan had no room: kept as deferred until the plan shows headroom. */
    public static function defer( array $ids ) {
        self::set_state( $ids, self::DEFERRED, null, null );
    }

    /** A transient failure: back off, doubling from a minute, capped at a day. */
    public static function retry_later( array $ids, $attempts, $error ) {
        $delay = min( DAY_IN_SECONDS, MINUTE_IN_SECONDS * (int) pow( 2, min( 10, (int) $attempts ) ) );
        self::set_state( $ids, self::QUEUED, gmdate( 'Y-m-d H:i:s', time() + $delay ), (string) $error, true );
    }

    /** Deferred and failed rows go back to queued — after an upgrade, or on the daily retry. */
    public static function release( $state ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'UPDATE ' . self::table() . ' SET state = %s, next_at = NULL, updated_at = %s WHERE state = %s',
            self::QUEUED, current_time( 'mysql', true ), $state
        ) );
    }

    /** The owner closed the stream on personaizer.com: nothing of it is pushed until it opens again. */
    public static function drop_stream( $stream ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE stream = %s', $stream ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function clear() {
        global $wpdb;
        $wpdb->query( 'DELETE FROM ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    // ── the admin page's side ──

    /** @return array<string,array{queued:int,deferred:int,failed:int}> per stream. */
    public static function counts() {
        global $wpdb;
        self::ensure();
        $rows = (array) $wpdb->get_results( 'SELECT stream, state, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY stream, state' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $out  = array();
        foreach ( $rows as $row ) {
            if ( ! isset( $out[ $row->stream ] ) ) $out[ $row->stream ] = array( 'queued' => 0, 'deferred' => 0, 'failed' => 0 );
            if ( isset( $out[ $row->stream ][ $row->state ] ) ) $out[ $row->stream ][ $row->state ] = (int) $row->n;
        }
        return $out;
    }

    /** The most recent failures, for the admin page. @return array<int,object{stream:string,external_id:string,last_error:string,updated_at:string}> */
    public static function failures( $limit = 5 ) {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'SELECT stream, external_id, last_error, updated_at FROM ' . self::table() . ' WHERE state = %s ORDER BY updated_at DESC LIMIT %d',
            self::FAILED, (int) $limit
        ) );
    }

    // ── plumbing ──

    private static function delete_ids( array $ids ) {
        global $wpdb;
        $ids = array_map( 'intval', $ids );
        if ( empty( $ids ) ) return;
        $wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE id IN (' . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private static function set_state( array $ids, $state, $next_at, $error, $bump_attempts = false ) {
        global $wpdb;
        $ids = array_map( 'intval', $ids );
        if ( empty( $ids ) ) return;
        $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'UPDATE ' . self::table() . ' SET state = %s, next_at = %s, last_error = %s, attempts = attempts + %d, updated_at = %s WHERE id IN (' . implode( ',', $ids ) . ')',
            $state, $next_at, $error, $bump_attempts ? 1 : 0, current_time( 'mysql', true )
        ) );
    }
}
