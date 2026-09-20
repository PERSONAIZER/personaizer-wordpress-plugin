<?php
namespace Personaizer\Admin;

use Personaizer\Connect\Flow;
use Personaizer\Options;
use Personaizer\Site\Streams;
use Personaizer\Sync\Backfill;
use Personaizer\Sync\Outbox;
use Personaizer\Sync\Reconcile;
use Personaizer\Sync\State;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one admin screen: what this site is connected as, whether the persona is ready, how each stream is doing,
 * and two buttons — Sync now and Disconnect (Connect when it is not connected). Nothing is configured here except whether
 * signed-in customers are recognised; every other setting (streams, persona, widget) is a link to personaizer.com.
 */
final class Page {

	const SLUG = 'personaizer';

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PERSONAIZER_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_menu_page( 'PERSONAIZER', 'PERSONAIZER', 'manage_options', self::SLUG, array( __CLASS__, 'render' ), self::icon(), 30 );
	}

	public static function settings() {
		register_setting(
			'personaizer',
			Options::IDENTIFY_USERS,
			array(
				'type'              => 'string',
				'sanitize_callback' => static function ( $v ) {
					return $v === '1' ? '1' : '';
				},
			)
		);
	}

	public static function assets( $hook ) {
		if ( $hook !== 'toplevel_page_' . self::SLUG ) {
			return;
		}
		wp_enqueue_style( 'personaizer-admin', plugins_url( 'assets/admin-page.css', PERSONAIZER_PLUGIN_FILE ), array(), PERSONAIZER_VERSION );
		wp_register_script( 'personaizer-admin', plugins_url( 'assets/admin-page.js', PERSONAIZER_PLUGIN_FILE ), array(), PERSONAIZER_VERSION, true );
		wp_add_inline_script( 'personaizer-admin', 'window.PersonaizerAdminPage = ' . wp_json_encode( array( 'autoReload' => self::is_busy() ) ) . ';', 'before' );
		wp_enqueue_script( 'personaizer-admin' );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">Settings</a>' );
		return $links;
	}

	/** Everything the view needs, computed once. */
	public static function model() {
		$connected = Options::is_connected();
		$state     = $connected ? State::get() : null;
		$counts    = $connected ? Outbox::counts() : array();
		$outcomes  = $connected ? Reconcile::outcomes() : array();

		$streams = array();
		foreach ( Streams::all() as $key => $meta ) {
			$row             = $state !== null && isset( $state['streams'][ $key ] ) ? $state['streams'][ $key ] : null;
			$streams[ $key ] = array(
				'label'   => $meta['label'],
				'local'   => Streams::published_count( $key ),
				'offered' => $row !== null,
				'enabled' => $row !== null && $row['enabled'],
				'synced'  => $row !== null ? $row['document_count'] : null,
				'ready'   => $row !== null ? $row['ready_count'] : null,
				'queue'   => $counts[ $key ] ?? array(
					'queued'   => 0,
					'deferred' => 0,
					'failed'   => 0,
				),
				'check'   => $outcomes[ $key ] ?? null,
				'last'    => $row !== null ? $row['last_reconcile'] : null,
			);
		}

		return array(
			'connected' => $connected,
			'reachable' => $state !== null,
			'state'     => $state,
			'streams'   => $streams,
			'backfill'  => $connected ? Backfill::progress() : array(
				'running'  => false,
				'enqueued' => 0,
			),
			'reconcile' => $connected ? Reconcile::progress() : array(
				'running' => false,
				'stream'  => null,
			),
			'failures'  => $connected ? Outbox::failures() : array(),
			'error'     => Options::last_error(),
			'last_push' => (int) get_option( Options::LAST_PUSH, 0 ),
			'app_url'   => rtrim( PERSONAIZER_APP_URL, '/' ),
			'dashboard' => $connected ? Flow::dashboard_url() : '',
			'cron_off'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'notice'    => self::notice(),
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$model = self::model();
		include PERSONAIZER_PLUGIN_DIR . '/src/Admin/views/page.php';
	}

	/** The page reloads itself while something is in flight, so the numbers move without the owner refreshing. */
	private static function is_busy() {
		if ( ! Options::is_connected() ) {
			return false;
		}
		$state = State::get();
		if ( $state !== null && $state['persona'] !== null && $state['persona']['building'] ) {
			return true;
		}
		if ( Backfill::progress()['running'] || Reconcile::progress()['running'] ) {
			return true;
		}
		// Queued rows of a stream that is OFF are parked, not in flight — no reason to keep reloading for them.
		$on = State::enabled_streams();
		foreach ( Outbox::counts() as $stream => $c ) {
			if ( $c['queued'] > 0 && isset( $on[ $stream ] ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return array{kind:string,text:string}|null the one-line result of the action that led here. */
	private static function notice() {
		if ( ! empty( $_GET['pz_connected'] ) ) {
			return array(
				'kind' => 'success',
				'text' => 'Connected. Your content is being sent to PERSONAIZER now.',
			);
		}
		if ( ! empty( $_GET['pz_disconnected'] ) ) {
			return array(
				'kind' => 'info',
				'text' => 'Disconnected. Nothing was deleted on PERSONAIZER; connect again any time to resume.',
			);
		}
		if ( ! empty( $_GET['pz_syncing'] ) ) {
			return array(
				'kind' => 'info',
				'text' => 'Syncing now — every stream is being checked against PERSONAIZER.',
			);
		}
		if ( ! empty( $_GET['pz_error'] ) ) {
			return array(
				'kind' => 'error',
				'text' => sanitize_text_field( wp_unslash( $_GET['pz_error'] ) ),
			);
		}
		return null;
	}

	/** Ago-text for a unix time. */
	public static function ago( $time ) {
		return $time > 0 ? human_time_diff( $time, time() ) . ' ago' : 'never';
	}

	public static function action_url( $action ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), $action );
	}

	private static function icon() {
		return 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" d="M10 2a8 8 0 0 0-6.9 12l-1 4 4.2-1A8 8 0 1 0 10 2zm-3 6h6v2H7V8zm0 3h4v2H7v-2z"/></svg>' );
	}
}
