<?php
namespace Personaizer\Site;

/**
 * The languages this site publishes in, primary first, as ISO-639-1 codes — what PERSONAIZER calls the brand's
 * audience languages. A crawler infers these from <html lang> and hreflang links; we are inside the site and can
 * read them as facts: a multilingual plugin knows every language and which one is the default.
 *
 * The plugin-specific reads are behind function/option checks so this works on any site; the detection order is
 * WPML, Polylang, TranslatePress, then WordPress's own locale. Pure where it can be (the code normalisation is
 * tested without WordPress).
 */
final class Languages {

	/** @return string[] ISO-639-1 codes, primary first, deduplicated, never empty when the locale is known. */
	public static function audience() {
		$languages = self::wpml() ?: self::polylang() ?: self::translatepress();
		if ( empty( $languages ) ) {
			$languages = array( (string) get_bloginfo( 'language' ) );
		}
		return self::normalize( $languages );
	}

	/**
	 * Locale tags to ISO-639-1 codes ("ka-GE" → "ka", "pt_BR" → "pt", "zh-Hans-CN" → "zh"), keeping the order
	 * (the first is the primary), dropping blanks and repeats.
	 *
	 * @param string[] $tags
	 * @return string[]
	 */
	public static function normalize( array $tags ) {
		$out = array();
		foreach ( $tags as $tag ) {
			$code = strtolower( trim( (string) $tag ) );
			$code = preg_split( '/[-_]/', $code )[0];
			if ( $code === '' || ! preg_match( '/^[a-z]{2,3}$/', $code ) ) {
				continue;
			}
			if ( ! in_array( $code, $out, true ) ) {
				$out[] = $code;
			}
		}
		return $out;
	}

	// ── WPML: every active language; the default first ──
	private static function wpml() {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return array();
		}
		$default = (string) apply_filters( 'wpml_default_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter
		$active  = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter
		$codes   = array();
		if ( $default !== '' ) {
			$codes[] = $default;
		}
		if ( is_array( $active ) ) {
			foreach ( $active as $code => $language ) {
				$codes[] = is_array( $language ) && ! empty( $language['default_locale'] ) ? $language['default_locale'] : (string) $code;
			}
		}
		return $codes;
	}

	// ── Polylang: pll_languages_list(); the default first ──
	private static function polylang() {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return array();
		}
		$codes   = array();
		$default = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';
		if ( $default !== '' ) {
			$codes[] = $default;
		}
		foreach ( (array) pll_languages_list( array( 'fields' => 'slug' ) ) as $slug ) {
			$codes[] = (string) $slug;
		}
		return $codes;
	}

	// ── TranslatePress: its settings option holds the default and the translation languages ──
	private static function translatepress() {
		$settings = get_option( 'trp_settings' );
		if ( ! is_array( $settings ) ) {
			return array();
		}
		$codes = array();
		if ( ! empty( $settings['default-language'] ) ) {
			$codes[] = (string) $settings['default-language'];
		}
		foreach ( (array) ( $settings['translation-languages'] ?? array() ) as $code ) {
			$codes[] = (string) $code;
		}
		return $codes;
	}
}
