<?php
namespace BizyDash\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Word (.docx) → clean HTML for contracts, with no external libraries.
 *
 * Keeps what matters in an agreement: headings, bold/italic/underline, colour, alignment, indents,
 * Word's automatic numbering (rendered as real text such as "1.1" or "(a)" so legal numbering survives),
 * tables (bordered or borderless layout tables), horizontal lines, page breaks, images and links.
 * Anything it cannot carry over is reported back as a warning instead of silently dropped.
 */
class Docx_Import {

	const W  = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
	const R  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	const A  = 'http://schemas.openxmlformats.org/drawingml/2006/main';
	const WP = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';

	const MAX_BYTES = 15728640; // 15 MB upload.
	const PB        = "\x01PB\x01";

	private $zip;
	private $styles      = array();
	private $default_p   = '';
	private $abs         = array();
	private $nums        = array();
	private $rels        = array();
	private $counters    = array();
	private $warnings    = array();
	private $base_sz     = 22;
	private $title       = '';
	private $img_ok      = 0;
	private $img_skipped = 0;
	private $has_textbox = false;
	private $uploaded    = array();
	private $import_images = true;
	private $in_heading   = false;

	/* ───────────────────────── Entry points ───────────────────────── */

	/** @return array{title:string,html:string,warnings:string[]}|\WP_Error */
	public static function from_file( $path, $fallback_title = '', $import_images = true ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return new \WP_Error( 'bizydash_import', 'This server cannot open Word files (the PHP zip extension is missing).', array( 'status' => 500 ) );
		}
		if ( ! is_readable( $path ) || filesize( $path ) > self::MAX_BYTES ) {
			return new \WP_Error( 'bizydash_import', 'That file is too large (limit 15 MB).', array( 'status' => 400 ) );
		}
		$head = (string) file_get_contents( $path, false, null, 0, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" === $head ) { // legacy .doc (OLE container)
			return Doc_Import::from_file( $path, $fallback_title );
		}
		$i = new self();
		return $i->run( $path, $fallback_title, $import_images );
	}

	/** Google Docs (shared with “anyone with the link”) or a .docx stored in Google Drive. */
	public static function from_google( $url ) {
		$url = trim( (string) $url );
		$dl  = '';
		if ( preg_match( '#docs\.google\.com/document/(?:u/\d+/)?d/([A-Za-z0-9_-]{20,})#', $url, $m ) ) {
			$dl = 'https://docs.google.com/document/d/' . $m[1] . '/export?format=docx';
		} elseif ( preg_match( '#drive\.google\.com/(?:file/d/|open\?id=|uc\?(?:[^"\s]*&)?id=)([A-Za-z0-9_-]{20,})#', $url, $m ) ) {
			$dl = 'https://drive.google.com/uc?export=download&id=' . $m[1];
		} elseif ( preg_match( '#docs\.google\.com/document/d/e/#', $url ) ) {
			return new \WP_Error( 'bizydash_import', 'That is a “published to the web” link. In Google Docs choose Share → “Anyone with the link” → Viewer, then paste that link instead.', array( 'status' => 400 ) );
		} else {
			return new \WP_Error( 'bizydash_import', 'Paste a Google Docs link (it starts with docs.google.com/document/d/…).', array( 'status' => 400 ) );
		}
		$res = wp_remote_get( $dl, array( 'timeout' => 30, 'redirection' => 6, 'limit_response_size' => self::MAX_BYTES + 1, 'user-agent' => 'Mozilla/5.0 (compatible; BizyDash)' ) );
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'bizydash_import', 'Could not reach Google: ' . $res->get_error_message(), array( 'status' => 502 ) );
		}
		$body = wp_remote_retrieve_body( $res );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) || 'PK' !== substr( $body, 0, 2 ) ) {
			return new \WP_Error( 'bizydash_import', 'Google would not give us the document. Open it in Google Docs → Share → set General access to “Anyone with the link” (Viewer), then try again.', array( 'status' => 400 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( 'bizydash-gdoc' );
		file_put_contents( $tmp, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$out = self::from_file( $tmp, 'Google Doc' );
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		return $out;
	}

	/* ───────────────────────── XML helpers ───────────────────────── */

	private static function el( \DOMNode $n, $name ) {
		foreach ( $n->childNodes as $c ) {
			if ( XML_ELEMENT_NODE === $c->nodeType && self::W === $c->namespaceURI && $name === $c->localName ) {
				return $c;
			}
		}
		return null;
	}

	private static function wa( $n, $attr ) {
		return $n ? $n->getAttributeNS( self::W, $attr ) : '';
	}

	private static function on( $n ) {
		if ( ! $n ) {
			return false;
		}
		$v = strtolower( self::wa( $n, 'val' ) );
		return ! in_array( $v, array( '0', 'false', 'off', 'none' ), true );
	}

	private function load_xml( $name ) {
		$raw = $this->zip->getFromName( $name );
		if ( false === $raw || '' === $raw || strlen( $raw ) > 12582912 ) {
			return null;
		}
		$d    = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = $d->loadXML( $raw, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $ok ? $d : null;
	}

	/* ───────────────────────── Main ───────────────────────── */

	private function run( $path, $fallback_title, $import_images ) {
		$this->import_images = (bool) $import_images;
		$this->zip           = new \ZipArchive();
		if ( true !== $this->zip->open( $path ) ) {
			return new \WP_Error( 'bizydash_import', 'That does not look like a Word document. Please upload a .doc or .docx file.', array( 'status' => 400 ) );
		}
		$total = 0;
		for ( $i = 0; $i < $this->zip->numFiles; $i++ ) {
			$st     = $this->zip->statIndex( $i );
			$total += $st ? (int) $st['size'] : 0;
		}
		if ( $total > 120 * 1048576 ) {
			$this->zip->close();
			return new \WP_Error( 'bizydash_import', 'That document is too large to import.', array( 'status' => 400 ) );
		}
		$doc = $this->load_xml( 'word/document.xml' );
		if ( ! $doc ) {
			$this->zip->close();
			return new \WP_Error( 'bizydash_import', 'That does not look like a Word document (.doc or .docx).', array( 'status' => 400 ) );
		}
		$this->parse_styles( $this->load_xml( 'word/styles.xml' ) );
		$this->parse_numbering( $this->load_xml( 'word/numbering.xml' ) );
		$this->parse_rels( $this->load_xml( 'word/_rels/document.xml.rels' ) );

		$body = null;
		foreach ( $doc->documentElement->childNodes as $c ) {
			if ( XML_ELEMENT_NODE === $c->nodeType && 'body' === $c->localName ) {
				$body = $c;
			}
		}
		if ( ! $body ) {
			$this->zip->close();
			return new \WP_Error( 'bizydash_import', 'The document appears to be empty.', array( 'status' => 400 ) );
		}
		$html = $this->blocks( $body, true );

		// Headers / footers are not part of the agreement text.
		for ( $i = 0; $i < $this->zip->numFiles; $i++ ) {
			$n = $this->zip->getNameIndex( $i );
			if ( preg_match( '#^word/(header|footer)\d*\.xml$#', $n ) ) {
				$x = $this->zip->getFromIndex( $i );
				if ( $x && preg_match( '/<w:t[ >][^<]*[^<\s][^<]*<\/w:t>/', $x ) ) {
					$this->warnings[] = 'Page headers and footers were not imported — the contract page adds its own header, numbering and footer.';
					break;
				}
			}
		}
		if ( $this->has_textbox ) {
			$this->warnings[] = 'Text boxes were skipped. Move their text into normal paragraphs in Word and import again.';
		}
		if ( $this->img_skipped ) {
			$this->warnings[] = sprintf( '%d image(s) could not be imported (unsupported type or too large).', $this->img_skipped );
		}
		if ( ! $this->title ) {
			$core = $this->load_xml( 'docProps/core.xml' );
			if ( $core ) {
				$t = $core->getElementsByTagNameNS( 'http://purl.org/dc/elements/1.1/', 'title' );
				if ( $t->length && trim( $t->item( 0 )->textContent ) ) {
					$this->title = trim( $t->item( 0 )->textContent );
				}
			}
		}
		$this->zip->close();

		$html = $this->tidy( $html );
		if ( '' === trim( wp_strip_all_tags( $html, true ) ) && false === strpos( $html, '<img' ) ) {
			return new \WP_Error( 'bizydash_import', 'No text was found in that document.', array( 'status' => 400 ) );
		}
		return array(
			'title'    => $this->title ? mb_substr( $this->title, 0, 180 ) : $fallback_title,
			'html'     => wp_kses_post( $html ),
			'warnings' => array_values( array_unique( $this->warnings ) ),
		);
	}

	/** Trim stray empty paragraphs at both ends and cap runs of blank lines. */
	private function tidy( $html ) {
		$empty = '<p>&nbsp;</p>';
		$html  = preg_replace( '/(' . preg_quote( $empty, '/' ) . '\s*){3,}/', $empty . $empty, $html );
		$html  = preg_replace( '/^(\s*' . preg_quote( $empty, '/' ) . ')+/', '', $html );
		$html  = preg_replace( '/(' . preg_quote( $empty, '/' ) . '\s*)+$/', '', $html );
		return trim( $html );
	}

	/* ───────────────────────── Styles & numbering ───────────────────────── */

	private function parse_styles( $d ) {
		if ( ! $d ) {
			return;
		}
		foreach ( $d->documentElement->childNodes as $s ) {
			if ( XML_ELEMENT_NODE !== $s->nodeType ) {
				continue;
			}
			if ( 'docDefaults' === $s->localName ) {
				$rpr = null;
				$rd  = self::el( $s, 'rPrDefault' );
				if ( $rd ) {
					$rpr = self::el( $rd, 'rPr' );
				}
				$sz = $rpr ? self::el( $rpr, 'sz' ) : null;
				if ( $sz && (int) self::wa( $sz, 'val' ) > 0 ) {
					$this->base_sz = (int) self::wa( $sz, 'val' );
				}
				continue;
			}
			if ( 'style' !== $s->localName ) {
				continue;
			}
			$id = self::wa( $s, 'styleId' );
			$nm = self::el( $s, 'name' );
			$bo = self::el( $s, 'basedOn' );
			$this->styles[ $id ] = array(
				'type'  => self::wa( $s, 'type' ),
				'name'  => strtolower( self::wa( $nm, 'val' ) ),
				'based' => self::wa( $bo, 'val' ),
				'ppr'   => self::el( $s, 'pPr' ),
				'rpr'   => self::el( $s, 'rPr' ),
				'tbl'   => self::el( $s, 'tblPr' ),
			);
			if ( 'paragraph' === self::wa( $s, 'type' ) && '1' === self::wa( $s, 'default' ) ) {
				$this->default_p = $id;
			}
		}
		if ( $this->default_p && isset( $this->styles[ $this->default_p ]['rpr'] ) && $this->styles[ $this->default_p ]['rpr'] ) {
			$sz = self::el( $this->styles[ $this->default_p ]['rpr'], 'sz' );
			if ( $sz && (int) self::wa( $sz, 'val' ) > 0 ) {
				$this->base_sz = (int) self::wa( $sz, 'val' );
			}
		}
	}

	private function chain( $id ) {
		$out = array();
		$n   = 0;
		while ( $id && isset( $this->styles[ $id ] ) && $n++ < 12 ) {
			array_unshift( $out, $id );
			$id = $this->styles[ $id ]['based'];
		}
		return $out;
	}

	private function parse_numbering( $d ) {
		if ( ! $d ) {
			return;
		}
		foreach ( $d->documentElement->childNodes as $n ) {
			if ( XML_ELEMENT_NODE !== $n->nodeType ) {
				continue;
			}
			if ( 'abstractNum' === $n->localName ) {
				$aid = self::wa( $n, 'abstractNumId' );
				foreach ( $n->childNodes as $lv ) {
					if ( XML_ELEMENT_NODE !== $lv->nodeType || 'lvl' !== $lv->localName ) {
						continue;
					}
					$il   = (int) self::wa( $lv, 'ilvl' );
					$ppr  = self::el( $lv, 'pPr' );
					$ind  = $ppr ? self::el( $ppr, 'ind' ) : null;
					$left = $ind ? self::wa( $ind, 'left' ) : '';
					if ( '' === $left && $ind ) {
						$left = self::wa( $ind, 'start' );
					}
					$this->abs[ $aid ][ $il ] = array(
						'start'   => ( $s = self::el( $lv, 'start' ) ) ? (int) self::wa( $s, 'val' ) : 1,
						'fmt'     => ( $f = self::el( $lv, 'numFmt' ) ) ? self::wa( $f, 'val' ) : 'decimal',
						'text'    => ( $t = self::el( $lv, 'lvlText' ) ) ? self::wa( $t, 'val' ) : '%1.',
						'left'    => '' === $left ? 720 * ( $il + 1 ) : (int) $left,
						'hanging' => $ind && '' !== self::wa( $ind, 'hanging' ) ? (int) self::wa( $ind, 'hanging' ) : 360,
					);
				}
			} elseif ( 'num' === $n->localName ) {
				$nid  = self::wa( $n, 'numId' );
				$ref  = self::el( $n, 'abstractNumId' );
				$over = array();
				foreach ( $n->childNodes as $o ) {
					if ( XML_ELEMENT_NODE === $o->nodeType && 'lvlOverride' === $o->localName ) {
						$so = self::el( $o, 'startOverride' );
						if ( $so ) {
							$over[ (int) self::wa( $o, 'ilvl' ) ] = (int) self::wa( $so, 'val' );
						}
					}
				}
				$this->nums[ $nid ] = array( 'abs' => self::wa( $ref, 'val' ), 'over' => $over );
			}
		}
	}

	private function parse_rels( $d ) {
		if ( ! $d ) {
			return;
		}
		foreach ( $d->getElementsByTagName( 'Relationship' ) as $r ) {
			$this->rels[ $r->getAttribute( 'Id' ) ] = array( 'target' => $r->getAttribute( 'Target' ), 'mode' => $r->getAttribute( 'TargetMode' ) );
		}
	}

	/* ───────────────────────── Property parsing ───────────────────────── */

	private function color( $v ) {
		return ( preg_match( '/^[0-9A-Fa-f]{6}$/', $v ) && '000000' !== strtoupper( $v ) ) ? '#' . strtolower( $v ) : '';
	}

	private function parse_ppr( $ppr ) {
		$o = array();
		if ( ! $ppr ) {
			return $o;
		}
		if ( $x = self::el( $ppr, 'jc' ) ) {
			$v          = self::wa( $x, 'val' );
			$o['jc']    = in_array( $v, array( 'center', 'right', 'end' ), true ) ? ( 'center' === $v ? 'center' : 'right' ) : ( in_array( $v, array( 'both', 'distribute' ), true ) ? 'justify' : '' );
		}
		if ( $x = self::el( $ppr, 'ind' ) ) {
			$l = self::wa( $x, 'left' );
			if ( '' === $l ) {
				$l = self::wa( $x, 'start' );
			}
			if ( '' !== $l ) {
				$o['left'] = (int) $l;
			}
			if ( '' !== self::wa( $x, 'hanging' ) ) {
				$o['hanging'] = (int) self::wa( $x, 'hanging' );
			}
			if ( '' !== self::wa( $x, 'firstLine' ) ) {
				$o['first'] = (int) self::wa( $x, 'firstLine' );
			}
		}
		if ( $np = self::el( $ppr, 'numPr' ) ) {
			if ( $x = self::el( $np, 'numId' ) ) {
				$o['numId'] = self::wa( $x, 'val' );
			}
			if ( $x = self::el( $np, 'ilvl' ) ) {
				$o['ilvl'] = (int) self::wa( $x, 'val' );
			}
		}
		if ( $b = self::el( $ppr, 'pBdr' ) ) {
			foreach ( array( 'top', 'bottom' ) as $side ) {
				if ( $x = self::el( $b, $side ) ) {
					$v = self::wa( $x, 'val' );
					if ( $v && 'nil' !== $v && 'none' !== $v ) {
						$o[ 'b_' . $side ] = array( 'sz' => (int) self::wa( $x, 'sz' ), 'color' => $this->color( self::wa( $x, 'color' ) ) );
					}
				}
			}
		}
		if ( $x = self::el( $ppr, 'pageBreakBefore' ) ) {
			$o['pbb'] = self::on( $x );
		}
		return $o;
	}

	private function eff_ppr( \DOMElement $p ) {
		$ppr = self::el( $p, 'pPr' );
		$sid = '';
		if ( $ppr && ( $x = self::el( $ppr, 'pStyle' ) ) ) {
			$sid = self::wa( $x, 'val' );
		}
		if ( ! $sid ) {
			$sid = $this->default_p;
		}
		$props = array( 'style' => $sid );
		foreach ( $this->chain( $sid ) as $id ) {
			$props = array_merge( $props, $this->parse_ppr( $this->styles[ $id ]['ppr'] ) );
		}
		return array_merge( $props, $this->parse_ppr( $ppr ) );
	}

	private function parse_rpr( $rpr, $with_look = true ) {
		$o = array();
		if ( ! $rpr ) {
			return $o;
		}
		foreach ( array( 'b' => 'b', 'i' => 'i', 'strike' => 's', 'caps' => 'caps', 'smallCaps' => 'sc' ) as $tag => $key ) {
			if ( $x = self::el( $rpr, $tag ) ) {
				$o[ $key ] = self::on( $x );
			}
		}
		if ( $x = self::el( $rpr, 'u' ) ) {
			$o['u'] = self::on( $x );
		}
		if ( $x = self::el( $rpr, 'vertAlign' ) ) {
			$o['va'] = self::wa( $x, 'val' );
		}
		if ( $with_look ) {
			if ( $x = self::el( $rpr, 'color' ) ) {
				$o['color'] = $this->color( self::wa( $x, 'val' ) );
			}
			if ( $x = self::el( $rpr, 'sz' ) ) {
				$o['sz'] = (int) self::wa( $x, 'val' );
			}
			if ( $x = self::el( $rpr, 'highlight' ) ) {
				$map       = array( 'yellow' => '#fff3a3', 'green' => '#c8f7c5', 'cyan' => '#c9f1f7', 'magenta' => '#f7c9ec', 'lightGray' => '#e5e7eb' );
				$o['hl']   = isset( $map[ self::wa( $x, 'val' ) ] ) ? $map[ self::wa( $x, 'val' ) ] : '';
			} elseif ( ( $x = self::el( $rpr, 'shd' ) ) && ( $c = $this->color( self::wa( $x, 'fill' ) ) ) ) {
				$o['hl'] = $c;
			}
		}
		return $o;
	}

	private function eff_rpr( \DOMElement $r, $pstyle, $use_style ) {
		$props = array();
		if ( $use_style ) {
			foreach ( $this->chain( $pstyle ) as $id ) {
				$props = array_merge( $props, $this->parse_rpr( $this->styles[ $id ]['rpr'] ) );
			}
		}
		$rpr = self::el( $r, 'rPr' );
		if ( $rpr && ( $x = self::el( $rpr, 'rStyle' ) ) ) {
			foreach ( $this->chain( self::wa( $x, 'val' ) ) as $id ) {
				$props = array_merge( $props, $this->parse_rpr( $this->styles[ $id ]['rpr'], $use_style ) );
			}
		}
		return array_merge( $props, $this->parse_rpr( $rpr, true ) );
	}

	/* ───────────────────────── Blocks ───────────────────────── */

	private function blocks( \DOMNode $parent, $top = false ) {
		$out     = array();
		$first   = true;
		foreach ( $parent->childNodes as $c ) {
			if ( XML_ELEMENT_NODE !== $c->nodeType || self::W !== $c->namespaceURI ) {
				continue;
			}
			switch ( $c->localName ) {
				case 'p':
					$h = $this->paragraph( $c, $top && $first );
					if ( '' !== $h ) {
						$first = false;
						$out[] = $h;
					}
					break;
				case 'tbl':
					$first = false;
					$out[] = $this->table( $c );
					break;
				case 'sdt':
					if ( $content = self::el( $c, 'sdtContent' ) ) {
						$out[] = $this->blocks( $content );
					}
					break;
			}
		}
		return implode( "\n", $out );
	}

	private function is_title_style( $sid ) {
		return isset( $this->styles[ $sid ] ) && 'title' === $this->styles[ $sid ]['name'];
	}

	private function heading_level( $sid ) {
		if ( isset( $this->styles[ $sid ] ) && preg_match( '/^heading\s*(\d)$/', $this->styles[ $sid ]['name'], $m ) ) {
			return min( 4, (int) $m[1] + 1 ); // Heading 1 → <h2> (the page already shows the contract title as <h1>).
		}
		return 0;
	}

	private function paragraph( \DOMElement $p, $first_block ) {
		$pp    = $this->eff_ppr( $p );
		$sid   = $pp['style'];
		$level = $this->heading_level( $sid );
		$is_t  = $this->is_title_style( $sid );
		$this->in_heading = (bool) ( $level || $is_t );
		$raw              = $this->runs( $p, $sid, ! ( $level || $is_t ) );
		$this->in_heading = false;
		$plain = trim( str_replace( array( self::PB, '&nbsp;' ), ' ', wp_strip_all_tags( $raw ) ) );

		// A leading "Title" paragraph becomes the contract title instead of body text.
		if ( $is_t && '' !== $plain && '' === $this->title ) {
			$this->title = html_entity_decode( $plain, ENT_QUOTES, 'UTF-8' );
			return '';
		}

		$extra = '';
		$ppr   = self::el( $p, 'pPr' );
		if ( $ppr && ( $sp = self::el( $ppr, 'sectPr' ) ) ) {
			$t = ( $x = self::el( $sp, 'type' ) ) ? self::wa( $x, 'val' ) : 'nextPage';
			if ( 'continuous' !== $t ) {
				$extra = '<hr class="bd-pagebreak">';
			}
		}
		$pre = ! empty( $pp['pbb'] ) ? '<hr class="bd-pagebreak">' : '';

		// Pure horizontal rule: an empty paragraph carrying a border, or Word's legacy "horizontal line" shape.
		$has_hr = false !== strpos( $raw, '<!--hr-->' );
		$raw    = str_replace( '<!--hr-->', '', $raw );
		if ( '' === trim( str_replace( array( self::PB, '&nbsp;' ), ' ', wp_strip_all_tags( $raw ) ) ) && false === strpos( $raw, '<img' ) ) {
			if ( $has_hr || isset( $pp['b_bottom'] ) || isset( $pp['b_top'] ) ) {
				$b = isset( $pp['b_bottom'] ) ? $pp['b_bottom'] : ( isset( $pp['b_top'] ) ? $pp['b_top'] : array( 'sz' => 6, 'color' => '' ) );
				return $pre . '<hr class="ct-rule' . ( $b['sz'] >= 12 ? ' ct-rule--thick' : '' ) . '">' . $extra;
			}
			if ( false !== strpos( $raw, self::PB ) ) {
				return $pre . str_replace( self::PB, '<hr class="bd-pagebreak">', $raw ) . $extra;
			}
			return $pre . '<p>&nbsp;</p>' . $extra;
		}

		$style = array();
		if ( ! empty( $pp['jc'] ) ) {
			$style[] = 'text-align:' . $pp['jc'];
		}
		if ( isset( $pp['b_bottom'] ) ) {
			$style[] = 'border-bottom:' . ( $pp['b_bottom']['sz'] >= 12 ? 2 : 1 ) . 'px solid ' . ( $pp['b_bottom']['color'] ? $pp['b_bottom']['color'] : '#222530' ) . ';padding-bottom:4px';
		}
		if ( isset( $pp['b_top'] ) ) {
			$style[] = 'border-top:1px solid ' . ( $pp['b_top']['color'] ? $pp['b_top']['color'] : '#222530' ) . ';padding-top:4px';
		}

		$label   = '';
		$num_id  = isset( $pp['numId'] ) ? $pp['numId'] : '0';
		$list    = ( '0' !== $num_id && '' !== $num_id && isset( $this->nums[ $num_id ] ) );
		$left_px = 0;
		$hang_px = 0;
		if ( $list ) {
			$il    = isset( $pp['ilvl'] ) ? (int) $pp['ilvl'] : 0;
			$label = $this->next_label( $num_id, $il );
			$lv    = $this->level( $num_id, $il );
			$dp    = $this->parse_ppr( $ppr ); // only the paragraph's own indent beats the numbering level's.
			$left  = isset( $dp['left'] ) ? $dp['left'] : $lv['left'];
			$hang  = isset( $dp['hanging'] ) ? $dp['hanging'] : $lv['hanging'];
			$left_px = max( 0, min( 360, (int) round( ( $left - $hang ) / 15 ) ) );
			$hang_px = max( 18, min( 90, (int) round( $hang / 15 ) ) );
		} else {
			if ( isset( $pp['left'] ) && $pp['left'] > 0 ) {
				$style[] = 'margin-left:' . min( 360, (int) round( $pp['left'] / 15 ) ) . 'px';
			}
			if ( isset( $pp['first'] ) && $pp['first'] > 0 ) {
				$style[] = 'text-indent:' . min( 90, (int) round( $pp['first'] / 15 ) ) . 'px';
			}
		}

		$segments = explode( self::PB, $raw );
		$html     = '';
		foreach ( $segments as $k => $seg ) {
			if ( $k > 0 ) {
				$html .= '<hr class="bd-pagebreak">';
			}
			if ( '' === trim( str_replace( '&nbsp;', ' ', wp_strip_all_tags( $seg ) ) ) && false === strpos( $seg, '<img' ) ) {
				continue;
			}
			$st = $style ? ' style="' . esc_attr( implode( ';', $style ) ) . '"' : '';
			if ( $level ) {
				$lab   = ( 0 === $k && $label ) ? '<span class="ct-hnum">' . esc_html( $label ) . '</span> ' : '';
				$html .= "<h{$level}{$st}>{$lab}{$seg}</h{$level}>";
			} elseif ( $list && 0 === $k ) {
				$ls    = 'margin-left:' . $left_px . 'px' . ( ! empty( $pp['jc'] ) ? ';text-align:' . $pp['jc'] : '' );
				$html .= '<div class="ct-li" style="' . esc_attr( $ls ) . '"><span class="ct-num" style="width:' . $hang_px . 'px">' . esc_html( $label ) . '</span><span class="ct-tx">' . $seg . '</span></div>';
			} elseif ( $is_t ) {
				$html .= '<h1' . ( $st ? $st : ' style="text-align:center"' ) . ">{$seg}</h1>";
			} else {
				$html .= "<p{$st}>{$seg}</p>";
			}
		}
		return $pre . $html . $extra;
	}

	/* ───────────────────────── Numbering labels ───────────────────────── */

	private function level( $num_id, $il ) {
		$abs = $this->nums[ $num_id ]['abs'];
		if ( isset( $this->abs[ $abs ][ $il ] ) ) {
			return $this->abs[ $abs ][ $il ];
		}
		return array( 'start' => 1, 'fmt' => 'decimal', 'text' => '%' . ( $il + 1 ) . '.', 'left' => 720 * ( $il + 1 ), 'hanging' => 360 );
	}

	private function next_label( $num_id, $il ) {
		$n   = $this->nums[ $num_id ];
		$key = $n['over'] ? 'n' . $num_id : 'a' . $n['abs'];
		if ( ! isset( $this->counters[ $key ] ) ) {
			$this->counters[ $key ] = array();
		}
		$c  = &$this->counters[ $key ];
		$lv = $this->level( $num_id, $il );
		if ( ! isset( $c[ $il ] ) ) {
			$c[ $il ] = ( isset( $n['over'][ $il ] ) ? $n['over'][ $il ] : $lv['start'] ) - 1;
		}
		$c[ $il ]++;
		foreach ( array_keys( $c ) as $k ) {
			if ( $k > $il ) {
				unset( $c[ $k ] );
			}
		}
		if ( 'bullet' === $lv['fmt'] ) {
			return $this->bullet( $lv['text'], $il );
		}
		if ( 'none' === $lv['fmt'] ) {
			return '';
		}
		$text = $lv['text'];
		return preg_replace_callback(
			'/%([1-9])/',
			function ( $m ) use ( $c, $num_id ) {
				$i  = (int) $m[1] - 1;
				$lv = $this->level( $num_id, $i );
				$v  = isset( $c[ $i ] ) ? $c[ $i ] : $lv['start'];
				return $this->format_number( $v, $lv['fmt'] );
			},
			$text
		);
	}

	private function bullet( $t, $il ) {
		$t = (string) $t;
		if ( '' === $t ) {
			return '•';
		}
		$cp = function_exists( 'mb_ord' ) ? mb_ord( mb_substr( $t, 0, 1 ) ) : ord( $t );
		if ( $cp >= 0xE000 && $cp <= 0xF8FF ) { // Symbol / Wingdings private-use glyphs.
			$map = array( 0xF0B7 => '•', 0xF0A7 => '▪', 0xF0D8 => '➢', 0xF0FC => '✓', 0xF076 => '❖', 0xF0A8 => '◻' );
			return isset( $map[ $cp ] ) ? $map[ $cp ] : ( $il % 2 ? '◦' : '•' );
		}
		if ( 'o' === $t ) {
			return '◦';
		}
		if ( '§' === $t ) {
			return '▪';
		}
		return $t;
	}

	private function format_number( $n, $fmt ) {
		$n = (int) $n;
		switch ( $fmt ) {
			case 'decimalZero':
				return sprintf( '%02d', $n );
			case 'lowerLetter':
			case 'upperLetter':
				$s = '';
				$x = max( 1, $n );
				while ( $x > 0 ) {
					$x--;
					$s = chr( 97 + ( $x % 26 ) ) . $s;
					$x = intdiv( $x, 26 );
				}
				return 'upperLetter' === $fmt ? strtoupper( $s ) : $s;
			case 'lowerRoman':
			case 'upperRoman':
				$map = array( 1000 => 'm', 900 => 'cm', 500 => 'd', 400 => 'cd', 100 => 'c', 90 => 'xc', 50 => 'l', 40 => 'xl', 10 => 'x', 9 => 'ix', 5 => 'v', 4 => 'iv', 1 => 'i' );
				$s   = '';
				$x   = max( 1, $n );
				foreach ( $map as $v => $r ) {
					while ( $x >= $v ) {
						$s .= $r;
						$x -= $v;
					}
				}
				return 'upperRoman' === $fmt ? strtoupper( $s ) : $s;
			default:
				return (string) $n;
		}
	}

	/* ───────────────────────── Runs ───────────────────────── */

	private function fmt_key( array $f ) {
		return wp_json_encode( array( ! empty( $f['b'] ), ! empty( $f['i'] ), ! empty( $f['u'] ), ! empty( $f['s'] ), isset( $f['va'] ) ? $f['va'] : '', isset( $f['color'] ) ? $f['color'] : '', isset( $f['sz'] ) ? $f['sz'] : 0, ! empty( $f['caps'] ), ! empty( $f['sc'] ), isset( $f['hl'] ) ? $f['hl'] : '' ) );
	}

	private function wrap( $html, array $f ) {
		if ( '' === $html ) {
			return '';
		}
		$st = array();
		if ( ! empty( $f['color'] ) ) {
			$st[] = 'color:' . $f['color'];
		}
		if ( ! empty( $f['hl'] ) ) {
			$st[] = 'background-color:' . $f['hl'];
		}
		if ( ! $this->in_heading && ! empty( $f['sz'] ) && abs( $f['sz'] - $this->base_sz ) >= 2 ) {
			$st[] = 'font-size:' . round( $f['sz'] / $this->base_sz, 2 ) . 'em';
		}
		if ( ! empty( $f['caps'] ) ) {
			$st[] = 'text-transform:uppercase';
		}
		$out = $html;
		if ( $st ) {
			$out = '<span style="' . esc_attr( implode( ';', $st ) ) . '">' . $out . '</span>';
		}
		if ( ! empty( $f['sc'] ) ) {
			$out = '<span style="font-variant:small-caps">' . $out . '</span>';
		}
		if ( ! empty( $f['u'] ) ) {
			$out = '<u>' . $out . '</u>';
		}
		if ( ! empty( $f['s'] ) ) {
			$out = '<s>' . $out . '</s>';
		}
		if ( ! empty( $f['i'] ) ) {
			$out = '<em>' . $out . '</em>';
		}
		if ( ! $this->in_heading && ! empty( $f['b'] ) ) {
			$out = '<strong>' . $out . '</strong>';
		}
		if ( ! empty( $f['va'] ) ) {
			$out = 'superscript' === $f['va'] ? '<sup>' . $out . '</sup>' : ( 'subscript' === $f['va'] ? '<sub>' . $out . '</sub>' : $out );
		}
		return $out;
	}

	private function text( $s ) {
		$s = esc_html( $s );
		return preg_replace( '/ {2,}/', ' &nbsp;', str_replace( "\xC2\xA0", '&nbsp;', $s ) );
	}

	/** Render the inline content of a paragraph (or hyperlink). */
	private function runs( \DOMNode $p, $sid, $use_style, $link = '' ) {
		$segs = array(); // [key, props, html]
		$this->collect_runs( $p, $sid, $use_style, $segs );
		$out = '';
		$i   = 0;
		$n   = count( $segs );
		while ( $i < $n ) {
			$key  = $segs[ $i ][0];
			$html = $segs[ $i ][2];
			$f    = $segs[ $i ][1];
			$j    = $i + 1;
			while ( $j < $n && $segs[ $j ][0] === $key && ! $segs[ $j ][3] && ! $segs[ $i ][3] ) {
				$html .= $segs[ $j ][2];
				$j++;
			}
			$out .= $segs[ $i ][3] ? $html : $this->wrap( $html, $f );
			$i    = $j;
		}
		return $out;
	}

	private function collect_runs( \DOMNode $node, $sid, $use_style, array &$segs, $href = '' ) {
		foreach ( $node->childNodes as $c ) {
			if ( XML_ELEMENT_NODE !== $c->nodeType || self::W !== $c->namespaceURI ) {
				continue;
			}
			switch ( $c->localName ) {
				case 'r':
					$f    = $this->eff_rpr( $c, $sid, $use_style );
					$html = $this->run_content( $c, $raw_ok );
					if ( '' === $html ) {
						break;
					}
					if ( ! $href && false !== strpos( $html, self::PB ) ) {
						foreach ( explode( self::PB, $html ) as $pi => $part ) {
							if ( $pi > 0 ) {
								$segs[] = array( 'pb' . count( $segs ), array(), self::PB, true );
							}
							if ( '' !== $part ) {
								$segs[] = array( $this->fmt_key( $f ), $f, $part, false !== strpos( $part, '<img' ) || false !== strpos( $part, '<!--hr-->' ) || false !== strpos( $part, '<br>' ) );
							}
						}
						break;
					}
					if ( $href ) {
						$segs[] = array( 'a' . count( $segs ), $f, '<a href="' . esc_url( $href ) . '" target="_blank" rel="noopener">' . $this->wrap( $html, $f ) . '</a>', true );
					} else {
						$raw = false !== strpos( $html, '<img' ) || false !== strpos( $html, self::PB ) || false !== strpos( $html, '<!--hr-->' ) || false !== strpos( $html, '<br>' );
						$segs[] = array( $this->fmt_key( $f ), $f, $html, $raw );
					}
					break;
				case 'hyperlink':
					$rid  = $c->getAttributeNS( self::R, 'id' );
					$url  = '';
					if ( $rid && isset( $this->rels[ $rid ] ) && 'External' === $this->rels[ $rid ]['mode'] && preg_match( '#^(https?://|mailto:|tel:)#i', $this->rels[ $rid ]['target'] ) ) {
						$url = $this->rels[ $rid ]['target'];
					}
					$this->collect_runs( $c, $sid, $use_style, $segs, $url );
					break;
				case 'ins':
				case 'smartTag':
				case 'fldSimple':
				case 'sdt':
					$inner = 'sdt' === $c->localName ? self::el( $c, 'sdtContent' ) : $c;
					if ( $inner ) {
						$this->collect_runs( $inner, $sid, $use_style, $segs, $href );
					}
					break;
			}
		}
	}

	private function run_content( \DOMElement $r, &$raw_ok = null ) {
		$out = '';
		foreach ( $r->childNodes as $c ) {
			if ( XML_ELEMENT_NODE !== $c->nodeType ) {
				continue;
			}
			if ( self::W !== $c->namespaceURI ) {
				if ( 'AlternateContent' === $c->localName ) {
					$this->has_textbox = $this->has_textbox || false !== strpos( $c->ownerDocument->saveXML( $c ), 'txbxContent' );
					$out .= $this->drawing_html( $c );
				}
				continue;
			}
			switch ( $c->localName ) {
				case 't':
					$out .= $this->text( $c->textContent );
					break;
				case 'tab':
				case 'ptab':
					$out .= '<span class="ct-tab">&emsp;&emsp;</span>';
					break;
				case 'br':
					$t = self::wa( $c, 'type' );
					if ( 'page' === $t ) {
						$out .= self::PB;
					} else {
						$out .= '<br>';
					}
					break;
				case 'cr':
					$out .= '<br>';
					break;
				case 'noBreakHyphen':
					$out .= '&#8209;';
					break;
				case 'drawing':
					$out .= $this->drawing_html( $c );
					break;
				case 'pict':
					$x = $c->ownerDocument->saveXML( $c );
					if ( false !== strpos( $x, 'o:hr="t"' ) || false !== strpos( $x, 'o:hr=\'t\'' ) || false !== strpos( $x, 'hrstd' ) ) {
						$out .= '<!--hr-->';
					} elseif ( false !== strpos( $x, 'txbxContent' ) ) {
						$this->has_textbox = true;
					}
					break;
			}
		}
		return $out;
	}

	/* ───────────────────────── Images ───────────────────────── */

	private function drawing_html( \DOMNode $d ) {
		$xml = $d->ownerDocument->saveXML( $d );
		if ( false !== strpos( $xml, 'txbxContent' ) && false === strpos( $xml, 'a:blip' ) ) {
			$this->has_textbox = true;
			return '';
		}
		$blip = null;
		foreach ( $d->getElementsByTagNameNS( self::A, 'blip' ) as $b ) {
			$blip = $b;
			break;
		}
		if ( ! $blip ) {
			return '';
		}
		$rid = $blip->getAttributeNS( self::R, 'embed' );
		if ( ! $rid || ! isset( $this->rels[ $rid ] ) || ! $this->import_images ) {
			if ( ! $this->import_images ) {
				$this->img_skipped++;
			}
			return '';
		}
		$target = ltrim( $this->rels[ $rid ]['target'], '/' );
		$path   = 0 === strpos( $target, 'word/' ) ? $target : 'word/' . $target;
		$ext    = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'png', 'jpg', 'jpeg', 'gif', 'webp' ), true ) || $this->img_ok >= 25 ) {
			$this->img_skipped++;
			return '';
		}
		$bytes = $this->zip->getFromName( $path );
		if ( false === $bytes || strlen( $bytes ) > 4194304 ) {
			$this->img_skipped++;
			return '';
		}
		$url = $this->save_image( $bytes, $ext );
		if ( ! $url ) {
			$this->img_skipped++;
			return '';
		}
		$this->img_ok++;
		$w = 0;
		foreach ( $d->getElementsByTagNameNS( self::WP, 'extent' ) as $e ) {
			$w = (int) round( (int) $e->getAttribute( 'cx' ) / 9525 );
			break;
		}
		$w   = $w > 0 ? min( 640, $w ) : 0;
		$alt = '';
		foreach ( $d->getElementsByTagNameNS( self::WP, 'docPr' ) as $e ) {
			$alt = $e->getAttribute( 'descr' );
			break;
		}
		return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '"' . ( $w ? ' width="' . $w . '"' : '' ) . ' style="max-width:100%;height:auto">';
	}

	private function save_image( $bytes, $ext ) {
		$hash = hash( 'sha256', $bytes );
		if ( isset( $this->uploaded[ $hash ] ) ) {
			return $this->uploaded[ $hash ];
		}
		$check = wp_check_filetype( 'x.' . $ext );
		if ( empty( $check['type'] ) ) {
			return '';
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$up = wp_upload_bits( 'contract-' . substr( $hash, 0, 12 ) . '.' . $ext, null, $bytes );
		if ( ! empty( $up['error'] ) ) {
			return '';
		}
		wp_insert_attachment( array( 'post_mime_type' => $check['type'], 'post_title' => 'Contract image ' . substr( $hash, 0, 8 ), 'post_status' => 'inherit' ), $up['file'] );
		$this->uploaded[ $hash ] = $up['url'];
		return $up['url'];
	}

	/* ───────────────────────── Tables ───────────────────────── */

	private function table_bordered( \DOMElement $tbl ) {
		$sides = array( 'top', 'left', 'bottom', 'right', 'insideH', 'insideV' );
		$check = function ( $borders ) use ( $sides ) {
			if ( ! $borders ) {
				return false;
			}
			foreach ( $sides as $s ) {
				if ( $x = self::el( $borders, $s ) ) {
					$v = self::wa( $x, 'val' );
					if ( $v && 'nil' !== $v && 'none' !== $v ) {
						return true;
					}
				}
			}
			return false;
		};
		$pr = self::el( $tbl, 'tblPr' );
		if ( $pr ) {
			if ( $check( self::el( $pr, 'tblBorders' ) ) ) {
				return true;
			}
			if ( $ts = self::el( $pr, 'tblStyle' ) ) {
				foreach ( $this->chain( self::wa( $ts, 'val' ) ) as $id ) {
					$tp = $this->styles[ $id ]['tbl'];
					if ( $tp && $check( self::el( $tp, 'tblBorders' ) ) ) {
						return true;
					}
				}
			}
		}
		foreach ( $tbl->getElementsByTagNameNS( self::W, 'tcBorders' ) as $b ) {
			if ( $check( $b ) ) {
				return true;
			}
		}
		return false;
	}

	private function table( \DOMElement $tbl ) {
		$grid = array();
		if ( $g = self::el( $tbl, 'tblGrid' ) ) {
			foreach ( $g->childNodes as $gc ) {
				if ( XML_ELEMENT_NODE === $gc->nodeType && 'gridCol' === $gc->localName ) {
					$grid[] = (int) self::wa( $gc, 'w' );
				}
			}
		}
		$gsum = array_sum( $grid );
		$rows = array();
		$open = array(); // grid column → [row, cell] that a vertical merge extends.
		foreach ( $tbl->childNodes as $tr ) {
			if ( XML_ELEMENT_NODE !== $tr->nodeType || 'tr' !== $tr->localName ) {
				continue;
			}
			$ri  = count( $rows );
			$col = 0;
			$row = array();
			foreach ( $tr->childNodes as $tc ) {
				if ( XML_ELEMENT_NODE !== $tc->nodeType || 'tc' !== $tc->localName ) {
					continue;
				}
				$pr   = self::el( $tc, 'tcPr' );
				$span = ( $pr && ( $x = self::el( $pr, 'gridSpan' ) ) ) ? max( 1, (int) self::wa( $x, 'val' ) ) : 1;
				$vm   = ( $pr && ( $x = self::el( $pr, 'vMerge' ) ) ) ? ( 'restart' === self::wa( $x, 'val' ) ? 'restart' : 'cont' ) : '';
				if ( 'cont' === $vm && isset( $open[ $col ] ) ) {
					$rows[ $open[ $col ][0] ][ $open[ $col ][1] ]['rowspan']++;
					$col += $span;
					continue;
				}
				$w = 0;
				if ( $pr && ( $x = self::el( $pr, 'tcW' ) ) && 'dxa' === self::wa( $x, 'type' ) ) {
					$w = (int) self::wa( $x, 'w' );
				} elseif ( $grid ) {
					for ( $k = $col; $k < $col + $span; $k++ ) {
						$w += isset( $grid[ $k ] ) ? $grid[ $k ] : 0;
					}
				}
				$fill = ( $pr && ( $x = self::el( $pr, 'shd' ) ) ) ? $this->color( self::wa( $x, 'fill' ) ) : '';
				if ( '#ffffff' === $fill ) {
					$fill = '';
				}
				$cell = array( 'html' => $this->blocks( $tc ), 'colspan' => $span, 'rowspan' => 1, 'w' => $w, 'fill' => $fill );
				$row[] = $cell;
				if ( 'restart' === $vm ) {
					$open[ $col ] = array( $ri, count( $row ) - 1 );
				} else {
					unset( $open[ $col ] );
				}
				$col += $span;
			}
			$rows[] = $row;
		}
		$tot = $gsum ? $gsum : 1;
		$out = '<table class="ct-table' . ( $this->table_bordered( $tbl ) ? ' ct-bordered' : '' ) . '"><tbody>';
		foreach ( $rows as $row ) {
			$out .= '<tr>';
			foreach ( $row as $c ) {
				$st = array();
				if ( $c['w'] && $gsum ) {
					$st[] = 'width:' . round( 100 * $c['w'] / $tot, 1 ) . '%';
				}
				if ( $c['fill'] ) {
					$st[] = 'background-color:' . $c['fill'];
				}
				$out .= '<td' . ( $c['colspan'] > 1 ? ' colspan="' . $c['colspan'] . '"' : '' ) . ( $c['rowspan'] > 1 ? ' rowspan="' . $c['rowspan'] . '"' : '' ) . ( $st ? ' style="' . esc_attr( implode( ';', $st ) ) . '"' : '' ) . '>' . ( '' === trim( $c['html'] ) ? '&nbsp;' : $c['html'] ) . '</td>';
			}
			$out .= '</tr>';
		}
		return $out . '</tbody></table>';
	}
}
