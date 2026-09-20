<?php
namespace Personaizer\Sync;

use Personaizer\Api\Client;
use Personaizer\Content\PostPayload;
use Personaizer\Content\ProductPayload;
use Personaizer\Options;
use Personaizer\Site\Streams;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The full-list check: once a day (and after a backfill, and on demand) each stream that is on is walked in full,
 * every published record fingerprinted — the hash of the exact record we would push — and the list handed to
 * PERSONAIZER. It answers what it is missing or holds stale (queued here for the worker) and removes what the site
 * no longer lists, behind rails of its own (a list that would orphan more than a quarter of a stream is held until
 * the next one agrees). This is what makes "the AI holds exactly what the site has" true after a missed hook, a
 * timed-out push, a product trashed while the plugin was inactive.
 *
 * A stream's list is sent only when the site can enumerate it fully right now: a post type that isn't registered
 * (WooCommerce deactivated) is skipped, never reported empty. The list is built in slices on WP-Cron; a
 * 100 000-record stream fits in the walk's option (id + hash ≈ 60 bytes each).
 */
final class Reconcile {

	const HOOK           = 'personaizer_reconcile';
	const SLICE          = 200;
	const BUDGET_SECONDS = 20;
	const MAX_ITEMS      = 100000;

	public static function boot() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/** Begin a walk of every stream that is on. A walk already in flight simply continues. */
	public static function start() {
		if ( ! Options::is_connected() ) {
			return;
		}
		$walk = get_option( Options::RECONCILE );
		if ( is_array( $walk ) && ! empty( $walk['streams'] ) && empty( $walk['finished_at'] ) ) {
			self::arm( 0 );
			return;
		}
		$streams = array();
		foreach ( State::enabled_streams() as $key => $_ ) {
			if ( self::can_enumerate( $key ) ) {
				$streams[] = $key;
			}
		}
		update_option(
			Options::RECONCILE,
			array(
				'streams'     => $streams,
				'stream'      => null,
				'offset'      => 0,
				'items'       => array(),
				'started_at'  => time(),
				'finished_at' => null,
			),
			false
		);
		self::arm( 0 );
	}

	public static function run() {
		$walk = get_option( Options::RECONCILE );
		if ( ! is_array( $walk ) || ! empty( $walk['finished_at'] ) ) {
			return;
		}
		$deadline = microtime( true ) + self::BUDGET_SECONDS;

		while ( microtime( true ) < $deadline ) {
			if ( $walk['stream'] === null ) {
				if ( empty( $walk['streams'] ) ) {
					break;
				}
				$walk['stream'] = array_shift( $walk['streams'] );
				$walk['offset'] = 0;
				$walk['items']  = array();
			}
			$slice = self::slice( $walk['stream'], (int) $walk['offset'] );
			foreach ( $slice as $item ) {
				$walk['items'][] = $item;
			}
			$walk['offset'] += self::SLICE;

			if ( count( $slice ) < self::SLICE ) {
				self::send( $walk['stream'], $walk['items'] );
				$walk['stream'] = null;
				$walk['items']  = array();
			} elseif ( count( $walk['items'] ) > self::MAX_ITEMS ) {
				self::record(
					$walk['stream'],
					array(
						'error' => 'The stream is larger than a single list can carry.',
						'at'    => time(),
					)
				);
				$walk['stream'] = null;
				$walk['items']  = array();
			}
			update_option( Options::RECONCILE, $walk, false );
		}

		if ( $walk['stream'] === null && empty( $walk['streams'] ) ) {
			$walk['finished_at'] = time();
			update_option( Options::RECONCILE, $walk, false );
			Worker::arm();
		} else {
			self::arm( 1 );
		}
	}

