<?php
namespace Personaizer\Sync;

use Personaizer\Api\Contracts;
use Personaizer\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one recurring tick. Everything hands-off rides it: the full-list check, releasing records the plan had no
 * room for once it has room, retrying records that were refused, and a drain. WP-Cron fires it on the first
 * visit after it is due — a quiet site may run it late, which is why the worker also runs at the end of admin
 * requests, and why the admin page tells an owner about DISABLE_WP_CRON + a system cron.
 */
final class Daily {

	const HOOK = 'personaizer_daily';

	public static function boot() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule();
		}
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function run() {
		if ( ! Options::is_connected() ) {
			return;
		}
		$state = State::get( true );
		// Records the plan had no room for go again only when the plan says it has room — not on every tick.
		if ( $state !== null && Contracts::has_headroom( $state ) ) {
			Outbox::release( Outbox::DEFERRED );
		}
		Outbox::release( Outbox::FAILED );
		Reconcile::start();
		Worker::arm();
	}
}
