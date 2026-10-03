<?php
namespace BizyDash\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Old Word (.doc, Word 97–2003 binary) → clean HTML, in plain PHP (no converters are available on shared hosting).
 *
 * Reads the OLE container, the text "piece table", character and paragraph formatting runs, list definitions and
 * table structure. Carries over text, headings, bold/italic/underline/colour/size, alignment, indents, automatic
 * numbering (as real text), tables, bottom/top rules, page breaks, links and JPEG/PNG images.
 * Anything it cannot carry over is skipped quietly and the caller adds a "please check it over" notice.
 */
class Doc_Import {

	const PB = "\x01PB\x01";

	private $file;
	private $sector      = 512;
	private $mini        = 64;
	private $fat         = array();
	private $minifat     = array();
	private $ministream  = '';
	private $dir         = array();
	private $wd          = '';
	private $tbl         = '';
	private $data        = '';
	private $chpx        = array(); // [start, end, grpprl]
	private $papx        = array();
	private $styles      = array(); // istd => sti
	private $lists       = array(); // lsid => [levels]
	private $lfo         = array(); // ilfo (1-based) => lsid
	private $counters    = array();
	private $warnings    = array();
	private $title       = '';
	private $img_ok      = 0;
	private $img_skipped = 0;
	private $uploaded    = array();
	private $run_cache   = array();

	/** @return array{title:string,html:string,warnings:string[]}|\WP_Error */
	public static function from_file( $path, $fallback_title = '' ) {
		$bytes = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
		if ( false === $bytes || strlen( $bytes ) < 1024 ) {
			return new \WP_Error( 'bizydash_import', 'That file could not be read.', array( 'status' => 400 ) );
		}
		$i = new self();
		return $i->run( $bytes, $fallback_title );
	}

	private static function err( $msg ) {
		return new \WP_Error( 'bizydash_import', $msg, array( 'status' => 400 ) );
	}

	/* ───────────────────────── Binary helpers ───────────────────────── */

	private static function u8( $s, $o ) {
		return $o < strlen( $s ) ? ord( $s[ $o ] ) : 0;
	}

	private static function u16( $s, $o ) {
		return $o + 2 <= strlen( $s ) ? unpack( 'v', substr( $s, $o, 2 ) )[1] : 0;
	}

	private static function s16( $s, $o ) {
		$v = self::u16( $s, $o );
		return $v >= 0x8000 ? $v - 0x10000 : $v;
	}

	private static function u32( $s, $o ) {
		return $o + 4 <= strlen( $s ) ? unpack( 'V', substr( $s, $o, 4 ) )[1] : 0;
	}

	/* ───────────────────────── OLE compound file ───────────────────────── */

	private function open_cfb( $b ) {
		if ( "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" !== substr( $b, 0, 8 ) ) {
			return false;
		}
		$this->file   = $b;
		$this->sector = 1 << self::u16( $b, 0x1E );
		$this->mini   = 1 << self::u16( $b, 0x20 );
		if ( $this->sector < 128 || $this->sector > 4096 ) {
			return false;
		}
		$difat = array();
		for ( $i = 0; $i < 109; $i++ ) {
			$difat[] = self::u32( $b, 0x4C + 4 * $i );
		}
		$next = self::u32( $b, 0x44 );
		$cnt  = self::u32( $b, 0x48 );
		$per  = $this->sector / 4 - 1;
		for ( $k = 0; $k < $cnt && $next < 0xFFFFFFFA && $k < 200; $k++ ) {
			$s = $this->sec( $next );
			$d = array_values( unpack( 'V*', $s ) );
			for ( $j = 0; $j < $per; $j++ ) {
				$difat[] = $d[ $j ];
			}
			$next = $d[ $per ];
		}
		foreach ( $difat as $sid ) {
			if ( $sid >= 0xFFFFFFFA ) {
				continue;
			}
			foreach ( unpack( 'V*', $this->sec( $sid ) ) as $v ) {
				$this->fat[] = $v;
			}
		}
		$dir = $this->chain( self::u32( $b, 0x30 ) );
		for ( $o = 0; $o + 128 <= strlen( $dir ); $o += 128 ) {
			$len  = min( 64, self::u16( $dir, $o + 0x40 ) );
			$name = $len > 2 ? mb_convert_encoding( substr( $dir, $o, $len - 2 ), 'UTF-8', 'UTF-16LE' ) : '';
			$this->dir[] = array( 'name' => $name, 'type' => self::u8( $dir, $o + 0x42 ), 'start' => self::u32( $dir, $o + 0x74 ), 'size' => self::u32( $dir, $o + 0x78 ) );
		}
		if ( empty( $this->dir ) ) {
			return false;
		}
		$ms = self::u32( $b, 0x3C );
		if ( $ms < 0xFFFFFFFA ) {
			foreach ( unpack( 'V*', $this->chain( $ms ) . '' ) ?: array() as $v ) {
				$this->minifat[] = $v;
			}
		}
		$root = $this->dir[0];
		if ( $root['start'] < 0xFFFFFFFA ) {
			$this->ministream = substr( $this->chain( $root['start'] ), 0, $root['size'] );
		}
		return true;
	}

	private function sec( $n ) {
		return substr( $this->file, ( $n + 1 ) * $this->sector, $this->sector );
	}

	private function chain( $start ) {
		$out = '';
		$n   = 0;
		$max = count( $this->fat ) + 8;
		while ( $start < 0xFFFFFFFA && $n++ < $max ) {
			$out  .= $this->sec( $start );
			$start = isset( $this->fat[ $start ] ) ? $this->fat[ $start ] : 0xFFFFFFFE;
		}
		return $out;
	}

