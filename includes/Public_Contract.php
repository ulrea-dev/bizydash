<?php
namespace BizyDash;

use BizyDash\Services\Contract_Pdf;
use BizyDash\Services\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Public signing page   https://site/sign/<token>
 * Staff certificate     https://site/?bd_certificate=<contract id>   (needs manage_bizydash)
 */
class Public_Contract {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
	}

	private static function nocache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'bizydash contract' );
		header( 'X-LiteSpeed-Cache-Control: no-cache, no-store' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		header( 'Referrer-Policy: no-referrer' );
	}

	public static function maybe_render() {
		$token  = '';
		$cert   = 0;
		$verify = null;
		$preview = null;
		if ( ! empty( $_GET['bd_certificate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$cert = (int) $_GET['bd_certificate']; // phpcs:ignore WordPress.Security.NonceVerification
		} else {
			$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			$rel  = trim( substr( (string) $path, strlen( $home ) ), '/' );
			if ( preg_match( '#^sign/([a-z0-9]{10,64})$#', $rel, $m ) ) {
				$token = $m[1];
			} elseif ( preg_match( '#^verify(?:/([A-Za-z0-9-]{8,40}))?$#', $rel, $m ) ) {
				$verify = isset( $m[1] ) ? $m[1] : '';
			} elseif ( preg_match( '#^contract/([a-z0-9-]{4,64})$#', $rel, $m ) ) {
				$preview = $m[1];
			}
		}
		if ( ! empty( $_GET['id'] ) && null !== $verify && '' === $verify ) { // phpcs:ignore WordPress.Security.NonceVerification
			$verify = sanitize_text_field( wp_unslash( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( ! $token && ! $cert && null === $verify && null === $preview ) {
			return;
		}
		self::nocache();

		if ( null !== $preview ) {
			self::handle_preview( $preview );
			exit;
		}

		if ( null !== $verify ) {
			status_header( 200 );
			echo self::render_verify( $verify ); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}

		if ( $cert ) {
			if ( ! current_user_can( 'manage_bizydash' ) ) {
				wp_die( 'You need to be signed in to BizyDash to view this.', 'Not allowed', array( 'response' => 403 ) );
			}
			$c = Contracts::get( $cert );
			if ( ! $c ) {
				self::gone();
			}
			if ( ! empty( $_GET['pdf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$u = wp_get_current_user();
				self::stream_pdf( $c, 0, $u->display_name . ' (staff)' );
			}
			status_header( 200 );
			echo self::render( $c, null, true ); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}

		$f = Contracts::signer_by_token( $token );
		if ( ! $f || 'draft' === $f['contract']['status'] ) {
			self::gone();
		}
		if ( ! empty( $_GET['pdf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::stream_pdf( $f['contract'], $f['signer']['id'], $f['signer']['name'] );
		}
		Contracts::viewed( $f['contract'], $f['signer'] );
		$f = Contracts::signer_by_token( $token );
		status_header( 200 );
		echo self::render( $f['contract'], $f['signer'], false ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private static function gone() {
		status_header( 404 );
		echo Public_Invoice::shell( 'Document unavailable', '<main class="bd-wrap"><div class="bd-doc bd-doc--empty"><h1>This document isn’t available</h1><p>The link may be wrong or may have been replaced. Please contact the sender.</p></div></main>', '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/* ───────────────────────── Helpers ───────────────────────── */

	private static function utc( $mysql ) {
		return $mysql ? esc_html( $mysql . ' UTC' ) : '—';
	}

	private static function local( $mysql ) {
		return $mysql ? esc_html( wp_date( 'j M Y, H:i:s', strtotime( $mysql ) ) . ' (' . wp_timezone_string() . ')' ) : '—';
	}

	private static function label( $event ) {
		$map = array(
			'sent'             => 'Sent for signature',
			'invite_emailed'   => 'Invitation emailed',
			'reminder_emailed' => 'Reminder emailed',
			'viewed'           => 'Document opened',
			'consent_given'    => 'Consented to e-sign',
			'otp_sent'         => 'Verification code emailed',
			'otp_failed'       => 'Wrong code entered',
			'otp_expired'      => 'Code expired',
			'otp_verified'     => 'Code verified',
			'signed'           => 'Signed',
			'declined'         => 'Declined',
			'voided'           => 'Voided',
			'completed'        => 'All parties signed',
			'pdf_generated'    => 'Signed PDF sealed',
			'pdf_downloaded'   => 'Signed PDF downloaded',
			'pdf_failed'       => 'PDF could not be created',
			'preview_link_created'  => 'Preview link created',
			'preview_link_replaced' => 'Preview link replaced',
			'preview_access_changed' => 'Preview access changed',
			'preview_otp_sent'      => 'Preview code emailed',
			'preview_otp_failed'    => 'Wrong preview code',
			'preview_otp_verified'  => 'Preview unlocked',
			'preview_viewed'        => 'Preview opened',
			'preview_denied'        => 'Preview refused',
		);
		return isset( $map[ $event ] ) ? $map[ $event ] : $event;
	}

	/** One-line, human description of an event's recorded detail. */
	private static function summary( array $e, $reveal_codes ) {
		$d = json_decode( (string) $e['detail'], true );
		$d = is_array( $d ) ? $d : array();
		switch ( $e['event'] ) {
			case 'sent':
				return 'Document fingerprint ' . substr( (string) ( isset( $d['document_sha256'] ) ? $d['document_sha256'] : '' ), 0, 16 ) . '…';
			case 'invite_emailed':
			case 'reminder_emailed':
				return ( isset( $d['to'] ) ? $d['to'] : '' ) . ( empty( $d['ok'] ) ? ' — not accepted by the mail server' : ' — accepted by the mail server' );
			case 'viewed':
				return isset( $d['email'] ) ? $d['email'] : '';
			case 'consent_given':
				return 'Typed name “' . ( isset( $d['typed_name'] ) ? $d['typed_name'] : '' ) . '”';
			case 'otp_sent':
				return 'To ' . ( isset( $d['to'] ) ? $d['to'] : '' ) . ', code ' . ( $reveal_codes ? ( isset( $d['code'] ) ? $d['code'] : '' ) : '••••••' ) . ( empty( $d['mail_accepted'] ) ? ' (email failed)' : '' );
			case 'otp_failed':
				return 'Entered ' . ( $reveal_codes ? ( isset( $d['entered'] ) ? $d['entered'] : '' ) : '••••••' ) . ' (attempt ' . ( isset( $d['attempt'] ) ? (int) $d['attempt'] : 1 ) . ')';
			case 'otp_verified':
				return 'Entered ' . ( $reveal_codes ? ( isset( $d['entered'] ) ? $d['entered'] : '' ) : '••••••' ) . ' — matches the code emailed to ' . ( isset( $d['email'] ) ? $d['email'] : '' );
			case 'signed':
				return ( isset( $d['name'] ) ? $d['name'] : '' ) . ' · ' . ( isset( $d['method'] ) ? $d['method'] : '' ) . ' signature';
			case 'declined':
			case 'voided':
				return isset( $d['reason'] ) ? $d['reason'] : '';
			case 'pdf_generated':
				return 'PDF SHA-256 ' . substr( (string) ( isset( $d['pdf_sha256'] ) ? $d['pdf_sha256'] : '' ), 0, 16 ) . '…';
			case 'pdf_downloaded':
				return isset( $d['by'] ) ? $d['by'] : '';
			case 'pdf_failed':
				return isset( $d['error'] ) ? $d['error'] : '';
			case 'preview_access_changed':
				return 'Now ' . ( isset( $d['access'] ) ? $d['access'] : '' );
			case 'preview_otp_sent':
				return 'To ' . ( isset( $d['to'] ) ? $d['to'] : '' ) . ', code ' . ( $reveal_codes ? ( isset( $d['code'] ) ? $d['code'] : '' ) : '••••••' );
			case 'preview_otp_failed':
				return 'Entered ' . ( $reveal_codes ? ( isset( $d['entered'] ) ? $d['entered'] : '' ) : '••••••' );
			case 'preview_otp_verified':
				return 'Entered ' . ( $reveal_codes ? ( isset( $d['entered'] ) ? $d['entered'] : '' ) : '••••••' ) . ' — matches the code emailed to ' . ( isset( $d['email'] ) ? $d['email'] : '' );
			case 'preview_viewed':
				return ( isset( $d['mode'] ) && 'public' === $d['mode'] ? 'Public link' : 'Verified' ) . ( ! empty( $d['who'] ) ? ' · ' . $d['who'] : '' );
			case 'preview_denied':
				return ( isset( $d['email'] ) ? $d['email'] : '' ) . ' is not on the signer list'; 
			case 'completed':
				return 'Final fingerprint ' . substr( (string) ( isset( $d['final_sha256'] ) ? $d['final_sha256'] : '' ), 0, 16 ) . '…';
		}
		return '';
	}

	public static function event_label( $event ) {
		return self::label( $event );
	}

	public static function event_summary( array $e, $reveal_codes ) {
		return self::summary( $e, $reveal_codes );
	}

	/** Streams the sealed PDF (only if it still matches its recorded fingerprint) and logs the download. */
	private static function stream_pdf( array $c, $signer_id, $who ) {
		if ( 'signed' !== $c['status'] || ! Contract_Pdf::verify( $c ) ) {
			status_header( 404 );
			echo Public_Invoice::shell( 'PDF unavailable', '<main class="bd-wrap"><div class="bd-doc bd-doc--empty"><h1>The signed PDF is not available</h1><p>It has not been created yet, or it failed its integrity check. Please contact the sender.</p></div></main>', '' ); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
		Contracts::event( $c['id'], $signer_id, 'pdf_downloaded', array( 'by' => $who ) );
		$path = Contract_Pdf::path( $c );
		status_header( 200 );
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . Contract_Pdf::filename( $c ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	private static function pill( $status ) {
		$map = array(
			'draft' => array( 'Draft', '' ), 'sent' => array( 'Awaiting signatures', 'warn' ), 'partially_signed' => array( 'Partly signed', 'info' ),
			'signed' => array( 'Signed', 'ok' ), 'declined' => array( 'Declined', 'bad' ), 'void' => array( 'Voided', 'bad' ), 'pending' => array( 'Waiting', 'warn' ), 'viewed' => array( 'Opened', 'info' ),
		);
		$m = isset( $map[ $status ] ) ? $map[ $status ] : array( ucfirst( $status ), '' );
		return '<span class="bd-pill' . ( $m[1] ? ' bd-pill--' . $m[1] : '' ) . '">' . esc_html( $m[0] ) . '</span>';
	}

	/* ───────────────────────── Preview links ───────────────────────── */

	private static function handle_preview( $slug ) {
		$c = Contracts::find_by_preview( $slug );
		if ( ! $c ) {
			self::gone();
		}
		$staff = current_user_can( 'manage_bizydash' );
		$url   = Contracts::preview_url( $slug );
		$msg   = '';
		$step  = 'email';
		$email = '';

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && ! empty( $_POST['bd_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$act   = sanitize_key( wp_unslash( $_POST['bd_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( 'request' === $act ) {
				$r = Contracts::request_view_otp( $c, $email );
				if ( is_wp_error( $r ) ) {
					$msg = $r->get_error_message();
				} else {
					$step = 'code';
					$msg  = 'If that address is on this agreement, we have emailed it a 6-digit code.';
				}
			} elseif ( 'verify' === $act ) {
				$sg = Contracts::verify_view_otp( $c, $email, isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
				if ( is_wp_error( $sg ) ) {
					$step = 'code';
					$msg  = $sg->get_error_message();
				} else {
					Contracts::issue_view_cookie( $c, $sg );
					wp_safe_redirect( $url, 303 );
					exit;
				}
			}
		}

		$signer = Contracts::view_cookie_signer( $c );
		if ( $staff ) {
			$mode = 'staff';
		} elseif ( 'public' === $c['view_access'] ) {
			$mode = 'public';
		} elseif ( $signer ) {
			$mode = 'signer';
		} else {
			status_header( 200 );
			echo self::render_gate( $url, $step, $email, $msg ); // phpcs:ignore WordPress.Security.EscapeOutput
			return;
		}
		if ( 'staff' !== $mode ) {
			Contracts::log_preview_view( $c, 'public' === $mode ? 'public' : 'verified', $signer ? (int) $signer['id'] : 0, $signer ? $signer['email'] : '' );
		}
		status_header( 200 );
		echo self::render( $c, null, $staff, array( 'preview' => true, 'viewer' => $signer ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function render_gate( $url, $step, $email, $msg ) {
		$s    = Settings::get();
		$body = '<style>.vf-in{width:100%;font:inherit;font-size:16px;padding:13px 15px;border:0;border-radius:11px;box-shadow:inset 0 0 0 1px rgba(14,18,27,.18)}.vf-in:focus{outline:2px solid #222530}.vf-code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:24px;letter-spacing:.35em;text-align:center}.vf-go{border:0;background:var(--deep);color:#fff;font:inherit;font-weight:650;padding:12px 22px;border-radius:10px;cursor:pointer;margin-top:12px}.vf-msg{margin-top:14px;padding:11px 14px;border-radius:10px;background:#f2f5f8;color:#222530;font-size:13.5px}.vf-link{background:none;border:0;color:var(--ink2);text-decoration:underline;font:inherit;cursor:pointer;padding:0;margin-left:12px}</style>';
		$body .= '<main class="bd-wrap"><div class="bd-doc" style="max-width:520px;margin:40px auto"><div class="bd-eyebrow">' . esc_html( $s['business_name'] ) . '</div><h1 style="margin:4px 0 8px;font-size:25px;letter-spacing:-.03em">This agreement is private</h1>';
		if ( 'code' === $step ) {
			$body .= '<p class="bd-muted">Enter the 6-digit code we emailed to <strong>' . esc_html( $email ) . '</strong>.</p><form method="post" action="' . esc_url( $url ) . '"><input type="hidden" name="bd_action" value="verify"><input type="hidden" name="email" value="' . esc_attr( $email ) . '"><input class="vf-in vf-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="••••••" autofocus><button class="vf-go" type="submit">Open the agreement</button><button class="vf-link" type="submit" name="bd_action" value="request" formnovalidate>Send a new code</button></form>';
		} else {
			$body .= '<p class="bd-muted">Enter the email address this agreement was sent to. We will email you a one-time code to open it.</p><form method="post" action="' . esc_url( $url ) . '"><input type="hidden" name="bd_action" value="request"><input class="vf-in" type="email" name="email" value="' . esc_attr( $email ) . '" placeholder="you@company.com" autocomplete="email" required autofocus><button class="vf-go" type="submit">Email me a code</button></form>';
		}
		if ( $msg ) {
			$body .= '<div class="vf-msg">' . esc_html( $msg ) . '</div>';
		}
		$body .= '<p class="bd-muted bd-sm" style="margin-top:20px">Got this agreement in an email? Open the link in that email instead — it takes you straight to it.</p></div></main>';
		return Public_Invoice::shell( 'Private agreement — ' . $s['business_name'], $body, $s['business_name'] );
	}

	/* ───────────────────────── Public verification ───────────────────────── */

	public static function render_verify( $id ) {
		$s    = Settings::get();
		$c    = '' !== $id ? Contracts::find_by_cert( $id ) : null;
		$body = '<style>.ct-verify{display:flex;gap:22px;align-items:center;border-radius:14px;padding:18px 22px;margin:14px 0 6px}.ct-verify.is-ok{background:#effaf4;box-shadow:inset 0 0 0 1px #bfe8d1}.ct-verify.is-bad{background:#fff1f0;box-shadow:inset 0 0 0 1px #f4c2bf}.ct-verify__main{min-width:0;flex:1}.ct-verify__h{font-size:19px;font-weight:700;letter-spacing:-.02em;margin:2px 0 8px}.ct-checks{list-style:none;margin:0 0 8px;padding:0;font-size:13px}.ct-checks li{padding:2px 0}.ct-checks .ok{color:#0b4627}.ct-checks .bad{color:#b42318;font-weight:600}.vf-in{width:100%;font:inherit;font-size:17px;font-family:ui-monospace,Menlo,Consolas,monospace;letter-spacing:.06em;padding:13px 15px;border:0;border-radius:11px;box-shadow:inset 0 0 0 1px rgba(14,18,27,.18);text-transform:uppercase}.vf-in:focus{outline:2px solid #222530}.vf-go{border:0;background:var(--deep);color:#fff;font:inherit;font-weight:650;padding:12px 22px;border-radius:10px;cursor:pointer;margin-top:12px}.vf-row{display:flex;justify-content:space-between;gap:14px;padding:9px 0;box-shadow:inset 0 -1px 0 var(--line)}.vf-row:last-child{box-shadow:none}</style><main class="bd-wrap"><div class="bd-doc" style="max-width:640px;margin:0 auto">';
		$body .= '<div class="bd-eyebrow">' . esc_html( $s['business_name'] ) . '</div><h1 style="margin:4px 0 8px;font-size:26px;letter-spacing:-.03em">Verify a signed agreement</h1><p class="bd-muted">Enter the Certificate ID printed on the Certificate of Completion (or scan its QR code). We recompute every fingerprint from the stored record and tell you whether it is genuine and unaltered.</p>';
		$body .= '<form method="get" action="' . esc_url( Contracts::verify_url() ) . '" style="margin-top:18px"><input class="vf-in" name="id" placeholder="XXXX-XXXX-XXXX-XXXX" value="' . esc_attr( $id ) . '" autocomplete="off" maxlength="40"><button class="vf-go" type="submit">Verify</button></form>';
		if ( '' !== $id ) {
			if ( ! $c ) {
				$body .= '<div class="ct-verify is-bad" style="margin-top:22px"><div class="ct-verify__main"><div class="ct-verify__h">✖ No agreement found with that ID</div><div class="bd-muted bd-sm">Check the ID for typing mistakes. If the ID came from a document you received and this keeps failing, treat the document as unverified.</div></div></div>';
			} else {
				$in   = Contracts::integrity( $c['id'] );
				$sg   = array_filter( Contracts::signers( $c['id'] ), function ( $x ) {
					return 'signed' === $x['status'];
				} );
				$body .= '<div class="ct-verify ' . ( $in['ok'] ? 'is-ok' : 'is-bad' ) . '" style="margin-top:22px"><div class="ct-verify__main"><div class="ct-verify__h">' . ( $in['ok'] ? '✔ Genuine and unaltered' : '✖ Integrity check failed' ) . '</div><ul class="ct-checks">';
				foreach ( $in['checks'] as $ck ) {
					$body .= '<li class="' . ( $ck['ok'] ? 'ok' : 'bad' ) . '">' . ( $ck['ok'] ? '✔ ' : '✖ ' ) . esc_html( $ck['label'] ) . '</li>';
				}
				$body .= '</ul></div></div><div style="margin-top:18px"><div class="vf-row"><span class="bd-muted">Agreement</span><strong>' . esc_html( $c['number'] . ' · ' . $c['title'] ) . '</strong></div><div class="vf-row"><span class="bd-muted">Completed</span><strong>' . esc_html( $c['completed_at'] ? wp_date( 'j M Y, H:i', strtotime( $c['completed_at'] ) ) . ' (' . wp_timezone_string() . ')' : '—' ) . '</strong></div>';
				foreach ( $sg as $x ) {
					$body .= '<div class="vf-row"><span class="bd-muted">Signed by</span><strong>' . esc_html( $x['name'] ) . ' · ' . esc_html( wp_date( 'j M Y, H:i', strtotime( $x['signed_at'] ) ) ) . '</strong></div>';
				}
				$body .= '</div><p class="bd-muted bd-sm" style="margin-top:16px">The agreement text itself is not shown here. Ask the sender for the signed copy and compare its Document SHA-256 with the one on the certificate.</p>';
			}
		}
		$body .= '</div></main>';
		return Public_Invoice::shell( 'Verify a signed agreement — ' . $s['business_name'], $body, $s['business_name'] );
	}

	/* ───────────────────────── Page ───────────────────────── */

	/**
	 * @param array|null $me    The signer viewing (null for staff).
	 * @param bool       $staff Staff view shows every code; signers see only their own.
	 */
	public static function render( array $c, $me, $staff, array $opts = array() ) {
		$s        = Settings::get();
		$signers  = Contracts::signers( $c['id'], true );
		$sent     = ! in_array( $c['status'], array( 'draft' ), true );
		$done     = 'signed' === $c['status'];
		$can_sign = $me && true === Contracts::can_sign( $c, $me );
		$otps     = Contracts::otps( $c['id'] );
		$preview  = ! empty( $opts['preview'] );
		$viewer   = isset( $opts['viewer'] ) ? $opts['viewer'] : null;
		$events   = Contracts::events( $c['id'] );
		$chain    = Contracts::verify_chain( $c['id'] );
		$body     = wp_kses_post( $c['body'] );
		$reveal   = function ( $signer_id ) use ( $staff, $me ) {
			return $staff || ( $me && (int) $me['id'] === (int) $signer_id );
		};
		$rest     = $me ? esc_url_raw( rest_url( 'bizydash/v1/sign/' . $me['token'] ) ) : '';

		ob_start();
		?>
<style>
.ct-bar{position:sticky;top:0;z-index:5;background:rgba(255,255,255,.86);backdrop-filter:blur(14px);box-shadow:0 1px 0 var(--line)}
.ct-bar>div{max-width:920px;margin:0 auto;padding:11px 20px;display:flex;justify-content:space-between;align-items:center;gap:12px}
.ct-bar b{font-weight:650}.ct-btn{border:0;font:inherit;font-weight:600;padding:8px 15px;border-radius:9px;cursor:pointer;background:#fff;color:var(--ink);box-shadow:0 0 0 1px rgba(14,18,27,.12),0 1px 2px rgba(14,18,27,.05)}
.ct-body{font-size:15px;line-height:1.7;color:#1f2430;overflow-wrap:anywhere}
.ct-body h1,.ct-body h2,.ct-body h3,.ct-body h4{color:var(--ink);letter-spacing:-.015em;text-transform:none;font-weight:700;line-height:1.3;margin:1.5em 0 .5em}
.ct-body h1{font-size:1.55em}.ct-body h2{font-size:1.22em}.ct-body h3{font-size:1.06em}.ct-body h4{font-size:1em}
.ct-body>:first-child{margin-top:0}.ct-body p{margin:.7em 0}.ct-body ol,.ct-body ul{padding-left:1.4em;margin:.7em 0}.ct-body li{margin:.25em 0}
.ct-body .ct-li{display:flex;margin:.45em 0}.ct-body .ct-num{flex:none;font-variant-numeric:tabular-nums}.ct-body .ct-tx{flex:1;min-width:0}.ct-body .ct-hnum{margin-right:.5em}
.ct-body table.ct-table{width:100%;border-collapse:collapse;margin:1em 0}.ct-body table.ct-table td,.ct-body table.ct-table th{padding:8px 11px;vertical-align:top;box-shadow:none;text-align:left}
.ct-body table.ct-bordered td,.ct-body table.ct-bordered th{border:1px solid #cdd3dc}.ct-body table.ct-table td p{margin:.25em 0}.ct-body table.ct-table td .ct-li{margin:.2em 0}
.ct-body hr{border:0;border-top:1px solid var(--deep);margin:1.3em 0;height:0;background:none}.ct-body hr.ct-rule--thick{border-top-width:2px}
.ct-body hr.bd-pagebreak{border:0;border-top:1px dashed #d3d8e0;margin:1.8em 0;height:0}
.ct-body img{max-width:100%;height:auto;display:inline-block;vertical-align:middle}.ct-body a{color:#1d4ed8}.ct-body .ct-tab{white-space:pre}
.ct-body blockquote{margin:1em 0;padding:.2em 1em;border-left:3px solid #d3d8e0;color:var(--ink2)}
.ct-sigs{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-top:8px}
.ct-sig{background:var(--soft);border-radius:14px;padding:16px 18px;min-height:150px;break-inside:avoid}
.ct-sig img{display:block;height:64px;max-width:100%;object-fit:contain;margin:6px 0}.ct-line{height:64px;box-shadow:inset 0 -1px 0 #c5cad3;margin:6px 0}
.ct-panel{margin-top:22px;background:#fff;border-radius:18px;padding:30px 34px;box-shadow:0 0 0 1px rgba(14,18,27,.04),0 18px 48px -22px rgba(14,18,27,.22)}
.ct-panel h2{font-size:18px;text-transform:none;letter-spacing:-.02em;color:var(--ink);margin:0 0 4px}
.ct-in{width:100%;font:inherit;padding:11px 13px;border:0;border-radius:10px;box-shadow:inset 0 0 0 1px rgba(14,18,27,.14);background:#fff;color:var(--ink)}.ct-in:focus{outline:2px solid #222530}
.ct-tabs{display:flex;gap:6px;margin:14px 0 10px}.ct-tab{border:0;background:var(--soft);padding:7px 14px;border-radius:999px;font:inherit;font-weight:600;cursor:pointer;color:var(--ink2)}.ct-tab.on{background:var(--deep);color:#fff}
.ct-pad{background:#fff;border-radius:12px;box-shadow:inset 0 0 0 1px rgba(14,18,27,.14);position:relative}.ct-pad canvas{display:block;width:100%;height:150px;touch-action:none;cursor:crosshair}
.ct-typed{font:400 44px/1.2 'Snell Roundhand','Segoe Script','Brush Script MT','Lucida Handwriting',cursive;padding:18px 20px;min-height:100px;color:#10131a;word-break:break-word}
.ct-go{border:0;background:var(--deep);color:#fff;font:inherit;font-weight:650;padding:12px 22px;border-radius:10px;cursor:pointer}.ct-go[disabled]{opacity:.5;cursor:default}
.ct-link{background:none;border:0;color:var(--ink2);font:inherit;text-decoration:underline;cursor:pointer;padding:0}
.ct-consent{display:flex;gap:10px;align-items:flex-start;margin:16px 0;color:var(--ink2);font-size:13.5px}.ct-consent input{margin-top:3px;width:17px;height:17px;accent-color:#222530}
.ct-err{color:#b42318;margin-top:10px;font-weight:550}.ct-ok{color:#0b4627;background:#e0faec;border-radius:12px;padding:12px 16px;margin-bottom:14px;font-weight:550}
.ct-otp{font:700 26px/1 'Inter',monospace;letter-spacing:.35em;text-align:center;max-width:260px}
.ct-cert h2{font-size:12px;text-transform:uppercase;letter-spacing:.09em;color:var(--muted);margin:26px 0 8px}
.ct-cert table{font-size:12.5px}.ct-cert td,.ct-cert th{padding:8px 10px 8px 0;vertical-align:top;word-break:break-word}.ct-mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;word-break:break-all}
.ct-evid{background:var(--soft);border-radius:12px;padding:14px 16px;margin:10px 0;break-inside:avoid}.ct-evid dl{margin:8px 0 0;display:grid;grid-template-columns:150px 1fr;gap:5px 14px;font-size:12.5px}.ct-evid dt{color:var(--muted)}.ct-evid dd{margin:0;word-break:break-word}
.ct-hide{display:none}
.ct-ribbon{background:#fffaeb;color:#624c18;border-radius:12px;padding:11px 16px;margin:0 0 14px;font-weight:550;font-size:13.5px}
.ct-verify{display:flex;gap:22px;align-items:center;border-radius:14px;padding:18px 22px;margin:14px 0 6px;break-inside:avoid}
.ct-verify.is-ok{background:#effaf4;box-shadow:inset 0 0 0 1px #bfe8d1}.ct-verify.is-bad{background:#fff1f0;box-shadow:inset 0 0 0 1px #f4c2bf}
.ct-verify__qr{flex:none;text-align:center;width:118px}.ct-verify__qr svg{display:block;width:118px;height:118px;background:#fff;padding:6px;border-radius:8px}
.ct-verify__main{min-width:0;flex:1}.ct-verify__h{font-size:19px;font-weight:700;letter-spacing:-.02em;margin:2px 0 8px}
.ct-cid{font:700 17px/1.3 ui-monospace,Menlo,Consolas,monospace;letter-spacing:.06em;margin-bottom:8px;word-break:break-all}
.ct-checks{list-style:none;margin:0 0 8px;padding:0;font-size:13px}.ct-checks li{padding:2px 0}.ct-checks .ok{color:#0b4627}.ct-checks .bad{color:#b42318;font-weight:600}
@media print{
.ct-bar,.no-print,.ct-panel--sign{display:none!important}
.ct-body h1,.ct-body h2,.ct-body h3,.ct-body h4{break-after:avoid;page-break-after:avoid}
.ct-body p{orphans:3;widows:3}
.ct-body .ct-li,.ct-body table.ct-table tr,.ct-body img,.ct-body blockquote,.ct-sig,.ct-sigs,.ct-evid,.ct-cert table tr{break-inside:avoid;page-break-inside:avoid}
.ct-body hr.bd-pagebreak{border:0;margin:0;height:0;break-after:page;page-break-after:always}
.ct-body hr{break-after:avoid}
.ct-body>:last-child{break-after:avoid;page-break-after:avoid}
.ct-body .ct-li,.ct-body table.ct-table{break-after:auto}
.ct-body table.ct-table thead{display:table-header-group}
.bd-block{break-inside:avoid;page-break-inside:avoid}
.ct-panel,.ct-cert{box-shadow:none;border-radius:0;margin:0;padding:0 13mm;zoom:.9;background:#fff}
.ct-cert{break-before:page;page-break-before:always}
.ct-cert h2{break-after:avoid}
.bd-doc{overflow:visible}
}
@media(max-width:760px){.ct-verify{flex-direction:column;align-items:flex-start}.ct-panel{padding:22px 18px;border-radius:0}.ct-evid dl{grid-template-columns:1fr}}
</style>
<div class="ct-bar no-print"><div><b><?php echo esc_html( $s['business_name'] ); ?></b><span><?php echo wp_kses_post( self::pill( $c['status'] ) ); ?> <?php if ( $viewer && in_array( $c['status'], array( 'sent', 'partially_signed' ), true ) && in_array( $viewer['status'], array( 'pending', 'viewed' ), true ) ) : ?><a class="ct-btn" style="text-decoration:none;display:inline-block;background:var(--deep);color:#fff" href="<?php echo esc_url( Contracts::sign_url( $viewer['token'] ) ); ?>">Continue to sign</a> <?php endif; ?><?php if ( $done && ( ! $preview || $staff ) && Contract_Pdf::verify( $c ) ) : ?><a class="ct-btn" style="text-decoration:none;display:inline-block;background:var(--deep);color:#fff" href="<?php echo esc_url( $staff ? Contract_Pdf::url( $c['id'] ) : '?pdf=1' ); ?>">Download signed PDF</a> <?php endif; ?><button class="ct-btn" onclick="window.print()">Print</button></span></div></div>
<main class="bd-wrap">
<table class="bd-paper" role="presentation"><thead><tr><td><div class="bd-pg-sp"></div></td></tr></thead><tbody><tr><td>
<?php if ( $preview ) : ?>
<div class="ct-ribbon no-print"><?php
			$rb = array( 'draft' => 'Draft preview — the wording may still change before it is sent for signature.', 'sent' => 'Preview — waiting for signatures. This page is for reading only.', 'partially_signed' => 'Preview — some people have signed, others have not yet. This page is for reading only.', 'signed' => 'Signed and complete.', 'declined' => 'This agreement was declined.', 'void' => 'This agreement was cancelled by the sender.' );
			echo esc_html( isset( $rb[ $c['status'] ] ) ? $rb[ $c['status'] ] : '' );
			if ( $viewer && in_array( $c['status'], array( 'sent', 'partially_signed' ), true ) && in_array( $viewer['status'], array( 'pending', 'viewed' ), true ) ) {
				echo ' You are verified as ' . esc_html( $viewer['name'] ) . ' — use “Continue to sign” when you are ready.';
			} elseif ( ! $staff ) {
				echo ' To sign, use the personal link emailed to you.';
			}
		?></div>
<?php endif; ?>
<article class="bd-doc">
	<header class="bd-head">
		<div class="bd-brand"><div>
			<?php if ( $s['business_logo_url'] ) : ?><img class="bd-logo" src="<?php echo esc_url( $s['business_logo_url'] ); ?>" alt="<?php echo esc_attr( $s['business_name'] ); ?>"><?php else : ?><div class="bd-biz"><?php echo esc_html( $s['business_name'] ); ?></div><?php endif; ?>
		</div></div>
		<div class="bd-title"><div class="bd-eyebrow">Agreement</div><div class="bd-num"><?php echo esc_html( $c['number'] ); ?></div></div>
	</header>
	<div class="bd-for"><h1><?php echo esc_html( $c['title'] ); ?></h1></div>
	<div class="ct-body"><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
	<div class="bd-block"><h2>Signatures</h2>
		<div class="ct-sigs">
		<?php foreach ( $signers as $sg ) : ?>
			<div class="ct-sig">
				<div class="bd-k"><?php echo esc_html( ucfirst( $sg['role'] ) ); ?></div>
				<div class="bd-strong"><?php echo esc_html( $sg['name'] ); ?></div>
				<?php if ( 'signed' === $sg['status'] && $sg['sign_image'] ) : ?>
					<img src="<?php echo esc_attr( $sg['sign_image'] ); ?>" alt="Signature of <?php echo esc_attr( $sg['sign_name'] ); ?>">
					<div class="bd-muted bd-sm">Signed <?php echo self::local( $sg['signed_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				<?php else : ?>
					<div class="ct-line"></div><div class="bd-muted bd-sm"><?php echo 'declined' === $sg['status'] ? 'Declined' : 'Not signed yet'; ?></div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
		</div>
	</div>
	<footer class="bd-foot"><?php echo $done ? ( $preview && ! $staff ? 'Signed electronically.' : 'Signed electronically. The Certificate of Completion below records how each signature was made.' ) : ( $c['body_hash'] ? 'Document fingerprint (SHA-256): <span class="ct-mono">' . esc_html( $c['body_hash'] ) . '</span>' : 'Draft — not yet sent for signature.' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></footer>
</article>

		<?php if ( $me && 'signed' === $me['status'] ) : ?>
<div class="ct-panel no-print"><div class="ct-ok">You signed this on <?php echo self::local( $me['signed_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>.<?php echo $done ? ' Everyone has now signed.' : ' We are waiting for the other signers; you will get an email when it is complete.'; ?></div></div>
		<?php elseif ( $me && 'declined' === $me['status'] ) : ?>
<div class="ct-panel no-print"><h2>You declined this document</h2><p class="bd-muted">The sender has been notified.</p></div>
		<?php elseif ( $me && ! $can_sign ) : ?>
<div class="ct-panel no-print"><h2>This document is not open for signing</h2><p class="bd-muted"><?php echo esc_html( 'void' === $c['status'] ? 'It was cancelled by the sender.' : ( 'declined' === $c['status'] ? 'A signer declined it.' : 'Please contact the sender.' ) ); ?></p></div>
		<?php elseif ( $me ) : ?>
<div class="ct-panel ct-panel--sign no-print" id="ct-sign">
	<h2>Sign this document</h2>
	<p class="bd-muted">Signing as <strong><?php echo esc_html( $me['name'] ); ?></strong> · <?php echo esc_html( $me['email'] ); ?></p>
	<div id="ct-step1">
		<label class="bd-k" style="margin-top:18px;display:block">Your full legal name</label>
		<input class="ct-in" id="ct-name" value="<?php echo esc_attr( $me['name'] ); ?>" autocomplete="name">
		<div class="ct-tabs"><button type="button" class="ct-tab on" data-m="typed">Type</button><button type="button" class="ct-tab" data-m="drawn">Draw</button></div>
		<div id="ct-typed" class="ct-pad"><div class="ct-typed" id="ct-typed-out"></div></div>
		<div id="ct-draw" class="ct-pad ct-hide"><canvas id="ct-canvas" width="900" height="300"></canvas><button type="button" class="ct-link" id="ct-clear" style="position:absolute;right:12px;top:8px;font-size:12px">Clear</button></div>
		<label class="ct-consent"><input type="checkbox" id="ct-consent"><span><?php echo esc_html( Contracts::CONSENT_TEXT ); ?></span></label>
		<button class="ct-go" id="ct-send">Email me a verification code</button>
		<div class="ct-err" id="ct-err1"></div>
	</div>
	<div id="ct-step2" class="ct-hide">
		<div class="ct-ok" id="ct-sent"></div>
		<label class="bd-k" style="display:block;margin-bottom:6px">6-digit code</label>
		<input class="ct-in ct-otp" id="ct-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="••••••">
		<div style="margin-top:16px"><button class="ct-go" id="ct-sign-btn">Sign and agree</button> <button class="ct-link" id="ct-resend" style="margin-left:10px">Send a new code</button></div>
		<div class="ct-err" id="ct-err2"></div>
	</div>
	<p style="margin-top:22px"><button class="ct-link bd-sm" id="ct-decline">I do not agree — decline to sign</button></p>
</div>
<script>
(function(){
var API=<?php echo wp_json_encode( $rest ); ?>,$=function(i){return document.getElementById(i)},method='typed',drawn=false;
var cv=$('ct-canvas'),cx=cv.getContext('2d');cx.lineWidth=5;cx.lineCap='round';cx.lineJoin='round';cx.strokeStyle='#10131a';
function typedImage(){var c=document.createElement('canvas');c.width=900;c.height=240;var x=c.getContext('2d');x.fillStyle='#fff';x.fillRect(0,0,900,240);x.fillStyle='#10131a';x.font="64px 'Snell Roundhand','Segoe Script','Brush Script MT','Lucida Handwriting',cursive";x.textBaseline='middle';x.fillText($('ct-name').value,24,120,850);return c.toDataURL('image/png')}
function image(){if(method==='typed')return typedImage();var o=document.createElement('canvas');o.width=900;o.height=300;var x=o.getContext('2d');x.fillStyle='#fff';x.fillRect(0,0,900,300);x.drawImage(cv,0,0);return o.toDataURL('image/png')}
function sync(){$('ct-typed-out').textContent=$('ct-name').value}sync();$('ct-name').addEventListener('input',sync);
document.querySelectorAll('.ct-tab').forEach(function(b){b.onclick=function(){method=b.dataset.m;document.querySelectorAll('.ct-tab').forEach(function(t){t.classList.toggle('on',t===b)});$('ct-typed').classList.toggle('ct-hide',method!=='typed');$('ct-draw').classList.toggle('ct-hide',method!=='drawn')}});
var down=false;function pos(e){var r=cv.getBoundingClientRect(),t=e.touches?e.touches[0]:e;return[(t.clientX-r.left)*cv.width/r.width,(t.clientY-r.top)*cv.height/r.height]}
function start(e){down=true;var p=pos(e);cx.beginPath();cx.moveTo(p[0],p[1]);e.preventDefault()}function move(e){if(!down)return;var p=pos(e);cx.lineTo(p[0],p[1]);cx.stroke();drawn=true;e.preventDefault()}function end(){down=false}
['mousedown','touchstart'].forEach(function(n){cv.addEventListener(n,start,{passive:false})});['mousemove','touchmove'].forEach(function(n){cv.addEventListener(n,move,{passive:false})});['mouseup','mouseleave','touchend'].forEach(function(n){cv.addEventListener(n,end)});
$('ct-clear').onclick=function(){cx.clearRect(0,0,cv.width,cv.height);drawn=false};
function post(path,body){return fetch(API+path,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}).then(function(r){return r.json().then(function(j){if(!r.ok)throw new Error(j.message||'Something went wrong');return j})})}
function payload(){return{name:$('ct-name').value,method:method,image:image(),consent:$('ct-consent').checked,tz:(Intl.DateTimeFormat().resolvedOptions()||{}).timeZone||'',lang:navigator.language||'',screen:screen.width+'x'+screen.height,platform:navigator.platform||''}}
function requestCode(btn,errEl){errEl.textContent='';if(method==='drawn'&&!drawn){errEl.textContent='Please draw your signature.';return}if($('ct-name').value.trim().length<2){errEl.textContent='Please type your full name.';return}if(!$('ct-consent').checked){errEl.textContent='Please tick the box to agree to sign electronically.';return}
btn.disabled=true;post('/otp',payload()).then(function(r){$('ct-step1').classList.add('ct-hide');$('ct-step2').classList.remove('ct-hide');$('ct-sent').textContent='We emailed a 6-digit code to '+r.sent_to+'. It expires in '+Math.round(r.expires_in/60)+' minutes.';$('ct-code').focus()}).catch(function(e){errEl.textContent=e.message}).then(function(){btn.disabled=false})}
$('ct-send').onclick=function(){requestCode($('ct-send'),$('ct-err1'))};
$('ct-resend').onclick=function(){requestCode($('ct-resend'),$('ct-err2'))};
$('ct-sign-btn').onclick=function(){var b=$('ct-sign-btn'),er=$('ct-err2');er.textContent='';var p=payload();p.code=$('ct-code').value;if(p.code.replace(/\D/g,'').length!==6){er.textContent='Enter the 6-digit code from your email.';return}b.disabled=true;post('/submit',p).then(function(){location.reload()}).catch(function(e){er.textContent=e.message;b.disabled=false})};
$('ct-decline').onclick=function(){var r=prompt('Optional: tell the sender why you are declining.');if(r===null)return;post('/decline',{reason:r}).then(function(){location.reload()}).catch(function(e){alert(e.message)})};
})();
</script>
		<?php endif; ?>

		<?php if ( $sent && ( ! $preview || $staff ) && ( $staff || $done || ( $me && 'signed' === $me['status'] ) ) ) : ?>
<section class="ct-panel ct-cert">
	<div class="bd-eyebrow">Certificate of Completion</div>
	<h2 style="font-size:20px;color:var(--ink);text-transform:none;letter-spacing:-.02em;margin:4px 0 6px"><?php echo esc_html( $c['title'] ); ?> · <?php echo esc_html( $c['number'] ); ?></h2>
	<p class="bd-muted">This certificate records how this document was sent, verified and signed. All times are shown in UTC unless marked otherwise.</p>
	<?php $integ = Contracts::integrity( $c['id'] ); ?>
	<div class="ct-verify <?php echo $integ['ok'] ? 'is-ok' : 'is-bad'; ?>">
		<?php if ( $integ['cert_id'] ) : ?><div class="ct-verify__qr"><div id="ct-qr"></div><div class="bd-sm bd-muted">Scan to verify</div></div><?php endif; ?>
		<div class="ct-verify__main">
			<div class="bd-k">Verification</div>
			<div class="ct-verify__h"><?php echo $integ['ok'] ? '✔ Genuine and unaltered' : '✖ Integrity check failed'; ?></div>
			<?php if ( $integ['cert_id'] ) : ?><div class="bd-muted bd-sm">Certificate ID</div><div class="ct-cid"><?php echo esc_html( $integ['cert_id'] ); ?></div><?php endif; ?>
			<ul class="ct-checks"><?php foreach ( $integ['checks'] as $ck ) : ?><li class="<?php echo $ck['ok'] ? 'ok' : 'bad'; ?>"><?php echo $ck['ok'] ? '✔' : '✖'; ?> <?php echo esc_html( $ck['label'] ); ?></li><?php endforeach; ?></ul>
			<?php if ( $integ['cert_id'] ) : ?><div class="bd-muted bd-sm">Anyone can check this certificate at <?php echo esc_html( preg_replace( '#^https?://#', '', Contracts::verify_url() ) ); ?> using the ID above. Each fingerprint is recomputed from the stored record every time this page is opened.</div><?php endif; ?>
		</div>
	</div>
	<?php if ( $integ['cert_id'] ) : ?>
	<script><?php echo file_get_contents( BIZYDASH_DIR . 'assets/qrcode.min.js' ); // phpcs:ignore WordPress.Security.EscapeOutput, WordPress.WP.AlternativeFunctions ?></script>
	<script>(function(){try{var q=qrcode(0,'M');q.addData(<?php echo wp_json_encode( Contracts::verify_url( $integ['cert_id'] ) ); ?>);q.make();document.getElementById('ct-qr').innerHTML=q.createSvgTag({cellSize:3,margin:0,scalable:true})}catch(e){}})()</script>
	<?php endif; ?>
	<h2>Document</h2>
	<div class="ct-evid"><dl>
		<dt>Status</dt><dd><?php echo wp_kses_post( self::pill( $c['status'] ) ); ?></dd>
		<dt>Sent</dt><dd><?php echo self::local( $c['sent_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
		<dt>Completed</dt><dd><?php echo self::local( $c['completed_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
		<dt>Document SHA-256</dt><dd class="ct-mono"><?php echo esc_html( $c['body_hash'] ); ?></dd>
		<?php if ( $c['final_hash'] ) : ?><dt>Final record SHA-256</dt><dd class="ct-mono"><?php echo esc_html( $c['final_hash'] ); ?></dd><?php endif; ?>
		<?php if ( $c['pdf_hash'] ) : ?><dt>Signed PDF SHA-256</dt><dd class="ct-mono"><?php echo esc_html( $c['pdf_hash'] ); ?></dd><?php endif; ?>
		<dt>Audit log integrity</dt><dd><?php echo $chain['ok'] ? '✔ Verified — ' . (int) $chain['count'] . ' entries, each linked to the one before' : '✖ The audit log does not verify (entry ' . (int) $chain['broken_at'] . ')'; ?></dd>
	</dl></div>

	<h2>Signers</h2>
		<?php foreach ( $signers as $sg ) :
			$env  = json_decode( (string) $sg['sign_env'], true );
			$env  = is_array( $env ) ? $env : array();
			$mine = array_values( array_filter( $otps, function ( $o ) use ( $sg ) {
				return (int) $o['signer_id'] === (int) $sg['id'];
			} ) );
			$show = $reveal( $sg['id'] );
			?>
	<div class="ct-evid">
		<div class="bd-strong"><?php echo esc_html( $sg['name'] ); ?> <span class="bd-muted">· <?php echo esc_html( $sg['email'] ); ?> · <?php echo esc_html( ucfirst( $sg['role'] ) ); ?></span> <?php echo wp_kses_post( self::pill( $sg['status'] ) ); ?></div>
		<dl>
			<dt>Invited</dt><dd><?php echo self::local( $sg['invited_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
			<dt>First opened</dt><dd><?php echo self::local( $sg['viewed_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
			<dt>Consented to e-sign</dt><dd><?php echo self::local( $sg['consent_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
			<?php if ( 'signed' === $sg['status'] ) : ?>
			<dt>Signed</dt><dd><?php echo self::local( $sg['signed_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
			<dt>Name typed</dt><dd><?php echo esc_html( $sg['sign_name'] ); ?> (<?php echo esc_html( $sg['sign_method'] ); ?> signature)</dd>
			<dt>Signature image</dt><dd><img src="<?php echo esc_attr( $sg['sign_image'] ); ?>" alt="" style="height:44px;background:#fff;border-radius:6px"> <span class="ct-mono">sha256 <?php echo esc_html( substr( hash( 'sha256', (string) $sg['sign_image'] ), 0, 24 ) ); ?>…</span></dd>
			<?php if ( $show ) : ?>
			<dt>IP address</dt><dd><?php echo esc_html( $sg['sign_ip'] . ( $sg['sign_forwarded'] && $sg['sign_forwarded'] !== $sg['sign_ip'] ? ' (forwarded: ' . $sg['sign_forwarded'] . ')' : '' ) ); ?></dd>
			<dt>Browser / device</dt><dd><?php echo esc_html( $sg['sign_ua'] ); ?></dd>
			<dt>Time zone · language · screen</dt><dd><?php echo esc_html( implode( ' · ', array_filter( array( isset( $env['timezone'] ) ? $env['timezone'] : '', isset( $env['language'] ) ? $env['language'] : '', isset( $env['screen'] ) ? $env['screen'] : '', isset( $env['platform'] ) ? $env['platform'] : '' ) ) ) ); ?></dd>
			<?php endif; ?>
			<?php elseif ( 'declined' === $sg['status'] ) : ?>
			<dt>Declined</dt><dd><?php echo esc_html( $sg['declined_reason'] ); ?></dd>
			<?php endif; ?>
			<?php foreach ( $mine as $i => $o ) : ?>
			<dt>Code <?php echo (int) ( $i + 1 ); ?> emailed</dt><dd><?php echo self::utc( $o['sent_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?> to <?php echo esc_html( $o['email'] ); ?> — <?php echo $o['mail_ok'] ? 'accepted by mail server' : 'email failed'; ?> · code <strong class="ct-mono"><?php echo $show ? esc_html( $o['code'] ) : '••••••'; ?></strong></dd>
			<dt>Code <?php echo (int) ( $i + 1 ); ?> entered</dt><dd><?php echo $o['entered_at'] ? self::utc( $o['entered_at'] ) . ' · entered <strong class="ct-mono">' . ( $show ? esc_html( $o['entered_code'] ) : '••••••' ) . '</strong> · ' . ( 'verified' === $o['status'] ? '✔ matched' : esc_html( $o['status'] ) ) . ' · ' . (int) $o['attempts'] . ' attempt(s)' : esc_html( 'Not entered (' . $o['status'] . ')' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
			<?php endforeach; ?>
		</dl>
	</div>
		<?php endforeach; ?>

	<h2>Consent statement shown to every signer</h2>
	<p class="bd-muted"><?php echo esc_html( Contracts::CONSENT_TEXT ); ?></p>

	<h2>Audit trail</h2>
	<table><thead><tr><th>Time (UTC)</th><th>Event</th><th>By</th><th>Detail</th><th>IP</th></tr></thead><tbody>
		<?php foreach ( $events as $e ) :
			$who = 'Staff';
			foreach ( $signers as $sg ) {
				if ( (int) $sg['id'] === (int) $e['signer_id'] ) {
					$who = $sg['name'];
				}
			}
			if ( ! $e['signer_id'] && ! $e['user_id'] ) {
				$who = 'System';
			} elseif ( ! $e['signer_id'] && $e['user_name'] ) {
				$who = $e['user_name'];
			}
			$rv = $staff || ( $me && (int) $me['id'] === (int) $e['signer_id'] );
			?>
		<tr><td class="ct-mono"><?php echo esc_html( $e['created_at'] ); ?></td><td><?php echo esc_html( self::label( $e['event'] ) ); ?></td><td><?php echo esc_html( $who ); ?></td><td><?php echo esc_html( self::summary( $e, $rv ) ); ?></td><td class="ct-mono"><?php echo esc_html( $e['ip'] ); ?></td></tr>
		<?php endforeach; ?>
	</tbody></table>
	<p class="bd-muted bd-sm" style="margin-top:18px">Each audit row stores a SHA-256 hash that includes the previous row’s hash, so any later change to the history can be detected. Electronic signatures are legally recognised in most countries (including under the US ESIGN Act and UETA, the EU/UK eIDAS rules, and Nigeria’s Evidence Act). This certificate is a record, not legal advice.</p>
</section>
		<?php endif; ?>
</td></tr></tbody><tfoot><tr><td><div class="bd-pg-sp"></div></td></tr></tfoot></table>
</main>
<script>if(/[?&]print=1/.test(location.search)){window.addEventListener('load',function(){setTimeout(function(){window.print()},400)})}</script>
		<?php
		return Public_Invoice::shell( $c['title'] . ' — ' . $s['business_name'], ob_get_clean(), $s['business_name'] );
	}
}
