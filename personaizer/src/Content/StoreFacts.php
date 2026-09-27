<?php
namespace Personaizer\Content;

use Personaizer\Site\Streams;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `store-facts` stream: what a shopper asks about the shop itself, built from the site's settings rather than
 * from a post — `store-facts` (name, tagline, currency, address, where it sells and ships, taxes, payment methods,
 * time zone, privacy and terms pages) and, with WooCommerce, `categories` (the product category tree with links).
 *
 * The ids are the ones PERSONAIZER's onboarding gives the same records when it reads the site before install, so the
 * install enriches them in place.
 */
final class StoreFacts {

	const STREAM         = 'store-facts';
	const FACTS_ID       = 'store-facts';
	const CATEGORIES_ID  = 'categories';
	const MAX_TREE_DEPTH = 6;

	/** @return array<int,array> every record of the stream, each with its own fingerprint. */
	public static function records() {
		$records = array( self::facts() );
		$tree    = Streams::has_woocommerce() ? self::categories() : null;
		if ( $tree !== null ) {
			$records[] = $tree;
		}
		return $records;
	}

	/** One record by id, or null when it has none now (no WooCommerce, no categories) — which makes it a delete. */
	public static function record( $id ) {
		foreach ( self::records() as $record ) {
			if ( $record['id'] === $id ) {
				return $record;
			}
		}
		return null;
	}

	/** @return string[] the ids the stream holds right now. */
	public static function ids() {
		return array_map(
			static function ( $r ) {
				return $r['id'];
			},
			self::records()
		);
	}

	private static function facts() {
		$home  = untrailingslashit( home_url( '/' ) );
		$name  = html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$lines = array( '# ' . ( $name !== '' ? $name : wp_parse_url( $home, PHP_URL_HOST ) ) . ': store facts', '', '- Website: ' . $home );

		$tagline = html_entity_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( $tagline !== '' ) {
			$lines[] = '- About: ' . $tagline;
		}

		if ( Streams::has_woocommerce() ) {
			$lines[] = '- Currency: ' . get_woocommerce_currency();
			$address = self::address();
			if ( $address !== '' ) {
				$lines[] = '- Based in: ' . $address;
			}
			$sells = self::countries( 'woocommerce_allowed_countries', 'woocommerce_specific_allowed_countries', 'woocommerce_all_except_countries' );
			if ( $sells !== '' ) {
				$lines[] = '- Sells to: ' . $sells;
			}
			$ships = get_option( 'woocommerce_ship_to_countries', '' );
			if ( $ships === 'disabled' ) {
				$lines[] = '- Shipping: not offered (no delivery)';
			} elseif ( $ships === 'specific' ) {
				$lines[] = '- Ships to: ' . self::country_names( (array) get_option( 'woocommerce_specific_ship_to_countries', array() ) );
			}
			if ( get_option( 'woocommerce_calc_taxes' ) === 'yes' ) {
				$lines[] = get_option( 'woocommerce_prices_include_tax' ) === 'yes' ? '- Prices include tax' : '- Prices exclude tax (added at checkout)';
			}
			$payments = self::payment_methods();
			if ( $payments !== '' ) {
				$lines[] = '- Payment methods: ' . $payments;
			}
			$lines[] = '- Units: weight in ' . get_option( 'woocommerce_weight_unit', 'kg' ) . ', sizes in ' . get_option( 'woocommerce_dimension_unit', 'cm' );
		}

		$timezone = wp_timezone_string();
		if ( $timezone !== '' && $timezone !== '+00:00' ) {
			$lines[] = '- Time zone: ' . $timezone;
		}
		$privacy = get_privacy_policy_url();
		if ( $privacy !== '' ) {
			$lines[] = '- Privacy policy: ' . $privacy;
		}
		$terms_id = function_exists( 'wc_terms_and_conditions_page_id' ) ? (int) wc_terms_and_conditions_page_id() : 0;
		if ( $terms_id > 0 && get_post_status( $terms_id ) === 'publish' ) {
			$lines[] = '- Terms and conditions: ' . get_permalink( $terms_id );
		}

		return self::record_of( self::FACTS_ID, 'Store facts', implode( "\n", $lines ), $home );
	}

	/** @return array|null the category tree as one record, or null when the shop has no categories. */
	private static function categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return null;
		}
		$children = array();
		foreach ( $terms as $term ) {
			$children[ (int) $term->parent ][] = $term;
		}
		$lines = array();
		self::outline( $children, 0, 0, $lines );
		if ( empty( $lines ) ) {
			return null;
		}
		$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );
		$body = implode( "\n", array_merge( array( '# Product categories', '', "The shop's product categories a shopper can open:", '' ), $lines ) );
		return self::record_of( self::CATEGORIES_ID, 'Product categories', $body, $shop );
	}

	private static function outline( array $children, $parent, $depth, array &$lines ) {
		if ( empty( $children[ $parent ] ) || $depth > self::MAX_TREE_DEPTH ) {
			return;
		}
		$level = $children[ $parent ];
		usort(
			$level,
			static function ( $a, $b ) {
				return strcasecmp( $a->name, $b->name );
			}
		);
		foreach ( $level as $term ) {
			$link    = get_term_link( $term );
			$lines[] = str_repeat( '  ', $depth ) . '- ' . html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' )
				. ( is_wp_error( $link ) ? '' : ': ' . $link );
			self::outline( $children, (int) $term->term_id, $depth + 1, $lines );
		}
	}

	private static function address() {
		$default = explode( ':', (string) get_option( 'woocommerce_default_country', '' ) );
		$country = $default[0] ?? '';
		$parts   = array_filter(
			array(
				get_option( 'woocommerce_store_address', '' ),
				get_option( 'woocommerce_store_address_2', '' ),
				get_option( 'woocommerce_store_city', '' ),
				get_option( 'woocommerce_store_postcode', '' ),
				$country !== '' ? self::country_names( array( $country ) ) : '',
			),
			'strlen'
		);
		return implode( ', ', $parts );
	}

	private static function countries( $mode_option, $specific_option, $except_option ) {
		$mode = get_option( $mode_option, 'all' );
		if ( $mode === 'specific' ) {
			return self::country_names( (array) get_option( $specific_option, array() ) );
		}
		if ( $mode === 'all_except' ) {
			$except = (array) get_option( $except_option, array() );
			return empty( $except ) ? 'every country' : 'every country except ' . self::country_names( $except );
		}
		return 'every country';
	}

	private static function country_names( array $codes ) {
		$names = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : array();
		return implode(
			', ',
			array_map(
				static function ( $code ) use ( $names ) {
					return isset( $names[ $code ] ) ? html_entity_decode( $names[ $code ], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : $code;
				},
				array_filter( $codes, 'strlen' )
			)
		);
	}

	/** The checkout's enabled gateways by the titles shoppers see. */
	private static function payment_methods() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return '';
		}
		$titles = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
			if ( isset( $gateway->enabled ) && $gateway->enabled === 'yes' ) {
				$title = trim( wp_strip_all_tags( (string) $gateway->get_title() ) );
				if ( $title !== '' ) {
					$titles[ $title ] = true;
				}
			}
		}
		return implode( ', ', array_keys( $titles ) );
	}

	private static function record_of( $id, $title, $markdown, $url ) {
		$record                = array(
			'id'      => $id,
			'title'   => $title,
			'content' => $markdown,
			'links'   => array(
				array(
					'url'        => $url,
					'is_primary' => true,
				),
			),
			'images'  => array(),
		);
		$record['fingerprint'] = Fingerprint::of( $record );
		return $record;
	}
}
