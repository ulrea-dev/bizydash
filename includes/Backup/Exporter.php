<?php
namespace BizyDash\Backup;

use BizyDash\Database\Schema;
use BizyDash\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a backup in small resumable steps, so a slow host never kills it half way.
 *
 * The job array is the whole state; a step does a few seconds of work and returns. Rows are streamed to one
 * .ndjson file per table in id order, and the byte offset reached is recorded after every batch, so a step that is
 * replayed after a crash truncates the file back to the last good point instead of duplicating rows.
 */
class Exporter {

	const FORMAT = 1;
	const BATCH  = 100;

	/** Encode one row (all values are strings or null) as a single JSON line. */
	public static function encode_row( array $row ) {
		foreach ( $row as $k => $v ) {
			if ( is_string( $v ) && ! mb_check_encoding( $v, 'UTF-8' ) ) {
				$row[ $k ] = array( '__b64' => base64_encode( $v ) );
			}
		}
		return wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	public static function decode_row( $line ) {
		$row = json_decode( $line, true );
		if ( ! is_array( $row ) ) {
			return null;
		}
		foreach ( $row as $k => $v ) {
			if ( is_array( $v ) && isset( $v['__b64'] ) ) {
				$row[ $k ] = base64_decode( $v['__b64'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			}
		}
		return $row;
	}

	/** Running fingerprint of a table: changes if any row's content or order changes. */
	public static function chain( $prev, $line ) {
		return hash( 'sha256', $prev . '|' . hash( 'sha256', $line ) );
	}

	/** Fresh job for the given options. */
	public static function create( array $opts, $type ) {
		global $wpdb;
		$tables = array();
		$total  = 0;
		foreach ( Module_Map::modules() as $m ) {
			foreach ( $m['tables'] as $t ) {
				$optional = in_array( $t, $m['optional'], true );
				if ( $optional && empty( $opts['logs'] ) ) {
					continue;
				}
				$tables[] = $t;
				$total   += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $t ) ); // phpcs:ignore WordPress.DB
			}
		}
		return array(
			'id'      => bin2hex( random_bytes( 8 ) ),
			'kind'    => 'export',
			'type'    => $type,
			'opts'    => array( 'files' => ! empty( $opts['files'] ), 'logs' => ! empty( $opts['logs'] ), 'encrypt' => ! empty( $opts['encrypt'] ) ),
			'phase'   => 'data',
			'tables'  => $tables,
			'ti'      => 0,
			'last'    => 0,
			'offset'  => 0,
			'rows'    => 0,
			'chk'     => '',
			'stats'   => array(),
			'users'   => array(),
			'total'   => max( 1, $total ),
			'done_rows' => 0,
			'files'   => array(),
			'fi'      => 0,
			'seq'     => 0,
			'started' => time(),
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	public static function work_dir( $job ) {
		$d = Local_Store::work_root() . '/' . preg_replace( '/[^a-f0-9]/', '', $job['id'] );
		if ( ! is_dir( $d . '/data' ) ) {
			wp_mkdir_p( $d . '/data' );
		}
		return $d;
	}

	/**
	 * Do up to $budget seconds of work. $save persists the job after each batch. Returns the job; phase 'zip'
	 * means every piece is ready and Backup_Manager assembles the archive.
	 */
	public static function step( array $job, $budget, callable $save ) {
		$deadline = microtime( true ) + $budget;
		while ( microtime( true ) < $deadline && in_array( $job['phase'], array( 'data', 'options', 'files' ), true ) ) {
			if ( 'data' === $job['phase'] ) {
				$job = self::data_batch( $job );
			} elseif ( 'options' === $job['phase'] ) {
				$job = self::options_phase( $job );
			} else {
				$job = self::files_phase( $job, $deadline );
			}
			$save( $job );
			if ( defined( 'BIZYDASH_BACKUP_FAIL_AT' ) && BIZYDASH_BACKUP_FAIL_AT === $job['phase'] ) {
				throw new \RuntimeException( 'Injected failure at ' . $job['phase'] );
			}
		}
		return $job;
	}

	private static function data_batch( array $job ) {
		global $wpdb;
		if ( $job['ti'] >= count( $job['tables'] ) ) {
			$job['phase'] = 'options';
			return $job;
		}
		$name = $job['tables'][ $job['ti'] ];
		$tbl  = Schema::table( $name );
		$path = self::work_dir( $job ) . '/data/' . $name . '.ndjson';
		$fh   = fopen( $path, 'c+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			throw new \RuntimeException( 'Cannot write ' . $name );
		}
		ftruncate( $fh, (int) $job['offset'] );
		fseek( $fh, 0, SEEK_END );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $tbl WHERE id > %d ORDER BY id ASC LIMIT %d", (int) $job['last'], self::BATCH ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( null === $rows && $wpdb->last_error ) {
			fclose( $fh );
			throw new \RuntimeException( 'Database error reading ' . $name . ': ' . $wpdb->last_error );
		}
		foreach ( (array) $rows as $row ) {
			$line = self::encode_row( $row );
			if ( false === $line ) {
				fclose( $fh );
				throw new \RuntimeException( 'Could not encode a row of ' . $name . ' (id ' . $row['id'] . ').' );
			}
			fwrite( $fh, $line . "\n" );
			$job['chk']  = self::chain( $job['chk'], $line );
			$job['last'] = (int) $row['id'];
			$job['rows']++;
			$job['done_rows']++;
			foreach ( Module_Map::user_columns() as $c ) {
				if ( isset( $row[ $c ] ) && (int) $row[ $c ] > 0 ) {
					$job['users'][ (int) $row[ $c ] ] = true;
				}
			}
		}
		fflush( $fh );
		$job['offset'] = (int) ftell( $fh );
		fclose( $fh );
		if ( count( (array) $rows ) < self::BATCH ) {
			$job['stats'][ $name ] = array( 'rows' => $job['rows'], 'checksum' => $job['chk'], 'sha256' => hash_file( 'sha256', $path ) );
			$job['ti']++;
			$job['last'] = 0;
			$job['offset'] = 0;
			$job['rows'] = 0;
			$job['chk']  = '';
		}
		return $job;
	}

	public static function option_payload() {
		$out = array();
		foreach ( array_keys( Module_Map::options() ) as $name ) {
			$v = get_option( $name, null );
			if ( null === $v ) {
				continue;
			}
			if ( 'bizydash_mail' === $name && is_array( $v ) ) {
				unset( $v['smtp_pass_enc'] ); // encrypted with this site's keys, so useless elsewhere
			}
			$out[ $name ] = $v;
		}
		return $out;
	}

	private static function options_phase( array $job ) {
		$json = wp_json_encode( self::option_payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		file_put_contents( self::work_dir( $job ) . '/options.json', $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$job['phase'] = $job['opts']['files'] ? 'files' : 'zip';
		if ( $job['opts']['files'] ) {
			$job['files'] = self::collect_files();
		}
		return $job;
	}

	/** Media-library files the data points at: attachment id => path relative to the uploads folder. */
	public static function attachment_map() {
		global $wpdb;
		$u    = wp_upload_dir();
		$base = wp_normalize_path( trailingslashit( $u['basedir'] ) );
		$ids  = array();
		foreach ( Module_Map::attachment_columns() as $t => $cols ) {
			foreach ( $cols as $c ) {
				$ids = array_merge( $ids, (array) $wpdb->get_col( 'SELECT DISTINCT ' . $c . ' FROM ' . Schema::table( $t ) . ' WHERE ' . $c . ' > 0' ) ); // phpcs:ignore WordPress.DB
			}
		}
		$s = get_option( 'bizydash_settings', array() );
		foreach ( array( 'business_logo_id', 'business_logo_email_id' ) as $k ) {
			if ( ! empty( $s[ $k ] ) ) {
				$ids[] = (int) $s[ $k ];
			}
		}
		$out = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $aid ) {
			$abs = get_attached_file( $aid );
			if ( $abs && 0 === strpos( wp_normalize_path( $abs ), $base ) && is_file( $abs ) ) {
				$out[ $aid ] = substr( wp_normalize_path( $abs ), strlen( $base ) );
			}
		}
		return $out;
	}

	/** Files the data points at: signed contract PDFs, logos/receipts, and uploads embedded in contract text. */
	public static function collect_files() {
		global $wpdb;
		$u    = wp_upload_dir();
		$base = wp_normalize_path( trailingslashit( $u['basedir'] ) );
		$out  = array();
		$add  = function ( $abs, $zip ) use ( &$out ) {
			if ( $abs && is_file( $abs ) && ! isset( $out[ $zip ] ) ) {
				$out[ $zip ] = array( 'path' => $zip, 'src' => $abs, 'size' => (int) filesize( $abs ) );
			}
		};
		$ct = Schema::table( 'contracts' );
		foreach ( (array) $wpdb->get_col( "SELECT DISTINCT pdf_file FROM $ct WHERE pdf_file <> ''" ) as $f ) { // phpcs:ignore WordPress.DB
			$add( \BizyDash\Services\Contract_Pdf::dir() . '/' . basename( $f ), 'files/contract-pdfs/' . basename( $f ) );
		}
		foreach ( self::attachment_map() as $rel ) {
			$add( $base . $rel, 'files/uploads/' . $rel );
		}
		$bodies = array();
		foreach ( array( 'contracts', 'contract_templates' ) as $t ) {
			$bodies = array_merge( $bodies, (array) $wpdb->get_col( 'SELECT body FROM ' . Schema::table( $t ) ) ); // phpcs:ignore WordPress.DB
		}
		foreach ( $bodies as $body ) {
			if ( preg_match_all( '#/uploads/([A-Za-z0-9_\-./ %]+\.(?:png|jpe?g|gif|webp|svg|pdf))#i', (string) $body, $m ) ) {
				foreach ( $m[1] as $rel ) {
					$rel = rawurldecode( $rel );
					if ( false !== strpos( $rel, '..' ) ) {
						continue;
					}
					$add( $base . $rel, 'files/uploads/' . $rel );
				}
			}
		}
		return array_values( $out );
	}

	private static function files_phase( array $job, $deadline ) {
		$n = count( $job['files'] );
		while ( $job['fi'] < $n && microtime( true ) < $deadline ) {
			$f = &$job['files'][ $job['fi'] ];
			$f['sha256'] = hash_file( 'sha256', $f['src'] );
			unset( $f );
			$job['fi']++;
		}
		if ( $job['fi'] >= $n ) {
			$job['phase'] = 'zip';
		}
		return $job;
	}

	public static function progress( array $job ) {
		switch ( $job['phase'] ) {
			case 'data':
				return (int) min( 80, round( 80 * $job['done_rows'] / $job['total'] ) );
			case 'options':
				return 82;
			case 'files':
				return 85 + (int) ( 8 * ( $job['fi'] / max( 1, count( $job['files'] ) ) ) );
			case 'zip':
				return 95;
			default:
				return 100;
		}
	}

	/** Assemble the zip (data, options, files, manifest). Returns the zip path. */
	public static function build_zip( array $job ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new \RuntimeException( 'This server has no zip support (PHP ZipArchive), so backups cannot be created.' );
		}
		$dir  = self::work_dir( $job );
		$zipp = $dir . '/backup.zip';
		if ( file_exists( $zipp ) ) {
			unlink( $zipp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zipp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( 'Could not create the archive file.' );
		}
		$modules = array();
		foreach ( Module_Map::modules() as $id => $m ) {
			$t = array();
			foreach ( $m['tables'] as $name ) {
				if ( isset( $job['stats'][ $name ] ) ) {
					$t[ $name ] = array( 'rows' => $job['stats'][ $name ]['rows'], 'checksum' => $job['stats'][ $name ]['checksum'], 'sha256' => $job['stats'][ $name ]['sha256'] );
				}
			}
			$modules[ $id ] = array( 'tables' => $t, 'skipped' => array_values( array_diff( $m['tables'], array_keys( $t ) ) ), 'excluded' => $m['excluded'] );
		}
		$users = array();
		foreach ( get_users( array( 'include' => array_keys( $job['users'] ) ?: array( 0 ), 'fields' => array( 'ID', 'user_email', 'display_name' ) ) ) as $u ) {
			$users[ (int) $u->ID ] = array( 'email' => $u->user_email, 'name' => $u->display_name );
		}
		$files = array();
		foreach ( $job['files'] as $f ) {
			$files[] = array( 'path' => $f['path'], 'size' => $f['size'], 'sha256' => $f['sha256'] ?? '' );
		}
		$manifest = array(
			'format_version'     => self::FORMAT,
			'min_reader_version' => 1,
			'plugin_version'     => defined( 'BIZYDASH_VERSION' ) ? BIZYDASH_VERSION : '',
			'schema_version'     => (int) Schema::VERSION,
			'created_at'         => $job['created_at'],
			'site_url'           => home_url(),
			'blog_id'            => get_current_blog_id(),
			'type'               => $job['type'],
			'modules'            => $modules,
			'options'            => array_keys( json_decode( (string) file_get_contents( $dir . '/options.json' ), true ) ?: array() ), // phpcs:ignore WordPress.WP.AlternativeFunctions
			'options_sha256'     => hash_file( 'sha256', $dir . '/options.json' ),
			'files'              => $files,
			'files_included'     => (bool) $job['opts']['files'],
			'users'              => $users,
			'attachments'        => (object) self::attachment_map(),
			'notes'              => array( 'The SMTP password is not included; enter it again after restoring.', 'One-time sign codes (contract_otps) are not included.' ),
		);
		$zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) );
		$zip->addFile( $dir . '/options.json', 'options.json' );
		foreach ( $job['stats'] as $name => $_ ) {
			$zip->addFile( $dir . '/data/' . $name . '.ndjson', 'data/' . $name . '.ndjson' );
		}
		foreach ( $job['files'] as $f ) {
			$zip->addFile( $f['src'], $f['path'] );
			if ( method_exists( $zip, 'setCompressionName' ) && preg_match( '/\.(pdf|png|jpe?g|gif|webp)$/i', $f['path'] ) ) {
				$zip->setCompressionName( $f['path'], \ZipArchive::CM_STORE );
			}
		}
		if ( ! $zip->close() ) {
			throw new \RuntimeException( 'Could not finish the archive (is the disk full?).' );
		}
		$job['manifest'] = $manifest;
		return array( $zipp, $manifest );
	}
}
