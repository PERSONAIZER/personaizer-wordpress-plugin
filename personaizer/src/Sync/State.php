<?php
namespace Personaizer\Sync;

use Personaizer\Api\Client;
use Personaizer\Api\Contracts;
use Personaizer\Options;
use Personaizer\Site\Streams;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What PERSONAIZER last told this site — the answer to POST /v1/integration/sync: which streams are on, the brand,
 * the persona, the plan. Read before every push (cached a minute), refreshed after anything that could change it
 * (a connect, a closed door), and backed by the last good answer when the API is unreachable. This is the ONLY
 * source of truth for which streams sync: the owner switches them on personaizer.com, never here.
 *
 * `status` is the server's `active` / `disconnected`, plus one of our own: `gone` — the key was refused outright
 * (the integration was deleted on personaizer.com), which the last good answer must not stand in for. An
 * outage keeps showing yesterday's state; a deleted integration shows "connect again".
 */
final class State {

	const GONE = 'gone';

	const TTL       = MINUTE_IN_SECONDS;
	const TRANSIENT = 'personaizer_sync_state';

	/**
	 * @param bool $force Skip the cache and ask again.
	 * @return array|null The parsed sync answer (Api\Contracts::sync_response), or null when unconnected and nothing
	 *                    is known. A stale fallback is returned when the API is unreachable.
	 */
	public static function get( $force = false ) {
		if ( ! Options::is_connected() ) {
			return null;
		}
		if ( ! $force ) {
			$hit = get_transient( self::TRANSIENT );
			if ( is_array( $hit ) ) {
				return $hit;
			}
		}
		$live = Client::sync( Streams::inventory() );
		if ( Client::is_gone( $live ) ) {
			$live = Contracts::sync_response( array( 'status' => self::GONE ) );
		} elseif ( is_wp_error( $live ) ) {
			$fallback = get_option( Options::STATE_FALLBACK );
			return is_array( $fallback ) ? $fallback : null;
		}
		set_transient( self::TRANSIENT, $live, self::TTL );
		update_option( Options::STATE_FALLBACK, $live, false );
		return $live;
	}

	/** Drop the cached answer — after a connect, a disconnect, or a write the server refused as closed. */
	public static function forget() {
		delete_transient( self::TRANSIENT );
	}

	/** Streams the owner has switched on, keyed by stream, each with its type. @return array<string,array> */
	public static function enabled_streams() {
		$state = self::get();
		if ( $state === null || $state['status'] !== 'active' ) {
			return array();
		}
		return array_filter(
			$state['streams'],
			static function ( $s ) {
				return ! empty( $s['enabled'] );
			}
		);
	}

	public static function is_enabled( $stream ) {
		$on = self::enabled_streams();
		return isset( $on[ $stream ] );
	}

	/** Is the API reachable with this site's key right now? (A fresh, uncached read.) */
	public static function reachable() {
		return Options::is_connected() && ! is_wp_error( Client::sync( Streams::inventory() ) );
	}
}
