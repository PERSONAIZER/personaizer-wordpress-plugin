<?php
namespace Personaizer\Content;

use WC_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A WooCommerce product as the typed record a CATALOG stream carries: title, description, every category path it
 * is filed under, its fixed attributes, images, permalink and per-SKU variants. No request, no side effects — the
 * full-list check fingerprints THIS.
 *
 * Every product ships as per-SKU `variants` — PERSONAIZER rolls them up into the parent "from" price / any-in-stock
 * and projects the facet union for filtering. The parent's price and stock are deliberately NOT sent (the backend
 * derives them, which also sidesteps the variable-product get_regular_price() = '' sale bug).
 */
final class ProductPayload {

	const MAX_IMAGES = 15;

	/** `wc-product-<ID>` — the site's stable record id. */
	public static function external_id( $product_id ) {
		return 'wc-product-' . (int) $product_id;
	}

	public static function product_id_of( $external_id ) {
		return preg_match( '/^wc-product-(\d+)$/', (string) $external_id, $m ) ? (int) $m[1] : null;
	}

	/** Only a published product the shop shows belongs in the catalog: a hidden or private one is removed. */
	public static function is_syncable( WC_Product $product ) {
		return $product->get_status() === 'publish' && $product->is_visible();
	}

	/** @return array The record; carries its own `fingerprint`. */
	public static function build( WC_Product $product ) {
		$id    = $product->get_id();
		$paths = self::category_paths( $product );

		$attributes = self::attributes( $product );
		// Every category name the product touches also rides along as a filterable attribute, so the AI can narrow
		// WITHIN a category domain ("dibond, inside sheet materials") without needing a separate domain per leaf.
		$names = self::category_names( $paths );
		if ( ! empty( $names ) ) {
			$attributes['category'] = $names;
		}

		$record                = array(
			'id'          => self::external_id( $id ),
			'title'       => $product->get_name(),
			'description' => self::description( $product ),
			'categories'  => $paths,
			'currency'    => get_woocommerce_currency(),
			'attributes'  => $attributes,
			'images'      => self::images( $product ),
			'links'       => array(
				array(
					'url'        => get_permalink( $id ),
					'is_primary' => true,
				),
			),
			'variants'    => $product->is_type( 'variable' ) ? self::variants( $product ) : array( self::simple_variant( $product ) ),
		);
		$record['fingerprint'] = Fingerprint::of( $record );
		return $record;
	}

	/**
	 * The short description first, then the long one. On a WooCommerce product page the short description is the
	 * block beside the price — where shops put the specs a buyer asks about (thickness, size, price per m²) — and
	 * the long description is the "Description" tab below. Both are what the product IS; 2.x sent only one of them.
	 */
	private static function description( WC_Product $product ) {
		$parts = array();
		foreach ( array( $product->get_short_description(), $product->get_description() ) as $html ) {
			$text = Markdown::from_html( (string) apply_filters( 'the_content', (string) $html ) );
			if ( $text !== '' ) {
				$parts[] = $text;
			}
		}
		return implode(
			'

',
			$parts
		);
	}

	/**
	 * Every category path the product is filed under, each a root→leaf chain of names:
	 * [ ["Outlet"], ["Sheet materials", "Dibond"] ]. WooCommerce gives a product an unordered SET of terms and
	 * nothing says which is definitive, so all of them are sent — the platform decides which nodes become groups.
	 * Only MAXIMAL paths: a ticked ancestor of another ticked term is a prefix of that path and adds nothing.
	 * A product with no categories falls back to one "products" path, so it is still reachable.
	 *
	 * @return array<int,string[]>
	 */
	private static function category_paths( WC_Product $product ) {
		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return array( array( 'products' ) );
		}

