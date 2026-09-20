<?php
/**
 * Test bootstrap — no WordPress. The classes under test (Api\Contracts, Content\Markdown, Content\Fingerprint,
 * Site\Languages::normalize) are pure PHP; the loader below is the plugin's own, and the two WordPress functions
 * the pure code reaches for get the smallest possible stand-ins.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'PERSONAIZER_PLUGIN_DIR', dirname( __DIR__ ) . '/personaizer' );

spl_autoload_register( static function ( $class ) {
    if ( strpos( $class, 'Personaizer\\' ) !== 0 ) return;
    $file = PERSONAIZER_PLUGIN_DIR . '/src/' . str_replace( '\\', '/', substr( $class, strlen( 'Personaizer\\' ) ) ) . '.php';
    if ( is_file( $file ) ) require $file;
} );

/** Read a recorded exchange: { request, status, response }. */
function pz_fixture( string $name ): array {
    $path = dirname( __DIR__ ) . '/fixtures/v1-integration/' . $name . '.json';
    if ( ! is_file( $path ) ) {
        throw new RuntimeException( "Fixture $name missing — run tools/sync-fixtures.sh" );
    }
    return json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
}

// The one WordPress function the pure content code reaches for: wp_parse_url is parse_url with saner failure.
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
}
