<?php
namespace BizyDash\Services;

use BizyDash\Database\Schema;
use BizyDash\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Contracts & e-signatures.
 *
 * Evidence model (what a court or arbitrator looks for):
 *  - the exact text agreed is frozen and fingerprinted (SHA-256) when it is sent;
 *  - each signer proves control of their email address with a one-time code;
 *  - the signer's intent and consent, name, signature image, IP, browser and time are recorded;
 *  - every step is written to an append-only, hash-chained audit log (editing a past row breaks the chain);
 *  - a Certificate of Completion summarises all of it for every party.
 */
class Contracts {

	const OTP_TTL      = 600;  // seconds a code stays valid.
	const OTP_ATTEMPTS = 5;    // wrong entries before the code is burned.
	const OTP_HOURLY   = 6;    // codes per signer per hour.
	const CONSENT_TEXT = 'I agree to sign this document electronically and to be legally bound by it. I understand my electronic signature has the same legal effect as a handwritten one, and I have had the opportunity to read the whole document and keep a copy.';

	public static function t( $n ) {
		return Schema::table( $n );
	}

	/* ───────────────────────── Numbers, tokens, merge ───────────────────────── */

	public static function next_number() {
		global $wpdb;
		$n = (int) get_option( 'bizydash_contract_next', 0 );
		if ( $n < 1 ) {
			$n = 1 + (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'contracts' ) ); // phpcs:ignore WordPress.DB
		}
		do {
			$num    = 'CT-' . str_pad( (string) $n, 4, '0', STR_PAD_LEFT );
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'contracts' ) . ' WHERE number=%s', $num ) ); // phpcs:ignore WordPress.DB
			$n++;
		} while ( $exists );
		update_option( 'bizydash_contract_next', $n, false );
		return $num;
	}

	public static function new_token() {
		global $wpdb;
		do {
			$t      = strtolower( wp_generate_password( 22, false, false ) );
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'contract_signers' ) . ' WHERE token=%s', $t ) ); // phpcs:ignore WordPress.DB
		} while ( $exists );
		return $t;
	}

	public static function sign_url( $token ) {
		return home_url( '/sign/' . rawurlencode( $token ) );
	}

	/** {{tokens}} → values. Unknown tokens are left visible so staff notice them before sending. */
	public static function merge( $html, array $c ) {
		global $wpdb;
		$client  = ! empty( $c['client_id'] ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'clients' ) . ' WHERE id=%d', $c['client_id'] ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB
		$s       = Settings::get();
		$map     = array(
			'client_name'    => $client ? $client['company_name'] : '',
			'client_contact' => $client ? $client['contact_person'] : '',
			'client_email'   => $client ? $client['email'] : '',
			'client_address' => $client && isset( $client['address'] ) ? $client['address'] : '',
			'company'        => $s['business_name'],
			'company_address' => $s['business_address'],
			'date'           => wp_date( get_option( 'date_format' ) ),
			'contract_title' => isset( $c['title'] ) ? $c['title'] : '',
			'contract_number' => isset( $c['number'] ) ? $c['number'] : '',
		);
		return preg_replace_callback(
			'/\{\{\s*([a-z_]+)\s*\}\}/i',
			function ( $m ) use ( $map ) {
				$k = strtolower( $m[1] );
				return ( isset( $map[ $k ] ) && '' !== (string) $map[ $k ] ) ? esc_html( $map[ $k ] ) : $m[0];
			},
			(string) $html
		);
	}

	public static function tokens_help() {
		return array( 'client_name', 'client_contact', 'client_email', 'company', 'company_address', 'date', 'contract_title', 'contract_number' );
	}

	/** The fingerprint of exactly what the signers see. */
	public static function doc_hash( array $c ) {
		return hash( 'sha256', $c['number'] . "\n" . $c['title'] . "\n" . $c['body'] );
	}

	/* ───────────────────────── Audit log (hash-chained) ───────────────────────── */

	public static function ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ), 0, 64 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	public static function forwarded() {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				return substr( sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ), 0, 255 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			}
		}
		return '';
	}

	public static function ua() {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/** Appends an event; each row's hash covers the previous row's hash. Returns the event hash. */
	public static function event( $contract_id, $signer_id, $event, array $detail = array() ) {
		global $wpdb;
		$lock = 'bd_ct_' . (int) $contract_id;
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ); // phpcs:ignore WordPress.DB
		$tbl  = self::t( 'contract_events' );
		$prev = (string) $wpdb->get_var( $wpdb->prepare( "SELECT hash FROM $tbl WHERE contract_id=%d ORDER BY id DESC LIMIT 1", $contract_id ) ); // phpcs:ignore WordPress.DB
		$now  = gmdate( 'Y-m-d H:i:s' );
		$json = wp_json_encode( $detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$hash = hash( 'sha256', $prev . '|' . (int) $contract_id . '|' . (int) $signer_id . '|' . $event . '|' . $json . '|' . $now );
		$wpdb->insert(
			$tbl,
			array(
				'contract_id' => (int) $contract_id,
				'signer_id'   => (int) $signer_id,
				'user_id'     => get_current_user_id(),
				'event'       => $event,
				'detail'      => $json,
				'ip'          => self::ip(),
				'ua'          => self::ua(),
				'created_at'  => $now,
				'prev_hash'   => $prev,
				'hash'        => $hash,
			)
		);
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB
		return $hash;
	}

	/** Re-computes the chain. Returns array(ok => bool, count => int, broken_at => id|null). */
	public static function verify_chain( $contract_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'contract_events' ) . ' WHERE contract_id=%d ORDER BY id', $contract_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$prev = '';
		foreach ( (array) $rows as $r ) {
			$h = hash( 'sha256', $prev . '|' . (int) $r['contract_id'] . '|' . (int) $r['signer_id'] . '|' . $r['event'] . '|' . $r['detail'] . '|' . $r['created_at'] );
			if ( $r['prev_hash'] !== $prev || $r['hash'] !== $h ) {
				return array( 'ok' => false, 'count' => count( $rows ), 'broken_at' => (int) $r['id'] );
			}
			$prev = $r['hash'];
		}
		return array( 'ok' => true, 'count' => count( $rows ), 'broken_at' => null );
	}

	/* ───────────────────────── Preview links ───────────────────────── */

	const VIEW_TTL = DAY_IN_SECONDS; // how long a verified preview stays unlocked on that browser.

	public static function preview_url( $slug ) {
		return $slug ? home_url( '/contract/' . rawurlencode( $slug ) ) : '';
	}

	public static function find_by_preview( $slug ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-z0-9-]{4,64}$/', (string) $slug ) ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'contracts' ) . ' WHERE preview_slug=%s', $slug ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/** The contract's preview slug (created on first use; always new when $regenerate). */
	public static function ensure_preview( $id, $regenerate = false ) {
		global $wpdb;
		$tbl  = self::t( 'contracts' );
		$slug = (string) $wpdb->get_var( $wpdb->prepare( "SELECT preview_slug FROM $tbl WHERE id=%d", $id ) ); // phpcs:ignore WordPress.DB
		if ( '' === $slug || $regenerate ) {
			do {
				$slug   = strtolower( wp_generate_password( 12, false, false ) );
				$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tbl WHERE preview_slug=%s", $slug ) ); // phpcs:ignore WordPress.DB
			} while ( $exists );
			$wpdb->update( $tbl, array( 'preview_slug' => $slug ), array( 'id' => (int) $id ) );
			self::event( $id, 0, $regenerate ? 'preview_link_replaced' : 'preview_link_created', array() );
		}
		return $slug;
	}

	public static function set_view_access( $id, $access ) {
		global $wpdb;
		$access = 'public' === $access ? 'public' : 'private';
		$c      = self::get( $id );
		if ( $c && $c['view_access'] !== $access ) {
			$wpdb->update( self::t( 'contracts' ), array( 'view_access' => $access ), array( 'id' => (int) $id ) );
			self::event( $id, 0, 'preview_access_changed', array( 'access' => $access ) );
		}
		return $access;
	}

	private static function signer_by_email( $contract_id, $email ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'contract_signers' ) . ' WHERE contract_id=%d AND LOWER(email)=LOWER(%s) LIMIT 1', $contract_id, trim( (string) $email ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/** Step 1 of a private preview: email a code — but only to someone on the signer list. Never reveals whether the address is listed. */
	public static function request_view_otp( array $c, $email ) {
		global $wpdb;
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'bizydash_invalid', 'Please enter a valid email address.', array( 'status' => 400 ) );
		}
		$ipkey = 'bd_pvr_' . md5( self::ip() . '|' . $c['id'] );
		$n     = (int) get_transient( $ipkey );
		if ( $n >= 8 ) {
			return new \WP_Error( 'bizydash_rate', 'Too many attempts. Please wait a while and try again.', array( 'status' => 429 ) );
		}
		set_transient( $ipkey, $n + 1, HOUR_IN_SECONDS );

		$sg = self::signer_by_email( $c['id'], $email );
		if ( ! $sg ) {
			self::event( $c['id'], 0, 'preview_denied', array( 'email' => $email ) );
			return true;
		}
		$tbl  = self::t( 'contract_otps' );
		$hour = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tbl WHERE signer_id=%d AND purpose='view' AND sent_at > %s", $sg['id'], gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
		if ( $hour >= self::OTP_HOURLY ) {
			return new \WP_Error( 'bizydash_rate', 'Too many codes requested. Please wait a while and try again.', array( 'status' => 429 ) );
		}
		$wpdb->update( $tbl, array( 'status' => 'superseded' ), array( 'signer_id' => $sg['id'], 'status' => 'sent', 'purpose' => 'view' ) );
		$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$mail = self::wrap_mail( 'Your code to view the agreement', '<p>Hi ' . esc_html( $sg['name'] ) . ',</p><p>Use this code to open <strong>' . esc_html( $c['title'] ) . '</strong>:</p><p style="font-size:34px;font-weight:700;letter-spacing:.3em;margin:18px 0;color:#222530">' . esc_html( $code ) . '</p><p>It works once and expires in ' . (int) ( self::OTP_TTL / 60 ) . ' minutes. Viewing does not sign anything.</p>' );
		$res  = Mailer::send( array( 'to' => array( $sg['email'] ), 'subject' => 'Your code to view: ' . $c['title'], 'html' => $mail, 'text' => 'Your code to view the agreement is ' . $code ) );
		$wpdb->insert( $tbl, array( 'contract_id' => (int) $c['id'], 'signer_id' => (int) $sg['id'], 'email' => $sg['email'], 'code' => $code, 'sent_at' => gmdate( 'Y-m-d H:i:s' ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::OTP_TTL ), 'mail_ok' => $res['ok'] ? 1 : 0, 'mail_error' => $res['ok'] ? '' : mb_substr( $res['error'], 0, 250 ), 'status' => $res['ok'] ? 'sent' : 'failed', 'purpose' => 'view', 'ip' => self::ip() ) );
		self::event( $c['id'], $sg['id'], 'preview_otp_sent', array( 'to' => $sg['email'], 'code' => $code, 'mail_accepted' => (bool) $res['ok'] ) );
		return true;
	}

	/** Step 2: check the code. Returns the signer row on success. */
	public static function verify_view_otp( array $c, $email, $code ) {
		global $wpdb;
		$sg   = self::signer_by_email( $c['id'], sanitize_email( $email ) );
		$code = preg_replace( '/\D/', '', (string) $code );
		$bad  = new \WP_Error( 'bizydash_otp', 'That code is not right, or it has expired. Please request a new one.', array( 'status' => 400 ) );
		if ( ! $sg ) {
			return $bad;
		}
		$tbl = self::t( 'contract_otps' );
		$otp = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tbl WHERE signer_id=%d AND purpose='view' AND status='sent' ORDER BY id DESC LIMIT 1", $sg['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $otp ) {
			return $bad;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( $otp['expires_at'] < $now ) {
			$wpdb->update( $tbl, array( 'status' => 'expired', 'entered_code' => substr( $code, 0, 10 ), 'entered_at' => $now ), array( 'id' => $otp['id'] ) );
			return $bad;
		}
		$attempts = (int) $otp['attempts'] + 1;
		if ( ! hash_equals( (string) $otp['code'], $code ) ) {
			$wpdb->update( $tbl, array( 'attempts' => $attempts, 'entered_code' => substr( $code, 0, 10 ), 'entered_at' => $now, 'status' => $attempts >= self::OTP_ATTEMPTS ? 'failed' : 'sent' ), array( 'id' => $otp['id'] ) );
			self::event( $c['id'], $sg['id'], 'preview_otp_failed', array( 'entered' => $code, 'attempt' => $attempts ) );
			return $bad;
		}
		$wpdb->update( $tbl, array( 'attempts' => $attempts, 'entered_code' => $code, 'entered_at' => $now, 'status' => 'verified' ), array( 'id' => $otp['id'] ) );
		self::event( $c['id'], $sg['id'], 'preview_otp_verified', array( 'entered' => $code, 'sent_code' => $otp['code'], 'email' => $sg['email'] ) );
		return $sg;
	}

	private static function cookie_name( $contract_id ) {
		return 'bdcv_' . (int) $contract_id;
	}

	private static function cookie_sig( $contract_id, $signer_id, $exp ) {
		return hash_hmac( 'sha256', (int) $contract_id . '|' . (int) $signer_id . '|' . (int) $exp, wp_salt( 'auth' ) . 'bd-view' );
	}

	public static function issue_view_cookie( array $c, array $signer ) {
		$exp = time() + self::VIEW_TTL;
		$val = (int) $signer['id'] . '.' . $exp . '.' . self::cookie_sig( $c['id'], $signer['id'], $exp );
		setcookie( self::cookie_name( $c['id'] ), $val, array( 'expires' => $exp, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		$_COOKIE[ self::cookie_name( $c['id'] ) ] = $val;
	}

	/** The signer this browser verified as (if the preview cookie is valid for this contract). */
	public static function view_cookie_signer( array $c ) {
		$name = self::cookie_name( $c['id'] );
		if ( empty( $_COOKIE[ $name ] ) ) {
			return null;
		}
		$parts = explode( '.', sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( 3 !== count( $parts ) || (int) $parts[1] < time() || ! hash_equals( self::cookie_sig( $c['id'], (int) $parts[0], (int) $parts[1] ), (string) $parts[2] ) ) {
			return null;
		}
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'contract_signers' ) . ' WHERE id=%d AND contract_id=%d', (int) $parts[0], $c['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/** Log a preview view, at most once per visitor per half hour so the audit log stays readable. */
	public static function log_preview_view( array $c, $mode, $signer_id = 0, $who = '' ) {
		$key = 'bd_pvv_' . md5( self::ip() . '|' . self::ua() . '|' . $c['id'] . '|' . $mode . '|' . $signer_id );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, 30 * MINUTE_IN_SECONDS );
		self::event( $c['id'], $signer_id, 'preview_viewed', array( 'mode' => $mode, 'who' => $who ) );
	}

	/* ───────────────────────── Certificate ID & integrity ───────────────────────── */

	/** Serial printed on the Certificate of Completion (derived from the sealed "completed" log entry). */
	public static function cert_id( $contract_id ) {
		global $wpdb;
		$h = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT hash FROM ' . self::t( 'contract_events' ) . " WHERE contract_id=%d AND event='completed' ORDER BY id LIMIT 1", $contract_id ) ); // phpcs:ignore WordPress.DB
		return '' === $h ? '' : strtoupper( implode( '-', str_split( substr( $h, 0, 16 ), 4 ) ) );
	}

	public static function verify_url( $cert_id = '' ) {
		return $cert_id ? home_url( '/verify/' . rawurlencode( $cert_id ) ) : home_url( '/verify' );
	}

	public static function find_by_cert( $cert_id ) {
		global $wpdb;
		$hex = strtolower( preg_replace( '/[^0-9a-f]/i', '', (string) $cert_id ) );
		if ( 16 !== strlen( $hex ) ) {
			return null;
		}
		$cid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT contract_id FROM ' . self::t( 'contract_events' ) . " WHERE event='completed' AND hash LIKE %s LIMIT 1", $wpdb->esc_like( $hex ) . '%' ) ); // phpcs:ignore WordPress.DB
		return $cid ? self::get( $cid ) : null;
	}

	/**
	 * Re-computes every fingerprint from what is stored right now and compares it with what was sealed at signing.
	 *
	 * @return array{ok:bool,complete:bool,checks:array<int,array{label:string,ok:bool}>,cert_id:string}
	 */
	public static function integrity( $id ) {
		global $wpdb;
		$c = self::get( $id );
		if ( ! $c ) {
			return array( 'ok' => false, 'complete' => false, 'checks' => array(), 'cert_id' => '' );
		}
		$checks  = array();
		$signers = self::signers( $id, true );
		$sent    = '' !== $c['body_hash'];
		if ( $sent ) {
			$checks[] = array( 'label' => 'The agreement text is exactly what was sent for signature', 'ok' => hash_equals( $c['body_hash'], self::doc_hash( $c ) ) );
		}
		// What each "signed" log entry recorded about the signature image.
		$rec = array();
		foreach ( self::events( $id ) as $e ) {
			if ( 'signed' === $e['event'] ) {
				$d = json_decode( (string) $e['detail'], true );
				$rec[ (int) $e['signer_id'] ] = is_array( $d ) ? $d : array();
			}
		}
		$sig_ok = true;
		$n      = 0;
		foreach ( $signers as $s ) {
			if ( 'signed' !== $s['status'] ) {
				continue;
			}
			$n++;
			$d = isset( $rec[ (int) $s['id'] ] ) ? $rec[ (int) $s['id'] ] : array();
			if ( empty( $d['signature_sha256'] ) || ! hash_equals( $d['signature_sha256'], hash( 'sha256', (string) $s['sign_image'] ) ) || ( ! empty( $d['document_sha256'] ) && $d['document_sha256'] !== $c['body_hash'] ) ) {
				$sig_ok = false;
			}
		}
		if ( $n ) {
			$checks[] = array( 'label' => $n > 1 ? 'All ' . $n . ' signatures are unchanged since they were made' : 'The signature is unchanged since it was made', 'ok' => $sig_ok );
		}
		$chain = self::verify_chain( $id );
		if ( $chain['count'] ) {
			$checks[] = array( 'label' => 'The audit log is complete and untouched (' . (int) $chain['count'] . ' entries, each sealed to the one before)', 'ok' => $chain['ok'] );
		}
		$complete = 'signed' === $c['status'];
		if ( $complete ) {
			$parts = array( $c['body_hash'] );
			foreach ( $signers as $s ) {
				$parts[] = $s['email'] . ':' . $s['signed_at'] . ':' . hash( 'sha256', (string) $s['sign_image'] );
			}
			$final = hash( 'sha256', implode( '|', $parts ) );
			$sealed = '';
			foreach ( self::events( $id ) as $e ) {
				if ( 'completed' === $e['event'] ) {
					$d      = json_decode( (string) $e['detail'], true );
					$sealed = is_array( $d ) && isset( $d['final_sha256'] ) ? $d['final_sha256'] : '';
					break;
				}
			}
			$checks[] = array( 'label' => 'The final signed record matches the seal made when the last person signed', 'ok' => '' !== $c['final_hash'] && hash_equals( $c['final_hash'], $final ) && hash_equals( $sealed, $final ) );
		}
		if ( ! empty( $c['pdf_file'] ) ) {
			$sealed_pdf = '';
			foreach ( self::events( $id ) as $e ) {
				if ( 'pdf_generated' === $e['event'] ) {
					$d          = json_decode( (string) $e['detail'], true );
					$sealed_pdf = is_array( $d ) && isset( $d['pdf_sha256'] ) ? $d['pdf_sha256'] : '';
					break;
				}
			}
			$checks[] = array( 'label' => 'The stored signed PDF is exactly the file that was sealed', 'ok' => '' !== $sealed_pdf && hash_equals( $sealed_pdf, (string) $c['pdf_hash'] ) && Contract_Pdf::verify( $c ) );
		}
		$ok = $checks ? ! in_array( false, wp_list_pluck( $checks, 'ok' ), true ) : false;
		return array( 'ok' => $ok, 'complete' => $complete, 'checks' => $checks, 'cert_id' => $complete ? self::cert_id( $id ) : '' );
	}

	/* ───────────────────────── Reads ───────────────────────── */

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'contracts' ) . ' WHERE id=%d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function signers( $contract_id, $with_image = false ) {
		global $wpdb;
		$cols = $with_image ? '*' : 'id, contract_id, name, email, role, sort_order, token, status, invited_at, viewed_at, consent_at, otp_verified_at, signed_at, sign_name, sign_method, sign_ip, sign_forwarded, sign_ua, sign_env, declined_reason';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT $cols FROM " . self::t( 'contract_signers' ) . ' WHERE contract_id=%d ORDER BY sort_order, id', $contract_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as &$r ) {
			$r['id']   = (int) $r['id'];
			$r['link'] = self::sign_url( $r['token'] );
		}
		return (array) $rows;
	}

	public static function events( $contract_id ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT e.*, u.display_name AS user_name FROM ' . self::t( 'contract_events' ) . ' e LEFT JOIN ' . $wpdb->users . ' u ON u.ID=e.user_id WHERE e.contract_id=%d ORDER BY e.id', $contract_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function otps( $contract_id, $purpose = 'sign' ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'contract_otps' ) . ' WHERE contract_id=%d AND purpose=%s ORDER BY id', $contract_id, $purpose ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/* ───────────────────────── Draft editing ───────────────────────── */

	/** Replace the signer list while a contract is still a draft. */
	public static function sync_signers( $contract_id, array $list ) {
		global $wpdb;
		$tbl      = self::t( 'contract_signers' );
		$existing = $wpdb->get_results( $wpdb->prepare( "SELECT id, email, token FROM $tbl WHERE contract_id=%d", $contract_id ), OBJECT_K ); // phpcs:ignore WordPress.DB
		$keep     = array();
		$i        = 0;
		foreach ( $list as $s ) {
			$name  = isset( $s['name'] ) ? sanitize_text_field( $s['name'] ) : '';
			$email = isset( $s['email'] ) ? sanitize_email( $s['email'] ) : '';
			if ( '' === $name && '' === $email ) {
				continue;
			}
			$role = isset( $s['role'] ) && in_array( $s['role'], array( 'client', 'company', 'witness' ), true ) ? $s['role'] : 'client';
			$row  = array( 'name' => $name, 'email' => $email, 'role' => $role, 'sort_order' => $i++ );
			$id   = isset( $s['id'] ) ? (int) $s['id'] : 0;
			if ( $id && isset( $existing[ $id ] ) ) {
				$wpdb->update( $tbl, $row, array( 'id' => $id ) );
				$keep[] = $id;
			} else {
				$row['contract_id'] = (int) $contract_id;
				$row['token']       = self::new_token();
				$wpdb->insert( $tbl, $row );
				$keep[] = (int) $wpdb->insert_id;
			}
		}
		foreach ( array_keys( $existing ) as $id ) {
			if ( ! in_array( (int) $id, $keep, true ) ) {
				$wpdb->delete( $tbl, array( 'id' => (int) $id ) );
			}
		}
	}

	/* ───────────────────────── Sending ───────────────────────── */

	private static function wrap_mail( $title, $body_html ) {
		$s    = Settings::get();
		$logo = $s['business_logo_email_url'] ? '<img src="' . esc_url( $s['business_logo_email_url'] ) . '" alt="" style="height:36px;width:auto;margin-bottom:18px">' : '<div style="font-weight:700;font-size:16px;margin-bottom:18px">' . esc_html( $s['business_name'] ) . '</div>';
		return '<div style="background:#f3f5f8;padding:28px 12px;font-family:Inter,Arial,sans-serif;color:#0e121b"><div style="max-width:520px;margin:0 auto;background:#fff;border-radius:14px;padding:30px">' . $logo . '<h2 style="margin:0 0 14px;font-size:19px">' . esc_html( $title ) . '</h2>' . $body_html . '<p style="color:#868e9d;font-size:12px;margin-top:26px">Sent by ' . esc_html( $s['business_name'] ) . '. If you did not expect this email you can ignore it.</p></div></div>';
	}

	private static function button( $url, $label ) {
		return '<p style="margin:22px 0"><a href="' . esc_url( $url ) . '" style="background:#222530;color:#fff;text-decoration:none;font-weight:600;padding:12px 20px;border-radius:9px;display:inline-block">' . esc_html( $label ) . '</a></p>';
	}

	public static function invite( array $c, array $signer, $reminder = false ) {
		$s    = Settings::get();
		$html = self::wrap_mail(
			( $reminder ? 'Reminder: ' : '' ) . $c['title'],
			'<p>Hi ' . esc_html( $signer['name'] ) . ',</p><p>' . esc_html( $s['business_name'] ) . ' has asked you to review and sign <strong>' . esc_html( $c['title'] ) . '</strong> (' . esc_html( $c['number'] ) . ').</p><p>You will confirm it is you with a one-time code we email you, so please have this inbox open.</p>' . self::button( self::sign_url( $signer['token'] ), 'Review & sign' )
		);
		$res = Mailer::send( array( 'to' => array( $signer['email'] ), 'subject' => ( $reminder ? 'Reminder: please sign — ' : 'Please sign — ' ) . $c['title'], 'html' => $html, 'text' => 'Review and sign: ' . self::sign_url( $signer['token'] ) ) );
		self::event( $c['id'], $signer['id'], $reminder ? 'reminder_emailed' : 'invite_emailed', array( 'to' => $signer['email'], 'ok' => (bool) $res['ok'], 'error' => $res['ok'] ? '' : $res['error'] ) );
		return $res;
	}

	/** Freeze the text, fingerprint it and invite every signer. */
	public static function send( $id ) {
		global $wpdb;
		$c = self::get( $id );
		if ( ! $c ) {
			return new \WP_Error( 'bizydash_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		if ( 'draft' !== $c['status'] ) {
			return new \WP_Error( 'bizydash_state', 'This contract was already sent.', array( 'status' => 409 ) );
		}
		$signers = self::signers( $id );
		if ( ! $signers ) {
			return new \WP_Error( 'bizydash_invalid', 'Add at least one person who needs to sign.', array( 'status' => 400 ) );
		}
		foreach ( $signers as $sg ) {
			if ( '' === $sg['name'] || ! is_email( $sg['email'] ) ) {
				return new \WP_Error( 'bizydash_invalid', 'Every signer needs a name and a valid email address.', array( 'status' => 400 ) );
			}
		}
		if ( '' === trim( wp_strip_all_tags( (string) $c['body'] ) ) ) {
			return new \WP_Error( 'bizydash_invalid', 'The contract has no text yet.', array( 'status' => 400 ) );
		}
		$c['body'] = self::merge( $c['body'], $c );
		if ( preg_match( '/\{\{\s*[a-z_]+\s*\}\}/i', $c['body'], $m ) ) {
			return new \WP_Error( 'bizydash_invalid', 'The text still contains an empty field: ' . $m[0] . '. Fill it in or remove it first.', array( 'status' => 400 ) );
		}
		$hash = self::doc_hash( $c );
		$now  = current_time( 'mysql' );
		$wpdb->update( self::t( 'contracts' ), array( 'status' => 'sent', 'body' => $c['body'], 'body_hash' => $hash, 'sent_at' => $now, 'updated_at' => $now ), array( 'id' => $id ) );
		self::event( $id, 0, 'sent', array( 'document_sha256' => $hash, 'signers' => wp_list_pluck( $signers, 'email' ) ) );
		$c['id'] = (int) $id;
		$failed  = array();
		foreach ( $signers as $sg ) {
			$wpdb->update( self::t( 'contract_signers' ), array( 'invited_at' => $now ), array( 'id' => $sg['id'] ) );
			$r = self::invite( $c, $sg );
			if ( ! $r['ok'] ) {
				$failed[] = $sg['email'];
			}
		}
		Activity::log( 'contracts', $id, (int) $c['client_id'], 'sent', sprintf( 'Contract %s sent for signature', $c['number'] ) );
		return array( 'failed' => $failed );
	}

	public static function remind( $id ) {
		$c = self::get( $id );
		if ( ! $c || ! in_array( $c['status'], array( 'sent', 'partially_signed' ), true ) ) {
			return new \WP_Error( 'bizydash_state', 'Only contracts waiting for signatures can be reminded.', array( 'status' => 409 ) );
		}
		$n = 0;
		foreach ( self::signers( $id ) as $sg ) {
			if ( in_array( $sg['status'], array( 'pending', 'viewed' ), true ) ) {
				self::invite( $c, $sg, true );
				$n++;
			}
		}
		return array( 'reminded' => $n );
	}

	public static function void( $id, $reason = '' ) {
		global $wpdb;
		$c = self::get( $id );
		if ( ! $c || in_array( $c['status'], array( 'draft', 'signed', 'void' ), true ) ) {
			return new \WP_Error( 'bizydash_state', 'Only contracts that are out for signature can be voided. A fully signed contract is a permanent record.', array( 'status' => 409 ) );
		}
		$wpdb->update( self::t( 'contracts' ), array( 'status' => 'void', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
		self::event( $id, 0, 'voided', array( 'reason' => sanitize_text_field( $reason ) ) );
		Activity::log( 'contracts', $id, (int) $c['client_id'], 'void', sprintf( 'Contract %s voided', $c['number'] ) );
		return true;
	}

	/* ───────────────────────── Signing (public) ───────────────────────── */

	public static function signer_by_token( $token ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-z0-9]{10,64}$/', (string) $token ) ) {
			return null;
		}
		$sg = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'contract_signers' ) . ' WHERE token=%s', $token ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $sg ) {
			return null;
		}
		$c = self::get( $sg['contract_id'] );
		return $c ? array( 'signer' => $sg, 'contract' => $c ) : null;
	}

	public static function can_sign( array $c, array $sg ) {
		if ( ! in_array( $c['status'], array( 'sent', 'partially_signed' ), true ) ) {
			return new \WP_Error( 'bizydash_state', 'This contract is no longer open for signing.', array( 'status' => 409 ) );
		}
		if ( ! empty( $c['expires_on'] ) && $c['expires_on'] < gmdate( 'Y-m-d' ) ) {
			return new \WP_Error( 'bizydash_state', 'This signing link has expired. Please ask for a new one.', array( 'status' => 410 ) );
		}
		if ( 'signed' === $sg['status'] || 'declined' === $sg['status'] ) {
			return new \WP_Error( 'bizydash_state', 'You have already responded to this contract.', array( 'status' => 409 ) );
		}
		return true;
	}

	/** Validate the signature payload the browser sends. */
	private static function clean_signature( array $p ) {
		$name = isset( $p['name'] ) ? trim( sanitize_text_field( $p['name'] ) ) : '';
		if ( strlen( $name ) < 2 ) {
			return new \WP_Error( 'bizydash_invalid', 'Please type your full name.', array( 'status' => 400 ) );
		}
		$method = isset( $p['method'] ) && 'drawn' === $p['method'] ? 'drawn' : 'typed';
		$img    = isset( $p['image'] ) ? (string) $p['image'] : '';
		if ( '' === $img || 0 !== strpos( $img, 'data:image/png;base64,' ) || strlen( $img ) > 300000 || ! preg_match( '#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $img ) ) {
			return new \WP_Error( 'bizydash_invalid', 'Please add your signature.', array( 'status' => 400 ) );
		}
		return array( 'name' => $name, 'method' => $method, 'image' => $img );
	}

	/** Step 1: they consented and signed on screen; we email a one-time code. */
	public static function request_otp( $token, array $p ) {
		global $wpdb;
		$f = self::signer_by_token( $token );
		if ( ! $f ) {
			return new \WP_Error( 'bizydash_not_found', 'This link is not valid.', array( 'status' => 404 ) );
		}
		$c  = $f['contract'];
		$sg = $f['signer'];
		$ok = self::can_sign( $c, $sg );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( empty( $p['consent'] ) ) {
			return new \WP_Error( 'bizydash_invalid', 'Please tick the box to agree to sign electronically.', array( 'status' => 400 ) );
		}
		$sig = self::clean_signature( $p );
		if ( is_wp_error( $sig ) ) {
			return $sig;
		}
		$tbl  = self::t( 'contract_otps' );
		$hour = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tbl WHERE signer_id=%d AND purpose='sign' AND sent_at > %s", $sg['id'], gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
		if ( (int) $hour >= self::OTP_HOURLY ) {
			return new \WP_Error( 'bizydash_rate', 'Too many codes requested. Please wait a while and try again.', array( 'status' => 429 ) );
		}
		$last = $wpdb->get_var( $wpdb->prepare( "SELECT sent_at FROM $tbl WHERE signer_id=%d AND purpose='sign' ORDER BY id DESC LIMIT 1", $sg['id'] ) ); // phpcs:ignore WordPress.DB
		if ( $last && ( time() - strtotime( $last . ' UTC' ) ) < 30 ) {
			return new \WP_Error( 'bizydash_rate', 'A code was just sent. You can ask for another in a few seconds.', array( 'status' => 429 ) );
		}

		$wpdb->update( $tbl, array( 'status' => 'superseded' ), array( 'signer_id' => $sg['id'], 'status' => 'sent', 'purpose' => 'sign' ) );
		$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$now  = gmdate( 'Y-m-d H:i:s' );
		$mail = self::wrap_mail(
			'Your verification code',
			'<p>Hi ' . esc_html( $sg['name'] ) . ',</p><p>Use this code to confirm it is you signing <strong>' . esc_html( $c['title'] ) . '</strong>:</p><p style="font-size:34px;font-weight:700;letter-spacing:.3em;margin:18px 0;color:#222530">' . esc_html( $code ) . '</p><p>It works once and expires in ' . (int) ( self::OTP_TTL / 60 ) . ' minutes. Never share it with anyone.</p>'
		);
		$res = Mailer::send( array( 'to' => array( $sg['email'] ), 'subject' => 'Your signing code: ' . $code, 'html' => $mail, 'text' => 'Your signing code is ' . $code . '. It expires in ' . (int) ( self::OTP_TTL / 60 ) . ' minutes.' ) );
		$wpdb->insert(
			$tbl,
			array(
				'contract_id' => (int) $c['id'],
				'signer_id'   => (int) $sg['id'],
				'email'       => $sg['email'],
				'code'        => $code,
				'sent_at'     => $now,
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + self::OTP_TTL ),
				'mail_ok'     => $res['ok'] ? 1 : 0,
				'mail_error'  => $res['ok'] ? '' : mb_substr( $res['error'], 0, 250 ),
				'status'      => $res['ok'] ? 'sent' : 'failed',
				'ip'          => self::ip(),
			)
		);
		if ( empty( $sg['consent_at'] ) ) {
			$wpdb->update( self::t( 'contract_signers' ), array( 'consent_at' => current_time( 'mysql' ) ), array( 'id' => $sg['id'] ) );
		}
		self::event( $c['id'], $sg['id'], 'consent_given', array( 'text' => self::CONSENT_TEXT, 'typed_name' => $sig['name'], 'document_sha256' => $c['body_hash'] ) );
		self::event( $c['id'], $sg['id'], 'otp_sent', array( 'to' => $sg['email'], 'code' => $code, 'mail_accepted' => (bool) $res['ok'], 'error' => $res['ok'] ? '' : $res['error'], 'expires_in_seconds' => self::OTP_TTL ) );
		if ( ! $res['ok'] ) {
			return new \WP_Error( 'bizydash_mail', 'We could not email the code. Please try again, or contact the sender.', array( 'status' => 502 ) );
		}
		$at = explode( '@', $sg['email'] );
		return array( 'sent_to' => substr( $at[0], 0, 2 ) . str_repeat( '•', max( 1, strlen( $at[0] ) - 2 ) ) . '@' . ( isset( $at[1] ) ? $at[1] : '' ), 'expires_in' => self::OTP_TTL );
	}

	/** Step 2: verify the code and record the signature. */
	public static function sign( $token, array $p ) {
		global $wpdb;
		$f = self::signer_by_token( $token );
		if ( ! $f ) {
			return new \WP_Error( 'bizydash_not_found', 'This link is not valid.', array( 'status' => 404 ) );
		}
		$c  = $f['contract'];
		$sg = $f['signer'];
		$ok = self::can_sign( $c, $sg );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$sig = self::clean_signature( $p );
		if ( is_wp_error( $sig ) ) {
			return $sig;
		}
		if ( empty( $p['consent'] ) ) {
			return new \WP_Error( 'bizydash_invalid', 'Please tick the box to agree to sign electronically.', array( 'status' => 400 ) );
		}
		$entered = isset( $p['code'] ) ? preg_replace( '/\D/', '', (string) $p['code'] ) : '';
		$otbl    = self::t( 'contract_otps' );
		$otp     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $otbl WHERE signer_id=%d AND purpose='sign' AND status='sent' ORDER BY id DESC LIMIT 1", $sg['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $otp ) {
			return new \WP_Error( 'bizydash_otp', 'Please request a new code.', array( 'status' => 400 ) );
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( $otp['expires_at'] < $now ) {
			$wpdb->update( $otbl, array( 'status' => 'expired', 'entered_code' => substr( $entered, 0, 10 ), 'entered_at' => $now ), array( 'id' => $otp['id'] ) );
			self::event( $c['id'], $sg['id'], 'otp_expired', array( 'entered' => $entered ) );
			return new \WP_Error( 'bizydash_otp', 'That code has expired. Please request a new one.', array( 'status' => 400 ) );
		}
		$attempts = (int) $otp['attempts'] + 1;
		if ( ! hash_equals( (string) $otp['code'], $entered ) ) {
			$burn = $attempts >= self::OTP_ATTEMPTS;
			$wpdb->update( $otbl, array( 'attempts' => $attempts, 'entered_code' => substr( $entered, 0, 10 ), 'entered_at' => $now, 'status' => $burn ? 'failed' : 'sent' ), array( 'id' => $otp['id'] ) );
			self::event( $c['id'], $sg['id'], 'otp_failed', array( 'entered' => $entered, 'attempt' => $attempts ) );
			return new \WP_Error( 'bizydash_otp', $burn ? 'Too many wrong codes. Please request a new one.' : 'That code is not right. Please check your email and try again.', array( 'status' => 400 ) );
		}
		$wpdb->update( $otbl, array( 'attempts' => $attempts, 'entered_code' => $entered, 'entered_at' => $now, 'status' => 'verified' ), array( 'id' => $otp['id'] ) );
		self::event( $c['id'], $sg['id'], 'otp_verified', array( 'entered' => $entered, 'sent_code' => $otp['code'], 'match' => true, 'email' => $sg['email'] ) );

		$env = array(
			'timezone' => isset( $p['tz'] ) ? sanitize_text_field( $p['tz'] ) : '',
			'language' => isset( $p['lang'] ) ? sanitize_text_field( $p['lang'] ) : '',
			'screen'   => isset( $p['screen'] ) ? sanitize_text_field( $p['screen'] ) : '',
			'platform' => isset( $p['platform'] ) ? sanitize_text_field( $p['platform'] ) : '',
		);
		$local = current_time( 'mysql' );
		$wpdb->update(
			self::t( 'contract_signers' ),
			array(
				'status'          => 'signed',
				'otp_verified_at' => $local,
				'signed_at'       => $local,
				'sign_name'       => $sig['name'],
				'sign_method'     => $sig['method'],
				'sign_image'      => $sig['image'],
				'sign_ip'         => self::ip(),
				'sign_forwarded'  => self::forwarded(),
				'sign_ua'         => self::ua(),
				'sign_env'        => wp_json_encode( $env ),
			),
			array( 'id' => $sg['id'] )
		);
		self::event(
			$c['id'],
			$sg['id'],
			'signed',
			array(
				'name'            => $sig['name'],
				'email'           => $sg['email'],
				'method'          => $sig['method'],
				'signature_sha256' => hash( 'sha256', $sig['image'] ),
				'document_sha256' => $c['body_hash'],
				'env'             => $env,
			)
		);

		$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'contract_signers' ) . " WHERE contract_id=%d AND status <> 'signed'", $c['id'] ) ); // phpcs:ignore WordPress.DB
		if ( 0 === $left ) {
			self::complete( $c['id'] );
		} else {
			$wpdb->update( self::t( 'contracts' ), array( 'status' => 'partially_signed', 'updated_at' => $local ), array( 'id' => $c['id'] ) );
		}
		Activity::log( 'contracts', $c['id'], (int) $c['client_id'], 'signed', sprintf( '%s signed %s', $sg['name'], $c['number'] ) );
		return array( 'complete' => 0 === $left );
	}

	public static function decline( $token, $reason ) {
		global $wpdb;
		$f = self::signer_by_token( $token );
		if ( ! $f ) {
			return new \WP_Error( 'bizydash_not_found', 'This link is not valid.', array( 'status' => 404 ) );
		}
		$ok = self::can_sign( $f['contract'], $f['signer'] );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$reason = mb_substr( sanitize_textarea_field( $reason ), 0, 1000 );
		$wpdb->update( self::t( 'contract_signers' ), array( 'status' => 'declined', 'declined_reason' => $reason ), array( 'id' => $f['signer']['id'] ) );
		$wpdb->update( self::t( 'contracts' ), array( 'status' => 'declined', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $f['contract']['id'] ) );
		self::event( $f['contract']['id'], $f['signer']['id'], 'declined', array( 'reason' => $reason ) );
		Activity::log( 'contracts', $f['contract']['id'], (int) $f['contract']['client_id'], 'declined', sprintf( '%s declined %s', $f['signer']['name'], $f['contract']['number'] ) );
		return true;
	}

	/** Everyone signed: seal the record and email each party a link to their copy. */
	private static function complete( $id ) {
		global $wpdb;
		$c       = self::get( $id );
		$signers = self::signers( $id, true );
		$parts   = array( $c['body_hash'] );
		foreach ( $signers as $s ) {
			$parts[] = $s['email'] . ':' . $s['signed_at'] . ':' . hash( 'sha256', (string) $s['sign_image'] );
		}
		$final = hash( 'sha256', implode( '|', $parts ) );
		$now   = current_time( 'mysql' );
		$wpdb->update( self::t( 'contracts' ), array( 'status' => 'signed', 'final_hash' => $final, 'completed_at' => $now, 'updated_at' => $now ), array( 'id' => $id ) );
		self::event( $id, 0, 'completed', array( 'final_sha256' => $final, 'document_sha256' => $c['body_hash'] ) );
		$cert   = self::cert_id( $id );
		$pdf    = Contract_Pdf::generate( $id );
		$attach = is_wp_error( $pdf ) ? array() : array( $pdf['path'] );
		foreach ( $signers as $s ) {
			$html = self::wrap_mail( 'Signed: ' . $c['title'], '<p>Hi ' . esc_html( $s['name'] ) . ',</p><p>Everyone has signed <strong>' . esc_html( $c['title'] ) . '</strong> (' . esc_html( $c['number'] ) . '). ' . ( $attach ? 'The signed PDF — with the Certificate of Completion — is attached. You can also open your copy any time:' : 'You can open, print or save your copy — with the Certificate of Completion — any time:' ) . '</p>' . self::button( self::sign_url( $s['token'] ), 'Open signed copy' ) . '<p style="margin-top:6px">Certificate ID <strong style="font-family:monospace;letter-spacing:.04em">' . esc_html( $cert ) . '</strong><br><span style="color:#868e9d;font-size:13px">Anyone can confirm this agreement is genuine and unaltered at <a href="' . esc_url( self::verify_url( $cert ) ) . '">' . esc_html( preg_replace( '#^https?://#', '', self::verify_url() ) ) . '</a>.</span></p>' );
			Mailer::send( array( 'to' => array( $s['email'] ), 'subject' => 'Signed — ' . $c['title'], 'html' => $html, 'text' => 'Your signed copy: ' . self::sign_url( $s['token'] ), 'attachments' => $attach ) );
		}
	}

	/** Mark opened (first time per signer) and log every view. */
	public static function viewed( array $c, array $sg ) {
		global $wpdb;
		if ( empty( $sg['viewed_at'] ) ) {
			$wpdb->update( self::t( 'contract_signers' ), array( 'viewed_at' => current_time( 'mysql' ), 'status' => 'signed' === $sg['status'] ? 'signed' : 'viewed' ), array( 'id' => $sg['id'] ) );
		}
		self::event( $c['id'], $sg['id'], 'viewed', array( 'email' => $sg['email'] ) );
	}

	/* ───────────────────────── Summaries for lists ───────────────────────── */

	public static function counts( $contract_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS total, SUM(status=\'signed\') AS signed FROM ' . self::t( 'contract_signers' ) . ' WHERE contract_id=%d', $contract_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array( 'total' => (int) $row['total'], 'signed' => (int) $row['signed'] );
	}

	/** Statuses that need attention on Home. */
	public static function waiting() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'contracts' ) . " WHERE status IN ('sent','partially_signed')" ); // phpcs:ignore WordPress.DB
	}
}
