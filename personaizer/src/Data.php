<?php
namespace Personaizer;

use Personaizer\Sync\Backfill;
use Personaizer\Sync\Outbox;
use Personaizer\Sync\Reconcile;
use Personaizer\Sync\State;
use Personaizer\Sync\Worker;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Everything the plugin stores on this site, in one place — so Disconnect and uninstall.php remove exactly all of
 * it and nothing else. Options are listed in Options::ALL; the rest is the outbox table, the transients and the
 * schedules.
 */
final class Data {

    /** Disconnect: forget the connection and everything queued for it. The plugin stays installed. */
    public static function clear() {
        foreach ( Options::ALL as $option ) delete_option( $option );
        State::forget();
        Outbox::clear();
        Worker::disarm();
        Backfill::disarm();
        Reconcile::disarm();
        self::clear_transients();
    }

    /** Uninstall: clear() and drop the table and the schedule too. */
    public static function purge() {
        self::clear();
        Outbox::drop();
        wp_clear_scheduled_hook( 'personaizer_daily' );
    }

    private static function clear_transients() {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_personaizer\\_%' OR option_name LIKE '\\_transient\\_timeout\\_personaizer\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_site\\_transient\\_personaizer\\_%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_personaizer\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }
}
