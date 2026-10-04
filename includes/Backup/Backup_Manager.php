<?php
namespace BizyDash\Backup;

use BizyDash\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Runs backup jobs, keeps the saved list tidy, and owns the automatic-backup settings and schedule. */
class Backup_Manager {

	const OPTION   = 'bizydash_backup_settings';
	const ACTIVE   = 'bizydash_backup_active';
	const AUTO     = 'bizydash_backup_auto';
	const CONTINUE = 'bizydash_backup_continue';
	const BUDGET   = 8;
	const KEEP_PRE = 3;

	public static function init() {
		add_action( self::AUTO, array( __CLASS__, 'run_auto' ) );
		add_action( self::CONTINUE, array( __CLASS__, 'continue_auto' ) );
		add_action( 'bizydash_daily', array( __CLASS__, 'housekeeping' ) );
		add_action( 'template_redirect', array( __CLASS__, 'guard_public' ), 0 );
	}

	public static function store() {
		return new Local_Store();
	}

	/* ───────────────────────── Settings ───────────────────────── */

	public static function defaults() {
		return array( 'auto' => 'off', 'keep' => 7, 'files' => true, 'logs' => true, 'last_run' => '', 'last_ok' => '', 'last_status' => '', 'last_error' => '' );
	}

	public static function settings() {
		return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function save_settings( array $in ) {
		$s = self::settings();
		if ( isset( $in['auto'] ) && in_array( $in['auto'], array( 'off', 'daily', 'weekly' ), true ) ) {
			$s['auto'] = $in['auto'];
		}
		if ( isset( $in['keep'] ) ) {
			$s['keep'] = max( 1, min( 60, (int) $in['keep'] ) );
		}
		foreach ( array( 'files', 'logs' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$s[ $k ] = (bool) $in[ $k ];
			}
		}
		update_option( self::OPTION, $s, false );
		self::reschedule();
		return $s;
	}

	public static function reschedule() {
		wp_clear_scheduled_hook( self::AUTO );
		$s = self::settings();
		if ( 'off' !== $s['auto'] ) {
			wp_schedule_event( time() + 600, 'weekly' === $s['auto'] ? 'weekly' : 'daily', self::AUTO );
		}
	}

	private static function note_result( $ok, $error = '' ) {
		$s                = self::settings();
		$s['last_run']    = gmdate( 'Y-m-d H:i:s' );
		$s['last_status'] = $ok ? 'ok' : 'failed';
		$s['last_error']  = $ok ? '' : $error;
		if ( $ok ) {
			$s['last_ok'] = $s['last_run'];
		}
		update_option( self::OPTION, $s, false );
	}

	/* ───────────────────────── Jobs ───────────────────────── */

	private static function job_file( $id ) {
		return Local_Store::work_root() . '/' . preg_replace( '/[^a-f0-9]/', '', $id ) . '/job.json';
	}

	private static function load( $id ) {
		$f = self::job_file( $id );
		if ( ! is_file( $f ) ) {
			return null;
		}
		$j = json_decode( (string) file_get_contents( $f ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return is_array( $j ) ? $j : null;
	}

	public static function save( array $job ) {
		$dir = Exporter::work_dir( $job );
		file_put_contents( $dir . '/job.json', wp_json_encode( $job ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		update_option( self::ACTIVE, array( 'id' => $job['id'], 'hb' => time() ), false );
	}

	public static function active() {
		$a = get_option( self::ACTIVE );
		return ( is_array( $a ) && ! empty( $a['id'] ) && time() - (int) $a['hb'] < 300 ) ? $a : null;
	}

	/** Drop a job that will never finish (its owner gave up). */
	public static function abandon( $id ) {
		self::release( $id );
		self::rmdir( Local_Store::work_root() . '/' . preg_replace( '/[^a-f0-9]/', '', $id ) );
	}

	public static function locked() {
		return Importer::locked();
	}

	/** While a restore runs, public contract and invoice pages show a short maintenance notice. */
	public static function guard_public() {
		if ( ! self::locked() ) {
			return;
		}
		$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$rel  = trim( substr( (string) $path, strlen( $home ) ), '/' );
		$base = preg_quote( \BizyDash\Services\Invoices::slug_base(), '#' );
		if ( empty( $_GET['bd_invoice'] ) && empty( $_GET['bd_certificate'] ) && ! preg_match( '#^(sign|verify|contract|' . $base . ')(/|$)#', $rel ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		status_header( 503 );
		header( 'Retry-After: 120' );
		nocache_headers();
		wp_die( 'This page is briefly unavailable while the system is being updated. Please try again in a couple of minutes.', 'Back shortly', array( 'response' => 503 ) );
	}

	private static function release( $id ) {
		$a = get_option( self::ACTIVE );
		if ( is_array( $a ) && $a['id'] === $id ) {
			delete_option( self::ACTIVE );
		}
	}

	/** Why a backup cannot start right now (empty string when it can). */
	public static function preflight() {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return 'This server has no zip support (PHP ZipArchive), so backups cannot be created. Ask your host to enable it.';
		}
		if ( ! wp_is_writable( Local_Store::dir() ) ) {
			return 'The backup folder is not writable: ' . Local_Store::dir();
		}
		$free = @disk_free_space( Local_Store::dir() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$need = self::estimate_bytes() * 2.5;
		if ( false !== $free && $free < max( 20 * MB_IN_BYTES, $need ) ) {
			return 'Not enough free disk space for a backup (' . size_format( (int) $free ) . ' free, about ' . size_format( (int) $need ) . ' needed).';
		}
		return '';
	}

	/** Rough size of the data + files, for the disk check. */
	public static function estimate_bytes() {
		global $wpdb;
		$like = $wpdb->esc_like( $wpdb->prefix . 'bizydash_' ) . '%';
		$data = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(data_length),0) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name LIKE %s', $like ) ); // phpcs:ignore WordPress.DB
		$sum  = 0;
		foreach ( Exporter::collect_files() as $f ) {
			$sum += $f['size'];
		}
		return $data + $sum;
	}

	/** Start a backup. @return array|\WP_Error public job state */
	public static function start( $type, array $opts ) {
		$a = self::active();
		if ( $a ) {
			return new \WP_Error( 'bd_busy', 'A backup is already running. Wait for it to finish.', array( 'status' => 409 ) );
		}
		$why = self::preflight();
		if ( '' !== $why ) {
			return new \WP_Error( 'bd_preflight', $why, array( 'status' => 400 ) );
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		$job = Exporter::create( $opts, $type );
		self::save( $job );
		return self::public_state( $job );
	}

	public static function public_state( array $job ) {
		return array(
			'id'       => $job['id'],
			'phase'    => $job['phase'],
			'progress' => $job['progress'] ?? Exporter::progress( $job ),
			'done'     => 'done' === $job['phase'],
			'error'    => $job['error'] ?? '',
			'result'   => $job['result'] ?? null,
		);
	}

	/** One slice of work. $password is used only on the last step and never stored. */
	public static function step( $id, $password = '', $budget = null ) {
		$job = self::load( $id );
		if ( ! $job ) {
			return new \WP_Error( 'bd_job', 'That backup job is no longer there. Start again.', array( 'status' => 404 ) );
		}
		if ( 'done' === $job['phase'] || 'failed' === $job['phase'] ) {
			return self::public_state( $job );
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		try {
			$job = Exporter::step( $job, null === $budget ? self::BUDGET : $budget, array( __CLASS__, 'save' ) );
			if ( 'zip' === $job['phase'] ) {
				$job = self::finalize( $job, (string) $password );
			}
		} catch ( \Throwable $e ) {
			return self::fail( $job, $e->getMessage() );
		}
		if ( 'done' !== $job['phase'] ) {
			$job['seq'] = ( $job['seq'] ?? 0 ) + 1;
			self::save( $job );
		}
		return self::public_state( $job );
	}

	private static function fail( array $job, $msg ) {
		$job['phase'] = 'failed';
		$job['error'] = $msg;
		self::save( $job );
		self::release( $job['id'] );
		self::rmdir( Exporter::work_dir( $job ) );
		if ( 'auto' === $job['type'] ) {
			self::note_result( false, $msg );
		}
		return new \WP_Error( 'bd_backup_failed', $msg, array( 'status' => 500 ) );
	}

	private static function finalize( array $job, $password ) {
		list( $zipp, $manifest ) = Exporter::build_zip( $job );
		$encrypted = false;
		if ( ! empty( $job['opts']['encrypt'] ) ) {
			if ( '' === $password ) {
				throw new \RuntimeException( 'A password is needed to finish this protected backup.' );
			}
			$enc = $zipp . '.enc';
			$r   = Backup_Crypto::encrypt_file( $zipp, $enc, $password );
			if ( is_wp_error( $r ) ) {
				throw new \RuntimeException( $r->get_error_message() );
			}
			@unlink( $zipp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			$zipp      = $enc;
			$encrypted = true;
		}
		$counts = array();
		foreach ( $manifest['modules'] as $mid => $m ) {
			$counts[ $mid ] = array_sum( wp_list_pluck( $m['tables'], 'rows' ) );
		}
		$meta = array(
			'type'           => $job['type'],
			'created_at'     => $job['created_at'],
			'encrypted'      => $encrypted,
			'schema_version' => $manifest['schema_version'],
			'plugin_version' => $manifest['plugin_version'],
			'site_url'       => $manifest['site_url'],
			'rows'           => $counts,
			'files'          => count( $manifest['files'] ),
			'files_included' => $manifest['files_included'],
			'sha256'         => hash_file( 'sha256', $zipp ),
		);
		$store = self::store();
		$id    = $store->put( $zipp, $meta );
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( $id->get_error_message() );
		}
		$meta['id']      = $id;
		$meta['size']    = (int) filesize( $store->path( $id ) );
		$job['phase']    = 'done';
		$job['result']   = $meta;
		$job['progress'] = 100;
		self::release( $job['id'] );
		self::rmdir( Exporter::work_dir( $job ) );
		self::prune();
		if ( 'auto' === $job['type'] ) {
			self::note_result( true );
		}
		do_action( 'bizydash_backup_created', $meta, $store->path( $id ) );
		return $job;
	}

	/** Run a whole backup in one go (cron, WP-CLI). Gives up after $limit seconds if $resumable, leaving a job to continue. */
	public static function run_blocking( $type, array $opts ) {
		$s = self::start( $type, $opts );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$limit = microtime( true ) + 25;
		do {
			$s = self::step( $s['id'], '', 5 );
			if ( is_wp_error( $s ) || $s['done'] ) {
				return $s;
			}
		} while ( microtime( true ) < $limit );
		wp_schedule_single_event( time() + 20, self::CONTINUE, array( $s['id'] ) );
		return $s;
	}

	/* ───────────────────────── Automatic backups ───────────────────────── */

	public static function run_auto() {
		$s = self::settings();
		if ( 'off' === $s['auto'] || get_option( 'bizydash_restore_lock' ) ) {
			return;
		}
		$r = self::run_blocking( 'auto', array( 'files' => $s['files'], 'logs' => $s['logs'] ) );
		if ( is_wp_error( $r ) && 'bd_busy' !== $r->get_error_code() ) {
			self::note_result( false, $r->get_error_message() );
		}
	}

	public static function continue_auto( $id ) {
		$limit = microtime( true ) + 25;
		do {
			$s = self::step( $id, '', 5 );
			if ( is_wp_error( $s ) || $s['done'] ) {
				return;
			}
		} while ( microtime( true ) < $limit );
		wp_schedule_single_event( time() + 20, self::CONTINUE, array( $id ) );
	}

	/* ───────────────────────── Housekeeping ───────────────────────── */

	/** Auto backups beyond the keep count and old safety backups go; manual backups are never removed. */
	public static function prune() {
		$s     = self::settings();
		$store = self::store();
		$by    = array( 'auto' => array(), 'pre_restore' => array() );
		foreach ( $store->all() as $m ) { // newest first
			$t = $m['type'] ?? 'manual';
			if ( isset( $by[ $t ] ) ) {
				$by[ $t ][] = $m['id'];
			}
		}
		foreach ( array_slice( $by['auto'], max( 1, (int) $s['keep'] ) ) as $id ) {
			$store->delete( $id );
		}
		foreach ( array_slice( $by['pre_restore'], self::KEEP_PRE ) as $id ) {
			$store->delete( $id );
		}
	}

	/** Remove work folders left by jobs that died more than a day ago. */
	public static function housekeeping() {
		$root = Local_Store::work_root();
		foreach ( (array) glob( $root . '/*', GLOB_ONLYDIR ) as $d ) {
			if ( filemtime( $d ) < time() - DAY_IN_SECONDS ) {
				self::rmdir( $d );
			}
		}
		self::prune();
	}

	public static function rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $f ) {
			if ( '.' === $f || '..' === $f ) {
				continue;
			}
			$p = $dir . '/' . $f;
			is_dir( $p ) && ! is_link( $p ) ? self::rmdir( $p ) : @unlink( $p ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
	}

	/** Describe an archive file already on disk (reads the manifest when it is not password protected). */
	public static function meta_from_file( $f ) {
		$meta = array( 'type' => 'imported', 'created_at' => gmdate( 'Y-m-d H:i:s', filemtime( $f ) ), 'encrypted' => Backup_Crypto::is_encrypted( $f ), 'sha256' => hash_file( 'sha256', $f ) );
		if ( ! $meta['encrypted'] ) {
			$ins = Archive::inspect( $f );
			if ( is_array( $ins ) ) {
				list( $z, $mf ) = $ins;
				$z->close();
				$meta['created_at']     = $mf['created_at'] ?? $meta['created_at'];
				$meta['schema_version'] = $mf['schema_version'] ?? 0;
				$meta['plugin_version'] = $mf['plugin_version'] ?? '';
				$meta['site_url']       = $mf['site_url'] ?? '';
				$meta['files_included'] = ! empty( $mf['files_included'] );
				$meta['files']          = count( (array) ( $mf['files'] ?? array() ) );
				$rows                   = array();
				foreach ( (array) ( $mf['modules'] ?? array() ) as $mid => $m ) {
					$rows[ $mid ] = array_sum( wp_list_pluck( (array) ( $m['tables'] ?? array() ), 'rows' ) );
				}
				$meta['rows'] = $rows;
			} else {
				$meta['invalid'] = $ins->get_error_message();
			}
		}
		return $meta;
	}

	/** Accept one slice of an uploaded backup. The last slice makes it a saved backup. @return array|\WP_Error */
	public static function receive_chunk( $uid, $index, $total, $tmp_file ) {
		$uid  = preg_replace( '/[^a-f0-9]/', '', (string) $uid );
		if ( strlen( $uid ) < 16 || $index < 0 || $total < 1 || $index >= $total || ! is_uploaded_file( $tmp_file ) && ! is_readable( $tmp_file ) ) {
			return new \WP_Error( 'bd_upload', 'The upload request was not valid.', array( 'status' => 400 ) );
		}
		$part = Local_Store::work_root() . '/up-' . $uid . '.part';
		$idx  = Local_Store::work_root() . '/up-' . $uid . '.idx';
		$have = is_file( $idx ) ? (int) file_get_contents( $idx ) : 0; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( 0 === $index ) {
			if ( is_file( $part ) ) {
				unlink( $part ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			$have = 0;
			$free = @disk_free_space( Local_Store::dir() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false !== $free && $free < 50 * MB_IN_BYTES ) {
				return new \WP_Error( 'bd_disk', 'Not enough free disk space to receive this backup.', array( 'status' => 507 ) );
			}
		}
		if ( $index !== $have ) {
			return new \WP_Error( 'bd_upload_order', 'An upload piece arrived out of order. Start the upload again.', array( 'status' => 409, 'expected' => $have ) );
		}
		$in  = fopen( $tmp_file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$out = fopen( $part, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $in || ! $out ) {
			return new \WP_Error( 'bd_io', 'Could not store the upload.', array( 'status' => 500 ) );
		}
		stream_copy_to_stream( $in, $out );
		fclose( $in );
		fclose( $out );
		file_put_contents( $idx, (string) ( $index + 1 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $index + 1 < $total ) {
			return array( 'done' => false, 'next' => $index + 1 );
		}
		@unlink( $idx ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		$meta = self::meta_from_file( $part );
		if ( ! empty( $meta['invalid'] ) ) {
			@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			return new \WP_Error( 'bd_format', $meta['invalid'], array( 'status' => 400 ) );
		}
		$id = self::store()->put( $part, $meta );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return array( 'done' => true, 'backup' => self::store()->find( $id ) + array( 'size' => (int) filesize( self::store()->path( $id ) ) ) );
	}

	/** Adopt archives dropped into the folder by FTP (they have no sidecar yet). */
	public static function rescan() {
		$store = self::store();
		$added = 0;
		foreach ( (array) glob( Local_Store::dir() . '/*' . Local_Store::EXT ) as $f ) {
			$id = basename( $f, Local_Store::EXT );
			if ( ! Local_Store::valid_id( $id ) ) {
				$new = bin2hex( random_bytes( 16 ) );
				$dst = Local_Store::dir() . '/' . $new . Local_Store::EXT;
				if ( ! @rename( $f, $dst ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					continue;
				}
				$f  = $dst;
				$id = $new;
			}
			if ( $store->find( $id ) ) {
				continue;
			}
			$meta = self::meta_from_file( $f );
			$sidecar = Local_Store::dir() . '/' . $id . '.json';
			file_put_contents( $sidecar, wp_json_encode( $meta ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$added++;
		}
		return $added;
	}

	/* ───────────────────────── Health ───────────────────────── */

	public static function health() {
		$s    = self::settings();
		$free = @disk_free_space( Local_Store::dir() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$out  = array(
			'zip'           => class_exists( '\ZipArchive' ),
			'encryption'    => Backup_Crypto::available(),
			'writable'      => wp_is_writable( Local_Store::dir() ),
			'folder'        => Local_Store::dir(),
			'free_bytes'    => false === $free ? null : (int) $free,
			'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'next_auto'     => 'off' === $s['auto'] ? null : wp_next_scheduled( self::AUTO ),
			'exposed'       => null,
			'estimate'      => self::estimate_bytes(),
		);
		$url = Local_Store::probe_url();
		if ( $url ) {
			$cached = get_transient( 'bizydash_backup_probe' );
			if ( false === $cached ) {
				$r      = wp_remote_get( $url, array( 'timeout' => 5, 'sslverify' => false, 'redirection' => 0 ) );
				$cached = is_wp_error( $r ) ? 'unknown' : ( 200 === (int) wp_remote_retrieve_response_code( $r ) ? 'yes' : 'no' );
				set_transient( 'bizydash_backup_probe', $cached, 6 * HOUR_IN_SECONDS );
			}
			$out['exposed'] = 'yes' === $cached ? true : ( 'no' === $cached ? false : null );
		}
		return $out;
	}
}
