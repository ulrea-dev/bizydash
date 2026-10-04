<?php
/**
 * Plugin Name: BizyDash
 * Description: Agency operations in one app — clients, team, work, billing, payroll and e-signed contracts, with built-in backup and restore.
 * Version: 1.4.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Digitalzooks
 * License: GPL-2.0-or-later
 * Text Domain: bizydash
 */

defined( 'ABSPATH' ) || exit;

define( 'BIZYDASH_VERSION', '1.4.0' );
define( 'BIZYDASH_DB_VERSION', '3' );
define( 'BIZYDASH_FILE', __FILE__ );
define( 'BIZYDASH_DIR', plugin_dir_path( __FILE__ ) );
define( 'BIZYDASH_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	function ( $class ) {
		$prefix = 'BizyDash\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$path = BIZYDASH_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'BizyDash\\Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BizyDash\\Cron', 'unschedule' ) );

add_action( 'plugins_loaded', array( 'BizyDash\\Plugin', 'boot' ) );
