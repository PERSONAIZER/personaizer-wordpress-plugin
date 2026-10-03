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
			$check           = $outcomes[ $key ] ?? null;
			$streams[ $key ] = array(
				'label'   => $meta['label'],
				// What the site can send: the last full list's count — pages with text, sellable products. A cart or
				// checkout page has none and is never sent, so counting it would read as two pages forever waiting.
				// Until the first list ran, the published count is all there is.
				'local'   => isset( $check['listed'] ) ? (int) $check['listed'] : Streams::published_count( $key ),
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
				'check'   => $check,
				'last'    => $row !== null ? $row['last_reconcile'] : null,
			);
		}

		// The plan kept some of a source out: records wait for room, or knowledge is over its limit and not everything is in.
		$sync_plan = $state !== null ? $state['plan'] : null;
		$ku_over   = $sync_plan !== null && $sync_plan['knowledge_units_limit'] !== null
			&& $sync_plan['knowledge_units_used'] > $sync_plan['knowledge_units_limit'];
		foreach ( $streams as $key => $stream ) {
			$streams[ $key ]['full'] = $stream['enabled'] && $stream['status'] === 'ready'
				&& ( $stream['queue']['deferred'] > 0 || ( $ku_over && (int) $stream['synced'] < $stream['local'] ) );
		}

		return array(
			'connected' => $connected,
			'reachable' => $state !== null,
			'state'     => $state,
			'streams'   => $streams,
			'any_full'  => (bool) array_filter( array_column( $streams, 'full' ) ),
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
	 * The plan panel, worked out: what the plan gives, then conversations (credits shown as conversations) and knowledge
	 * (units the persona holds of the plan's). Both bars are drawn to what was used; past a limit, the part over is
	 * marked and the lines say how far over and what that means.
	 *
	 * @return array{name:string,gives:string,resets:string,conversations:array,knowledge:array}
	 */
	private static function plan_view( array $plan, array $streams ) {
		$resets = $plan['resets_at'] !== '' && strtotime( $plan['resets_at'] ) ? date_i18n( 'M j', strtotime( $plan['resets_at'] ) ) : '';
		$paid   = $plan['monthly_price'] !== null && $plan['monthly_price'] > 0;
		$per    = $plan['credits_per_conversation'];
		$limit  = $plan['credits_limit'];
		$used   = $plan['credits_used'];
		$cap    = $plan['knowledge_units_limit'];
		$held   = $plan['knowledge_units_used'];

		$gives = array();
		if ( $paid ) {
			$gives[] = self::money( $plan['monthly_price'], $plan['currency'] ) . ' / month';
		}
		$allowance = array();
		if ( $limit !== null ) {
			$allowance[] = number_format_i18n( $limit ) . ' credits' . ( $per > 0 ? ' (≈ ' . number_format_i18n( intdiv( $limit, $per ) ) . ' conversations)' : '' );
		}
		if ( $cap !== null ) {
			$allowance[] = number_format_i18n( $cap ) . ' knowledge units';
		}
		if ( $allowance ) {
			$gives[] = implode( ' and ', $allowance );
		}
		if ( $resets !== '' ) {
			$gives[] = ( $paid ? 'renews ' : 'resets ' ) . $resets;
		}

		if ( $limit === null ) {
			$conversations = self::meter( 'Unlimited', '', false, null, array( array( number_format_i18n( $used ) . ' credits used', false ) ) );
		} elseif ( $used >= $limit ) {
			$conversations = self::meter(
				'None left',
				'',
				true,
				self::bar( $used, $limit ),
				array(
					array( number_format_i18n( $used ) . ' credits used of ' . number_format_i18n( $limit ) . ( $used > $limit ? ' · ' . number_format_i18n( $used - $limit ) . ' over' : '' ), true ),
					array( 'Answers are paused until ' . ( $resets !== '' ? $resets . ' or ' : '' ) . 'an upgrade. Your team can still reply.', false ),
				)
			);
		} else {
			$left          = $limit - $used;
			$conversations = self::meter(
				$per > 0 ? '≈ ' . number_format_i18n( intdiv( $left, $per ) ) . ' left' : number_format_i18n( $left ) . ' credits left',
				$per > 0 ? 'of ≈ ' . number_format_i18n( intdiv( $limit, $per ) ) : '',
				false,
				self::bar( $used, $limit ),
				array( array( number_format_i18n( $left ) . ' of ' . number_format_i18n( $limit ) . ' credits left · ' . number_format_i18n( $used ) . ' used' . ( $resets !== '' ? ' · resets ' . $resets : '' ), false ) )
			);
		}

		$on      = array_filter(
			$streams,
			static function ( $s ) {
				return $s['enabled'];
			}
		);
		$items   = 0;
		$waiting = 0;
		foreach ( $on as $s ) {
			$items   += (int) $s['synced'];
			$waiting += max( 0, $s['local'] - (int) $s['synced'] );
		}
		$filled = number_format_i18n( $items ) . ' items from ' . count( $on ) . ( count( $on ) === 1 ? ' source' : ' sources' );
		if ( $cap === null ) {
			$knowledge = self::meter( number_format_i18n( round( $held ) ), 'units · unlimited', false, null, array( array( $filled, false ) ) );
		} elseif ( $held > $cap ) {
			$knowledge = self::meter(
				number_format_i18n( round( $held ) ),
				'of ' . number_format_i18n( $cap ) . ' units',
				true,
				self::bar( $held, $cap ),
				array(
					array( number_format_i18n( round( $held - $cap ) ) . ' over' . ( $waiting > 0 ? ' · ' . number_format_i18n( $waiting ) . ' items waiting for room' : '' ), true ),
					array( "New and changed content isn't learned until you upgrade or remove some.", false ),
				)
			);
		} else {
			$knowledge = self::meter(
				number_format_i18n( round( $held ) ),
				'of ' . number_format_i18n( $cap ) . ' units',
				false,
				self::bar( $held, $cap ),
				array( array( number_format_i18n( max( 0, round( $cap - $held ) ) ) . ' free · ' . $filled, false ) )
			);
		}

		return array(
			'name'          => $plan['name'],
			'gives'         => implode( ' · ', $gives ),
			'resets'        => $resets,
			'conversations' => $conversations,
			'knowledge'     => $knowledge,
		);
	}

	/** @return array{value:string,of:string,over:bool,bar:?array,lines:array} */
	private static function meter( $value, $of, $over, $bar, array $lines ) {
		return array(
			'value' => $value,
			'of'    => $of,
			'over'  => $over,
			'bar'   => $bar,
			'lines' => $lines,
		);
	}

	/**
	 * A bar drawn to what was used: filled up to the limit; past it, where the limit sits and the part over.
	 *
	 * @return array{fill:float,at:?float,limit:string}
	 */
	private static function bar( $used, $limit ) {
		if ( $used <= $limit ) {
			return array(
				'fill'  => $limit > 0 ? $used / $limit * 100 : 0,
				'at'    => null,
				'limit' => '',
			);
		}
		return array(
			'fill'  => $limit / $used * 100,
			'at'    => $limit / $used * 100,
			'limit' => 'limit ' . number_format_i18n( $limit ),
		);
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
