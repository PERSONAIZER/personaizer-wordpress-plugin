<?php
namespace Personaizer\Widget;

use Personaizer\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The chat widget on every page of the site. chat.js is loaded with the persona's PUBLIC id (safe to print — the
 * backend binds it to the site's registered origin) and calls PERSONAIZER directly from the visitor's browser: no
 * WordPress round-trip per message. Appearance and behaviour are not injected here — chat.js reads them from the
 * persona's own widget config on personaizer.com, which is where the owner edits them.
 */
final class Embed {

	public static function boot() {
		add_action( 'wp_footer', array( __CLASS__, 'inject' ) );
		// WP < 6.3 ignores the 'strategy' arg below; force async onto the tag either way.
		add_filter(
			'script_loader_tag',
			static function ( $tag, $handle ) {
				if ( $handle === 'personaizer-chat-widget' && strpos( $tag, ' async' ) === false ) {
					$tag = str_replace( ' src=', ' async src=', $tag );
				}
				return $tag;
			},
			10,
			2
		);
	}

	public static function inject() {
		$persona_id = Options::persona_id();
		if ( $persona_id === '' ) {
			return;
		}

		$cfg = array(
			// Steer the widget's /v1 calls at the same environment as the API (overrides the base baked into chat.js).
			'apiBase'     => rtrim( PERSONAIZER_WIDGET_API_BASE, '/' ),
			// Conversations show as "WordPress" in the owner's inbox. Descriptive only — never a credential.
			'integration' => 'wordpress',
		);

		// Recognise a signed-in customer (opt-in + identity secret). Split in two for cache safety: display
		// attributes ride the boot config (a mis-cached copy is a display glitch, never impersonation); the identity
		// TOKEN is fetched per request through a provider (never in HTML), so a cached page can't hand one customer's
		// token to another.
		$identify = is_user_logged_in() && Options::identify_users() && Options::identity_secret() !== '';
		if ( $identify ) {
			$attrs = IdentityToken::current_user_attributes();
			if ( $attrs ) {
				$cfg['userAttributes'] = $attrs;
			}
		}

		wp_register_script(
			'personaizer-chat-widget',
			PERSONAIZER_WIDGET_URL . '?k=' . rawurlencode( $persona_id ),
			array(),
			PERSONAIZER_VERSION,
			array(
				'strategy'  => 'async',
				'in_footer' => true,
			)
		);
		wp_add_inline_script( 'personaizer-chat-widget', 'window.PersonAIzerConfig = ' . wp_json_encode( $cfg ) . ';', 'before' );
		if ( $identify ) {
			wp_add_inline_script(
				'personaizer-chat-widget',
				sprintf(
					'(function(c){c.identityTokenProvider=function(){' .
					'return fetch(%s,{headers:{"X-WP-Nonce":%s},credentials:"same-origin"})' .
					'.then(function(r){return r.ok?r.json():null;}).then(function(d){return d?d.token:null;})' .
					'.catch(function(){return null;});};})(window.PersonAIzerConfig);',
					wp_json_encode( esc_url_raw( rest_url( 'personaizer/v1/identity-token' ) ) ),
					wp_json_encode( wp_create_nonce( 'wp_rest' ) )
				),
				'before'
			);
		}
		wp_enqueue_script( 'personaizer-chat-widget' );
	}
}
