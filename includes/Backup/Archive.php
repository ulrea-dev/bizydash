<?php
namespace BizyDash\Backup;

defined( 'ABSPATH' ) || exit;

/** Safe reading of a backup archive: only expected entries, no path tricks, no executable files, size limits. */
class Archive {

	const MAX_ENTRIES = 20000;
	const MAX_BYTES   = 4294967296; // 4 GiB uncompressed

	public static function entry_ok( $name ) {
		if ( '' === $name || strlen( $name ) > 300 || false !== strpos( $name, '..' ) || false !== strpos( $name, "\0" ) || false !== strpos( $name, '\\' ) || '/' === $name[0] ) {
			return false;
		}
		if ( in_array( $name, array( 'manifest.json', 'options.json' ), true ) ) {
			return true;
		}
		if ( preg_match( '#^data/[a-z_]{1,60}\.ndjson$#', $name ) ) {
			return true;
		}
		if ( ! preg_match( '#^files/(contract-pdfs|uploads)/[A-Za-z0-9_\-. ()+%/]+$#', $name ) || '/' === substr( $name, -1 ) ) {
			return false;
		}
		foreach ( explode( '/', $name ) as $seg ) {
			if ( '' === $seg || '.' === $seg[0] ) {
				return false; // hidden files such as .htaccess
			}
		}
		return ! preg_match( '/\.(php\d?|phtml|phar|pht|phps|htaccess|htpasswd|ini|sh|cgi|pl|py|exe)(\.|$)/i', $name );
	}

	/**
	 * Open and vet an archive.
	 *
	 * @return array|\WP_Error array( \ZipArchive $zip, array $manifest, array $entries ) — caller closes the zip.
	 */
	public static function inspect( $path ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return new \WP_Error( 'bd_zip', 'This server has no zip support (PHP ZipArchive), so backups cannot be read.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new \WP_Error( 'bd_format', 'This file is not a valid BizyDash backup (it could not be opened as an archive).' );
		}
		if ( $zip->numFiles > self::MAX_ENTRIES ) {
			$zip->close();
			return new \WP_Error( 'bd_limits', 'This backup has too many files to be a normal BizyDash backup.' );
		}
		$entries = array();
		$total   = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$st = $zip->statIndex( $i );
			$n  = $st['name'];
			if ( '/' === substr( $n, -1 ) ) {
				continue; // directory entry
			}
			if ( ! self::entry_ok( $n ) ) {
				$zip->close();
				return new \WP_Error( 'bd_unsafe', 'This backup contains an entry that is not allowed (“' . substr( $n, 0, 80 ) . '”), so it was not opened.' );
			}
			$total += (int) $st['size'];
			$entries[ $n ] = (int) $st['size'];
		}
		if ( $total > self::MAX_BYTES ) {
			$zip->close();
			return new \WP_Error( 'bd_limits', 'This backup is larger than the size this plugin will restore.' );
		}
		$raw = $zip->getFromName( 'manifest.json' );
		$man = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $man ) || empty( $man['format_version'] ) || ! isset( $man['modules'] ) ) {
			$zip->close();
			return new \WP_Error( 'bd_format', 'This archive is not a BizyDash backup (its manifest is missing or unreadable).' );
		}
		if ( (int) ( $man['min_reader_version'] ?? 1 ) > Exporter::FORMAT ) {
			$zip->close();
			return new \WP_Error( 'bd_newer', 'This backup was made by a newer version of BizyDash. Update the plugin, then try again.' );
		}
		return array( $zip, $man, $entries );
	}

	/** Copy one archive entry to a file, hashing as it goes. Returns the sha256, or false. */
	public static function extract( \ZipArchive $zip, $name, $dest ) {
		$in = $zip->getStream( $name );
		if ( ! $in ) {
			return false;
		}
		$out = fopen( $dest, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $out ) {
			fclose( $in );
			return false;
		}
		$ctx = hash_init( 'sha256' );
		while ( ! feof( $in ) ) {
			$buf = fread( $in, 1048576 );
			if ( false === $buf ) {
				break;
			}
			hash_update( $ctx, $buf );
			fwrite( $out, $buf );
		}
		fclose( $in );
		fclose( $out );
		return hash_final( $ctx );
	}

	public static function hash_entry( \ZipArchive $zip, $name ) {
		$in = $zip->getStream( $name );
		if ( ! $in ) {
			return false;
		}
		$ctx = hash_init( 'sha256' );
		while ( ! feof( $in ) ) {
			hash_update( $ctx, (string) fread( $in, 1048576 ) );
		}
		fclose( $in );
		return hash_final( $ctx );
	}
}