	private function stream( $name ) {
		foreach ( $this->dir as $i => $e ) {
			if ( 2 === $e['type'] && 0 === strcasecmp( $e['name'], $name ) ) {
				if ( $e['size'] < 4096 ) {
					$out = '';
					$s   = $e['start'];
					$n   = 0;
					while ( $s < 0xFFFFFFFA && $n++ < 100000 ) {
						$out .= substr( $this->ministream, $s * $this->mini, $this->mini );
						$s    = isset( $this->minifat[ $s - 0 ] ) ? $this->minifat[ $s ] : 0xFFFFFFFE;
					}
					return substr( $out, 0, $e['size'] );
				}
				return substr( $this->chain( $e['start'] ), 0, $e['size'] );
			}
		}
		return '';
	}

	/* ───────────────────────── Run ───────────────────────── */

	private function run( $bytes, $fallback_title ) {
		if ( ! $this->open_cfb( $bytes ) ) {
			if ( 0 === strpos( ltrim( $bytes ), '{\\rtf' ) ) {
				return self::err( 'That is an RTF file. Open it in Word and Save As “Word Document (.docx)”, then import it.' );
			}
			return self::err( 'That does not look like a Word document.' );
		}
		$this->wd = $this->stream( 'WordDocument' );
		if ( strlen( $this->wd ) < 0x200 || 0xA5EC !== self::u16( $this->wd, 0 ) ) {
			return self::err( 'That does not look like a Word document.' );
		}
		$flags = self::u16( $this->wd, 0x0A );
		if ( $flags & 0x0100 ) {
			return self::err( 'That document is password-protected. Remove the password in Word, then import it.' );
		}
		if ( self::u16( $this->wd, 2 ) < 0xC1 ) {
			return self::err( 'That is a very old Word format (Word 6/95). Open it in Word and Save As “Word Document (.docx)”, then import it.' );
		}
		$this->tbl  = $this->stream( ( $flags & 0x0200 ) ? '1Table' : '0Table' );
		$this->data = $this->stream( 'Data' );
		if ( '' === $this->tbl ) {
			return self::err( 'The document is damaged or uses an unsupported layout.' );
		}
		$ccp_text = self::u32( $this->wd, 0x4C );

		$this->read_styles();
		$this->read_lists();
		$this->read_bte( self::u32( $this->wd, 0xFA ), self::u32( $this->wd, 0xFE ), $this->chpx, false );
		$this->read_bte( self::u32( $this->wd, 0x102 ), self::u32( $this->wd, 0x106 ), $this->papx, true );
		$chars = $this->read_text( self::u32( $this->wd, 0x1A2 ), self::u32( $this->wd, 0x1A6 ), $ccp_text );
		if ( ! $chars ) {
			return self::err( 'No text was found in that document.' );
		}
		$html = $this->build( $chars );
		if ( $this->img_skipped ) {
			$this->warnings[] = sprintf( '%d image(s) could not be imported (unsupported type).', $this->img_skipped );
		}
		$this->warnings[] = 'Imported from an older .doc file — formatting is carried over as closely as possible, but please read it through. Saving it as .docx in Word first gives the most exact result.';
		$html = $this->tidy( $html );
		if ( '' === trim( wp_strip_all_tags( $html, true ) ) && false === strpos( $html, '<img' ) ) {
			return self::err( 'No text was found in that document.' );
		}
		return array(
			'title'    => $this->title ? mb_substr( $this->title, 0, 180 ) : $fallback_title,
			'html'     => wp_kses_post( $html ),
			'warnings' => array_values( array_unique( $this->warnings ) ),
		);
	}

	private function tidy( $html ) {
		$empty = '<p>&nbsp;</p>';
		$html  = preg_replace( '/(' . preg_quote( $empty, '/' ) . '\s*){3,}/', $empty . $empty, $html );
		$html  = preg_replace( '/^(\s*' . preg_quote( $empty, '/' ) . ')+/', '', $html );
		$html  = preg_replace( '/(' . preg_quote( $empty, '/' ) . '\s*)+$/', '', $html );
		return trim( $html );
	}

	/* ───────────────────────── Structures ───────────────────────── */

	private function read_styles() {
		$fc  = self::u32( $this->wd, 0xA2 );
		$lcb = self::u32( $this->wd, 0xA6 );
		if ( ! $lcb ) {
			return;
		}
		$s     = substr( $this->tbl, $fc, $lcb );
		$cbshi = self::u16( $s, 0 );
		$cstd  = self::u16( $s, 2 );
		$pos   = 2 + $cbshi;
		for ( $i = 0; $i < $cstd && $pos + 2 <= strlen( $s ); $i++ ) {
			$cb = self::u16( $s, $pos );
			if ( $cb >= 2 ) {
				$this->styles[ $i ] = self::u16( $s, $pos + 2 ) & 0x0FFF;
			}
			$pos += 2 + $cb;
		}
	}

