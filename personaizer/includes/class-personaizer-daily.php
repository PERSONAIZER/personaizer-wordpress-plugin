<?php
/**
 * The plugin's one recurring tick.
 *
 * Everything hands-off rides this hook rather than each scheduling its own: the lane manifest walk
 * (Personaizer_Manifest), the retry/overflow catch-up (personaizer_catch_up), and the backfill watchdog
 * (Personaizer_Backfill::resume_if_stalled). One schedule to arm, one to clear, one line in System Info.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Personaizer_Daily {

    const HOOK = 'personaizer_daily';

    public static function boot() {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
        }
    }

    /** Called from the plugin's register_deactivation_hook. */
    public static function on_deactivate() {
        wp_clear_scheduled_hook( self::HOOK );
    }
}