		$paths    = array();
		$by_key   = array();
		$term_ids = array();
		foreach ( $terms as $t ) {
			$chain = array();
			foreach ( array_reverse( get_ancestors( $t->term_id, 'product_cat' ) ) as $aid ) {
				$anc = get_term( (int) $aid, 'product_cat' );
				if ( $anc && ! is_wp_error( $anc ) ) {
					$chain[] = sanitize_text_field( $anc->name );
				}
			}
			$chain[] = sanitize_text_field( $t->name );
			$key     = implode( "\x1f", $chain );
			if ( isset( $by_key[ $key ] ) ) {
				continue;
			}
			$by_key[ $key ] = true;
			$paths[]        = $chain;
			$term_ids[]     = (int) $t->term_id;
		}

		$maximal = array();
		foreach ( $paths as $i => $chain ) {
			$covered = false;
			foreach ( $term_ids as $j => $other_id ) {
				if ( $i !== $j && in_array( $term_ids[ $i ], get_ancestors( $other_id, 'product_cat' ), true ) ) {
					$covered = true;
					break; }
			}
			if ( ! $covered ) {
				$maximal[] = $chain;
			}
		}
		return empty( $maximal ) ? $paths : $maximal;
	}

	/** @return string[] the distinct node names across the paths — the filterable `category` attribute. */
	private static function category_names( array $paths ) {
		$names = array();
		foreach ( $paths as $chain ) {
			foreach ( $chain as $name ) {
				$names[ $name ] = true;
			}
		}
		return array_keys( $names );
	}

	/**
	 * Flat descriptive fields: sku + the product's fixed, non-variation attributes. The WooCommerce product type
	 * is not a product attribute; stock quantity is volatile and rides the typed `in_stock` instead. Variation
	 * axes (colour/size) travel per-SKU in `variants`, and on a simple product its global (taxonomy) attributes go
	 * the same way, so `color`/`size` land under one facet key regardless of product type.
	 */
	private static function attributes( WC_Product $product ) {
		$attrs = array();
		$sku   = $product->get_sku();
		if ( $sku !== '' ) {
			$attrs['sku'] = $sku;
		}

		$is_variable = $product->is_type( 'variable' );
		foreach ( $product->get_attributes() as $name => $attribute ) {
			if ( is_a( $attribute, 'WC_Product_Attribute' ) ) {
				if ( $attribute->get_variation() ) {
					continue;
				}
				if ( ! $is_variable && $attribute->is_taxonomy() ) {
					continue;
				}
				$label   = wc_attribute_label( $attribute->get_name(), $product );
				$options = $attribute->is_taxonomy()
					? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) )
					: $attribute->get_options();
			} else {
				$label   = $name;
				$options = is_array( $attribute ) ? $attribute : array( $attribute );
			}
			$key     = self::attr_key( $label );
			$options = array_values( array_filter( array_map( 'strval', (array) $options ), 'strlen' ) );
			if ( $key !== '' && ! isset( $attrs[ $key ] ) && ! empty( $options ) ) {
				$attrs[ $key ] = $options;
			}
		}
		return $attrs;
	}

	/** Per-SKU rows of a variable product: price / original price / stock / sku / image + the variation-axis facets. */
	private static function variants( WC_Product $product ) {
		$variants = array();
		foreach ( $product->get_children() as $vid ) {
			$variation = wc_get_product( $vid );
			if ( ! $variation || ! $variation->exists() ) {
				continue;
			}
			$variant = self::commerce( $variation );
			foreach ( self::variation_facets( $variation ) as $key => $value ) {
				$variant[ $key ] = $value;
			}
			$variants[] = $variant;
		}
		return $variants;
	}

	/** A simple product as a variant-of-one: its commerce + its global (taxonomy) attributes as per-SKU facets. */
	private static function simple_variant( WC_Product $product ) {
		$variant = self::commerce( $product );
		foreach ( self::taxonomy_facets( $product ) as $key => $value ) {
			$variant[ $key ] = $value;
		}
		return $variant;
	}

	/** sku, price, original_price (only when on sale and higher), in_stock, image — of a product or a variation. */
	private static function commerce( WC_Product $product ) {
		$variant = array();
		$sku     = $product->get_sku();
		if ( $sku !== '' ) {
			$variant['sku'] = $sku;
		}
		$price = self::to_decimal( $product->get_price() );
		if ( $price !== null ) {
			$variant['price'] = $price;
			$regular          = self::to_decimal( $product->get_regular_price() );
			if ( $product->is_on_sale() && $regular !== null && $regular > $price ) {
				$variant['original_price'] = $regular;
			}
		}
		$variant['in_stock'] = (bool) $product->is_in_stock();
		$img_id              = $product->get_image_id();
		if ( $img_id ) {
			$url = wp_get_attachment_image_url( $img_id, 'full' );
			if ( $url ) {
				$variant['image'] = $url;
			}
		}
		return $variant;
	}

	/** The variation's chosen axis values as flat facets, e.g. { color: ["Blue"], size: ["M"] }. "Any" is skipped. */
	private static function variation_facets( $variation ) {
		$facets = array();
		foreach ( $variation->get_variation_attributes() as $raw_name => $value ) {
			if ( $value === '' ) {
				continue;
			}
			$name = str_replace( 'attribute_', '', $raw_name );
			if ( taxonomy_exists( $name ) ) {
				$term = get_term_by( 'slug', $value, $name );
				if ( $term && ! is_wp_error( $term ) ) {
					$value = $term->name;
				}
			}
			$key = self::attr_key( wc_attribute_label( $name ) );
			if ( $key !== '' ) {
				$facets[ $key ] = array( strval( $value ) );
			}
		}
		return $facets;
	}

	private static function taxonomy_facets( WC_Product $product ) {
		$facets = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_a( $attribute, 'WC_Product_Attribute' ) || ! $attribute->is_taxonomy() ) {
				continue;
			}
			$key     = self::attr_key( wc_attribute_label( $attribute->get_name(), $product ) );
			$options = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) );
			$options = array_values( array_filter( array_map( 'strval', (array) $options ), 'strlen' ) );
			if ( $key !== '' && ! empty( $options ) ) {
				$facets[ $key ] = $options;
			}
		}
		return $facets;
	}

	/**
	 * Featured image (primary) + gallery, capped, absolute URLs only. Descriptions are EMPTY on purpose:
	 * PERSONAIZER runs vision on the primary image only when it arrives without one, and folds the caption into
	 * what the product is searched on. `source: client` — declared, not scraped.
	 */
	private static function images( WC_Product $product ) {
		$images     = array();
		$seen       = array();
		$primary_id = $product->get_image_id();
		if ( $primary_id ) {
			$url = wp_get_attachment_image_url( $primary_id, 'full' );
			if ( $url ) {
				$images[]     = array(
					'url'         => $url,
					'description' => '',
					'is_primary'  => true,
					'source'      => 'client',
				);
				$seen[ $url ] = true; }
		}
		foreach ( (array) $product->get_gallery_image_ids() as $gid ) {
			if ( count( $images ) >= self::MAX_IMAGES ) {
				break;
			}
			$url = wp_get_attachment_image_url( $gid, 'full' );
			if ( $url && ! isset( $seen[ $url ] ) ) {
				$seen[ $url ] = true;
				$images[]     = array(
					'url'         => $url,
					'description' => '',
					'is_primary'  => false,
					'source'      => 'client',
				); }
		}
		return $images;
	}

	/**
	 * An attribute label as a stable key. Unicode-aware, so labels in any script survive (a plain a-z0-9 class
	 * silently discarded Georgian "სისქე"); Core re-normalises whatever key arrives.
	 */
	public static function attr_key( $label ) {
		$key = mb_strtolower( trim( (string) $label ), 'UTF-8' );
		$key = preg_replace( '/[^\p{L}\p{N}]+/u', '_', $key );
		return trim( (string) $key, '_' );
	}

	private static function to_decimal( $value ) {
		if ( $value === '' || $value === null ) {
			return null;
		}
		return round( (float) $value, 2 );
	}
}
