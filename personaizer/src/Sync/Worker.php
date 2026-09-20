<?php
namespace Personaizer\Sync;

use Personaizer\Api\Client;
use Personaizer\Api\Contracts;
use Personaizer\Content\PostPayload;
use Personaizer\Content\ProductPayload;
use Personaizer\Options;
use Personaizer\Site\Streams;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Drains the outbox to PERSONAIZER. One pass: ask which streams are on (Sync\State), then for each stream with
 * work take up to a batch of rows, build each record from the live post (a row whose post is no longer published
 * becomes a delete), send ONE items call, and act on the answer — written rows go, deferred rows wait for plan
 * room, rejected rows are kept with their reason, a closed stream drops its rows, anything else backs off.
 *
 * Runs on a single-event cron armed by whoever enqueues, and opportunistically at the end of an admin request
 * (with a small time budget), so a site whose WP-Cron never fires still syncs whenever someone is in wp-admin.
 */
final class Worker {

	const HOOK                    = 'personaizer_drain';
	const BATCH                   = 100;
	const CRON_BUDGET_SECONDS     = 20;
	const SHUTDOWN_BUDGET_SECONDS = 4;

	private static $armed_for_shutdown = false;

	public static function boot() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/** Something was enqueued: run soon. A single event; arming twice is one run. */
	public static function arm() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK );
		}
		// And at the end of this request when it is an admin one — the fastest path on a quiet site.
		if ( is_admin() && ! self::$armed_for_shutdown ) {
			self::$armed_for_shutdown = true;
			add_action( 'shutdown', array( __CLASS__, 'run_at_shutdown' ), 5 );
		}
	}

	public static function disarm() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** The cron tick. */
	public static function run() {
		self::drain( self::CRON_BUDGET_SECONDS );
	}

	public static function run_at_shutdown() {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		self::drain( self::SHUTDOWN_BUDGET_SECONDS );
	}

	/**
	 * Push until the outbox is empty or the budget is spent. Returns how many records landed.
	 *
	 * @param int $budget_seconds
	 */
	public static function drain( $budget_seconds ) {
		if ( ! Options::is_connected() ) {
			return 0;
		}
		$deadline = microtime( true ) + $budget_seconds;
		$landed   = 0;

		$on = State::enabled_streams();
		foreach ( Outbox::streams_with_work() as $stream ) {
			if ( ! isset( $on[ $stream ] ) ) {
				continue;   // off, or never switched on: rows wait for the owner
			}
			while ( microtime( true ) < $deadline ) {
				$rows = Outbox::claim( $stream, self::BATCH );
				if ( empty( $rows ) ) {
					break;
				}
				$landed += self::push( $stream, $on[ $stream ]['type'], $rows );
				if ( count( $rows ) < self::BATCH ) {
					break;
				}
			}
			if ( microtime( true ) >= $deadline ) {
				self::arm();   // more to do — come back
				break;
			}
		}
		return $landed;
	}

	/**
	 * One batch of one stream → one items call.
	 *
	 * @param object[] $rows Outbox rows.
	 * @return int records that landed.
	 */
	private static function push( $stream, $type, array $rows ) {
		$upserts   = array();
		$deletes   = array();
		$row_by_id = array();
		foreach ( $rows as $row ) {
			$row_by_id[ $row->external_id ] = $row;
			$record                         = $row->op === Outbox::UPSERT ? self::record( $type, $row ) : null;
			if ( $record === null ) {
				$deletes[] = $row->external_id;     // unpublished, hidden, trashed, gone — or an explicit delete
			} else {
				$upserts[] = $record;
			}
		}

		$result = Client::items( $stream, $upserts, $deletes );

		if ( is_wp_error( $result ) ) {
			if ( Client::is_closed( $result ) ) {
				// The owner shut this stream (or disconnected) on personaizer.com. Not a failure of these records:
				// drop them and let the next state read decide what syncs.
				Outbox::drop_stream( $stream );
				State::forget();
				return 0;
			}
			if ( Client::is_quota( $result ) ) {
				Outbox::defer( self::ids( $rows ) );
				return 0;
			}
			$attempts = max(
				array_map(
					static function ( $r ) {
						return (int) $r->attempts;
					},
					$rows
				)
			);
			Outbox::retry_later( self::ids( $rows ), $attempts + 1, $result->get_error_message() );
			return 0;
		}

		// Written and taken deletes are done; deferred wait for plan room; rejected keep their reason. A catalog
		// batch with an invalid record writes nothing (all-or-nothing on validation) — the others come back in
		// no list, stay queued and go again without the bad one.
		$done = array();
		foreach ( $result['written'] as $id ) {
			if ( isset( $row_by_id[ $id ] ) ) {
				$done[] = $row_by_id[ $id ]->id;
			}
		}
		if ( ! $result['deletes_busy'] ) {
			foreach ( $deletes as $id ) {
				if ( isset( $row_by_id[ $id ] ) ) {
					$done[] = $row_by_id[ $id ]->id;
				}
			}
		}
		Outbox::ack( $done );

		$deferred = array();
		foreach ( $result['deferred'] as $id ) {
			if ( isset( $row_by_id[ $id ] ) ) {
				$deferred[] = $row_by_id[ $id ]->id;
			}
		}
		Outbox::defer( $deferred );

		foreach ( $result['rejected'] as $id => $why ) {
			if ( isset( $row_by_id[ $id ] ) ) {
				Outbox::fail( array( $row_by_id[ $id ]->id ), trim( $why['code'] . ' ' . $why['message'] ) );
			}
		}

		if ( count( $result['written'] ) > 0 ) {
			Options::clear_error();
			// The synced counts the page shows come from the cached sync answer — it is out of date now.
			State::forget();
		}
		return count( $result['written'] );
	}

	/**
	 * The record for a row, from the live post — or null when the record no longer belongs to the AI (unpublished,
	 * hidden, trashed, deleted), which turns the row into a delete.
	 */
	private static function record( $type, $row ) {
		if ( $type === Streams::CATALOG ) {
			$product_id = $row->post_id ?: ProductPayload::product_id_of( $row->external_id );
			$product    = $product_id && Streams::has_woocommerce() ? wc_get_product( $product_id ) : null;
			if ( ! $product || ! $product->exists() || ! ProductPayload::is_syncable( $product ) ) {
				return null;
			}
			return ProductPayload::build( $product );
		}
		$post_id = $row->post_id ?: PostPayload::post_id_of( $row->external_id );
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || $post->post_status !== 'publish' ) {
			return null;
		}
		return PostPayload::build( $post );
	}

	private static function ids( array $rows ) {
		return array_map(
			static function ( $r ) {
				return (int) $r->id;
			},
			$rows
		);
	}
}
