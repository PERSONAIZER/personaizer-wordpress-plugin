<?php
namespace Personaizer\Sync;

use Personaizer\Content\PostPayload;
use Personaizer\Content\ProductPayload;
use Personaizer\Options;
use Personaizer\Site\Streams;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Sync everything that already exists." Hooks can't fire for content that was published before the site
 * connected, so once — after a connect, and on demand — every published record of every stream that is on is
 * enqueued. Enumeration alone: the outbox and the worker do the pushing. Walks in pages on WP-Cron with a small
 * cursor option, so a 50 000-product catalog never has to fit in one request.
 */
final class Backfill {

	const HOOK           = 'personaizer_backfill';
	const PAGE           = 500;
	const BUDGET_SECONDS = 20;

	public static function boot() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/** Start over: every stream that is on, from the beginning. */
	public static function start() {
		if ( ! Options::is_connected() ) {
			return;
		}
		update_option(
			Options::BACKFILL,
			array(
				'streams'     => array_keys( State::enabled_streams() ),
				'offset'      => 0,
				'enqueued'    => 0,
				'started_at'  => time(),
				'finished_at' => null,
			),
			false
		);
		self::arm( 0 );
	}

	public static function run() {
		$state = get_option( Options::BACKFILL );
		if ( ! is_array( $state ) || ! empty( $state['finished_at'] ) ) {
			return;
		}
		$deadline = microtime( true ) + self::BUDGET_SECONDS;

		while ( ! empty( $state['streams'] ) && microtime( true ) < $deadline ) {
			$stream = $state['streams'][0];
			$rows   = self::page( $stream, (int) $state['offset'] );
			if ( ! empty( $rows ) ) {
				Outbox::enqueue_upserts( $stream, $rows );
				$state['enqueued'] += count( $rows );
			}
			if ( count( $rows ) < self::PAGE ) {
				array_shift( $state['streams'] );
				$state['offset'] = 0;
			} else {
				$state['offset'] += self::PAGE;
			}
			update_option( Options::BACKFILL, $state, false );
		}

		if ( empty( $state['streams'] ) ) {
			$state['finished_at'] = time();
			update_option( Options::BACKFILL, $state, false );
			// Everything is queued; the first full-list check after the drain confirms nothing was missed.
			Reconcile::arm( HOUR_IN_SECONDS );
		} else {
			self::arm( 1 );
		}
		Worker::arm();
	}

	/** @return array<int,array{external_id:string,post_id:int}> published records of the stream, one page. */
	private static function page( $stream, $offset ) {
		$all = Streams::all();
		if ( ! isset( $all[ $stream ] ) ) {
			return array();
		}
		$ids  = get_posts(
			array(
				'post_type'      => $all[ $stream ]['post_type'],
				'post_status'    => 'publish',
				'posts_per_page' => self::PAGE,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				// get_posts() suppresses query filters by default, so a language plugin's "current language only"
				// filter does not hide the other languages' records from the enumeration.
				'no_found_rows'  => true,
			)
		);
		$rows = array();
		foreach ( $ids as $id ) {
			$rows[] = array(
				'external_id' => $stream === 'products' ? ProductPayload::external_id( $id ) : 'wp-' . $all[ $stream ]['post_type'] . '-' . (int) $id,
				'post_id'     => (int) $id,
			);
		}
		return $rows;
	}

	/** @return array{running:bool,enqueued:int} for the admin page. */
	public static function progress() {
		$state = get_option( Options::BACKFILL );
		if ( ! is_array( $state ) ) {
			return array(
				'running'  => false,
				'enqueued' => 0,
			);
		}
		return array(
			'running'  => empty( $state['finished_at'] ),
			'enqueued' => (int) $state['enqueued'],
		);
	}

	private static function arm( $delay ) {
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( time() + (int) $delay, self::HOOK );
	}

	public static function disarm() {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
