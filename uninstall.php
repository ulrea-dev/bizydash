<?php
/**
 * Removes BizyDash data on uninstall, only when the "delete on uninstall" setting is enabled.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$settings = get_option( 'bizydash_settings', array() );
if ( empty( $settings['delete_on_uninstall'] ) ) {
	return;
}

require_once __DIR__ . '/includes/Backup/Module_Map.php';
$tables = BizyDash\Backup\Module_Map::all_tables();
foreach ( $tables as $table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'bizydash_' . $table . '`' ); // phpcs:ignore WordPress.DB
}

foreach ( array_merge( array_keys( BizyDash\Backup\Module_Map::options() ), array( 'bizydash_db_version', 'bizydash_backup_settings', 'bizydash_backup_active', 'bizydash_restore_lock', 'bizydash_fresh_install' ) ) as $opt ) {
	delete_option( $opt );
}
wp_clear_scheduled_hook( 'bizydash_backup_auto' );

if ( ! empty( $settings['delete_backups_on_uninstall'] ) ) {
	$up  = wp_upload_dir();
	$dir = defined( 'BIZYDASH_BACKUP_DIR' ) && BIZYDASH_BACKUP_DIR ? rtrim( BIZYDASH_BACKUP_DIR, '/\\' ) : trailingslashit( $up['basedir'] ) . 'bizydash-backups';
	if ( is_dir( $dir ) ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

delete_option( 'bizydash_settings' );
delete_option( 'bizydash_mail' );
delete_option( 'bizydash_db_version' );
delete_option( 'bizydash_modules' );
remove_role( 'bizydash_staff' );

$role = get_role( 'administrator' );
if ( $role ) {
	$role->remove_cap( 'manage_bizydash' );
}
