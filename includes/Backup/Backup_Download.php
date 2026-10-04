<?php
namespace BizyDash\Backup;

defined( 'ABSPATH' ) || exit;

/** Hands a backup to the browser through a short-lived, single-use link tied to the administrator who asked. */
class Backup_Download {

	const PARAM = 'bd_backup_dl';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_stream' ), 0 );
	}

	public static function filename( array $meta ) {
		$host = sanitize_file_name( (string) wp_parse_url( $meta['site_url'] ?? home_url(), PHP_URL_HOST ) );
		return 'bizydash-backup-' . gmdate( 'Ymd-His', strtotime( $meta['created_at'] ?? 'now' ) ) . ( $host ? '-' . $host : '' ) . ( ! empty( $meta['encrypted'] ) ? '-protected' : '' ) . Local_Store::EXT;
	}

	/** @return array|\WP_Error { url, name } */
	public static function make_link( $id, $password = '' ) {
		$store = Backup_Manager::store();
		$meta  = $store->find( $id );
		$path  = $store->path( $id );
		if ( ! $meta || '' === $path ) {
			return new \WP_Error( 'bd_missing', 'That backup was not found.', array( 'status' => 404 ) );
		}
		$temp = '';
		if ( '' !== $password ) {
			if ( ! empty( $meta['encrypted'] ) ) {
				return new \WP_Error( 'bd_encrypted', 'This backup is already password protected.', array( 'status' => 400 ) );
			}
			$temp = Local_Store::work_root() . '/dl-' . bin2hex( random_bytes( 6 ) ) . '.enc';
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$r = Backup_Crypto::encrypt_file( $path, $temp, $password );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$meta['encrypted'] = true;
		}
		$token = bin2hex( random_bytes( 16 ) );
		set_transient( 'bizydash_dl_' . $token, array( 'id' => $id, 'user' => get_current_user_id(), 'temp' => $temp, 'name' => self::filename( $meta ) ), 5 * MINUTE_IN_SECONDS );
		return array( 'url' => add_query_arg( self::PARAM, $token, home_url( '/' ) ), 'name' => self::filename( $meta ) );
	}

	public static function maybe_stream() {
		if ( empty( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$token = preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_GET[ self::PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$d     = get_transient( 'bizydash_dl_' . $token );
		delete_transient( 'bizydash_dl_' . $token ); // single use
		if ( ! is_array( $d ) || ! \BizyDash\Roles::can_admin() || (int) $d['user'] !== get_current_user_id() ) {
			wp_die( 'This download link has expired. Go back and ask for the backup again.', 'Link expired', array( 'response' => 403 ) );
		}
		$path = $d['temp'] ? $d['temp'] : Backup_Manager::store()->path( $d['id'] );
		if ( '' === $path || ! is_file( $path ) ) {
			wp_die( 'That backup file is missing.', 'Not found', array( 'response' => 404 ) );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $d['name'] . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		$h = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		while ( $h && ! feof( $h ) ) {
			echo fread( $h, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput
			flush();
		}
		if ( $h ) {
			fclose( $h );
		}
		if ( $d['temp'] ) {
			@unlink( $d['temp'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
		exit;
	}
}
