<?php
namespace Personaizer\Api;

use Personaizer\Options;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The HTTP client for PERSONAIZER — the connect flow's two server-to-server calls and the push surface's four.
 *
 * The push surface is what this site talks to with its integration credential (`ik_…`, sent as X-Api-Key —
 * server-side only, it must never reach the browser). Every call also carries X-Personaizer-Plugin-Version, so
 * the backend can tell what is installed in the wild. Bodies are snake_case both ways; Contracts turns the
 * answers into arrays.
 *
 * Every method returns a parsed array on success or a WP_Error whose data is { status, code } — the HTTP status
 * and the problem `code` — so callers branch on Contracts::CLOSED / Contracts::QUOTA without re-parsing anything.
 */
final class Client {

	public static function base() {
		return rtrim( PERSONAIZER_API_URL, '/' );
	}

	// ── connect (no credential yet) ──

	/**
	 * Open the attempt: what this site is, its callback and PKCE challenge, its own account of the brand and what
	 * it could sync. Answers the connect id the browser takes to the consent screen.
	 *
	 * @return array{connect_id:string}|WP_Error
	 */
	public static function start( array $profile, array $inventory, $redirect_uri, $code_challenge ) {
		$body   = array(
			'platform'       => 'wordpress',
			'site_origin'    => self::site_origin(),
			'site_name'      => (string) get_bloginfo( 'name' ),
			'redirect_uri'   => $redirect_uri,
			'code_challenge' => $code_challenge,
			'site_profile'   => $profile,
			'inventory'      => array_values( $inventory ),
		);
		$result = self::call( 'POST', '/api/integrations/connect/start', $body, array(), 20 );
		return is_wp_error( $result ) ? $result : Contracts::start_response( $result );
	}

	/**
	 * Redeem the code the consent screen sent back for this site's credential.
	 *
	 * @return array{integration_id:string,integration_key:string,brand_id:string,persona_id:string,identity_secret:string}|WP_Error
	 */
	public static function token( $code, $code_verifier, $redirect_uri ) {
		$body   = array(
			'code'          => $code,
			'code_verifier' => $code_verifier,
			'redirect_uri'  => $redirect_uri,
		);
		$result = self::call( 'POST', '/api/integrations/connect/token', $body, array(), 20 );
		return is_wp_error( $result ) ? $result : Contracts::token_response( $result );
	}

	// ── the push surface (ik_) ──

	/**
	 * "Here is what I have; tell me what you want." Reports every stream the site could sync and learns which are
	 * on, the brand, the persona, the plan's headroom. See Contracts::sync_response for the shape.
	 *
	 * @return array|WP_Error
	 */
	public static function sync( array $inventory ) {
		$body   = array(
			'site_name' => (string) get_bloginfo( 'name' ),
			'inventory' => array_values( $inventory ),
		);
		$result = self::call( 'POST', '/v1/integration/sync', $body, self::auth(), 15 );
		return is_wp_error( $result ) ? $result : Contracts::sync_response( $result );
	}

	/**
	 * The one write: records to create-or-update and records to remove, in one stream. At most 100 upserts.
	 *
	 * @param array[]  $upserts Records as Content\PostPayload / Content\ProductPayload build them.
	 * @param string[] $deletes External ids.
	 * @return array{written:string[],deferred:string[],rejected:array,deleted:int,deletes_busy:bool}|WP_Error
	 */
	public static function items( $stream, array $upserts, array $deletes ) {
		$body   = array(
			'upserts' => array_values( $upserts ),
			'deletes' => array_values( $deletes ),
		);
		$result = self::call( 'PUT', '/v1/integration/streams/' . rawurlencode( $stream ) . '/items', $body, self::auth(), 60 );
		if ( ! is_wp_error( $result ) ) {
			update_option( Options::LAST_PUSH, time(), false );
		}
		return is_wp_error( $result ) ? $result : Contracts::items_response( $result );
	}

