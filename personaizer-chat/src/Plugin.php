<?php
namespace Personaizer;

use Personaizer\Admin\Page;
use Personaizer\Connect\Flow;
use Personaizer\Sync\Backfill;
use Personaizer\Sync\Daily;
use Personaizer\Sync\Hooks;
use Personaizer\Sync\Outbox;
use Personaizer\Sync\Reconcile;
use Personaizer\Sync\Worker;
use Personaizer\Widget\Embed;
use Personaizer\Widget\IdentityToken;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin together. Nothing here does work: every part registers its hooks and waits.
 *
 * What the plugin is, in one paragraph: the site's pages, posts, products (and public custom types) are STREAMS.
 * Every change on the site drops a row into the outbox (Sync\Hooks → Sync\Outbox); a worker drains the outbox
 * to PERSONAIZER in batches (Sync\Worker), after asking once a minute which streams the owner has switched on
 * (Sync\State ← POST /v1/integration/sync). Once a day each stream's full list is reconciled so nothing drifts
 * (Sync\Reconcile). The connection itself is made once, from the admin page (Connect\Flow), and the chat widget
 * rides on every page (Widget\Embed). Everything the owner configures lives on personaizer.com.
 */
final class Plugin {

	/** The version this site last ran, so an update can do its one-time work once. */
	const VERSION_OPTION = 'personaizer_version';

	/** A second full-list check after an update that renamed records: the first adds them, the second (agreeing) removes the old. */
	const RECHECK_HOOK = 'personaizer_recheck';

	public static function boot() {
		add_action( self::RECHECK_HOOK, array( Reconcile::class, 'start' ) );
		self::upgrade();
		Flow::boot();
		Hooks::boot();
		Worker::boot();
		Backfill::boot();
		Reconcile::boot();
		Daily::boot();
		Page::boot();
		Embed::boot();
		IdentityToken::boot();
	}

	public static function activate() {
		Outbox::install();
		Daily::schedule();
	}

	/**
	 * 3.1 names every record by its bare post ID (848, not wp-page-848), the id PERSONAIZER's onboarding also gives it,
	 * so a site read before install is enriched in place. A connected site coming from an earlier version drops the
	 * rows queued under the old ids and sends its full lists twice: the first puts every record under its new id, the
	 * second agrees that the old ids are gone, and PERSONAIZER removes them.
	 */
	private static function upgrade() {
		$was = (string) get_option( self::VERSION_OPTION, '' );
		if ( $was === PERSONAIZER_VERSION ) {
			return;
		}
		update_option( self::VERSION_OPTION, PERSONAIZER_VERSION, false );
		if ( ( $was === '' || version_compare( $was, '3.1.0', '<' ) ) && Options::is_connected() ) {
			Outbox::clear();
			Reconcile::forget();
			Reconcile::start();
			wp_schedule_single_event( time() + 30 * MINUTE_IN_SECONDS, self::RECHECK_HOOK );
		}
	}

	/** A deactivated plugin never keeps walking: every schedule goes; the connection and the outbox stay for reactivation. */
	public static function deactivate() {
		Daily::unschedule();
		wp_clear_scheduled_hook( self::RECHECK_HOOK );
		Worker::disarm();
		Backfill::disarm();
		Reconcile::disarm();
	}
}
