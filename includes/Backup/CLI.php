<?php
namespace BizyDash\Backup;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI: back up or list BizyDash backups from the command line (handy for server cron on big sites).
 *
 *   wp bizydash backup [--no-files] [--no-logs]
 *   wp bizydash backups
 */
class CLI {

	public static function register() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'bizydash backup', array( __CLASS__, 'backup' ) );
			\WP_CLI::add_command( 'bizydash backups', array( __CLASS__, 'backups' ) );
		}
	}

	public static function backup( $args, $assoc ) {
		$s = Backup_Manager::start( 'manual', array( 'files' => ! isset( $assoc['no-files'] ), 'logs' => ! isset( $assoc['no-logs'] ) ) );
		while ( ! is_wp_error( $s ) && ! $s['done'] ) {
			$s = Backup_Manager::step( $s['id'], '', 20 );
		}
		if ( is_wp_error( $s ) ) {
			\WP_CLI::error( $s->get_error_message() );
		}
		\WP_CLI::success( 'Backup saved: ' . $s['result']['id'] . ' (' . size_format( $s['result']['size'] ) . ')' );
	}

	public static function backups() {
		$rows = array();
		foreach ( Backup_Manager::store()->all() as $m ) {
			$rows[] = array( 'id' => $m['id'], 'created' => $m['created_at'], 'type' => $m['type'] ?? '', 'size' => size_format( (int) $m['size'] ), 'protected' => ! empty( $m['encrypted'] ) ? 'yes' : 'no' );
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'created', 'type', 'size', 'protected' ) );
	}
}
