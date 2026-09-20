<?php
/**
 * Fires when the user deletes the plugin from WordPress.
 *
 * Removes every trace of the account from this site: the connection, the outbox table, the schedules. The list
 * itself lives in Personaizer\Data — the same one the in-admin "Disconnect" uses — so the two can never drift
 * apart and strand a credential on the site after the plugin that owned it is gone.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'PERSONAIZER_PLUGIN_DIR' ) ) {
	define( 'PERSONAIZER_PLUGIN_DIR', __DIR__ );
}
spl_autoload_register(
	static function ( $class ) {
		if ( strpos( $class, 'Personaizer\\' ) !== 0 ) {
			return;
		}
		$file = PERSONAIZER_PLUGIN_DIR . '/src/' . str_replace( '\\', '/', substr( $class, strlen( 'Personaizer\\' ) ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

Personaizer\Data::purge();
