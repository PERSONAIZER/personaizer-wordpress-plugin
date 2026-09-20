<?php
namespace Personaizer\Sync;

use Personaizer\Content\PostPayload;
use Personaizer\Content\ProductPayload;
use Personaizer\Options;
use Personaizer\Site\Streams;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress and WooCommerce events → outbox rows. Never an HTTP call inside a hook: a save must not wait on
 * PERSONAIZER, and a request that dies mid-way must not lose the change. Each hook records "this record changed"
 * and arms the worker; the worker decides, at drain time, whether the record is now an upsert or a delete.
 */
final class Hooks {

	public static function boot() {
		// meta + terms are final on wp_after_insert_post (unlike save_post).
		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_post_saved' ), 20, 4 );
		add_action( 'trashed_post', array( __CLASS__, 'on_post_removed' ), 10, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_post_removed' ), 10, 1 );

		if ( Streams::has_woocommerce() ) {
			add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_changed' ), 20 );
			add_action( 'woocommerce_new_product', array( __CLASS__, 'on_product_changed' ), 20 );
			// Stock writes fire on every purchase; the row is idempotent, so a burst collapses into one push.
			add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_product_object' ), 20 );
			add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_variation_object' ), 20 );
		}
	}

	public static function on_post_saved( $post_id, $post, $update = null, $post_before = null ) {
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( $post->post_type === 'product' ) {
			return;   // WooCommerce's own hooks own products
		}
		if ( ! Options::is_connected() ) {
			return;
		}
		$stream = Streams::for_post_type( $post->post_type );
		if ( $stream === null ) {
			return;
		}

		$external_id = PostPayload::external_id( $post );
		// Only published content belongs to the AI — anything else (draft, pending, private, future) is removed.
		if ( $post->post_status === 'publish' ) {
			Outbox::enqueue_upsert( $stream, $external_id, $post->ID );
		} else {
			Outbox::enqueue_delete( $stream, $external_id );
		}
		Worker::arm();
	}

	public static function on_post_removed( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! Options::is_connected() ) {
			return;
		}
		$stream = Streams::for_post_type( $post->post_type );
		if ( $stream === null ) {
			return;
		}
		$external_id = $post->post_type === 'product' ? ProductPayload::external_id( $post->ID ) : PostPayload::external_id( $post );
		Outbox::enqueue_delete( $stream, $external_id );
		Worker::arm();
	}

	public static function on_product_changed( $product_id ) {
		if ( ! Options::is_connected() ) {
			return;
		}
		// The worker re-reads the product and decides upsert vs delete from its status and visibility then.
		Outbox::enqueue_upsert( 'products', ProductPayload::external_id( $product_id ), (int) $product_id );
		Worker::arm();
	}

	public static function on_product_object( $product ) {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			self::on_product_changed( $product->get_id() );
		}
	}

	/** A variation's stock changed: the parent's record carries the variants, so the parent is re-pushed. */
	public static function on_variation_object( $variation ) {
		if ( is_object( $variation ) && method_exists( $variation, 'get_parent_id' ) ) {
			self::on_product_changed( $variation->get_parent_id() );
		}
	}
}
