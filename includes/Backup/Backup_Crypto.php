<?php
namespace BizyDash\Backup;

defined( 'ABSPATH' ) || exit;

/**
 * Password protection for backup files: chunked AES-256-GCM, key from PBKDF2-SHA256.
 *
 * Portable on purpose: the key comes only from the password, never from this site's salts, so a protected backup
 * opens on any site. Streams file to file in 1 MiB chunks, so a large backup never sits in memory. Each chunk is
 * authenticated and bound to its position, and the last chunk is marked, so tampering, reordering and truncation
 * are all detected.
 *
 * Layout: MAGIC(8) | iterations(4) | salt(16) | nonce prefix(4) | chunk size(4), then per chunk: length(4) | tag(16) | data.
 */
class Backup_Crypto {

	const MAGIC = "BZDENC01";
	const ITER  = 250000;
	const CHUNK = 1048576;

	public static function is_encrypted( $path ) {
		$h = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
		if ( ! $h ) {
			return false;
		}
		$m = fread( $h, 8 );
		fclose( $h );
		return self::MAGIC === $m;
	}

	public static function available() {
		return function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) && function_exists( 'hash_pbkdf2' );
	}

	private static function key( $password, $salt, $iter ) {
		return hash_pbkdf2( 'sha256', (string) $password, $salt, $iter, 32, true );
	}

	private static function nonce( $prefix, $i ) {
		return $prefix . pack( 'J', $i );
	}

	private static function aad( $header, $i, $final ) {
		return hash( 'sha256', $header, true ) . pack( 'J', $i ) . ( $final ? 'F' : 'C' );
	}

	/** @return true|\WP_Error */
	public static function encrypt_file( $src, $dst, $password ) {
		if ( ! self::available() ) {
			return new \WP_Error( 'bd_crypto', 'This server cannot encrypt files (OpenSSL AES-GCM is missing).' );
		}
		$in = fopen( $src, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$out = fopen( $dst, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $in || ! $out ) {
			return new \WP_Error( 'bd_io', 'Could not open the backup for encryption.' );
		}
		$salt   = random_bytes( 16 );
		$prefix = random_bytes( 4 );
		$header = self::MAGIC . pack( 'N', self::ITER ) . $salt . $prefix . pack( 'N', self::CHUNK );
		fwrite( $out, $header );
		$key = self::key( $password, $salt, self::ITER );
		$i   = 0;
		$cur = fread( $in, self::CHUNK );
		do {
			$next  = feof( $in ) ? '' : fread( $in, self::CHUNK );
			$final = ( '' === $next || false === $next );
			$tag   = '';
			$ct    = openssl_encrypt( (string) $cur, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, self::nonce( $prefix, $i ), $tag, self::aad( $header, $i, $final ), 16 );
			if ( false === $ct ) {
				fclose( $in );
				fclose( $out );
				return new \WP_Error( 'bd_crypto', 'Encryption failed.' );
			}
			fwrite( $out, pack( 'N', strlen( $ct ) ) . $tag . $ct );
			$cur = $next;
			$i++;
		} while ( ! $final );
		fclose( $in );
		fclose( $out );
		return true;
	}

	/** @return true|\WP_Error */
	public static function decrypt_file( $src, $dst, $password ) {
		$in = fopen( $src, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $in ) {
			return new \WP_Error( 'bd_io', 'Could not read the backup file.' );
		}
		$header = fread( $in, 36 );
		if ( strlen( $header ) < 36 || self::MAGIC !== substr( $header, 0, 8 ) ) {
			fclose( $in );
			return new \WP_Error( 'bd_format', 'This is not a protected BizyDash backup.' );
		}
		$iter   = unpack( 'N', substr( $header, 8, 4 ) )[1];
		$salt   = substr( $header, 12, 16 );
		$prefix = substr( $header, 28, 4 );
		if ( $iter < 100000 || $iter > 5000000 ) {
			fclose( $in );
			return new \WP_Error( 'bd_format', 'The protected backup header is damaged.' );
		}
		$key = self::key( $password, $salt, $iter );
		$out = fopen( $dst, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$i   = 0;
		$err = new \WP_Error( 'bd_password', 'Wrong password, or the backup file is damaged.' );
		while ( true ) {
			$lh = fread( $in, 4 );
			if ( 4 !== strlen( (string) $lh ) ) {
				fclose( $in );
				fclose( $out );
				return new \WP_Error( 'bd_truncated', 'The backup file is incomplete (it ends too early).' );
			}
			$len = unpack( 'N', $lh )[1];
			if ( $len > self::CHUNK + 64 ) {
				fclose( $in );
				fclose( $out );
				return $err;
			}
			$tag = fread( $in, 16 );
			$ct  = $len ? fread( $in, $len ) : '';
			if ( 16 !== strlen( (string) $tag ) || strlen( (string) $ct ) !== $len ) {
				fclose( $in );
				fclose( $out );
				return new \WP_Error( 'bd_truncated', 'The backup file is incomplete (it ends too early).' );
			}
			$peek  = fread( $in, 1 );
			$final = ( '' === $peek || false === $peek );
			if ( ! $final ) {
				fseek( $in, -1, SEEK_CUR );
			}
			$pt = openssl_decrypt( $ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, self::nonce( $prefix, $i ), $tag, self::aad( $header, $i, $final ) );
			if ( false === $pt ) {
				fclose( $in );
				fclose( $out );
				return $err;
			}
			fwrite( $out, $pt );
			$i++;
			if ( $final ) {
				break;
			}
		}
		fclose( $in );
		fclose( $out );
		return true;
	}
}
