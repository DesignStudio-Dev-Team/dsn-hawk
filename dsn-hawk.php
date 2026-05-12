<?php
/**
 * Plugin Name:       DSN Hawk
 * Plugin URI:        https://github.com/DesignStudio-Dev-Team/dsn-hawk
 * Description:       Collects site health + configuration reports and pushes them to the DSN Skyline Laravel admin panel.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Juan Tamayo, DesignStudio Network
 * Author URI:        https://designstudionetwork.com
 * License:           Proprietary
 * Text Domain:       dsn-hawk
 * Update URI:        https://github.com/DesignStudio-Dev-Team/dsn-hawk
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DSN_HAWK_VERSION', '1.0.0' );
define( 'DSN_HAWK_FILE', __FILE__ );
define( 'DSN_HAWK_DIR', plugin_dir_path( __FILE__ ) );
define( 'DSN_HAWK_URL', plugin_dir_url( __FILE__ ) );
define( 'DSN_HAWK_BASENAME', plugin_basename( __FILE__ ) );
define( 'DSN_HAWK_SLUG', 'dsn-hawk' );
define( 'DSN_HAWK_GH_REPO', 'DesignStudio-Dev-Team/dsn-hawk' );
define( 'DSN_HAWK_DEFAULT_ENDPOINT', 'https://mvp.designstudio.com/api/v1/hawk/sync' );

// Composer autoload if present, else fall back to built-in PSR-4 loader.
if ( file_exists( DSN_HAWK_DIR . 'vendor/autoload.php' ) ) {
	require_once DSN_HAWK_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register( static function ( string $class ): void {
		$prefix = 'DSN\\Hawk\\';
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$path     = DSN_HAWK_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	} );
}

register_activation_hook( __FILE__, [ \DSN\Hawk\Plugin::class, 'onActivate' ] );
register_deactivation_hook( __FILE__, [ \DSN\Hawk\Plugin::class, 'onDeactivate' ] );

add_action( 'plugins_loaded', static function (): void {
	\DSN\Hawk\Plugin::instance()->boot();
} );