	private function read_lists() {
		$fc  = self::u32( $this->wd, 0x2E2 );
		$lcb = self::u32( $this->wd, 0x2E6 );
		if ( $lcb > 2 ) {
			$s    = substr( $this->tbl, $fc ); // the level definitions follow the list array, outside lcb.
			$c    = self::u16( $s, 0 );
			$lsts = array();
			for ( $i = 0; $i < $c; $i++ ) {
				$o      = 2 + 28 * $i;
				$lsts[] = array( 'lsid' => self::u32( $s, $o ), 'simple' => (bool) ( self::u8( $s, $o + 26 ) & 1 ) );
			}
			$pos = 2 + 28 * $c;
			foreach ( $lsts as $l ) {
				$levels = array();
				$n      = $l['simple'] ? 1 : 9;
				for ( $k = 0; $k < $n && $pos + 28 <= strlen( $s ); $k++ ) {
					$cbchp = self::u8( $s, $pos + 24 );
					$cbpap = self::u8( $s, $pos + 25 );
					$lv    = array(
						'start' => self::u32( $s, $pos ) ?: 1,
						'nfc'   => self::u8( $s, $pos + 4 ),
						'left'  => 720 * ( $k + 1 ),
						'hang'  => 360,
					);
					$pap   = substr( $s, $pos + 28, $cbpap );
					foreach ( $this->sprms( $pap ) as $sp ) {
						if ( 0x840F === $sp[0] || 0x845E === $sp[0] ) {
							$lv['left'] = self::s16( $sp[1], 0 );
						} elseif ( 0x8411 === $sp[0] || 0x8460 === $sp[0] ) {
							$lv['hang'] = -self::s16( $sp[1], 0 );
						}
					}
					$o  = $pos + 28 + $cbpap + $cbchp;
					$cc = self::u16( $s, $o );
					$tx = '';
					for ( $j = 0; $j < $cc; $j++ ) {
						$u   = self::u16( $s, $o + 2 + 2 * $j );
						$tx .= $u < 9 ? chr( $u ) : ( function_exists( 'mb_chr' ) ? mb_chr( $u, 'UTF-8' ) : '' );
					}
					$lv['text'] = $tx;
					$levels[]   = $lv;
					$pos        = $o + 2 + 2 * $cc;
				}
				$this->lists[ $l['lsid'] ] = $levels;
			}
		}
		$fc  = self::u32( $this->wd, 0x2EA );
		$lcb = self::u32( $this->wd, 0x2EE );
		if ( $lcb >= 4 ) {
			$s = substr( $this->tbl, $fc, $lcb );
			$n = min( self::u32( $s, 0 ), 5000 );
			for ( $i = 0; $i < $n; $i++ ) {
				$this->lfo[ $i + 1 ] = self::u32( $s, 4 + 16 * $i );
			}
		}
	}

	private function read_bte( $fc, $lcb, array &$out, $para ) {
		if ( $lcb < 12 ) {
			return;
		}
		$plc = substr( $this->tbl, $fc, $lcb );
		$n   = intdiv( $lcb - 4, 8 );
		for ( $i = 0; $i < $n; $i++ ) {
			$pn   = self::u32( $plc, 4 * ( $n + 1 ) + 4 * $i ) & 0x3FFFFF;
			$page = substr( $this->wd, $pn * 512, 512 );
			if ( strlen( $page ) < 512 ) {
				continue;
			}
			$cnt = self::u8( $page, 511 );
			for ( $j = 0; $j < $cnt; $j++ ) {
				$start = self::u32( $page, 4 * $j );
				$end   = self::u32( $page, 4 * ( $j + 1 ) );
				if ( $para ) {
					$off = self::u8( $page, 4 * ( $cnt + 1 ) + 13 * $j ) * 2;
					if ( ! $off ) {
						continue;
					}
					$cb = self::u8( $page, $off );
					if ( 0 === $cb ) {
						$size = self::u8( $page, $off + 1 ) * 2;
						$d    = substr( $page, $off + 2, $size );
					} else {
						$d = substr( $page, $off + 1, $cb * 2 - 1 );
					}
					$out[] = array( $start, $end, $d );
				} else {
					$off = self::u8( $page, 4 * ( $cnt + 1 ) + $j ) * 2;
					if ( ! $off ) {
						continue;
					}
					$cb    = self::u8( $page, $off );
					$out[] = array( $start, $end, substr( $page, $off + 1, $cb ) );
				}
			}
		}
	}