	/**
	 * Hand over a stream's whole list — every published record with its fingerprint — and learn what still has to
	 * be pushed while PERSONAIZER removes what the site no longer has.
	 *
	 * @param array<int,array{id:string,fingerprint:string}> $items
	 * @return array{generation:int,missing:string[],stale:string[],orphans_deleted:int,orphans_held:int,busy:bool}|WP_Error
	 */
	public static function reconcile( $stream, $generation, array $items ) {
		$body   = array(
			'generation' => (int) $generation,
			'items'      => array_values( $items ),
		);
		$result = self::call( 'PUT', '/v1/integration/streams/' . rawurlencode( $stream ) . '/reconcile', $body, self::auth(), 60 );
		return is_wp_error( $result ) ? $result : Contracts::reconcile_response( $result );
	}

	/** This site lets go: the integration freezes on PERSONAIZER (streams off, nothing deleted). Idempotent. */
	public static function disconnect() {
		if ( ! Options::is_connected() ) {
			return true;
		}
		$result = self::call( 'DELETE', '/v1/integration', null, self::auth(), 15 );
		return is_wp_error( $result ) ? $result : true;
	}

	// ── plumbing ──

	/** This site's identity on PERSONAIZER: the origin of its home URL. */
	public static function site_origin() {
		$home = home_url( '/' );
		$p    = wp_parse_url( $home );
		$port = isset( $p['port'] ) ? ':' . $p['port'] : '';
		return strtolower( ( $p['scheme'] ?? 'https' ) . '://' . ( $p['host'] ?? '' ) . $port );
	}

	private static function auth() {
		return array( 'X-Api-Key' => Options::integration_key() );
	}

	/**
	 * @return array|true|WP_Error The decoded JSON body (an array), true for an empty 2xx, or a WP_Error carrying
	 *                             { status, code } in its data.
	 */
	private static function call( $method, $path, $body, array $headers, $timeout ) {
		if ( isset( $headers['X-Api-Key'] ) && $headers['X-Api-Key'] === '' ) {
			return new WP_Error(
				'personaizer_not_connected',
				'This site is not connected to PERSONAIZER.',
				array(
					'status' => 0,
					'code'   => '',
				)
			);
		}
		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array_merge(
				array(
					'Accept'                       => 'application/json',
					'X-Personaizer-Plugin-Version' => PERSONAIZER_VERSION,
				),
				$headers
			),
		);
		if ( $body !== null ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::base() . $path, $args );
		if ( is_wp_error( $response ) ) {
			$response->add_data(
				array(
					'status' => 0,
					'code'   => '',
				)
			);
			Options::record_error( $response->get_error_message() );
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$json   = $raw === '' ? null : json_decode( $raw, true );

		if ( $status >= 200 && $status < 300 ) {
			return is_array( $json ) ? $json : true;
		}

		$code    = Contracts::problem_code( $json );
		$message = Contracts::problem_message( $json, $status );
		// A closed door is the owner's choice, not a fault of the site — the admin page must not show it as an error.
		if ( $code !== Contracts::CLOSED ) {
			Options::record_error( $message, $code );
		}
		return new WP_Error(
			'personaizer_http_' . $status,
			$message,
			array(
				'status' => $status,
				'code'   => $code,
			)
		);
	}

	/** The problem code a failed call carried ('' when none). */
	public static function code( WP_Error $error ) {
		$data = $error->get_error_data();
		return is_array( $data ) ? (string) ( $data['code'] ?? '' ) : '';
	}

	public static function is_closed( $result ) {
		return is_wp_error( $result ) && self::code( $result ) === Contracts::CLOSED;
	}

	public static function is_quota( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return false;
		}
		$data = $result->get_error_data();
		return self::code( $result ) === Contracts::QUOTA || ( is_array( $data ) && (int) ( $data['status'] ?? 0 ) === 402 );
	}
}
