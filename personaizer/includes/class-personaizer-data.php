<?php
/**
 * Everything this plugin stores, and how to remove it.
 *
 * Two callers need the same answer to "what did we leave on this site?": the Disconnect action (owner
 * unlinks but keeps the plugin) and uninstall.php (owner deletes the plugin). Keeping the list in one
 * place is the point — a forgotten option here is a credential that outlives the thing that created it.
 *
 * Deliberately inert: no hooks, no bootstrap. uninstall.php runs WITHOUT the plugin loaded, so this
 * file has to be safe to require on its own.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Personaizer_Data {

    /** Every option the plugin persists in wp_options. */
    const OPTIONS = [
        // Connection — provisioned by Connect.
        'personaizer_connector_id',
        'personaizer_connector_key',
        'personaizer_brand_id',
        'personaizer_persona_id',
        'personaizer_identity_secret',
        'personaizer_identify_users',
        // What personaizer.com last said about the connector, and how syncing is going.
        'personaizer_connector_state',
        'personaizer_backfill_state',
        'personaizer_manifest_state',
        'personaizer_manifest_result',
        'personaizer_pending_removals',
        'personaizer_pending_overflow',
        'personaizer_pending_retry',
        'personaizer_last_sync',
        'personaizer_last_error',
    ];

    /**
     * Options earlier releases stored and this one doesn't. Cleared on connect, disconnect and uninstall so
     * an upgraded install never carries a credential or a setting that nothing reads any more.
     */
    const RETIRED_OPTIONS = [
        // 1.x: the persona's secret key did the syncing; 2.0 syncs with the connector key.
        'personaizer_secret_key',
        'personaizer_connected_at',
        // 1.x: which lanes sync was a local setting; 2.0 reads it from the connector.
        'personaizer_sync_post_types',
        'personaizer_sync_products',
        // < 1.3: appearance/behavior (now on the persona's Widget tab) and AI Search.
        'personaizer_position',
        'personaizer_theme',
        'personaizer_accent',
        'personaizer_title',
        'personaizer_auto_open',
        'personaizer_nudge',
        'personaizer_search_enabled',
        'personaizer_search_mode',
        'personaizer_search_selector',
    ];

    /** Scheduled hooks the plugin owns. */
    const CRONS = [
        'personaizer_daily',
        'personaizer_backfill',
        'personaizer_manifest',
        'personaizer_catch_up',
    ];

    /** Scheduled hooks earlier releases owned — cleared alongside, for the same reason as RETIRED_OPTIONS. */
    const RETIRED_CRONS = [
        'personaizer_reconcile',
        'personaizer_overflow_catchup',
    ];

    /**
     * Forget this site's PERSONAIZER account entirely: credentials, sync state, caches, schedules.
     *
     * Does NOT touch anything on the PERSONAIZER side — the persona and its knowledge stay put, so
     * reconnecting later picks up where it left off. It only makes THIS site stop being connected.
     */
    public static function clear() {
        foreach ( self::OPTIONS as $option ) {
            delete_option( $option );
        }
        foreach ( self::CRONS as $hook ) {
            wp_clear_scheduled_hook( $hook );
        }
        self::clear_retired();
        self::clear_transients();
    }

    /** Remove what earlier releases left on this site (see RETIRED_OPTIONS). */
    public static function clear_retired() {
        foreach ( self::RETIRED_OPTIONS as $option ) {
            delete_option( $option );
        }
        foreach ( self::RETIRED_CRONS as $hook ) {
            wp_clear_scheduled_hook( $hook );
        }
        // 1.x kept a per-item sync fingerprint as post meta on every synced product/page; 2.0 keeps the
        // fingerprint on the backend. One row per item, so an upgraded site would otherwise carry
        // thousands of rows nothing reads.
        delete_post_meta_by_key( '_personaizer_sync_hash' );
    }

    /**
     * Drop cached persona profiles and the update manifest. Cache keys embed a hash of persona id + API
     * base, so there's no single name to delete — clear by prefix.
     *
     * Both flavours are swept: ordinary transients (the profile cache) and SITE transients (the updater's
     * manifest, which is site-wide because WordPress's update data is). Sweeping by prefix rather than
     * naming keys is what keeps this honest — a new cache added elsewhere is covered the day it ships,
     * which a hand-maintained list would not be.
     */
    private static function clear_transients() {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s"
                . " OR option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_personaizer_' ) . '%',
                $wpdb->esc_like( '_transient_timeout_personaizer_' ) . '%',
                $wpdb->esc_like( '_site_transient_personaizer_' ) . '%',
                $wpdb->esc_like( '_site_transient_timeout_personaizer_' ) . '%'
            )
        );
    }
}
