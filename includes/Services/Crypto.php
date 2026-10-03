<?php
namespace BizyDash\Services;

defined( 'ABSPATH' ) || exit;

/** Reversible encryption for stored secrets (e.g. the SMTP password), keyed from the site's salts. */
class Crypto {

	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . 'bizydash', true );
	}

	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'b64:' . base64_encode( $plain );
		}
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv );
		return 'enc:' . base64_encode( $iv . $cipher );
	}

	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( 0 === strpos( $stored, 'b64:' ) ) {
			return (string) base64_decode( substr( $stored, 4 ) );
		}
		if ( 0 !== strpos( $stored, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 4 ) );
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}
		$plain = openssl_decrypt( substr( $raw, 16 ), 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		return false === $plain ? '' : $plain;
	}
}