	/** @return array<int,array{0:string,1:int}> characters with their file offsets */
	private function read_text( $fc, $lcb, $ccp ) {
		$clx = substr( $this->tbl, $fc, $lcb );
		$pos = 0;
		$plc = '';
		while ( $pos < strlen( $clx ) ) {
			$t = self::u8( $clx, $pos );
			if ( 1 === $t ) {
				$pos += 3 + self::u16( $clx, $pos + 1 );
			} elseif ( 2 === $t ) {
				$len = self::u32( $clx, $pos + 1 );
				$plc = substr( $clx, $pos + 5, $len );
				break;
			} else {
				break;
			}
		}
		if ( strlen( $plc ) < 16 ) {
			return array();
		}
		$n   = intdiv( strlen( $plc ) - 4, 12 );
		$out = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$cp0 = self::u32( $plc, 4 * $i );
			$cp1 = min( self::u32( $plc, 4 * ( $i + 1 ) ), $ccp );
			if ( $cp0 >= $ccp ) {
				break;
			}
			$fcv  = self::u32( $plc, 4 * ( $n + 1 ) + 8 * $i + 2 );
			$comp = (bool) ( $fcv & 0x40000000 );
			$base = $comp ? ( $fcv & 0x3FFFFFFF ) >> 1 : $fcv;
			$len  = $cp1 - $cp0;
			if ( $comp ) {
				$raw = substr( $this->wd, $base, $len );
				$u   = mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' );
				$arr = preg_split( '//u', $u, -1, PREG_SPLIT_NO_EMPTY );
				foreach ( $arr as $k => $ch ) {
					$out[] = array( $ch, $base + $k );
				}
			} else {
				$raw = substr( $this->wd, $base, $len * 2 );
				$us  = array_values( unpack( 'v*', $raw ) ?: array() );
				$m   = count( $us );
				for ( $k = 0; $k < $m; $k++ ) {
					$u = $us[ $k ];
					if ( $u >= 0xD800 && $u < 0xDC00 && $k + 1 < $m ) {
						$low = $us[ $k + 1 ];
						$ch  = mb_chr( 0x10000 + ( ( $u - 0xD800 ) << 10 ) + ( $low - 0xDC00 ), 'UTF-8' );
						$out[] = array( $ch, $base + 2 * $k );
						$out[] = array( '', $base + 2 * $k + 2 );
						$k++;
					} elseif ( $u >= 0xD800 && $u < 0xE000 ) {
						$out[] = array( '', $base + 2 * $k );
					} else {
						$out[] = array( mb_chr( $u, 'UTF-8' ), $base + 2 * $k );
					}
				}
			}
		}
		return $out;
	}

	/* ───────────────────────── SPRMs ───────────────────────── */

	/** @return array<int,array{0:int,1:string}> */
	private function sprms( $g ) {
		$out = array();
		$i   = 0;
		$n   = strlen( $g );
		while ( $i + 2 <= $n ) {
			$op   = self::u16( $g, $i );
			$i   += 2;
			$spra = $op >> 13;
			switch ( $spra ) {
				case 0:
				case 1:
					$sz = 1;
					break;
				case 2:
				case 4:
				case 5:
					$sz = 2;
					break;
				case 3:
					$sz = 4;
					break;
				case 7:
					$sz = 3;
					break;
				default:
					if ( 0xD608 === $op ) {
						$sz = self::u16( $g, $i ) + 1;
						$out[] = array( $op, substr( $g, $i + 2, max( 0, $sz - 2 ) ) );
						$i += $sz;
						continue 2;
					}
					$sz = self::u8( $g, $i );
					if ( 0xC615 === $op && 255 === $sz ) {
						$del = self::u8( $g, $i + 1 );
						$add = self::u8( $g, $i + 2 + 4 * $del );
						$sz  = 1 + 4 * $del + 1 + 3 * $add;
					}
					$out[] = array( $op, substr( $g, $i + 1, $sz ) );
					$i    += 1 + $sz;
					continue 2;
			}
			$out[] = array( $op, substr( $g, $i, $sz ) );
			$i    += $sz;
		}
		return $out;
	}

	private function find_run( array $runs, $fc ) {
		$lo = 0;
		$hi = count( $runs ) - 1;
		while ( $lo <= $hi ) {
			$mid = ( $lo + $hi ) >> 1;
			if ( $fc < $runs[ $mid ][0] ) {
				$hi = $mid - 1;
			} elseif ( $fc >= $runs[ $mid ][1] ) {
				$lo = $mid + 1;
			} else {
				return $mid;
			}
		}
		return -1;
	}

	private function ico( $i ) {
		$map = array( 1 => '', 2 => '#0000ff', 3 => '#00a0b0', 4 => '#008000', 5 => '#b000b0', 6 => '#d00000', 7 => '#b08000', 8 => '', 9 => '#000080', 10 => '#006060', 11 => '#006000', 12 => '#600060', 13 => '#800000', 14 => '#606000', 15 => '#606060', 16 => '#909090' );
		return isset( $map[ $i ] ) ? $map[ $i ] : '';
	}

	private function chp_props( $idx ) {
		if ( isset( $this->run_cache[ $idx ] ) ) {
			return $this->run_cache[ $idx ];
		}
		$f = array();
		if ( $idx >= 0 ) {
			foreach ( $this->sprms( $this->chpx[ $idx ][2] ) as $sp ) {
				$v = ord( $sp[1] ? $sp[1][0] : "\0" );
				switch ( $sp[0] ) {
					case 0x0835:
						$f['b'] = ( 1 === $v || 0x81 === $v );
						break;
					case 0x0836:
						$f['i'] = ( 1 === $v || 0x81 === $v );
						break;
					case 0x0837:
						$f['s'] = ( 1 === $v );
						break;
					case 0x083B:
						$f['caps'] = ( 1 === $v );
						break;
					case 0x083A:
						$f['sc'] = ( 1 === $v );
						break;
					case 0x2A3E:
						$f['u'] = ( $v > 0 );
						break;
					case 0x4A43:
						$f['sz'] = self::u16( $sp[1], 0 );
						break;
					case 0x2A42:
						$f['color'] = $this->ico( $v );
						break;
					case 0x6870:
						$c = sprintf( '#%02x%02x%02x', self::u8( $sp[1], 0 ), self::u8( $sp[1], 1 ), self::u8( $sp[1], 2 ) );
						$f['color'] = '#000000' === $c ? '' : $c;
						break;
					case 0x2A48:
						$f['va'] = 1 === $v ? 'sup' : ( 2 === $v ? 'sub' : '' );
						break;
					case 0x2A0C:
						$map     = array( 7 => '#fff3a3', 4 => '#c8f7c5', 3 => '#c9f1f7', 5 => '#f7c9ec' );
						$f['hl'] = isset( $map[ $v ] ) ? $map[ $v ] : '';
						break;
					case 0x0855:
						$f['spec'] = ( 1 === $v );
						break;
					case 0x6A03:
						$f['pic'] = self::u32( $sp[1], 0 );
						break;
				}
			}
		}
		$this->run_cache[ $idx ] = $f;
		return $f;
	}

	private function pap_props( $fc ) {
		$idx = $this->find_run( $this->papx, $fc );
		$p   = array( 'istd' => 0 );
		if ( $idx < 0 ) {
			return $p;
		}
		$d       = $this->papx[ $idx ][2];
		$p['istd'] = self::u16( $d, 0 );
		foreach ( $this->sprms( substr( $d, 2 ) ) as $sp ) {
			$v = ord( $sp[1] ? $sp[1][0] : "\0" );
			switch ( $sp[0] ) {
				case 0x2461:
				case 0x2403:
					$p['jc'] = array( 0 => '', 1 => 'center', 2 => 'right', 3 => 'justify', 4 => 'justify' )[ $v ] ?? '';
					break;
				case 0x845E:
				case 0x840F:
					$p['left'] = self::s16( $sp[1], 0 );
					break;
				case 0x8460:
				case 0x8411:
					$p['first'] = self::s16( $sp[1], 0 );
					break;
				case 0x260A:
					$p['ilvl'] = $v;
					break;
				case 0x460B:
					$p['ilfo'] = self::u16( $sp[1], 0 );
					break;
				case 0x2416:
					$p['intbl'] = ( 1 === $v );
					break;
				case 0x2417:
					$p['ttp'] = ( 1 === $v );
					break;
				case 0x2407:
					$p['pbb'] = ( 1 === $v );
					break;
				case 0x6426:
					$p['b_bottom'] = ( self::u8( $sp[1], 1 ) > 0 && 255 !== self::u8( $sp[1], 1 ) );
					$p['b_thick']  = self::u8( $sp[1], 0 ) >= 12;
					break;
				case 0xC653:
					$p['b_bottom'] = ( self::u8( $sp[1], 5 ) > 0 && 255 !== self::u8( $sp[1], 5 ) );
					$p['b_thick']  = self::u8( $sp[1], 4 ) >= 12;
					break;
				case 0x6424:
					$p['b_top'] = ( self::u8( $sp[1], 1 ) > 0 && 255 !== self::u8( $sp[1], 1 ) );
					break;
				case 0xC650:
					$p['b_top'] = ( self::u8( $sp[1], 5 ) > 0 && 255 !== self::u8( $sp[1], 5 ) );
					break;
				case 0xD608:
					$p['tdef'] = $sp[1];
					break;
				case 0xD613: // table borders: six BRC80 (top, left, bottom, right, inside H, inside V)
					for ( $b = 0; $b < 6; $b++ ) {
						$ty = self::u8( $sp[1], 4 * $b + 1 );
						if ( $ty > 0 && 255 !== $ty ) {
							$p['tbord'] = true;
						}
					}
					break;
				case 0xD620: // set border on a range of cells
					$ty = self::u8( $sp[1], 4 );
					if ( $ty > 0 && 255 !== $ty ) {
						$p['tbord'] = true;
					}
					break;
			}
		}
		return $p;
	}

	/* ───────────────────────── Build ───────────────────────── */

	private function build( array $chars ) {
		$blocks = array();
		$seg    = array(); // [text, chpxIdx, href]
		$field  = array(); // stack of [state(code|result), code text, href]
		$table  = null;    // [rows, cells, cell_html, tdef]
		$first  = true;

		$flush_table = function () use ( &$table, &$blocks ) {
			if ( $table ) {
				$blocks[] = $this->emit_table( $table );
				$table    = null;
			}
		};
		$n = count( $chars );
		for ( $i = 0; $i < $n; $i++ ) {
			list( $ch, $fc ) = $chars[ $i ];
			if ( '' === $ch ) {
				continue;
			}
			$code = function_exists( 'mb_ord' ) ? mb_ord( $ch, 'UTF-8' ) : ord( $ch );
			$ci   = $this->find_run( $this->chpx, $fc );
			$f    = $this->chp_props( $ci );

			// Fields: hide instruction text, keep the result; make HYPERLINK results clickable.
			if ( 0x13 === $code ) {
				$field[] = array( 'code', '', '' );
				continue;
			}
			if ( 0x14 === $code && $field ) {
				$top = &$field[ count( $field ) - 1 ];
				if ( preg_match( '/HYPERLINK\s+"([^"]+)"/i', $top[1], $m ) && preg_match( '#^(https?://|mailto:|tel:)#i', $m[1] ) ) {
					$top[2] = $m[1];
				}
				$top[0] = 'result';
				unset( $top );
				continue;
			}
			if ( 0x15 === $code && $field ) {
				array_pop( $field );
				continue;
			}
			if ( $field ) {
				$top = &$field[ count( $field ) - 1 ];
				if ( 'code' === $top[0] ) {
					$top[1] .= $ch;
					unset( $top );
					continue;
				}
				unset( $top );
			}
			$href = '';
			foreach ( $field as $fl ) {
				if ( $fl[2] ) {
					$href = $fl[2];
				}
			}

			if ( 0x0D === $code || 0x07 === $code ) {
				$pp  = $this->pap_props( $fc );
				$end = $code;
				$h   = $this->para( $seg, $pp, $first );
				$seg = array();
				if ( ! empty( $pp['intbl'] ) || ! empty( $pp['ttp'] ) || 0x07 === $end ) {
					if ( ! $table ) {
						$table = array( 'rows' => array(), 'cells' => array(), 'cell' => '', 'tdef' => '' );
					}
					if ( ! empty( $pp['ttp'] ) ) {
						$table['rows'][] = array( 'cells' => $table['cells'], 'tdef' => isset( $pp['tdef'] ) ? $pp['tdef'] : '', 'bord' => ! empty( $pp['tbord'] ) );
						$table['cells']  = array();
						$table['cell']   = '';
						continue;
					}
					$table['cell'] .= $h;
					if ( 0x07 === $end ) {
						$table['cells'][] = $table['cell'];
						$table['cell']    = '';
					}
					continue;
				}
				$flush_table();
				if ( '' !== $h ) {
					$first    = false;
					$blocks[] = $h;
				}
				continue;
			}
			if ( 0x0C === $code ) {
				$seg[] = array( self::PB, -2, '' );
				continue;
			}
			if ( 0x0B === $code ) {
				$seg[] = array( '<br>', -3, '' );
				continue;
			}
			if ( 0x09 === $code ) {
				$seg[] = array( '<span class="ct-tab">&emsp;&emsp;</span>', -3, '' );
				continue;
			}
			if ( 0x01 === $code ) {
				if ( ! empty( $f['pic'] ) ) {
					$img = $this->picture( $f['pic'] );
					if ( $img ) {
						$seg[] = array( $img, -3, '' );
					}
				}
				continue;
			}
			if ( $code < 0x20 && ! in_array( $code, array( 0x1E ), true ) ) {
				continue; // footnote/annotation marks, drawn-object anchors, optional hyphen…
			}
			if ( 0x1E === $code ) {
				$seg[] = array( '&#8209;', -3, '' );
				continue;
			}
			$seg[] = array( $ch, $ci, $href );
		}
		if ( $seg ) {
			$pp = array( 'istd' => 0 );
			$h  = $this->para( $seg, $pp, $first );
			if ( '' !== $h ) {
				$blocks[] = $h;
			}
		}
		$flush_table();
		return implode( "\n", $blocks );
	}

	/* ───────────────────────── Paragraphs ───────────────────────── */

	private function fmt_wrap( $html, array $f, $heading ) {
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
		if ( ! $heading && ! empty( $f['sz'] ) && abs( $f['sz'] - 22 ) > 3 ) {
			$st[] = 'font-size:' . round( $f['sz'] / 22, 2 ) . 'em';
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
		if ( ! $heading && ! empty( $f['b'] ) ) {
			$out = '<strong>' . $out . '</strong>';
		}
		if ( ! empty( $f['va'] ) ) {
			$out = 'sup' === $f['va'] ? '<sup>' . $out . '</sup>' : '<sub>' . $out . '</sub>';
		}
		return $out;
	}

	private function inline( array $segs, $heading ) {
		$out = '';
		$n   = count( $segs );
		$i   = 0;
		while ( $i < $n ) {
			list( $t, $ci, $href ) = $segs[ $i ];
			if ( $ci < 0 ) { // pre-rendered (page break, <br>, tab, image)
				$out .= $t;
				$i++;
				continue;
			}
			$txt = $t;
			$j   = $i + 1;
			while ( $j < $n && $segs[ $j ][1] === $ci && $segs[ $j ][2] === $href ) {
				$txt .= $segs[ $j ][0];
				$j++;
			}
			$html = esc_html( $txt );
			$html = preg_replace( '/ {2,}/', ' &nbsp;', str_replace( "\xC2\xA0", '&nbsp;', $html ) );
			$html = $this->fmt_wrap( $html, $this->chp_props( $ci ), $heading );
			if ( $href ) {
				$html = '<a href="' . esc_url( $href ) . '" target="_blank" rel="noopener">' . $html . '</a>';
			}
			$out .= $html;
			$i    = $j;
		}
		return $out;
	}

	private function label( $lsid, $il ) {
		if ( ! isset( $this->lists[ $lsid ] ) ) {
			return array( '', 0, 0 );
		}
		$lv  = $this->lists[ $lsid ];
		if ( ! $lv ) {
			return array( '', 0, 0 );
		}
		$il  = min( $il, count( $lv ) - 1 );
		$c   = &$this->counters[ $lsid ];
		if ( ! is_array( $c ) ) {
			$c = array();
		}
		if ( ! isset( $c[ $il ] ) ) {
			$c[ $il ] = $lv[ $il ]['start'] - 1;
		}
		$c[ $il ]++;
		foreach ( array_keys( $c ) as $k ) {
			if ( $k > $il ) {
				unset( $c[ $k ] );
			}
		}
		$L = $lv[ $il ];
		if ( 23 === $L['nfc'] ) {
			$t  = $L['text'];
			$cp = '' !== $t && function_exists( 'mb_ord' ) ? mb_ord( mb_substr( $t, 0, 1 ), 'UTF-8' ) : 0;
			$lab = ( $cp >= 0xE000 && $cp <= 0xF8FF ) || '' === $t ? ( $il % 2 ? '◦' : '•' ) : ( 'o' === $t ? '◦' : $t );
			return array( $lab, $L['left'], $L['hang'] );
		}
		if ( 255 === $L['nfc'] ) {
			return array( '', $L['left'], $L['hang'] );
		}
		$text = '';
		$len  = strlen( $L['text'] );
		for ( $k = 0; $k < $len; $k++ ) {
			$o = ord( $L['text'][ $k ] );
			if ( $o < 9 && "\xC2" !== $L['text'][ $k ] ) {
				$lvl = $lv[ min( $o, count( $lv ) - 1 ) ];
				$val = isset( $c[ $o ] ) ? $c[ $o ] : $lvl['start'];
				$text .= $this->num( $val, $lvl['nfc'] );
			} else {
				$text .= $L['text'][ $k ];
			}
		}
		return array( $text, $L['left'], $L['hang'] );
	}

	private function num( $n, $nfc ) {
		$n = max( 1, (int) $n );
		switch ( $nfc ) {
			case 22:
				return sprintf( '%02d', $n );
			case 3:
			case 4:
				$s = '';
				$x = $n;
				while ( $x > 0 ) {
					$x--;
					$s = chr( 97 + ( $x % 26 ) ) . $s;
					$x = intdiv( $x, 26 );
				}
				return 3 === $nfc ? strtoupper( $s ) : $s;
			case 1:
			case 2:
				$map = array( 1000 => 'm', 900 => 'cm', 500 => 'd', 400 => 'cd', 100 => 'c', 90 => 'xc', 50 => 'l', 40 => 'xl', 10 => 'x', 9 => 'ix', 5 => 'v', 4 => 'iv', 1 => 'i' );
				$s   = '';
				$x   = $n;
				foreach ( $map as $v => $r ) {
					while ( $x >= $v ) {
						$s .= $r;
						$x -= $v;
					}
				}
				return 1 === $nfc ? strtoupper( $s ) : $s;
			default:
				return (string) $n;
		}
	}

	private function para( array $segs, array $pp, $first ) {
		$sti   = isset( $this->styles[ $pp['istd'] ] ) ? $this->styles[ $pp['istd'] ] : 0;
		$level = ( $sti >= 1 && $sti <= 9 ) ? min( 4, $sti + 1 ) : 0;
		$title = ( 62 === $sti );
		$inner = $this->inline( $segs, (bool) ( $level || $title ) );
		$plain = trim( str_replace( array( self::PB, '&nbsp;' ), ' ', wp_strip_all_tags( $inner ) ) );

		if ( $title && '' !== $plain && '' === $this->title ) {
			$this->title = html_entity_decode( $plain, ENT_QUOTES, 'UTF-8' );
			return '';
		}
		$pre = ! empty( $pp['pbb'] ) ? '<hr class="bd-pagebreak">' : '';

		if ( '' === $plain && false === strpos( $inner, '<img' ) ) {
			if ( ! empty( $pp['b_bottom'] ) || ! empty( $pp['b_top'] ) ) {
				return $pre . '<hr class="ct-rule' . ( ! empty( $pp['b_thick'] ) ? ' ct-rule--thick' : '' ) . '">';
			}
			if ( false !== strpos( $inner, self::PB ) ) {
				return $pre . str_replace( self::PB, '<hr class="bd-pagebreak">', $inner );
			}
			return $pre . '<p>&nbsp;</p>';
		}

		$style = array();
		if ( ! empty( $pp['jc'] ) ) {
			$style[] = 'text-align:' . $pp['jc'];
		}
		if ( ! empty( $pp['b_bottom'] ) ) {
			$style[] = 'border-bottom:' . ( ! empty( $pp['b_thick'] ) ? 2 : 1 ) . 'px solid #222530;padding-bottom:4px';
		}
		if ( ! empty( $pp['b_top'] ) ) {
			$style[] = 'border-top:1px solid #222530;padding-top:4px';
		}

		$lab  = '';
		$list = false;
		$lpx  = 0;
		$hpx  = 0;
		if ( ! empty( $pp['ilfo'] ) && isset( $this->lfo[ $pp['ilfo'] ] ) ) {
			list( $lab, $ll, $lh ) = $this->label( $this->lfo[ $pp['ilfo'] ], isset( $pp['ilvl'] ) ? $pp['ilvl'] : 0 );
			$list = true;
			$left = isset( $pp['left'] ) ? $pp['left'] : $ll;
			$hang = isset( $pp['first'] ) && $pp['first'] < 0 ? -$pp['first'] : $lh;
			$lpx  = max( 0, min( 360, (int) round( ( $left - $hang ) / 15 ) ) );
			$hpx  = max( 18, min( 90, (int) round( $hang / 15 ) ) );
		} else {
			if ( ! empty( $pp['left'] ) && $pp['left'] > 0 ) {
				$style[] = 'margin-left:' . min( 360, (int) round( $pp['left'] / 15 ) ) . 'px';
			}
			if ( ! empty( $pp['first'] ) && $pp['first'] > 0 ) {
				$style[] = 'text-indent:' . min( 90, (int) round( $pp['first'] / 15 ) ) . 'px';
			}
		}

		$html = '';
		foreach ( explode( self::PB, $inner ) as $k => $seg ) {
			if ( $k > 0 ) {
				$html .= '<hr class="bd-pagebreak">';
			}
			if ( '' === trim( str_replace( '&nbsp;', ' ', wp_strip_all_tags( $seg ) ) ) && false === strpos( $seg, '<img' ) ) {
				continue;
			}
			$st = $style ? ' style="' . esc_attr( implode( ';', $style ) ) . '"' : '';
			if ( $level ) {
				$l     = ( 0 === $k && $lab ) ? '<span class="ct-hnum">' . esc_html( $lab ) . '</span> ' : '';
				$html .= "<h{$level}{$st}>{$l}{$seg}</h{$level}>";
			} elseif ( $list && 0 === $k ) {
				$ls    = 'margin-left:' . $lpx . 'px' . ( ! empty( $pp['jc'] ) ? ';text-align:' . $pp['jc'] : '' );
				$html .= '<div class="ct-li" style="' . esc_attr( $ls ) . '"><span class="ct-num" style="width:' . $hpx . 'px">' . esc_html( $lab ) . '</span><span class="ct-tx">' . $seg . '</span></div>';
			} elseif ( $title ) {
				$html .= '<h1' . ( $st ? $st : ' style="text-align:center"' ) . ">{$seg}</h1>";
			} else {
				$html .= "<p{$st}>{$seg}</p>";
			}
		}
		return $pre . $html;
	}

	/* ───────────────────────── Tables ───────────────────────── */

	private function emit_table( array $t ) {
		$bordered = false;
		$prepared = array();
		$bounds   = array();
		foreach ( $t['rows'] as $row ) {
			$pos   = array();
			$flags = array();
			$d     = $row['tdef'];
			if ( strlen( $d ) >= 3 ) {
				$itc = self::u8( $d, 0 );
				for ( $k = 0; $k <= $itc; $k++ ) {
					$pos[] = self::s16( $d, 1 + 2 * $k );
				}
				$base = 1 + 2 * ( $itc + 1 );
				for ( $k = 0; $k < $itc; $k++ ) {
					$o       = $base + 20 * $k;
					$flags[] = self::u16( $d, $o );
					for ( $b = 0; $b < 4; $b++ ) {
						$ty = self::u8( $d, $o + 4 + 4 * $b + 1 );
						if ( $ty > 0 && 255 !== $ty ) {
							$bordered = true;
						}
					}
				}
			}
			if ( ! empty( $row['bord'] ) ) {
				$bordered = true;
			}
			$cells = array();
			$col   = 0;
			foreach ( $row['cells'] as $html ) {
				$span = 1;
				if ( $flags && isset( $flags[ $col ] ) && 1 === ( $flags[ $col ] & 3 ) ) {
					while ( isset( $flags[ $col + $span ] ) && 2 === ( $flags[ $col + $span ] & 3 ) ) {
						$span++;
					}
				}
				$l = isset( $pos[ $col ] ) ? $pos[ $col ] : null;
				$r = isset( $pos[ $col + $span ] ) ? $pos[ $col + $span ] : null;
				if ( null !== $l && null !== $r ) {
					$bounds[] = $l;
					$bounds[] = $r;
				}
				$cells[] = array( 'html' => $html, 'l' => $l, 'r' => $r );
				$col    += $span;
			}
			$prepared[] = $cells;
		}
		// One shared column grid for the whole table (cells whose edges line up within ~2mm count as the same edge).
		sort( $bounds );
		$grid = array();
		foreach ( $bounds as $b ) {
			if ( ! $grid || $b - end( $grid ) > 45 ) {
				$grid[] = $b;
			}
		}
		$near  = function ( $v ) use ( $grid ) {
			$best = 0;
			foreach ( $grid as $i => $g ) {
				if ( abs( $g - $v ) < abs( $grid[ $best ] - $v ) ) {
					$best = $i;
				}
			}
			return $best;
		};
		$total = count( $grid ) > 1 ? end( $grid ) - $grid[0] : 0;
		$out   = '';
		foreach ( $prepared as $cells ) {
			$out .= '<tr>';
			foreach ( $cells as $c ) {
				$span = 1;
				$w    = '';
				if ( $grid && null !== $c['l'] ) {
					$i0   = $near( $c['l'] );
					$i1   = $near( $c['r'] );
					$span = max( 1, $i1 - $i0 );
					if ( $total ) {
						$w = ' style="width:' . round( 100 * ( $grid[ $i1 ] - $grid[ $i0 ] ) / $total, 1 ) . '%"';
					}
				}
				$out .= '<td' . ( $span > 1 ? ' colspan="' . $span . '"' : '' ) . $w . '>' . ( '' === trim( $c['html'] ) ? '&nbsp;' : $c['html'] ) . '</td>';
			}
			$out .= '</tr>';
		}
		return '<table class="ct-table' . ( $bordered ? ' ct-bordered' : '' ) . '"><tbody>' . $out . '</tbody></table>';
	}

	/* ───────────────────────── Images ───────────────────────── */

	private function picture( $off ) {
		$d = $this->data;
		if ( '' === $d || $off + 68 > strlen( $d ) || $this->img_ok >= 25 ) {
			if ( '' !== $d ) {
				$this->img_skipped++;
			}
			return '';
		}
		$lcb  = self::u32( $d, $off );
		$cbh  = self::u16( $d, $off + 4 );
		$end  = min( strlen( $d ), $off + $lcb );
		$pos  = $off + $cbh;
		$blip = '';
		$ext  = '';
		$kind = '';
		$guard = 0;
		while ( $pos + 8 <= $end && $guard++ < 200 ) {
			$ver  = self::u16( $d, $pos );
			$type = self::u16( $d, $pos + 2 );
			$len  = self::u32( $d, $pos + 4 );
			$inst = $ver >> 4;
			$names = array( 0xF01A => 'EMF', 0xF01B => 'WMF', 0xF01C => 'PICT', 0xF01F => 'a bitmap (DIB)', 0xF029 => 'TIFF' );
			if ( isset( $names[ $type ] ) ) {
				$kind = $names[ $type ];
			}
			if ( 0xF01D === $type || 0xF02A === $type || 0xF01E === $type ) {
				$hdr = 16; // rgbUid1
				if ( in_array( $inst, array( 0x46B, 0x6E3, 0x6E1 ), true ) ) {
					$hdr += 16; // rgbUid2
				}
				$blip = substr( $d, $pos + 8 + $hdr + 1, max( 0, $len - $hdr - 1 ) );
				$ext  = 0xF01E === $type ? 'png' : 'jpg';
				break;
			}
			if ( 0x0F === ( $ver & 0x0F ) ) { // container: descend.
				$pos += 8;
			} else {
				$pos += 8 + $len;
			}
		}
		if ( '' === $blip || strlen( $blip ) > 4194304 ) {
			$this->img_skipped++;
			if ( $kind ) {
				$this->warnings[] = 'An image stored as ' . $kind . ' could not be imported (only JPEG and PNG pictures can be carried over from .doc files).';
			}
			return '';
		}
		$ok = ( 'png' === $ext && 0 === strpos( $blip, "\x89PNG" ) ) || ( 'jpg' === $ext && 0 === strpos( $blip, "\xFF\xD8" ) );
		if ( ! $ok ) {
			$this->img_skipped++;
			return '';
		}
		$hash = hash( 'sha256', $blip );
		if ( ! isset( $this->uploaded[ $hash ] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$up = wp_upload_bits( 'contract-' . substr( $hash, 0, 12 ) . '.' . $ext, null, $blip );
			if ( ! empty( $up['error'] ) ) {
				$this->img_skipped++;
				return '';
			}
			$chk = wp_check_filetype( 'x.' . $ext );
			wp_insert_attachment( array( 'post_mime_type' => $chk['type'], 'post_title' => 'Contract image ' . substr( $hash, 0, 8 ), 'post_status' => 'inherit' ), $up['file'] );
			$this->uploaded[ $hash ] = $up['url'];
		}
		$this->img_ok++;
		return '<img src="' . esc_url( $this->uploaded[ $hash ] ) . '" alt="" style="max-width:100%;height:auto">';
	}
}
