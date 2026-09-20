<?php
/**
 * Plugin Name: PERSONAIZER
 * Plugin URI:  https://personaizer.com
 * Description: Connect this site to PERSONAIZER: its pages, posts and products become what your AI persona knows, and the chat widget answers your visitors from them.
 * Version:     3.0.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author:      PERSONAIZER
 * Author URI:  https://personaizer.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: personaizer
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PERSONAIZER_VERSION', '3.0.0' );
define( 'PERSONAIZER_PLUGIN_FILE', __FILE__ );
define( 'PERSONAIZER_PLUGIN_DIR', __DIR__ );

// ── Where PERSONAIZER is ──────────────────────────────────────────────────────
// The source ALWAYS defaults to production; build-zip.sh rewrites the staged copy for a --dev build, and a
// wp-config.php define() wins over everything (testing/dev-override.php does exactly that):
//   define( 'PERSONAIZER_API_URL', 'https://dev-api.personaizer.com' );
//   define( 'PERSONAIZER_APP_URL', 'https://dev.personaizer.com' );
//   define( 'PERSONAIZER_WIDGET_URL', 'https://personaizerdevstore2.blob.core.windows.net/platform-builds-public/chat.js' );

/** The API this plugin's server talks to: the connect flow and the four sync calls. */
if ( ! defined( 'PERSONAIZER_API_URL' ) ) {
    define( 'PERSONAIZER_API_URL', 'https://api.personaizer.com' );
}
/** The dashboard: the consent screen lives at /connect, everything the owner configures lives there too. */
if ( ! defined( 'PERSONAIZER_APP_URL' ) ) {
    define( 'PERSONAIZER_APP_URL', 'https://personaizer.com' );
}
/** The chat widget script injected on the site's pages. */
if ( ! defined( 'PERSONAIZER_WIDGET_URL' ) ) {
    define( 'PERSONAIZER_WIDGET_URL', 'https://personaizerprodstore.blob.core.windows.net/platform-builds-public/chat.js' );
}
/** Where the widget's own calls go (chat.js bakes a default in; this steers it at the same environment as the API). */
if ( ! defined( 'PERSONAIZER_WIDGET_API_BASE' ) ) {
    define( 'PERSONAIZER_WIDGET_API_BASE', PERSONAIZER_API_URL );
}

// PSR-4 over src/: Personaizer\Sync\Outbox → src/Sync/Outbox.php. No Composer at runtime — a distributable
// plugin ships its own loader.
spl_autoload_register( static function ( $class ) {
    if ( strpos( $class, 'Personaizer\\' ) !== 0 ) return;
    $file = PERSONAIZER_PLUGIN_DIR . '/src/' . str_replace( '\\', '/', substr( $class, strlen( 'Personaizer\\' ) ) ) . '.php';
    if ( is_file( $file ) ) require $file;
} );

register_activation_hook( __FILE__, array( 'Personaizer\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Personaizer\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Personaizer\\Plugin', 'boot' ) );
