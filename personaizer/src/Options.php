<?php
namespace Personaizer;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Everything the plugin keeps in wp_options, named in one place. The connection (ids, credential, secret) is
 * written once by the connect flow; the rest is bookkeeping the sync keeps for itself. The admin page reads,
 * never writes, any of it except the identity toggle.
 */
final class Options {

    // The connection — written by Connect\Flow, cleared by Data::clear().
    const INTEGRATION_ID  = 'personaizer_integration_id';
    const INTEGRATION_KEY = 'personaizer_integration_key';
    const BRAND_ID        = 'personaizer_brand_id';
    const PERSONA_ID      = 'personaizer_persona_id';
    const IDENTITY_SECRET = 'personaizer_identity_secret';

    // The one setting kept here: whether signed-in customers are recognised in the widget.
    const IDENTIFY_USERS = 'personaizer_identify_users';

    // Bookkeeping.
    const LAST_ERROR      = 'personaizer_last_error';      // { message, code, at }
    const LAST_PUSH       = 'personaizer_last_push';       // unix time of the last successful items call
    const GENERATIONS     = 'personaizer_generations';     // { stream => int } — the reconcile counter per stream
    const BACKFILL        = 'personaizer_backfill';        // Sync\Backfill's cursor
    const RECONCILE       = 'personaizer_reconcile';       // Sync\Reconcile's walk
    const RECONCILE_LAST  = 'personaizer_reconcile_last';  // { stream => outcome } — what the admin page shows
    const STATE_FALLBACK  = 'personaizer_state';           // the last good sync answer, for when the API is unreachable

    /** Every option above — the list Data::clear() and uninstall.php walk. */
    const ALL = array(
        self::INTEGRATION_ID, self::INTEGRATION_KEY, self::BRAND_ID, self::PERSONA_ID, self::IDENTITY_SECRET,
        self::IDENTIFY_USERS,
        self::LAST_ERROR, self::LAST_PUSH, self::GENERATIONS, self::BACKFILL, self::RECONCILE, self::RECONCILE_LAST,
        self::STATE_FALLBACK,
    );

    public static function is_connected() {
        return self::integration_key() !== '';
    }

    public static function integration_key() {
        return trim( (string) get_option( self::INTEGRATION_KEY, '' ) );
    }

    public static function persona_id() {
        return trim( (string) get_option( self::PERSONA_ID, '' ) );
    }

    public static function identity_secret() {
        return trim( (string) get_option( self::IDENTITY_SECRET, '' ) );
    }

    public static function identify_users() {
        return get_option( self::IDENTIFY_USERS, '' ) === '1';
    }

    /** Remember why the last call failed, in the owner's words, so the admin page can explain a stalled sync. */
    public static function record_error( $message, $code = '' ) {
        update_option( self::LAST_ERROR, array( 'message' => (string) $message, 'code' => (string) $code, 'at' => time() ), false );
    }

    public static function clear_error() {
        delete_option( self::LAST_ERROR );
    }

    /** @return array{message:string,code:string,at:int}|null */
    public static function last_error() {
        $e = get_option( self::LAST_ERROR );
        return is_array( $e ) && ! empty( $e['message'] ) ? $e : null;
    }

    /** The next generation for a stream's full list — strictly increasing per stream, a counter the site keeps. */
    public static function next_generation( $stream ) {
        $all = get_option( self::GENERATIONS, array() );
        if ( ! is_array( $all ) ) $all = array();
        $next = ( isset( $all[ $stream ] ) ? (int) $all[ $stream ] : 0 ) + 1;
        $all[ $stream ] = $next;
        update_option( self::GENERATIONS, $all, false );
        return $next;
    }
}
