<?php
namespace Personaizer\Admin;

use Personaizer\Connect\Flow;
use Personaizer\Options;
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
				'failed'  => $row !== null ? $row['failed_count'] : 0,
				'status'  => $row !== null ? $row['status'] : '',
				'icon'    => self::stream_icon( $key ),
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
			'plan'      => $state !== null && $state['plan'] !== null ? self::plan_view( $state['plan'], $streams ) : null,
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
			'logo'      => plugins_url( 'assets/logo.svg', PERSONAIZER_PLUGIN_FILE ),
			'dashboard' => $connected ? Flow::dashboard_url() : '',
			'cron_off'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'notice'    => self::notice(),
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// The owner is watching: a pending backfill and the queued records advance at the end of this request rather
		// than on the next cron tick (which some hosts never fire).
		Backfill::run_soon();
		Worker::run_soon();
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the flags only pick which one-line notice
		// to show; nothing is read into state, and the actions that set them are nonce-checked in Connect\Flow.
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

	/**
	 * The plan panel, worked out: its head line, then conversations (credits shown as conversations, the raw credits
	 * beneath) and knowledge (units used of the plan's, and what fills them). Bars turn amber at 20% left and red at
	 * 10% — the dashboard's thresholds.
	 *
	 * @return array{name:string,price:string,resets:string,conversations:array,knowledge:array}
	 */
	private static function plan_view( array $plan, array $streams ) {
		$resets = $plan['resets_at'] !== '' && strtotime( $plan['resets_at'] ) ? date_i18n( 'M j', strtotime( $plan['resets_at'] ) ) : '';
		$price  = $plan['monthly_price'] !== null && $plan['monthly_price'] > 0
			? self::money( $plan['monthly_price'], $plan['currency'] ) . ' / month' . ( $resets !== '' ? ' · renews ' . $resets : '' )
			: '';

		$limit = $plan['credits_limit'];
		$per   = $plan['credits_per_conversation'];
		if ( $limit === null ) {
			$conversations = array(
				'value' => 'Unlimited',
				'of'    => '',
				'bar'   => null,
				'level' => '',
				'sub'   => number_format_i18n( $plan['credits_used'] ) . ' credits used',
			);
		} else {
			$left          = max( 0, $limit - $plan['credits_used'] );
			$conversations = array(
				'value' => $per > 0 ? '≈ ' . number_format_i18n( intdiv( $left, $per ) ) . ' left' : number_format_i18n( $left ) . ' credits left',
				'of'    => $per > 0 ? 'of ≈ ' . number_format_i18n( intdiv( $limit, $per ) ) : '',
				'bar'   => $limit > 0 ? $left / $limit : 0,
				'level' => self::level( $left, $limit ),
				'sub'   => number_format_i18n( $left ) . ' of ' . number_format_i18n( $limit ) . ' credits left · '
					. number_format_i18n( $plan['credits_used'] ) . ' used' . ( $resets !== '' ? ' · resets ' . $resets : '' ),
			);
		}

		$on     = array_filter( $streams, static function ( $s ) { return $s['enabled']; } );
		$items  = array_sum( array_map( static function ( $s ) { return (int) $s['synced']; }, $on ) );
		$filled = number_format_i18n( $items ) . ' items from ' . count( $on ) . ( count( $on ) === 1 ? ' source' : ' sources' );
		$used   = $plan['knowledge_units_used'];
		$cap    = $plan['knowledge_units_limit'];

		return array(
			'name'          => $plan['name'],
			'price'         => $price,
			'resets'        => $resets,
			'conversations' => $conversations,
			'knowledge'     => $cap === null ? array(
				'value' => number_format_i18n( round( $used ) ),
				'of'    => 'units · unlimited',
				'bar'   => null,
				'level' => '',
				'sub'   => $filled,
			) : array(
				'value' => number_format_i18n( round( $used ) ),
				'of'    => 'of ' . number_format_i18n( $cap ) . ' units',
				'bar'   => $cap > 0 ? $used / $cap : 0,
				'level' => self::level( $cap - $used, $cap ),
				'sub'   => number_format_i18n( max( 0, round( $cap - $used ) ) ) . ' free · ' . $filled,
			),
		);
	}

	/** '', 'warning' at 20% or less left, 'critical' at 10% or less. */
	private static function level( $left, $limit ) {
		if ( $limit <= 0 ) {
			return '';
		}
		$share = $left / $limit;
		return $share <= 0.1 ? 'critical' : ( $share <= 0.2 ? 'warning' : '' );
	}

	private static function money( $amount, $currency ) {
		$symbols = array(
			'USD' => '$',
			'EUR' => '€',
			'GBP' => '£',
		);
		$number  = number_format_i18n( $amount, floor( $amount ) == $amount ? 0 : 2 ); // phpcs:ignore Universal.Operators.StrictComparisons -- a float compared with its floor
		return isset( $symbols[ $currency ] ) ? $symbols[ $currency ] . $number : $number . ' ' . $currency;
	}

	/** Which icon a stream's row shows: products, posts, or a page for everything else. */
	private static function stream_icon( $key ) {
		if ( $key === 'products' ) {
			return 'products';
		}
		return $key === 'posts' ? 'posts' : 'pages';
	}

	/** Ago-text for a unix time. */
	public static function ago( $time ) {
		return $time > 0 ? human_time_diff( $time, time() ) . ' ago' : 'never';
	}

	public static function action_url( $action ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), $action );
	}

	/** The menu icon: the mark as a data URI, flat so WordPress can repaint it in the admin colour scheme. */
	private static function icon() {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own SVG file, not code and not remote
		return 'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( PERSONAIZER_PLUGIN_DIR . '/assets/menu-icon.svg' ) );
	}
}
