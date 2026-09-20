<?php
namespace Personaizer\Connect;

use Personaizer\Api\Client;
use Personaizer\Data;
use Personaizer\Options;
use Personaizer\Site\Profile;
use Personaizer\Site\Streams;
use Personaizer\Sync\Backfill;
use Personaizer\Sync\Outbox;
use Personaizer\Sync\Reconcile;
use Personaizer\Sync\State;
use Personaizer\Sync\Worker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Connect to PERSONAIZER" — OAuth Authorization-Code + PKCE, PERSONAIZER being the authorization server.
 *
 * 1. Connect (admin action): this server opens the attempt with everything the site knows about itself — its
 *    profile (Site\Profile), what it could sync (Site\Streams), its callback and a PKCE challenge — and sends the
 *    owner's browser to the consent screen with the connect id. Nothing user-visible rides in the URL.
 * 2. On personaizer.com the owner picks the brand (the form is pre-filled from the profile), the widget persona
 *    (built for the brand right there, if there is none) and the streams, and approves.
 * 3. Callback: the browser comes back with a single-use code; this server redeems it with the PKCE verifier for
 *    the site's integration credential (ik_), the brand, the persona and the account's identity secret. Then the
 *    first sync and the backfill start. Connecting again after a Disconnect is the same flow; PERSONAIZER resumes
 *    the same integration (same site origin) with its history.
 *
 * The verifier lives in a transient keyed by the `state` we sent, so the callback can't be replayed or forged.
 */
final class Flow {

	const ACTION_CONNECT    = 'personaizer_connect';
	const ACTION_CALLBACK   = 'personaizer_connect_callback';
	const ACTION_DISCONNECT = 'personaizer_disconnect';
	const ACTION_SYNC_NOW   = 'personaizer_sync_now';

	const ATTEMPT_TTL = 15 * MINUTE_IN_SECONDS;

	public static function boot() {
		add_action( 'admin_post_' . self::ACTION_CONNECT, array( __CLASS__, 'connect' ) );
		add_action( 'admin_post_' . self::ACTION_CALLBACK, array( __CLASS__, 'callback' ) );
		add_action( 'admin_post_' . self::ACTION_DISCONNECT, array( __CLASS__, 'disconnect' ) );
		add_action( 'admin_post_' . self::ACTION_SYNC_NOW, array( __CLASS__, 'sync_now' ) );
		// wp_safe_redirect() only follows redirects to the current site; the consent screen is on personaizer.com.
		add_filter(
			'allowed_redirect_hosts',
			static function ( $hosts ) {
				$hosts[] = wp_parse_url( PERSONAIZER_APP_URL, PHP_URL_HOST );
				return $hosts;
			}
		);
	}

	public static function callback_url() {
		return admin_url( 'admin-post.php?action=' . self::ACTION_CALLBACK );
	}

	/** Step 1: open the attempt, send the owner to the consent screen. */
	public static function connect() {
		check_admin_referer( self::ACTION_CONNECT );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}

		$verifier  = self::b64url( random_bytes( 48 ) );
		$challenge = self::b64url( hash( 'sha256', $verifier, true ) );
		$state     = self::b64url( random_bytes( 16 ) );

		$started = Client::start( Profile::build(), Streams::inventory(), self::callback_url(), $challenge );
		if ( is_wp_error( $started ) || $started['connect_id'] === '' ) {
			self::back( array( 'pz_error' => is_wp_error( $started ) ? $started->get_error_message() : 'PERSONAIZER did not open the connection.' ) );
		}

		set_transient(
			'personaizer_connect_' . $state,
			array(
				'connect_id' => $started['connect_id'],
				'verifier'   => $verifier,
			),
			self::ATTEMPT_TTL
		);
		wp_safe_redirect(
			add_query_arg(
				array(
					'c'     => $started['connect_id'],
					'state' => $state,
				),
				rtrim( PERSONAIZER_APP_URL, '/' ) . '/connect'
			)
		);
		exit;
	}

	/** Step 3: the browser is back with the code; redeem it. */
	public static function callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		$attempt = $state !== '' ? get_transient( 'personaizer_connect_' . $state ) : false;
		if ( $state !== '' ) {
			delete_transient( 'personaizer_connect_' . $state );
		}

		if ( $error !== '' ) {
			self::back( array( 'pz_error' => 'The connection was not approved.' ) );
		}
		if ( ! is_array( $attempt ) || $code === '' ) {
			self::back( array( 'pz_error' => 'The connection attempt expired — try again.' ) );
		}

		$grant = Client::token( $code, $attempt['verifier'], self::callback_url() );
		if ( is_wp_error( $grant ) || $grant['integration_key'] === '' ) {
			self::back( array( 'pz_error' => is_wp_error( $grant ) ? $grant->get_error_message() : 'PERSONAIZER did not hand back a credential.' ) );
		}

		update_option( Options::INTEGRATION_ID, $grant['integration_id'], false );
		update_option( Options::INTEGRATION_KEY, $grant['integration_key'], false );
		update_option( Options::BRAND_ID, $grant['brand_id'], false );
		if ( $grant['persona_id'] !== '' ) {
			update_option( Options::PERSONA_ID, $grant['persona_id'], false );
		} else {
			delete_option( Options::PERSONA_ID );
		}
		update_option( Options::IDENTITY_SECRET, $grant['identity_secret'], false );
		Options::clear_error();

		// A fresh connection: forget what the previous one was doing, learn what is on now, queue everything.
		State::forget();
		Outbox::clear();
		State::get( true );
		Backfill::start();

		// Land on the brand's knowledge map with the persona's chat open: the sync shows on the source cards there. The
		// WP admin page (the tab Connect was clicked in) reloads to "connected" on its own.
		wp_safe_redirect( self::dashboard_url() );
		exit;
	}

	/** Where the owner sees this site on personaizer.com: the brand's knowledge map, the persona picked when there is one. */
	public static function dashboard_url() {
		$args = array( 'brand' => Options::brand_id() );
		if ( Options::persona_id() !== '' ) {
			$args['persona'] = Options::persona_id();
		}
		return add_query_arg( $args, rtrim( PERSONAIZER_APP_URL, '/' ) . '/knowledge' );
	}

	/** Let go: the integration freezes on PERSONAIZER; nothing is deleted there. Locally everything goes. */
	public static function disconnect() {
		check_admin_referer( self::ACTION_DISCONNECT );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		Client::disconnect();
		Data::clear();
		self::back( array( 'pz_disconnected' => '1' ) );
	}

	/** Push whatever is waiting now and check every stream's full list. */
	public static function sync_now() {
		check_admin_referer( self::ACTION_SYNC_NOW );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		State::forget();
		Outbox::release( Outbox::FAILED );
		Worker::drain( 8 );
		Reconcile::start();
		self::back( array( 'pz_syncing' => '1' ) );
	}

	private static function back( array $args ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => 'personaizer' ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function b64url( $bytes ) {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
