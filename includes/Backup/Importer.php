<?php
namespace BizyDash\Backup;

use BizyDash\Database\Schema;
use BizyDash\Modules;
use BizyDash\Services\Contracts;
use BizyDash\Services\Contract_Pdf;

defined( 'ABSPATH' ) || exit;

/**
 * Restores chosen modules from a backup by replacing their tables.
 *
 * Nothing live is touched until the backup has been read back, checksummed and checked: rows go into temporary
 * `bdtmp_` copies of the tables, are verified byte for byte, and only then does one atomic RENAME TABLE swap them
 * in. A verified safety backup of the current data is taken first, so a restore can always be undone.
 */
class Importer {

	const LOCK   = 'bizydash_restore_lock';
	const BUDGET = 8;
	const BATCH  = 200;

	/* ───────────────────────── Lock ───────────────────────── */

	public static function locked() {
		$l = get_option( self::LOCK );
		return is_array( $l ) && time() - (int) $l['hb'] < 600;
	}

	private static function beat( $id ) {
		update_option( self::LOCK, array( 'id' => $id, 'hb' => time() ), false );
	}

	public static function unlock() {
		delete_option( self::LOCK );
	}

	/* ───────────────────────── Names ───────────────────────── */

	private static function tmp( $t ) {
		global $wpdb;
		return $wpdb->prefix . 'bdtmp_' . $t;
	}

	private static function old( $t ) {
		global $wpdb;
		return $wpdb->prefix . 'bdold_' . $t;
	}

	private static function live_cols( $t ) {
		global $wpdb;
		return (array) $wpdb->get_col( 'SHOW COLUMNS FROM ' . Schema::table( $t ), 0 ); // phpcs:ignore WordPress.DB
	}

