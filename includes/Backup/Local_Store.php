<?php
namespace BizyDash\Backup;

defined( 'ABSPATH' ) || exit;

/** Backups kept on this site, in a protected folder under uploads (override with BIZYDASH_BACKUP_DIR). */
class Local_Store implements Backup_Store {

	const EXT = '.bizydash';

	public static function dir() {
		if ( defined( 'BIZYDASH_BACKUP_DIR' ) && BIZYDASH_BACKUP_DIR ) {
			$dir = rtrim( BIZYDASH_BACKUP_DIR, '/\\' );
		} else {
			$u   = wp_upload_dir();
			$dir = trailingslashit( $u['basedir'] ) . 'bizydash-backups';
		}
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	public static function work_root() {
		$d = self::dir() . '/.work';
		if ( ! is_dir( $d ) ) {
			wp_mkdir_p( $d );
		}
		return $d;
	}

	/** Public URL the folder would have if it sat under uploads (used to probe for accidental exposure). */
	public static function probe_url() {
		$u   = wp_upload_dir();
		$dir = wp_normalize_path( self::dir() );
		$base = wp_normalize_path( $u['basedir'] );
		if ( 0 !== strpos( $dir, $base ) ) {
			return '';
		}
		return trailingslashit( $u['baseurl'] ) . ltrim( substr( $dir, strlen( $base ) ), '/' ) . '/index.html';
	}

	private function sidecar( $id ) {
		return self::dir() . '/' . $id . '.json';
	}

	public static function valid_id( $id ) {
		return is_string( $id ) && (bool) preg_match( '/^[a-f0-9]{32}$/', $id );
	}

	public function put( $local_path, array $meta ) {
		$id   = bin2hex( random_bytes( 16 ) );
		$dest = self::dir() . '/' . $id . self::EXT;
		if ( ! @rename( $local_path, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! copy( $local_path, $dest ) ) {
				return new \WP_Error( 'bd_io', 'Could not save the backup file.' );
			}
			@unlink( $local_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
		$meta['id']   = $id;
		$meta['size'] = (int) filesize( $dest );
		file_put_contents( $this->sidecar( $id ), wp_json_encode( $meta ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $id;
	}

	public function all() {
		$out = array();
		foreach ( (array) glob( self::dir() . '/*.json' ) as $f ) {
			$id = basename( $f, '.json' );
			if ( ! self::valid_id( $id ) || ! is_file( self::dir() . '/' . $id . self::EXT ) ) {
				continue;
			}
			$m = json_decode( (string) file_get_contents( $f ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( is_array( $m ) ) {
				$m['id']   = $id;
				$m['size'] = (int) filesize( self::dir() . '/' . $id . self::EXT );
				$out[]     = $m;
			}
		}
		usort( $out, function ( $a, $b ) {
			return strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) );
		} );
		return $out;
	}

	public function find( $id ) {
		if ( ! self::valid_id( $id ) || ! is_file( $this->sidecar( $id ) ) ) {
			return null;
		}
		$m = json_decode( (string) file_get_contents( $this->sidecar( $id ) ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $m ) ) {
			return null;
		}
		$m['id'] = $id;
		return $m;
	}

	public function path( $id ) {
		if ( ! self::valid_id( $id ) ) {
			return '';
		}
		$p = self::dir() . '/' . $id . self::EXT;
		return is_file( $p ) ? $p : '';
	}

	public function update( $id, array $patch ) {
		$m = $this->find( $id );
		if ( $m ) {
			file_put_contents( $this->sidecar( $id ), wp_json_encode( array_merge( $m, $patch ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	public function delete( $id ) {
		if ( ! self::valid_id( $id ) ) {
			return false;
		}
		@unlink( self::dir() . '/' . $id . self::EXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		@unlink( $this->sidecar( $id ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		return true;
	}
}
