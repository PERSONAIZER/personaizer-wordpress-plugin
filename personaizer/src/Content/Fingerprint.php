<?php
namespace Personaizer\Content;

/**
 * A stable fingerprint of exactly what we would send for one record.
 *
 * Sent with every push and stored by PERSONAIZER on the document; the full-list check (Sync\Reconcile) sends the
 * same fingerprints again and the backend answers which records it holds a different one for. Because it hashes
 * the mapper's ACTUAL OUTPUT, it changes whenever anything that reaches the AI changes — the merchant edits a
 * price, or WE change how a product is mapped. That second case is what makes it worth having: when a mapping fix
 * starts emitting an attribute that was silently dropped, every record's fingerprint changes and the next check
 * reports "393 out of date" instead of leaving someone to notice by reading documents one at a time.
 *
 * Key order must not affect the result — json_encode follows PHP's insertion order, so associative keys are sorted
 * recursively (lists keep their order, which is meaningful for images and variants). Pure PHP, tested on its own.
 */
final class Fingerprint {

	/** md5 of the canonical payload. Not a security hash — a cheap, stable equality check. */
	public static function of( array $payload ) {
		return md5( (string) json_encode( self::normalize( $payload ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	private static function normalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		$out     = array();
		foreach ( $value as $k => $v ) {
			$out[ $k ] = self::normalize( $v );
		}
		if ( ! $is_list ) {
			ksort( $out );
		}
		return $out;
	}
}
