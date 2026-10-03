<?php
namespace BizyDash\Services;

use BizyDash\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Sealed, stored PDF of a fully signed contract: the agreement, every signature, and the Certificate of Completion.
 *
 * Built with a bundled copy of dompdf (no server software needed). The file is written once, kept in a protected
 * folder under a random name, and its SHA-256 is written into the hash-chained audit log, so any later change to the
 * file is detected by the integrity check.
 */
class Contract_Pdf {

	const DIR = 'bizydash-contracts';

	/* ───────────────────────── Storage ───────────────────────── */

	public static function dir() {
		$u   = wp_upload_dir();
		$dir = trailingslashit( $u['basedir'] ) . self::DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	private static function work_dir( $name ) {
		$u   = wp_upload_dir();
		$dir = trailingslashit( $u['basedir'] ) . self::DIR . '/.' . $name;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	public static function path( array $c ) {
		return ! empty( $c['pdf_file'] ) ? self::dir() . '/' . basename( $c['pdf_file'] ) : '';
	}

	public static function url( $contract_id ) {
		return home_url( '/?bd_certificate=' . (int) $contract_id . '&pdf=1' );
	}

	public static function filename( array $c ) {
		return sanitize_file_name( $c['number'] . ' - ' . $c['title'] ) . ' (signed).pdf';
	}

	/** Does the stored file still exist and match the fingerprint recorded in the audit log? */
	public static function verify( array $c ) {
		$path = self::path( $c );
		return '' !== $path && is_readable( $path ) && ! empty( $c['pdf_hash'] ) && hash_equals( $c['pdf_hash'], hash_file( 'sha256', $path ) );
	}

	/* ───────────────────────── Generate ───────────────────────── */

	/** @return array{sha256:string,size:int}|\WP_Error */
	public static function generate( $contract_id ) {
		global $wpdb;
		$c = Contracts::get( $contract_id );
		if ( ! $c || 'signed' !== $c['status'] ) {
			return new \WP_Error( 'bizydash_state', 'Only a fully signed contract has a signed PDF.', array( 'status' => 409 ) );
		}
		if ( ! empty( $c['pdf_file'] ) ) {
			return new \WP_Error( 'bizydash_state', 'The signed PDF already exists and cannot be replaced.', array( 'status' => 409 ) );
		}
		try {
			$bytes = self::render( $c );
		} catch ( \Throwable $e ) {
			Contracts::event( $contract_id, 0, 'pdf_failed', array( 'error' => mb_substr( $e->getMessage(), 0, 300 ) ) );
			return new \WP_Error( 'bizydash_pdf', 'The PDF could not be created: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
		$name = strtolower( wp_generate_password( 24, false, false ) ) . '-' . sanitize_file_name( $c['number'] ) . '.pdf';
		$path = self::dir() . '/' . $name;
		if ( false === file_put_contents( $path, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new \WP_Error( 'bizydash_pdf', 'The PDF could not be saved on the server.', array( 'status' => 500 ) );
		}
		$hash = hash( 'sha256', $bytes );
		$wpdb->update( Contracts::t( 'contracts' ), array( 'pdf_file' => $name, 'pdf_hash' => $hash, 'pdf_size' => strlen( $bytes ), 'pdf_at' => current_time( 'mysql' ) ), array( 'id' => (int) $contract_id ) );
		Contracts::event( $contract_id, 0, 'pdf_generated', array( 'pdf_sha256' => $hash, 'size' => strlen( $bytes ) ) );
		return array( 'sha256' => $hash, 'size' => strlen( $bytes ), 'path' => $path );
	}

	private static function load_engine() {
		if ( ! class_exists( '\Dompdf\Dompdf', false ) ) {
			require_once BIZYDASH_DIR . 'lib/dompdf/autoload.inc.php';
		}
		require_once BIZYDASH_DIR . 'lib/qrcode.php';
	}

	private static function render( array $c ) {
		self::load_engine();
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@set_time_limit( 90 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$fonts = self::work_dir( 'fonts' );
		$tmp   = self::work_dir( 'tmp' );
		$opt   = new \Dompdf\Options();
		$opt->set( 'fontDir', $fonts );
		$opt->set( 'fontCache', $fonts );
		$opt->set( 'tempDir', $tmp );
		$opt->set( 'chroot', array( BIZYDASH_DIR, wp_upload_dir()['basedir'] ) );
		$opt->set( 'isRemoteEnabled', false );
		$opt->set( 'isPhpEnabled', false );
		$opt->set( 'isJavascriptEnabled', false );
		$opt->set( 'defaultFont', 'Inter' );
		$opt->set( 'dpi', 96 );
		$opt->set( 'isFontSubsettingEnabled', true );
		$opt->set( 'defaultPaperSize', 'a4' );
		$pdf = new \Dompdf\Dompdf( $opt );
		self::register_fonts( $pdf );
		$pdf->loadHtml( self::html( $c ), 'UTF-8' );
		$pdf->setPaper( 'A4', 'portrait' );
		$pdf->render();

		// Running footer on every page: reference + page numbers.
		$canvas = $pdf->getCanvas();
		$fm     = $pdf->getFontMetrics();
		$font   = $fm->getFont( 'Inter', 'normal' );
		$w      = $canvas->get_width();
		$h      = $canvas->get_height();
		$left   = $c['number'] . '  ·  ' . $c['title'];
		$cid    = Contracts::cert_id( $c['id'] );
		$canvas->page_text( 51, $h - 38, mb_strimwidth( $left, 0, 70, '…' ) . ( $cid ? '  ·  Certificate ' . $cid : '' ), $font, 7.5, array( 0.53, 0.56, 0.62 ) );
		$label = 'Page {PAGE_NUM} of {PAGE_COUNT}';
		$canvas->page_text( $w - 51 - $fm->getTextWidth( 'Page 00 of 00', $font, 7.5 ), $h - 38, $label, $font, 7.5, array( 0.53, 0.56, 0.62 ) );

		$pdf->addInfo( 'Title', $c['number'] . ' ' . $c['title'] );
		$pdf->addInfo( 'Author', Settings::get( 'business_name' ) );
		$pdf->addInfo( 'Subject', 'Electronically signed agreement with Certificate of Completion' );
		$pdf->addInfo( 'Creator', 'BizyDash' );
		return $pdf->output();
	}

	private static function register_fonts( \Dompdf\Dompdf $pdf ) {
		$fm  = $pdf->getFontMetrics();
		$dir = BIZYDASH_DIR . 'assets/pdf-fonts/';
		$fam = $fm->getFamily( 'inter' );
		if ( is_array( $fam ) && count( $fam ) >= 4 ) {
			return;
		}
		$map = array(
			array( 'normal', 'normal', 'Inter-Regular.ttf' ),
			array( 'normal', 'bold', 'Inter-Bold.ttf' ),
			array( 'italic', 'normal', 'Inter-Italic.ttf' ),
			array( 'italic', 'bold', 'Inter-BoldItalic.ttf' ),
		);
		foreach ( $map as $m ) {
			$fm->registerFont( array( 'family' => 'Inter', 'style' => $m[0], 'weight' => $m[1] ), $dir . $m[2] );
		}
	}

	/* ───────────────────────── Document HTML ───────────────────────── */

	private static function e( $s ) {
		return esc_html( (string) $s );
	}

	private static function local( $mysql ) {
		return $mysql ? wp_date( 'j M Y, H:i:s', strtotime( $mysql ) ) . ' (' . wp_timezone_string() . ')' : '—';
	}

	/** Uploads URL → data URI so the PDF engine never needs network access. */
	private static function embed_image( $url ) {
		$u = wp_upload_dir();
		if ( 0 === strpos( $url, 'data:image/' ) ) {
			return $url;
		}
		if ( 0 !== strpos( $url, $u['baseurl'] ) ) {
			return '';
		}
		$file = $u['basedir'] . substr( $url, strlen( $u['baseurl'] ) );
		$file = wp_normalize_path( $file );
		if ( false !== strpos( $file, '..' ) || ! is_readable( $file ) || filesize( $file ) > 6291456 ) {
			return '';
		}
		$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		$mime = array( 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml' );
		return isset( $mime[ $ext ] ) ? 'data:' . $mime[ $ext ] . ';base64,' . base64_encode( file_get_contents( $file ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/** Rewrites the contract body for the PDF engine (no flexbox): list rows → tables, rules/page breaks → blocks, images embedded. */
	private static function prepare_body( $html ) {
		$html = '<div id="bd-root">' . $html . '</div>';
		$dom  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$xp = new \DOMXPath( $dom );

		foreach ( iterator_to_array( $xp->query( '//div[contains(concat(" ",normalize-space(@class)," ")," ct-li ")]' ) ) as $li ) {
			$num = $xp->query( './/span[contains(@class,"ct-num")]', $li )->item( 0 );
			$tx  = $xp->query( './/span[contains(@class,"ct-tx")]', $li )->item( 0 );
			if ( ! $num || ! $tx ) {
				continue;
			}
			preg_match( '/margin-left:\s*(\d+)px/', (string) $li->getAttribute( 'style' ), $ml );
			preg_match( '/width:\s*(\d+)px/', (string) $num->getAttribute( 'style' ), $nw );
			preg_match( '/text-align:\s*(\w+)/', (string) $li->getAttribute( 'style' ), $ta );
			$t = $dom->createElement( 'table' );
			$t->setAttribute( 'class', 'li' );
			$t->setAttribute( 'style', 'margin-left:' . ( isset( $ml[1] ) ? (int) $ml[1] : 0 ) . 'px' );
			$tr = $dom->createElement( 'tr' );
			$t1 = $dom->createElement( 'td' );
			$t1->setAttribute( 'style', 'width:' . ( isset( $nw[1] ) ? (int) $nw[1] : 28 ) . 'px;padding-right:0' );
			$t1->nodeValue = $num->textContent;
			$t2 = $dom->createElement( 'td' );
			if ( isset( $ta[1] ) ) {
				$t2->setAttribute( 'style', 'text-align:' . $ta[1] );
			}
			while ( $tx->firstChild ) {
				$t2->appendChild( $tx->firstChild );
			}
			$tr->appendChild( $t1 );
			$tr->appendChild( $t2 );
			$t->appendChild( $tr );
			$li->parentNode->replaceChild( $t, $li );
		}
		foreach ( iterator_to_array( $xp->query( '//hr' ) ) as $hr ) {
			$d = $dom->createElement( 'div' );
			if ( false !== strpos( (string) $hr->getAttribute( 'class' ), 'bd-pagebreak' ) ) {
				$d->setAttribute( 'style', 'page-break-after:always;height:0;margin:0' );
			} else {
				$thick = false !== strpos( (string) $hr->getAttribute( 'class' ), 'thick' );
				$d->setAttribute( 'style', 'border-top:' . ( $thick ? 2 : 1 ) . 'px solid #222530;margin:12px 0;height:0' );
			}
			$hr->parentNode->replaceChild( $d, $hr );
		}
		foreach ( iterator_to_array( $xp->query( '//img' ) ) as $img ) {
			$data = self::embed_image( (string) $img->getAttribute( 'src' ) );
			if ( '' === $data ) {
				$img->parentNode->removeChild( $img );
				continue;
			}
			$img->setAttribute( 'src', $data );
			$w = (int) $img->getAttribute( 'width' );
			$img->setAttribute( 'style', 'max-width:100%' . ( $w ? ';width:' . min( 640, $w ) . 'px' : '' ) );
		}
		foreach ( iterator_to_array( $xp->query( '//a' ) ) as $a ) {
			$a->removeAttribute( 'target' );
		}
		$root = $dom->getElementById( 'bd-root' );
		if ( ! $root ) {
			$root = $xp->query( '//*[@id="bd-root"]' )->item( 0 );
		}
		$nodes = array();
		if ( $root ) {
			foreach ( $root->childNodes as $n ) {
				if ( XML_TEXT_NODE === $n->nodeType && '' === trim( $n->textContent ) ) {
					continue;
				}
				$nodes[] = $n;
			}
		}
		// The last clause (and its heading) travel with the signatures so a signature never sits alone on a page.
		$tail = array();
		if ( $nodes ) {
			$tail[] = array_pop( $nodes );
			if ( $nodes && preg_match( '/^h[1-4]$/', end( $nodes )->nodeName ) ) {
				array_unshift( $tail, array_pop( $nodes ) );
			}
		}
		$front = '';
		foreach ( $nodes as $n ) {
			$front .= $dom->saveHTML( $n );
		}
		$back = '';
		foreach ( $tail as $n ) {
			$back .= $dom->saveHTML( $n );
		}
		return array( $front, $back );
	}

	private static function qr_png( $text ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return '';
		}
		try {
			$qr = \BizyDash\Lib\QRCode::getMinimumQRCode( $text, QR_ERROR_CORRECT_LEVEL_M );
			$im = $qr->createImage( 6, 2 );
			ob_start();
			imagepng( $im );
			$png = ob_get_clean();
			imagedestroy( $im );
			return 'data:image/png;base64,' . base64_encode( $png );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	private static function css() {
		return <<<'CSS'
@page { margin: 20mm 17mm 22mm 17mm; }
body { font-family: Inter, DejaVu Sans, sans-serif; font-size: 10pt; line-height: 1.55; color: #1f2430; margin: 0; }
h1, h2, h3, h4 { color: #0e121b; font-weight: bold; line-height: 1.3; page-break-after: avoid; }
.body h1 { font-size: 17pt; margin: 18pt 0 6pt; } .body h2 { font-size: 13pt; margin: 16pt 0 5pt; } .body h3 { font-size: 11pt; margin: 13pt 0 4pt; } .body h4 { font-size: 10pt; margin: 11pt 0 3pt; }
.body p { margin: 0 0 7pt; orphans: 3; widows: 3; } .body ul, .body ol { margin: 0 0 7pt; padding-left: 18pt; } .body li { margin-bottom: 3pt; }
.body table.li { width: 100%; border-collapse: collapse; margin: 0 0 4pt; page-break-inside: avoid; } .body table.li td { padding: 0; vertical-align: top; border: 0; }
.body table.ct-table { width: 100%; border-collapse: collapse; margin: 8pt 0 10pt; } .body table.ct-table td { padding: 6pt 8pt; vertical-align: top; } .body table.ct-table tr { page-break-inside: avoid; }
.body table.ct-table td p { margin: 0 0 3pt; } .body table.ct-bordered td { border: 1px solid #cdd3dc; }
.body a { color: #1d4ed8; text-decoration: underline; } .body img { max-width: 100%; }
.k { font-size: 7.5pt; font-weight: bold; letter-spacing: .08em; text-transform: uppercase; color: #868e9d; }
.muted { color: #868e9d; } .small { font-size: 8pt; } .mono { font-family: Courier, monospace; font-size: 7.5pt; word-break: break-all; }
.head { width: 100%; border-collapse: collapse; margin-bottom: 14pt; } .head td { vertical-align: top; padding: 0; }
.num { font-size: 20pt; font-weight: bold; letter-spacing: -.02em; line-height: 1.1; }
.title { font-size: 19pt; font-weight: bold; letter-spacing: -.02em; margin: 0 0 12pt; }
.sigs { width: 100%; border-collapse: separate; border-spacing: 8pt; margin: 0 -8pt; } .sigs td { width: 50%; vertical-align: top; background: #f6f8fa; border-radius: 8pt; padding: 10pt 12pt; height: 92pt; page-break-inside: avoid; }
.sigimg { height: 44pt; margin: 5pt 0 3pt; background: #ffffff; }
.pill { font-size: 7.5pt; font-weight: bold; padding: 1pt 6pt; border-radius: 8pt; background: #e0faec; color: #0b4627; }
.pill.bad { background: #ffebec; color: #681219; }
.cert-h { page-break-before: always; }
.vbox { width: 100%; border-collapse: collapse; margin: 8pt 0 6pt; page-break-inside: avoid; } .vbox td { vertical-align: top; padding: 11pt 14pt; background: #effaf4; } .vbox.bad td { background: #fff1f0; }
.vbox td.q { width: 92pt; padding-right: 0; } .vbox .big { font-size: 13pt; font-weight: bold; margin-bottom: 4pt; } .cid { font-family: Courier, monospace; font-weight: bold; font-size: 11pt; letter-spacing: .05em; margin: 1pt 0 6pt; }
.checks { margin: 0; padding: 0; list-style: none; font-size: 8.5pt; } .checks li { padding: 1pt 0; }
.kv { width: 100%; border-collapse: collapse; margin: 4pt 0 8pt; background: #f6f8fa; } .kv td { padding: 2.5pt 10pt; vertical-align: top; font-size: 8pt; line-height: 1.35; } .kv tr { page-break-inside: avoid; } .kv td.l { width: 118pt; color: #868e9d; }
.signer { background: #f6f8fa; margin: 7pt 0; } .signer .nm { padding: 9pt 10pt 0; font-weight: bold; font-size: 9.5pt; }
.audit { width: 100%; border-collapse: collapse; font-size: 7.5pt; margin-top: 4pt; } .audit th { text-align: left; color: #868e9d; font-weight: bold; padding: 0 5pt 4pt 0; border-bottom: 1px solid #e3e7ec; } .audit td { padding: 3pt 5pt 3pt 0; line-height: 1.3; vertical-align: top; border-bottom: 1px solid #eef1f5; } .audit tr { page-break-inside: avoid; }
.cert-sec { margin: 14pt 0 4pt; }
CSS;
	}

	private static function html( array $c ) {
		$s       = Settings::get();
		$signers = Contracts::signers( $c['id'], true );
		$events  = Contracts::events( $c['id'] );
		$otps    = Contracts::otps( $c['id'] );
		$integ   = Contracts::integrity( $c['id'] );
		$cid     = $integ['cert_id'];
		$logo    = '';
		foreach ( array( 'business_logo_email_url', 'business_logo_url' ) as $lk ) {
			if ( '' === $logo && ! empty( $s[ $lk ] ) ) {
				$logo = self::embed_image( $s[ $lk ] );
			}
		}
		$verify  = Contracts::verify_url( $cid );
		$qr      = $cid ? self::qr_png( $verify ) : '';
		$consent = Contracts::CONSENT_TEXT;

		$h  = '<!doctype html><html><head><meta charset="utf-8"><style>' . self::css() . '</style></head><body>';
		$h .= '<table class="head"><tr><td>' . ( $logo ? '<img src="' . $logo . '" style="height:42pt">' : '<div style="font-size:15pt;font-weight:bold">' . self::e( $s['business_name'] ) . '</div>' ) . '</td><td style="text-align:right"><div class="k">Agreement</div><div class="num">' . self::e( $c['number'] ) . '</div></td></tr></table>';
		$h .= '<div class="title">' . self::e( $c['title'] ) . '</div>';
		list( $front, $back ) = self::prepare_body( wp_kses_post( $c['body'] ) );
		$h .= '<div class="body">' . $front . '</div>';

		// Signature cards (2 per row), kept together with the last clause.
		$h .= '<div class="body" style="page-break-inside:avoid">' . $back . '<div class="cert-sec"><div class="k" style="margin-bottom:2pt">Signatures</div><table class="sigs">';
		$cells = array();
		foreach ( $signers as $sg ) {
			$cell  = '<div class="k">' . self::e( ucfirst( $sg['role'] ) ) . '</div><div style="font-weight:bold">' . self::e( $sg['name'] ) . '</div>';
			$cell .= $sg['sign_image'] ? '<div><img class="sigimg" src="' . $sg['sign_image'] . '"></div><div class="muted small">Signed ' . self::e( self::local( $sg['signed_at'] ) ) . '</div>' : '<div class="muted small" style="margin-top:30pt">Not signed</div>';
			$cells[] = $cell;
		}
		foreach ( array_chunk( $cells, 2 ) as $row ) {
			$h .= '<tr><td>' . $row[0] . '</td><td>' . ( isset( $row[1] ) ? $row[1] : '' ) . '</td></tr>';
		}
		$h .= '</table></div><div class="muted small" style="text-align:center;margin-top:6pt">Signed electronically. The Certificate of Completion on the following pages records how each signature was made.</div></div>';

		// Certificate of Completion.
		$h .= '<div class="cert-h"><div class="k">Certificate of Completion</div><div style="font-size:15pt;font-weight:bold;margin:2pt 0 4pt">' . self::e( $c['title'] ) . ' · ' . self::e( $c['number'] ) . '</div><div class="muted small">This certificate records how this document was sent, verified and signed. Times are UTC unless marked otherwise.</div>';
		$h .= '<table class="vbox' . ( $integ['ok'] ? '' : ' bad' ) . '"><tr>' . ( $qr ? '<td class="q"><img src="' . $qr . '" style="width:78pt;height:78pt"><div class="muted small" style="margin-top:2pt">Scan to verify</div></td>' : '' ) . '<td><div class="k">Verification</div><div class="big">' . ( $integ['ok'] ? 'Genuine and unaltered' : 'Integrity check failed' ) . '</div>' . ( $cid ? '<div class="muted small">Certificate ID</div><div class="cid">' . self::e( $cid ) . '</div>' : '' ) . '<ul class="checks">';
		foreach ( $integ['checks'] as $ck ) {
			$h .= '<li>' . ( $ck['ok'] ? '<span style="font-family:DejaVu Sans;color:#0b4627">&#10003;</span> ' : '<span style="font-family:DejaVu Sans;color:#b42318">&#10007;</span> ' ) . self::e( $ck['label'] ) . '</li>';
		}
		$h .= '</ul>' . ( $cid ? '<div class="muted small" style="margin-top:5pt">Anyone can check this certificate at ' . self::e( preg_replace( '#^https?://#', '', Contracts::verify_url() ) ) . ' using the ID above.</div>' : '' ) . '</td></tr></table>';

		$h .= '<div class="cert-sec k">Document</div><table class="kv">';
		foreach ( array( 'Sent' => self::local( $c['sent_at'] ), 'Completed' => self::local( $c['completed_at'] ) ) as $k => $v ) {
			$h .= '<tr><td class="l">' . $k . '</td><td>' . self::e( $v ) . '</td></tr>';
		}
		$h .= '<tr><td class="l">Document SHA-256</td><td class="mono">' . self::e( $c['body_hash'] ) . '</td></tr>';
		$h .= '<tr><td class="l">Final record SHA-256</td><td class="mono">' . self::e( $c['final_hash'] ) . '</td></tr></table>';

		$h .= '<div class="cert-sec k">Signers</div>';
		foreach ( $signers as $sg ) {
			$env = json_decode( (string) $sg['sign_env'], true );
			$env = is_array( $env ) ? $env : array();
			$h  .= '<div class="signer"><div class="nm">' . self::e( $sg['name'] ) . ' <span class="muted" style="font-weight:normal">· ' . self::e( $sg['email'] ) . ' · ' . self::e( ucfirst( $sg['role'] ) ) . '</span> <span class="pill">' . self::e( ucfirst( $sg['status'] ) ) . '</span></div><table class="kv" style="background:none;margin:2pt 0 6pt">';
			$rows = array(
				'Invited'            => self::local( $sg['invited_at'] ),
				'First opened'       => self::local( $sg['viewed_at'] ),
				'Consented to e-sign' => self::local( $sg['consent_at'] ),
				'Signed'             => self::local( $sg['signed_at'] ),
				'Name typed'         => $sg['sign_name'] . ' (' . $sg['sign_method'] . ' signature)',
				'Signature SHA-256'  => hash( 'sha256', (string) $sg['sign_image'] ),
				'IP address'         => $sg['sign_ip'] . ( $sg['sign_forwarded'] && $sg['sign_forwarded'] !== $sg['sign_ip'] ? ' (forwarded: ' . $sg['sign_forwarded'] . ')' : '' ),
				'Browser / device'   => $sg['sign_ua'],
				'Time zone · language · screen' => implode( ' · ', array_filter( array( isset( $env['timezone'] ) ? $env['timezone'] : '', isset( $env['language'] ) ? $env['language'] : '', isset( $env['screen'] ) ? $env['screen'] : '', isset( $env['platform'] ) ? $env['platform'] : '' ) ) ),
			);
			foreach ( $rows as $k => $v ) {
				$h .= '<tr><td class="l">' . self::e( $k ) . '</td><td' . ( 'Signature SHA-256' === $k ? ' class="mono"' : '' ) . '>' . self::e( $v ) . '</td></tr>';
			}
			$n = 0;
			foreach ( $otps as $o ) {
				if ( (int) $o['signer_id'] !== (int) $sg['id'] ) {
					continue;
				}
				$n++;
				$h .= '<tr><td class="l">Code ' . $n . ' emailed</td><td>' . self::e( $o['sent_at'] . ' UTC to ' . $o['email'] . ' — ' . ( $o['mail_ok'] ? 'accepted by mail server' : 'email failed' ) . ' · code ' ) . '<b>' . self::e( $o['code'] ) . '</b></td></tr>';
				$h .= '<tr><td class="l">Code ' . $n . ' entered</td><td>' . ( $o['entered_at'] ? self::e( $o['entered_at'] . ' UTC · entered ' ) . '<b>' . self::e( $o['entered_code'] ) . '</b>' . self::e( ' · ' . ( 'verified' === $o['status'] ? 'matched' : $o['status'] ) . ' · ' . (int) $o['attempts'] . ' attempt(s)' ) : self::e( 'Not entered (' . $o['status'] . ')' ) ) . '</td></tr>';
			}
			$h .= '</table></div>';
		}

		$h .= '<div class="cert-sec k">Consent statement shown to every signer</div><div class="muted" style="font-size:8.5pt">' . self::e( $consent ) . '</div>';

		$h .= '<div class="cert-sec k">Audit trail</div><table class="audit"><tr><th style="width:84pt">Time (UTC)</th><th style="width:88pt">Event</th><th style="width:62pt">By</th><th>Detail</th><th style="width:62pt">IP</th></tr>';
		foreach ( $events as $e ) {
			$who = 'System';
			foreach ( $signers as $sg ) {
				if ( (int) $sg['id'] === (int) $e['signer_id'] ) {
					$who = $sg['name'];
				}
			}
			if ( ! $e['signer_id'] && $e['user_name'] ) {
				$who = $e['user_name'];
			}
			$h .= '<tr><td style="white-space:nowrap">' . self::e( $e['created_at'] ) . '</td><td>' . self::e( \BizyDash\Public_Contract::event_label( $e['event'] ) ) . '</td><td>' . self::e( $who ) . '</td><td>' . self::e( \BizyDash\Public_Contract::event_summary( $e, true ) ) . '</td><td>' . self::e( $e['ip'] ) . '</td></tr>';
		}
		$h .= '</table><div class="muted small" style="margin-top:10pt">Each audit row stores a SHA-256 hash that includes the previous row’s hash, so any later change to the history can be detected. Electronic signatures are legally recognised in most countries (including under the US ESIGN Act and UETA, the EU/UK eIDAS rules, and Nigeria’s Evidence Act). This certificate is a record, not legal advice.</div></div>';
		$h .= '</body></html>';
		return $h;
	}
}