	private static function count( $table ) {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $table . '`' ); // phpcs:ignore WordPress.DB
	}

	/* ───────────────────────── Opening a backup ───────────────────────── */

	/** @return array|\WP_Error array( plain_path, temp_path_to_delete ) */
	private static function open_plain( $id, $password, $into = '' ) {
		$store = Backup_Manager::store();
		$meta  = $store->find( $id );
		$path  = $store->path( $id );
		if ( ! $meta || '' === $path ) {
			return new \WP_Error( 'bd_missing', 'That backup was not found.', array( 'status' => 404 ) );
		}
		if ( ! Backup_Crypto::is_encrypted( $path ) ) {
			return array( $path, '' );
		}
		if ( '' === (string) $password ) {
			return new \WP_Error( 'bd_password_required', 'This backup is password protected. Enter its password.', array( 'status' => 400 ) );
		}
		$tmp = $into ? $into : Local_Store::work_root() . '/an-' . bin2hex( random_bytes( 6 ) ) . '.zip';
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$r = Backup_Crypto::decrypt_file( $path, $tmp, $password );
		if ( is_wp_error( $r ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			$r->add_data( array( 'status' => 400 ) );
			return $r;
		}
		return array( $tmp, $tmp );
	}

	/** Verify a freshly made backup is complete and unchanged. @return true|\WP_Error */
	public static function verify_backup( $id ) {
		$path = Backup_Manager::store()->path( $id );
		$ins  = Archive::inspect( $path );
		if ( is_wp_error( $ins ) ) {
			return $ins;
		}
		list( $zip, $man, $entries ) = $ins;
		foreach ( $man['modules'] as $m ) {
			foreach ( (array) $m['tables'] as $t => $info ) {
				if ( ! isset( $entries[ 'data/' . $t . '.ndjson' ] ) || Archive::hash_entry( $zip, 'data/' . $t . '.ndjson' ) !== $info['sha256'] ) {
					$zip->close();
					return new \WP_Error( 'bd_verify', 'The safety backup could not be verified (' . $t . ').' );
				}
			}
		}
		foreach ( (array) $man['files'] as $f ) {
			if ( ! isset( $entries[ $f['path'] ] ) ) {
				$zip->close();
				return new \WP_Error( 'bd_verify', 'The safety backup is missing a file (' . $f['path'] . ').' );
			}
		}
		$zip->close();
		return true;
	}

	/* ───────────────────────── Analyze ───────────────────────── */

	public static function analyze( $id, $password = '' ) {
		$op = self::open_plain( $id, $password );
		if ( is_wp_error( $op ) ) {
			return $op;
		}
		list( $path, $tmp ) = $op;
		$ins = Archive::inspect( $path );
		if ( is_wp_error( $ins ) ) {
			$tmp && @unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			return $ins;
		}
		list( $zip, $man, $entries ) = $ins;
		$report = self::report( $man, $entries );
		$zip->close();
		$tmp && @unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		$report['id'] = $id;
		return $report;
	}

	public static function report( array $man, array $entries ) {
		global $wpdb;
		$sv  = (int) ( $man['schema_version'] ?? 0 );
		$cur = (int) Schema::VERSION;
		if ( $sv > $cur ) {
			$compat = array( 'status' => 'blocked', 'message' => 'This backup was made by a newer version of BizyDash (database version ' . $sv . ', this site has ' . $cur . '). Update the plugin first, then restore.' );
		} elseif ( $sv < $cur ) {
			$compat = array( 'status' => 'migrate', 'message' => 'This backup is from an older version of BizyDash. Your data will be upgraded automatically after it is restored.' );
		} else {
			$compat = array( 'status' => 'ok', 'message' => 'This backup matches your version of BizyDash.' );
		}
		$modules = array();
		foreach ( Module_Map::modules() as $id => $m ) {
			$bm      = isset( $man['modules'][ $id ] ) ? $man['modules'][ $id ] : null;
			$btables = $bm ? (array) $bm['tables'] : array();
			$brows   = 0;
			$tables  = array();
			$live    = 0;
			foreach ( $m['tables'] as $t ) {
				$lr = self::count( Schema::table( $t ) );
				$live += $lr;
				$br = isset( $btables[ $t ] ) ? (int) $btables[ $t ]['rows'] : null;
				$brows += (int) $br;
				$tables[] = array( 'name' => $t, 'backup' => $br, 'live' => $lr, 'optional' => in_array( $t, $m['optional'], true ) );
			}
			if ( ! $btables ) {
				$status = 'missing';
				$note   = 'This backup has no ' . strtolower( $m['label'] ) . ' data at all, so it cannot be restored from it.';
			} elseif ( 0 === $brows ) {
				$status = 'empty';
				$note   = 'This backup has no records for this part.' . ( $live ? ' Restoring it would clear the ' . $live . ' you have now.' : '' );
			} else {
				$status = 'available';
				$note   = '';
			}
			$modules[] = array( 'id' => $id, 'label' => $m['label'], 'desc' => $m['desc'], 'status' => $status, 'note' => $note, 'backup_rows' => $brows, 'live_rows' => $live, 'tables' => $tables, 'enabled' => ( 'core' === $id || Modules::enabled( $id ) ), 'excluded' => $m['excluded'] );
		}
		$known = Module_Map::all_tables();
		$unsupported = array();
		foreach ( array_keys( $entries ) as $n ) {
			if ( preg_match( '#^data/([a-z_]+)\.ndjson$#', $n, $mm ) && ! in_array( $mm[1], $known, true ) ) {
				$unsupported[] = $mm[1];
			}
		}
		$um = array();
		$un = array();
		foreach ( (array) ( $man['users'] ?? array() ) as $uid => $u ) {
			$match = get_user_by( 'email', $u['email'] ?? '' );
			if ( $match ) {
				$um[ (int) $uid ] = (int) $match->ID;
			} else {
				$un[] = array( 'name' => $u['name'] ?? '', 'email' => $u['email'] ?? '' );
			}
		}
		$missing = array();
		foreach ( (array) ( $man['files'] ?? array() ) as $f ) {
			if ( ! isset( $entries[ $f['path'] ] ) ) {
				$missing[] = $f['path'];
			}
		}
		$same = untrailingslashit( (string) ( $man['site_url'] ?? '' ) ) === untrailingslashit( home_url() );
		$notes = array( 'The SMTP (email sending) password is never stored in a backup. If you send through SMTP, enter it again in Settings → Email after restoring.', 'One-time sign-in codes for contracts are not backed up; they only last minutes anyway.' );
		if ( ! $same ) {
			$notes[] = 'This backup came from another site (' . ( $man['site_url'] ?? 'unknown' ) . '). Public links will use this site’s address. Images inside contract text keep their original address, so they may not show unless the files are restored.';
		}
		if ( empty( $man['files_included'] ) ) {
			$notes[] = 'This backup was made without files: signed contract PDFs and uploaded images are not included. Contract records will still verify, but their stored PDFs will be missing unless the files are already on this site.';
		}
		if ( $un ) {
			$notes[] = count( $un ) . ' person' . ( 1 === count( $un ) ? '' : 's' ) . ' in this backup ' . ( 1 === count( $un ) ? 'has' : 'have' ) . ' no matching WordPress user on this site (matched by email). Their records are kept but are no longer linked to a user.';
		}
		return array(
			'manifest'    => array( 'created_at' => $man['created_at'] ?? '', 'site_url' => $man['site_url'] ?? '', 'plugin_version' => $man['plugin_version'] ?? '', 'schema_version' => $sv, 'type' => $man['type'] ?? '', 'files_included' => ! empty( $man['files_included'] ) ),
			'compat'      => $compat,
			'modules'     => $modules,
			'unsupported' => $unsupported,
			'users'       => array( 'total' => count( (array) ( $man['users'] ?? array() ) ), 'unmatched' => $un ),
			'files'       => array( 'count' => count( (array) ( $man['files'] ?? array() ) ), 'missing' => $missing ),
			'settings'    => ! empty( $man['options'] ),
			'same_site'   => $same,
			'notes'       => $notes,
		);
	}

	/* ───────────────────────── Start ───────────────────────── */

	public static function start( $id, $password, array $modules, array $opts ) {
		if ( self::locked() ) {
			return new \WP_Error( 'bd_busy', 'A restore is already running.', array( 'status' => 409 ) );
		}
		$report = self::analyze( $id, $password );
		if ( is_wp_error( $report ) ) {
			return $report;
		}
		if ( 'blocked' === $report['compat']['status'] ) {
			return new \WP_Error( 'bd_blocked', $report['compat']['message'], array( 'status' => 400 ) );
		}
		$modules = array_values( array_unique( array_map( 'strval', $modules ) ) );
		if ( ! $modules ) {
			return new \WP_Error( 'bd_modules', 'Choose at least one part to restore.', array( 'status' => 400 ) );
		}
		$by = array();
		foreach ( $report['modules'] as $m ) {
			$by[ $m['id'] ] = $m;
		}
		foreach ( $modules as $m ) {
			if ( ! isset( $by[ $m ] ) || 'missing' === $by[ $m ]['status'] ) {
				return new \WP_Error( 'bd_modules', 'This backup has nothing to restore for “' . $m . '”.', array( 'status' => 400 ) );
			}
		}
		if ( in_array( 'core', $modules, true ) && empty( $opts['allow_orphans'] ) ) {
			$left = array();
			foreach ( array( 'work', 'finance', 'contracts' ) as $dep ) {
				if ( ! in_array( $dep, $modules, true ) && $by[ $dep ]['live_rows'] > 0 ) {
					$left[] = $by[ $dep ]['label'];
				}
			}
			if ( $left ) {
				return new \WP_Error( 'bd_dependents', 'Clients and departments are linked to your ' . implode( ', ', $left ) . ' records. Restore those together with them, or the links would break.', array( 'status' => 400, 'dependents' => $left ) );
			}
		}
		$tables = array();
		foreach ( Module_Map::modules() as $mid => $mm ) {
			if ( ! in_array( $mid, $modules, true ) ) {
				continue;
			}
			$present = array();
			foreach ( $by[ $mid ]['tables'] as $t ) {
				$present[ $t['name'] ] = $t;
			}
			foreach ( $mm['tables'] as $t ) {
				if ( null === $present[ $t ]['backup'] ) {
					continue; // not in this backup: left alone
				}
				if ( $present[ $t ]['optional'] && isset( $opts['logs'] ) && ! $opts['logs'] ) {
					continue;
				}
				$tables[] = $t;
			}
		}
		$safety = Backup_Manager::start( 'pre_restore', array( 'files' => true, 'logs' => true ) );
		if ( is_wp_error( $safety ) ) {
			return $safety;
		}
		$job = array(
			'id'            => bin2hex( random_bytes( 8 ) ),
			'kind'          => 'restore',
			'src'           => $id,
			'modules'       => $modules,
			'tables'        => $tables,
			'opts'          => array( 'allow_orphans' => ! empty( $opts['allow_orphans'] ), 'apply_modules' => ! empty( $opts['apply_modules'] ), 'logs' => ! isset( $opts['logs'] ) || (bool) $opts['logs'] ),
			'phase'         => 'safety',
			'safety'        => $safety['id'],
			'safety_id'     => '',
			'ti'            => 0,
			'last'          => 0,
			'offset'        => 0,
			'rows'          => 0,
			'chk'           => '',
			'stats'         => array(),
			'cols'          => array(),
			'expect'        => array(),
			'created_files' => array(),
			'created_atts'  => array(),
			'user_map'      => array(),
			'att'           => array(),
			'warnings'      => array(),
			'swapped'       => false,
			'fi'            => 0,
			'vi'            => 0,
			'vlast'         => 0,
			'vchk'          => '',
			'vstage'        => 'tables',
			'seq'           => 0,
			'started'       => time(),
		);
		self::beat( $job['id'] );
		self::save( $job );
		return self::state( $job );
	}

	/* ───────────────────────── Job persistence ───────────────────────── */

	private static function dir( $id ) {
		$d = Local_Store::work_root() . '/' . preg_replace( '/[^a-f0-9]/', '', $id );
		if ( ! is_dir( $d . '/data' ) ) {
			wp_mkdir_p( $d . '/data' );
		}
		return $d;
	}

	private static function save( array $job ) {
		file_put_contents( self::dir( $job['id'] ) . '/job.json', wp_json_encode( $job ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	private static function load( $id ) {
		$f = Local_Store::work_root() . '/' . preg_replace( '/[^a-f0-9]/', '', $id ) . '/job.json';
		if ( ! is_file( $f ) ) {
			return null;
		}
		$j = json_decode( (string) file_get_contents( $f ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return is_array( $j ) && 'restore' === ( $j['kind'] ?? '' ) ? $j : null;
	}

	public static function state( array $job ) {
		$total = max( 1, array_sum( wp_list_pluck( $job['expect'] ?: array( array( 'rows' => 1 ) ), 'rows' ) ) );
		$done  = 0;
		foreach ( $job['stats'] as $s ) {
			$done += $s['rows'];
		}
		$done += $job['rows'];
		switch ( $job['phase'] ) {
			case 'safety':
				$p = 3;
				break;
			case 'extract':
				$p = 28;
				break;
			case 'files':
				$p = 32;
				break;
			case 'stage':
				$p = 35 + (int) ( 35 * min( 1, $done / $total ) );
				break;
			case 'validate':
				$p = 72 + (int) ( 14 * min( 1, ( $job['vi'] + 1 ) / max( 1, count( $job['tables'] ) ) ) );
				break;
			case 'swap':
				$p = 90;
				break;
			case 'post':
				$p = 95;
				break;
			default:
				$p = 100;
		}
		if ( isset( $job['progress_override'] ) ) {
			$p = $job['progress_override'];
		}
		return array(
			'id'       => $job['id'],
			'kind'     => 'restore',
			'phase'    => $job['phase'],
			'progress' => $p,
			'done'     => 'done' === $job['phase'],
			'error'    => $job['error'] ?? '',
			'warnings' => $job['warnings'],
			'result'   => $job['result'] ?? null,
		);
	}

	/* ───────────────────────── Stepping ───────────────────────── */

	public static function step( $id, $password = '' ) {
		$job = self::load( $id );
		if ( ! $job ) {
			return new \WP_Error( 'bd_job', 'That restore job is no longer there.', array( 'status' => 404 ) );
		}
		if ( in_array( $job['phase'], array( 'done', 'failed' ), true ) ) {
			return self::state( $job );
		}
		self::beat( $job['id'] );
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$deadline = microtime( true ) + self::BUDGET;
		try {
			do {
				$phase = $job['phase'];
				if ( defined( 'BIZYDASH_BACKUP_FAIL_AT' ) && BIZYDASH_BACKUP_FAIL_AT === 'restore_' . $phase ) {
					throw new \RuntimeException( 'Injected failure before ' . $phase );
				}
				$job = self::run_phase( $job, $password, $deadline );
				if ( 'done' !== $job['phase'] ) {
					self::save( $job );
				}
			} while ( ! in_array( $job['phase'], array( 'done', 'failed' ), true ) && microtime( true ) < $deadline && $phase !== 'safety' );
		} catch ( \Throwable $e ) {
			return self::abort( $job, $e->getMessage() );
		}
		return self::state( $job );
	}

	private static function run_phase( array $job, $password, $deadline ) {
		switch ( $job['phase'] ) {
			case 'safety':
				return self::p_safety( $job, $deadline );
			case 'extract':
				return self::p_extract( $job, $password );
			case 'files':
				return self::p_files( $job, $deadline );
			case 'stage':
				return self::p_stage( $job, $deadline );
			case 'validate':
				return self::p_validate( $job, $deadline );
			case 'swap':
				return self::p_swap( $job );
			case 'post':
				return self::p_post( $job );
		}
		return $job;
	}

	private static function p_safety( array $job, $deadline ) {
		$s = Backup_Manager::step( $job['safety'], '', max( 1, min( 6, $deadline - microtime( true ) ) ) );
		if ( is_wp_error( $s ) ) {
			throw new \RuntimeException( 'The safety backup could not be made, so nothing was changed. ' . $s->get_error_message() );
		}
		if ( $s['done'] ) {
			$ok = self::verify_backup( $s['result']['id'] );
			if ( is_wp_error( $ok ) ) {
				throw new \RuntimeException( 'The safety backup could not be verified, so nothing was changed. ' . $ok->get_error_message() );
			}
			$job['safety_id'] = $s['result']['id'];
			$job['phase']     = 'extract';
		} else {
			$job['progress_override'] = (int) ( $s['progress'] * 0.25 );
		}
		return $job;
	}

	private static function plain( array $job, $password ) {
		$store = Backup_Manager::store();
		$p     = $store->path( $job['src'] );
		if ( ! Backup_Crypto::is_encrypted( $p ) ) {
			return $p;
		}
		$dest = self::dir( $job['id'] ) . '/plain.zip';
		if ( ! is_file( $dest ) ) {
			$r = self::open_plain( $job['src'], $password, $dest );
			if ( is_wp_error( $r ) ) {
				throw new \RuntimeException( $r->get_error_message() );
			}
		}
		return $dest;
	}

	private static function p_extract( array $job, $password ) {
		unset( $job['progress_override'] );
		$plain = self::plain( $job, $password );
		$ins   = Archive::inspect( $plain );
		if ( is_wp_error( $ins ) ) {
			throw new \RuntimeException( $ins->get_error_message() );
		}
		list( $zip, $man, $entries ) = $ins;
		$dir = self::dir( $job['id'] );
		$job['expect'] = array();
		foreach ( $job['tables'] as $t ) {
			$info = null;
			foreach ( $man['modules'] as $m ) {
				if ( isset( $m['tables'][ $t ] ) ) {
					$info = $m['tables'][ $t ];
				}
			}
			if ( ! $info || ! isset( $entries[ 'data/' . $t . '.ndjson' ] ) ) {
				$zip->close();
				throw new \RuntimeException( 'The backup is missing the data for ' . $t . '.' );
			}
			$sha = Archive::extract( $zip, 'data/' . $t . '.ndjson', $dir . '/data/' . $t . '.ndjson' );
			if ( $sha !== $info['sha256'] ) {
				$zip->close();
				throw new \RuntimeException( 'The backup file is damaged (' . $t . ' does not match its checksum).' );
			}
			$job['expect'][ $t ] = array( 'rows' => (int) $info['rows'], 'checksum' => $info['checksum'] );
		}
		if ( isset( $entries['options.json'] ) ) {
			$sha = Archive::extract( $zip, 'options.json', $dir . '/options.json' );
			if ( ! empty( $man['options_sha256'] ) && $sha !== $man['options_sha256'] ) {
				$zip->close();
				throw new \RuntimeException( 'The backup file is damaged (settings do not match their checksum).' );
			}
		}
		$job['user_map'] = array();
		foreach ( (array) ( $man['users'] ?? array() ) as $uid => $u ) {
			$m = get_user_by( 'email', $u['email'] ?? '' );
			$job['user_map'][ (int) $uid ] = $m ? (int) $m->ID : 0;
		}
		$job['att_paths']   = (array) ( $man['attachments'] ?? array() );
		$job['files']       = (array) ( $man['files'] ?? array() );
		$job['backup_schema'] = (int) ( $man['schema_version'] ?? 0 );
		$job['same_site']   = untrailingslashit( (string) ( $man['site_url'] ?? '' ) ) === untrailingslashit( home_url() );
		$zip->close();
		$job['plain'] = $plain;
		$job['phase'] = ! empty( $man['files_included'] ) ? 'files' : 'stage';
		return $job;
	}

	private static function uploads_base() {
		$u = wp_upload_dir();
		return wp_normalize_path( trailingslashit( $u['basedir'] ) );
	}

	private static function p_files( array $job, $deadline ) {
		$ins = Archive::inspect( $job['plain'] );
		if ( is_wp_error( $ins ) ) {
			throw new \RuntimeException( $ins->get_error_message() );
		}
		list( $zip ) = $ins;
		$n = count( $job['files'] );
		while ( $job['fi'] < $n && microtime( true ) < $deadline ) {
			$f = $job['files'][ $job['fi'] ];
			$job['fi']++;
			$path = $f['path'];
			if ( 0 === strpos( $path, 'files/contract-pdfs/' ) ) {
				if ( ! in_array( 'contracts', $job['modules'], true ) ) {
					continue;
				}
				$dest = Contract_Pdf::dir() . '/' . basename( $path );
			} else {
				$dest = self::uploads_base() . substr( $path, strlen( 'files/uploads/' ) );
			}
			if ( file_exists( $dest ) ) {
				if ( ! empty( $f['sha256'] ) && hash_file( 'sha256', $dest ) !== $f['sha256'] ) {
					$job['warnings'][] = 'Kept the file already on this site instead of the backup’s copy: ' . basename( $path );
				}
				continue;
			}
			wp_mkdir_p( dirname( $dest ) );
			$sha = Archive::extract( $zip, $path, $dest );
			if ( false === $sha || ( ! empty( $f['sha256'] ) && $sha !== $f['sha256'] ) ) {
				@unlink( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
				$zip->close();
				throw new \RuntimeException( 'A file in the backup is damaged: ' . basename( $path ) );
			}
			$job['created_files'][] = $dest;
		}
		$zip->close();
		if ( $job['fi'] >= $n ) {
			$job['phase'] = 'stage';
		}
		return $job;
	}

	/* ───────────────────────── Remapping ───────────────────────── */

	private static function map_user( array &$job, $v ) {
		$v = (int) $v;
		if ( $v <= 0 ) {
			return '0';
		}
		if ( ! array_key_exists( $v, $job['user_map'] ) ) {
			$job['user_map'][ $v ] = get_userdata( $v ) ? $v : 0;
		}
		return (string) $job['user_map'][ $v ];
	}

	private static function map_attachment( array &$job, $aid ) {
		global $wpdb;
		$aid = (int) $aid;
		if ( $aid <= 0 ) {
			return '0';
		}
		if ( isset( $job['att'][ $aid ] ) ) {
			return (string) $job['att'][ $aid ];
		}
		$rel = isset( $job['att_paths'][ $aid ] ) ? $job['att_paths'][ $aid ] : '';
		$new = 0;
		if ( '' === $rel ) {
			$new = 'attachment' === get_post_type( $aid ) ? $aid : 0;
		} else {
			$cur = get_attached_file( $aid );
			if ( $cur && substr( wp_normalize_path( $cur ), -strlen( '/' . $rel ) ) === '/' . $rel && is_file( $cur ) ) {
				$new = $aid;
			} else {
				$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_wp_attached_file' AND meta_value=%s LIMIT 1", $rel ) ); // phpcs:ignore WordPress.DB
				if ( $found ) {
					$new = $found;
				} else {
					$dest = self::uploads_base() . $rel;
					if ( is_file( $dest ) ) {
						$mime = wp_check_filetype( $dest )['type'];
						$new  = (int) wp_insert_attachment( array( 'post_mime_type' => $mime ? $mime : 'application/octet-stream', 'post_title' => preg_replace( '/\.[^.]+$/', '', basename( $dest ) ), 'post_status' => 'inherit' ), $dest );
						if ( $new ) {
							require_once ABSPATH . 'wp-admin/includes/image.php';
							wp_update_attachment_metadata( $new, wp_generate_attachment_metadata( $new, $dest ) );
							$job['created_atts'][] = $new;
						}
					}
				}
			}
		}
		if ( ! $new ) {
			$job['warnings'][] = 'A logo or receipt image from the backup could not be restored (file not in the backup).';
		}
		$job['att'][ $aid ] = $new;
		return (string) $new;
	}

	/* ───────────────────────── Stage ───────────────────────── */

	private static function p_stage( array $job, $deadline ) {
		global $wpdb;
		if ( $job['ti'] >= count( $job['tables'] ) ) {
			$job['phase'] = 'validate';
			$job['vi']    = 0;
			return $job;
		}
		$t    = $job['tables'][ $job['ti'] ];
		$live = Schema::table( $t );
		$tmp  = self::tmp( $t );
		if ( ! isset( $job['cols'][ $t ] ) ) {
			$wpdb->query( "DROP TABLE IF EXISTS `$tmp`" ); // phpcs:ignore WordPress.DB
			$wpdb->query( "DROP TABLE IF EXISTS `" . self::old( $t ) . '`' ); // phpcs:ignore WordPress.DB
			if ( false === $wpdb->query( "CREATE TABLE `$tmp` LIKE `$live`" ) ) { // phpcs:ignore WordPress.DB
				throw new \RuntimeException( 'Could not create a working copy of ' . $t . ': ' . $wpdb->last_error );
			}
			$job['cols'][ $t ] = self::live_cols( $t );
			$job['last']   = 0;
			$job['offset'] = 0;
			$job['rows']   = 0;
			$job['chk']    = '';
		}
		$cols = $job['cols'][ $t ];
		$user_cols = Module_Map::user_columns();
		$att_cols  = Module_Map::attachment_columns();
		$att_cols  = isset( $att_cols[ $t ] ) ? $att_cols[ $t ] : array();
		$wpdb->query( $wpdb->prepare( "DELETE FROM `$tmp` WHERE id > %d", (int) $job['last'] ) ); // phpcs:ignore WordPress.DB
		$fh = fopen( self::dir( $job['id'] ) . '/data/' . $t . '.ndjson', 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			throw new \RuntimeException( 'Could not read the data for ' . $t . '.' );
		}
		fseek( $fh, (int) $job['offset'] );
		$n  = 0;
		$eof = false;
		while ( $n < self::BATCH ) {
			$line = fgets( $fh );
			if ( false === $line ) {
				$eof = true;
				break;
			}
			$line = rtrim( $line, "\n" );
			if ( '' === $line ) {
				continue;
			}
			$src = Exporter::decode_row( $line );
			if ( ! is_array( $src ) || ! isset( $src['id'] ) ) {
				fclose( $fh );
				throw new \RuntimeException( 'A row in ' . $t . ' could not be read.' );
			}
			$row = array();
			foreach ( $cols as $c ) {
				if ( array_key_exists( $c, $src ) ) {
					$v = $src[ $c ];
					if ( null !== $v ) {
						if ( in_array( $c, $user_cols, true ) ) {
							$v = self::map_user( $job, $v );
						} elseif ( in_array( $c, $att_cols, true ) ) {
							$v = self::map_attachment( $job, $v );
						}
					}
					$row[ $c ] = $v;
				}
			}
			if ( false === $wpdb->insert( $tmp, $row ) ) {
				fclose( $fh );
				throw new \RuntimeException( 'Could not restore a row of ' . $t . ' (id ' . $src['id'] . '): ' . $wpdb->last_error );
			}
			$job['chk']  = Exporter::chain( $job['chk'], Exporter::encode_row( $row ) );
			$job['last'] = (int) $src['id'];
			$job['rows']++;
			$n++;
			$job['offset'] = (int) ftell( $fh );
		}
		if ( ! $eof && false === fgets( $fh ) ) {
			$eof = true;
		}
		fclose( $fh );
		if ( $eof ) {
			if ( $job['rows'] !== $job['expect'][ $t ]['rows'] ) {
				throw new \RuntimeException( 'Row count mismatch while restoring ' . $t . ' (' . $job['rows'] . ' of ' . $job['expect'][ $t ]['rows'] . ').' );
			}
			$job['stats'][ $t ] = array( 'rows' => $job['rows'], 'chk' => $job['chk'], 'identical' => $job['chk'] === $job['expect'][ $t ]['checksum'] );
			$job['ti']++;
			$job['last']   = 0;
			$job['offset'] = 0;
			$job['rows']   = 0;
			$job['chk']    = '';
		}
		return $job;
	}

	/* ───────────────────────── Validate ───────────────────────── */

	private static function p_validate( array $job, $deadline ) {
		global $wpdb;
		if ( 'tables' === $job['vstage'] ) {
			while ( $job['vi'] < count( $job['tables'] ) && microtime( true ) < $deadline ) {
				$t    = $job['tables'][ $job['vi'] ];
				$tmp  = self::tmp( $t );
				$cols = '`' . implode( '`,`', $job['cols'][ $t ] ) . '`';
				$sel  = $wpdb->get_results( $wpdb->prepare( "SELECT $cols FROM `$tmp` WHERE id > %d ORDER BY id ASC LIMIT %d", (int) $job['vlast'], self::BATCH ), ARRAY_A ); // phpcs:ignore WordPress.DB
				foreach ( (array) $sel as $r ) {
					// only the columns that were in the backup take part
					$job['vchk']  = Exporter::chain( $job['vchk'], Exporter::encode_row( array_intersect_key( $r, self::staged_keys( $job, $t ) ) ) );
					$job['vlast'] = (int) $r['id'];
					$job['vrows'] = ( $job['vrows'] ?? 0 ) + 1;
				}
				if ( count( (array) $sel ) < self::BATCH ) {
					$staged_rows = self::count( $tmp );
					if ( $staged_rows !== $job['stats'][ $t ]['rows'] || $job['vchk'] !== $job['stats'][ $t ]['chk'] ) {
						throw new \RuntimeException( 'The restored copy of ' . $t . ' did not match the backup, so nothing was changed.' );
					}
					$job['vi']++;
					$job['vlast'] = 0;
					$job['vchk']  = '';
					$job['vrows'] = 0;
				}
			}
			if ( $job['vi'] < count( $job['tables'] ) ) {
				return $job;
			}
			$job['vstage'] = 'orphans';
		}
		if ( 'orphans' === $job['vstage'] ) {
			$sel = array_flip( $job['tables'] );
			$bad = array();
			foreach ( Module_Map::refs() as $child => $edges ) {
				foreach ( $edges as $col => $parent ) {
					if ( ! isset( $sel[ $child ] ) && ! isset( $sel[ $parent ] ) ) {
						continue;
					}
					$c_new = isset( $sel[ $child ] ) ? self::tmp( $child ) : Schema::table( $child );
					$p_new = isset( $sel[ $parent ] ) ? self::tmp( $parent ) : Schema::table( $parent );
					$q     = 'SELECT COUNT(*) FROM `%s` x LEFT JOIN `%s` y ON x.' . $col . ' = y.id WHERE x.' . $col . ' > 0 AND y.id IS NULL';
					$now   = (int) $wpdb->get_var( sprintf( $q, $c_new, $p_new ) ); // phpcs:ignore WordPress.DB
					if ( $now > 0 ) {
						$before = (int) $wpdb->get_var( sprintf( $q, Schema::table( $child ), Schema::table( $parent ) ) ); // phpcs:ignore WordPress.DB
						if ( $now > $before ) {
							$bad[] = ( $now - $before ) . ' ' . str_replace( '_', ' ', $child ) . ' would point at a missing ' . str_replace( '_', ' ', $parent ) . ' record';
						}
					}
				}
			}
			if ( $bad && empty( $job['opts']['allow_orphans'] ) ) {
				throw new \RuntimeException( 'Restoring this selection would leave broken links (' . implode( '; ', array_slice( $bad, 0, 4 ) ) . '). Nothing was changed. Restore the related parts together.' );
			}
			if ( $bad ) {
				$job['warnings'][] = 'Some records now point at things that are not there: ' . implode( '; ', $bad ) . '.';
			}
			$job['vstage'] = 'contracts';
			$job['cvi']    = 0;
		}
		if ( 'contracts' === $job['vstage'] ) {
			if ( in_array( 'contract_events', $job['tables'], true ) ) {
				$ev  = self::tmp( 'contract_events' );
				$ids = isset( $job['cids'] ) ? $job['cids'] : array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT contract_id FROM `$ev` ORDER BY contract_id" ) ); // phpcs:ignore WordPress.DB
				$job['cids'] = $ids;
				while ( $job['cvi'] < count( $ids ) && microtime( true ) < $deadline ) {
					$prev = '';
					foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `$ev` WHERE contract_id=%d ORDER BY id", $ids[ $job['cvi'] ] ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
						$h = Contracts::event_hash( $prev, $r['contract_id'], $r['signer_id'], $r['event'], $r['detail'], $r['created_at'] );
						if ( $r['prev_hash'] !== $prev || $r['hash'] !== $h ) {
							throw new \RuntimeException( 'The audit log of contract #' . $ids[ $job['cvi'] ] . ' in this backup does not verify, so nothing was changed.' );
						}
						$prev = $r['hash'];
					}
					$job['cvi']++;
				}
				if ( $job['cvi'] < count( $ids ) ) {
					return $job;
				}
				unset( $job['cids'] );
			}
			$job['phase'] = 'swap';
		}
		return $job;
	}

	private static function staged_keys( array $job, $t ) {
		// All live columns that exist in the staged table; backup rows lacking a column simply get the default there,
		// so the readback is compared on the columns the backup actually carried.
		static $cache = array();
		$k = $job['id'] . $t;
		if ( ! isset( $cache[ $k ] ) ) {
			$fh  = fopen( self::dir( $job['id'] ) . '/data/' . $t . '.ndjson', 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$src = Exporter::decode_row( (string) fgets( $fh ) );
			fclose( $fh );
			$cache[ $k ] = is_array( $src ) ? array_flip( array_intersect( $job['cols'][ $t ], array_keys( $src ) ) ) : array_flip( $job['cols'][ $t ] );
		}
		return $cache[ $k ];
	}

	/* ───────────────────────── Swap ───────────────────────── */

	private static function p_swap( array $job ) {
		global $wpdb;
		$parts = array();
		foreach ( $job['tables'] as $t ) {
			$parts[] = '`' . Schema::table( $t ) . '` TO `' . self::old( $t ) . '`';
			$parts[] = '`' . self::tmp( $t ) . '` TO `' . Schema::table( $t ) . '`';
		}
		if ( $parts ) {
			if ( false === $wpdb->query( 'RENAME TABLE ' . implode( ', ', $parts ) ) ) { // phpcs:ignore WordPress.DB
				throw new \RuntimeException( 'The final switch-over failed, so nothing was changed. ' . $wpdb->last_error );
			}
		}
		$job['swapped'] = true;
		$job['phase']   = 'post';
		return $job;
	}

	/* ───────────────────────── After the swap ───────────────────────── */

	private static function p_post( array $job ) {
		global $wpdb;
		$warn = array();
		try {
			$opt = array();
			$f   = self::dir( $job['id'] ) . '/options.json';
			if ( is_file( $f ) ) {
				$opt = json_decode( (string) file_get_contents( $f ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$opt = is_array( $opt ) ? $opt : array();
			}
			$mods = $job['modules'];
			if ( isset( $opt['bizydash_settings'] ) && is_array( $opt['bizydash_settings'] ) ) {
				$live  = get_option( 'bizydash_settings', array() );
				$live  = is_array( $live ) ? $live : array();
				$owned = array( 'finance' => Module_Map::settings_keys( 'finance' ), 'contracts' => Module_Map::settings_keys( 'contracts' ) );
				$keep  = array( 'app_slug', 'app_home', 'app_login', 'app_frontend', 'app_fullscreen', 'app_keep_staff', 'delete_on_uninstall', 'delete_backups_on_uninstall' );
				$next  = $live;
				foreach ( $opt['bizydash_settings'] as $k => $v ) {
					$owner = 'core';
					foreach ( $owned as $m => $keys ) {
						if ( in_array( $k, $keys, true ) ) {
							$owner = $m;
						}
					}
					if ( ! in_array( $owner, $mods, true ) || ( 'core' === $owner && in_array( $k, $keep, true ) ) ) {
						continue;
					}
					$next[ $k ] = $v;
				}
				if ( in_array( 'core', $mods, true ) ) {
					foreach ( array( 'business_logo_id', 'business_logo_email_id' ) as $k ) {
						if ( ! empty( $next[ $k ] ) ) {
							$next[ $k ] = (int) self::map_attachment( $job, $next[ $k ] );
						}
					}
				}
				if ( in_array( 'finance', $mods, true ) && isset( $live['invoice_next'] ) && isset( $next['invoice_next'] ) ) {
					$next['invoice_next'] = max( (int) $next['invoice_next'], (int) $live['invoice_next'] ); // a gap in numbering is harmless, a duplicate is not
				}
				update_option( 'bizydash_settings', $next );
			}
			if ( in_array( 'core', $mods, true ) && isset( $opt['bizydash_mail'] ) && is_array( $opt['bizydash_mail'] ) ) {
				$cur              = get_option( 'bizydash_mail', array() );
				$mail             = $opt['bizydash_mail'];
				$mail['smtp_pass_enc'] = is_array( $cur ) && isset( $cur['smtp_pass_enc'] ) ? $cur['smtp_pass_enc'] : '';
				update_option( 'bizydash_mail', $mail );
				if ( 'smtp' === ( $mail['method'] ?? '' ) && empty( $job['same_site'] ) ) {
					$warn[] = 'Email is set to send through SMTP. Enter the SMTP password again in Settings → Email.';
				}
			}
			if ( ! empty( $job['opts']['apply_modules'] ) ) {
				foreach ( array( 'bizydash_modules', 'bizydash_modules_analytics' ) as $k ) {
					if ( isset( $opt[ $k ] ) ) {
						update_option( $k, $opt[ $k ] );
					}
				}
			}
			if ( in_array( 'finance', $mods, true ) ) {
				if ( isset( $opt['bizydash_retainer_service_id'] ) ) {
					update_option( 'bizydash_retainer_service_id', (int) $opt['bizydash_retainer_service_id'] );
				}
				if ( isset( $opt['bizydash_payout_next'] ) ) {
					update_option( 'bizydash_payout_next', max( (int) $opt['bizydash_payout_next'], (int) get_option( 'bizydash_payout_next', 1 ) ) );
				}
			}
			if ( in_array( 'contracts', $mods, true ) && isset( $opt['bizydash_contract_next'] ) ) {
				update_option( 'bizydash_contract_next', max( (int) $opt['bizydash_contract_next'], (int) get_option( 'bizydash_contract_next', 0 ) ), false );
			}
			if ( ! empty( $job['backup_schema'] ) && (int) $job['backup_schema'] < (int) Schema::VERSION ) {
				update_option( 'bizydash_db_version', (string) $job['backup_schema'] );
				\BizyDash\Install::maybe_upgrade();
			}
			if ( in_array( 'core', $mods, true ) ) {
				\BizyDash\Lookups::seed();
			}
			\BizyDash\Install::grant_capability();
			wp_cache_flush();
		} catch ( \Throwable $e ) {
			$warn[] = 'The data was restored, but finishing the settings failed: ' . $e->getMessage();
		}
		// Check what is live now against what the backup said.
		$summary = array();
		$bad     = array();
		foreach ( $job['tables'] as $t ) {
			$n = self::count( Schema::table( $t ) );
			if ( $n !== $job['expect'][ $t ]['rows'] ) {
				$bad[] = $t;
			}
			$mod = Module_Map::module_of( $t );
			$summary[ $mod ] = ( $summary[ $mod ] ?? 0 ) + $n;
			$wpdb->query( 'DROP TABLE IF EXISTS `' . self::old( $t ) . '`' ); // phpcs:ignore WordPress.DB
		}
		if ( $bad ) {
			$warn[] = 'After restoring, the row counts of ' . implode( ', ', $bad ) . ' differ from the backup. Use “Undo” to go back.';
		}
		$contracts = null;
		if ( in_array( 'contracts', $mods, true ) ) {
			$ids = array_map( 'intval', $wpdb->get_col( 'SELECT id FROM ' . Schema::table( 'contracts' ) ) ); // phpcs:ignore WordPress.DB
			$ok  = 0;
			foreach ( $ids as $cid ) {
				$i = Contracts::integrity( $cid );
				if ( $i['ok'] || ! $i['checks'] ) {
					$ok++;
				}
			}
			$contracts = array( 'ok' => $ok, 'total' => count( $ids ) );
			if ( $ok < count( $ids ) ) {
				$warn[] = ( count( $ids ) - $ok ) . ' contract' . ( count( $ids ) - $ok === 1 ? '' : 's' ) . ' did not pass the integrity check (a stored PDF may be missing from a backup made without files).';
			}
		}
		$job['warnings'] = array_values( array_unique( array_merge( $job['warnings'], $warn ) ) );
		$job['result']   = array( 'restored' => $summary, 'modules' => $mods, 'safety_id' => $job['safety_id'], 'contracts' => $contracts, 'identical' => array_keys( array_filter( wp_list_pluck( $job['stats'], 'identical' ) ) ) );
		$job['phase']    = 'done';
		delete_option( 'bizydash_fresh_install' );
		self::finish( $job );
		return $job;
	}

	/* ───────────────────────── Finish / abort ───────────────────────── */

	private static function finish( array $job ) {
		self::unlock();
		Backup_Manager::rmdir( self::dir( $job['id'] ) );
	}

	private static function abort( array $job, $msg ) {
		global $wpdb;
		if ( empty( $job['swapped'] ) ) {
			foreach ( $job['tables'] as $t ) {
				$wpdb->query( 'DROP TABLE IF EXISTS `' . self::tmp( $t ) . '`' ); // phpcs:ignore WordPress.DB
			}
			foreach ( (array) $job['created_files'] as $f ) {
				if ( is_file( $f ) ) {
					@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
				}
			}
			foreach ( (array) $job['created_atts'] as $a ) {
				wp_delete_attachment( (int) $a, true );
			}
		}
		if ( 'safety' === $job['phase'] ) {
			Backup_Manager::abandon( $job['safety'] );
		}
		self::unlock();
		Backup_Manager::rmdir( self::dir( $job['id'] ) );
		return new \WP_Error( 'bd_restore_failed', $msg . ( empty( $job['swapped'] ) ? ' Your existing data was not changed.' : '' ), array( 'status' => 500 ) );
	}

	public static function cancel( $id ) {
		$job = self::load( $id );
		if ( ! $job ) {
			self::unlock();
			return true;
		}
		if ( ! empty( $job['swapped'] ) ) {
			return new \WP_Error( 'bd_late', 'It is too late to cancel; the restore has already been applied.', array( 'status' => 409 ) );
		}
		self::abort( $job, 'Cancelled.' );
		return true;
	}
}
