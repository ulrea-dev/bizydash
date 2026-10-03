<?php
namespace BizyDash\Services;

use BizyDash\Mail_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a single message through wp_mail, optionally via BizyDash's own SMTP settings.
 * The SMTP hook is attached only for the duration of the send, so other plugins' mail is untouched.
 */
class Mailer {

	private static function line( $s ) {
		return trim( str_replace( array( "\r", "\n", '"', '<', '>' ), ' ', (string) $s ) );
	}

	/** Configure a PHPMailer instance from BizyDash SMTP settings. */
	public static function configure( $pm, array $cfg, $password ) {
		$pm->isSMTP();
		$pm->Host    = $cfg['smtp_host'];
		$pm->Port    = (int) $cfg['smtp_port'];
		$pm->Timeout = 15;
		if ( 'ssl' === $cfg['smtp_secure'] ) {
			$pm->SMTPSecure = 'ssl';
		} elseif ( 'tls' === $cfg['smtp_secure'] ) {
			$pm->SMTPSecure = 'tls';
		} else {
			$pm->SMTPSecure   = '';
			$pm->SMTPAutoTLS  = false;
		}
		$pm->SMTPAuth = ! empty( $cfg['smtp_auth'] );
		if ( $pm->SMTPAuth ) {
			$pm->Username = $cfg['smtp_user'];
			$pm->Password = $password;
		}
	}

	/**
	 * @param array $m to[], cc[], bcc[] (email strings), subject, html, text.
	 * @return array{ok: bool, error?: string}
	 */
	public static function send( array $m ) {
		$cfg  = Mail_Settings::get_full();
		$from = Mail_Settings::from();
		if ( empty( $m['to'] ) ) {
			return array( 'ok' => false, 'error' => 'No recipient.' );
		}
		if ( 'smtp' === $cfg['method'] && ( '' === $cfg['smtp_host'] ) ) {
			return array( 'ok' => false, 'error' => 'SMTP is selected but no host is configured (Settings → Email).' );
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( $from['email'] ) {
			$headers[] = 'From: ' . ( $from['name'] ? self::line( $from['name'] ) . ' <' . $from['email'] . '>' : $from['email'] );
		}
		if ( $from['reply_to'] ) {
			$headers[] = 'Reply-To: ' . $from['reply_to'];
		}
		if ( ! empty( $m['cc'] ) ) {
			$headers[] = 'Cc: ' . implode( ', ', $m['cc'] );
		}
		if ( ! empty( $m['bcc'] ) ) {
			$headers[] = 'Bcc: ' . implode( ', ', $m['bcc'] );
		}

		$error = '';
		$fail  = function ( $e ) use ( &$error ) {
			$error = $e->get_error_message();
		};
		$init  = function ( $pm ) use ( $cfg, $m ) {
			if ( 'smtp' === $cfg['method'] ) {
				Mailer::configure( $pm, $cfg, Mail_Settings::smtp_password() );
			}
			if ( ! empty( $m['text'] ) ) {
				$pm->AltBody = $m['text'];
			}
		};
		add_action( 'wp_mail_failed', $fail );
		add_action( 'phpmailer_init', $init, 99 );
		$ok = wp_mail( $m['to'], self::line( $m['subject'] ), $m['html'], $headers, ! empty( $m['attachments'] ) ? (array) $m['attachments'] : array() );
		remove_action( 'wp_mail_failed', $fail );
		remove_action( 'phpmailer_init', $init, 99 );

		if ( $ok ) {
			return array( 'ok' => true );
		}
		return array( 'ok' => false, 'error' => $error ? $error : 'The mail server did not accept the message.' );
	}
}
