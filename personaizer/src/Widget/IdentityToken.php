<?php
namespace Personaizer\Widget;

use Personaizer\Options;
use WP_Error;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signed-in customer identity. Signs a short-lived HS256 JWT for the CURRENT logged-in user with the account's
 * identity secret, so the widget can prove who they are without the secret ever reaching the browser. Minted per
 * request — never baked into cacheable HTML. PERSONAIZER verifies it and trusts `sub`; a bad or absent token is
 * simply an anonymous visitor.
 */
final class IdentityToken {

	public static function boot() {
		add_action(
			'rest_api_init',
			static function () {
				register_rest_route(
					'personaizer/v1',
					'/identity-token',
					array(
						'methods'             => 'GET',
						'callback'            => array( __CLASS__, 'mint' ),
						// Cookie auth + the X-WP-Nonce WordPress enforces: a cross-site page can't mint a token for the visitor.
						'permission_callback' => static function () {
							return is_user_logged_in(); },
					)
				);
			}
		);
	}

	public static function mint() {
		$secret = Options::identity_secret();
		if ( $secret === '' || ! Options::identify_users() ) {
			return new WP_Error( 'personaizer_identity_off', 'Customer identity is not enabled.', array( 'status' => 404 ) );
		}
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return new WP_Error( 'personaizer_not_logged_in', 'Not signed in.', array( 'status' => 401 ) );
		}
		$now = time();
		return new WP_REST_Response(
			array(
				'token' => self::sign(
					array(
						'sub' => (string) $user->ID,   // stable per-user id (email can change)
						'iat' => $now,
						'exp' => $now + 600,           // 10 minutes — refreshed per request
					),
					$secret
				),
			),
			200
		);
	}

	/** Display attributes of the signed-in user — display/CRM only on the other side, NEVER identity (that is the signed `sub`). */
	public static function current_user_attributes() {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return array();
		}
		return array_filter(
			array(
				'name'  => $user->display_name,
				'email' => $user->user_email,
				'phone' => (string) get_user_meta( $user->ID, 'billing_phone', true ),
			),
			static function ( $v ) {
				return $v !== '' && $v !== null;
			}
		);
	}

	/** Minimal HS256 JWT. The key is the identity secret verbatim — the server verifies with the UTF-8 bytes of the same string. */
	public static function sign( array $payload, $secret ) {
		$b64url  = static function ( $data ) {
			return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
		};
		$header  = $b64url(
			wp_json_encode(
				array(
					'alg' => 'HS256',
					'typ' => 'JWT',
				)
			)
		);
		$body    = $b64url( wp_json_encode( $payload ) );
		$signing = $header . '.' . $body;
		return $signing . '.' . $b64url( hash_hmac( 'sha256', $signing, $secret, true ) );
	}
}
