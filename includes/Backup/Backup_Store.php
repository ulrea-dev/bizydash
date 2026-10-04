<?php
namespace BizyDash\Backup;

defined( 'ABSPATH' ) || exit;

/**
 * Where finished backups live. Only the local (this site) store exists today; an S3 / Google Drive / Dropbox
 * store can implement the same four methods later and be pushed to from the `bizydash_backup_created` action.
 */
interface Backup_Store {

	/** Adopt a finished archive file and its metadata. Returns the backup id. */
	public function put( $local_path, array $meta );

	/** @return array[] Metadata of every stored backup, newest first. */
	public function all();

	/** @return array|null */
	public function find( $id );

	/** Absolute local path of the archive (a remote store would download it first). Empty if missing. */
	public function path( $id );

	public function delete( $id );
}