	/** @return array<int,array{id:string,fingerprint:string}> one slice of the stream's records with their fingerprints. */
	private static function slice( $stream, $offset ) {
		$all = Streams::all();
		if ( ! isset( $all[ $stream ] ) ) {
			return array();
		}
		$items = array();
		if ( $stream === 'products' ) {
			$products = wc_get_products(
				array(
					'status'  => 'publish',
					'limit'   => self::SLICE,
					'offset'  => $offset,
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);
			foreach ( $products as $product ) {
				if ( ! ProductPayload::is_syncable( $product ) ) {
					continue;
				}
				$record  = ProductPayload::build( $product );
				$items[] = array(
					'id'          => $record['id'],
					'fingerprint' => $record['fingerprint'],
				);
			}
			return $items;
		}
		$posts = get_posts(
			array(
				'post_type'        => $all[ $stream ]['post_type'],
				'post_status'      => 'publish',
				'posts_per_page'   => self::SLICE,
				'offset'           => $offset,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		foreach ( $posts as $post ) {
			$record = PostPayload::build( $post );
			if ( $record !== null ) {
				$items[] = array(
					'id'          => $record['id'],
					'fingerprint' => $record['fingerprint'],
				);
			}
		}
		return $items;
	}

	/** Send a stream's list and act on the answer: everything missing or stale goes to the outbox. */
	private static function send( $stream, array $items ) {
		$generation = Options::next_generation( $stream );
		$result     = Client::reconcile( $stream, $generation, $items );
		if ( is_wp_error( $result ) ) {
			if ( Client::is_closed( $result ) ) {
				State::forget();
			}
			self::record(
				$stream,
				array(
					'error' => $result->get_error_message(),
					'at'    => time(),
				)
			);
			return;
		}
		$rows = array();
		foreach ( array_merge( $result['missing'], $result['stale'] ) as $external_id ) {
			$post_id = $stream === 'products' ? ProductPayload::product_id_of( $external_id ) : PostPayload::post_id_of( $external_id );
			if ( $post_id ) {
				$rows[] = array(
					'external_id' => $external_id,
					'post_id'     => $post_id,
				);
			}
		}
		if ( $rows ) {
			Outbox::enqueue_upserts( $stream, $rows );
		}
		self::record(
			$stream,
			array(
				'generation'      => $result['generation'],
				'listed'          => count( $items ),
				'missing'         => count( $result['missing'] ),
				'stale'           => count( $result['stale'] ),
				'orphans_deleted' => $result['orphans_deleted'],
				'orphans_held'    => $result['orphans_held'],
				'busy'            => $result['busy'],
				'at'              => time(),
			)
		);
	}

	/** The stream's post type is registered right now (WooCommerce may be deactivated). */
	private static function can_enumerate( $stream ) {
		$all = Streams::all();
		if ( ! isset( $all[ $stream ] ) ) {
			return false;
		}
		if ( $stream === 'products' ) {
			return Streams::has_woocommerce() && function_exists( 'wc_get_products' );
		}
		return post_type_exists( $all[ $stream ]['post_type'] );
	}

	private static function record( $stream, array $outcome ) {
		$all = get_option( Options::RECONCILE_LAST, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$all[ $stream ] = $outcome;
		update_option( Options::RECONCILE_LAST, $all, false );
	}

	/** The outcomes belong to a connection: a new one starts with none. */
	public static function forget() {
		delete_option( Options::RECONCILE_LAST );
		delete_option( Options::RECONCILE );
	}

	/** @return array<string,array> the last outcome per stream, for the admin page. */
	public static function outcomes() {
		$all = get_option( Options::RECONCILE_LAST, array() );
		return is_array( $all ) ? $all : array();
	}

	/** @return array{running:bool,stream:?string} */
	public static function progress() {
		$walk    = get_option( Options::RECONCILE );
		$running = is_array( $walk ) && empty( $walk['finished_at'] ) && ( ! empty( $walk['streams'] ) || $walk['stream'] !== null );
		return array(
			'running' => $running,
			'stream'  => $running ? $walk['stream'] : null,
		);
	}

	public static function arm( $delay ) {
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( time() + (int) $delay, self::HOOK );
	}

	public static function disarm() {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
