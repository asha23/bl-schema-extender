<?php
/**
 * EntityMap backups + restore.
 *
 * Before any destructive change to the published entitymap.json (an import from
 * the webroot, an uploaded "verify & import", or a restore), the current file is
 * snapshotted into a private, timestamped backup. The Files screen lists these
 * and can restore any of them — a genuine "undo" for the source of truth.
 *
 * Restoring re-imports the snapshot into the database (the real source of truth)
 * and regenerates the published files, so the whole map reverts, not just the
 * JSON on disk.
 *
 * Backups live under uploads/ (outside the plugin, survives plugin updates) in a
 * directory guarded against direct listing. Only the JSON is snapshotted — the
 * HTML is always derived from the JSON/DB, so restoring the JSON rebuilds it.
 *
 * @since 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BL_EntityMap_Backups {

	const SUBDIR       = 'bl-ai-tools/entitymap-backups';
	const KEEP_OPTION  = 'bl_em_backup_keep';
	const KEEP_DEFAULT = 10;

	/** Absolute path to the private backups directory (created on demand). */
	public static function dir() {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . self::SUBDIR;

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// Guard against directory listing / casual direct access.
			@file_put_contents( trailingslashit( $dir ) . 'index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore
			@file_put_contents( trailingslashit( $dir ) . '.htaccess', "Deny from all\n" );               // phpcs:ignore
		}

		return $dir;
	}

	/**
	 * Snapshot the current entitymap.json before it is overwritten.
	 *
	 * @param string $reason Short label stored alongside the backup (e.g. "import").
	 * @return string The backup's basename, or '' if there was nothing to back up.
	 */
	public static function archive( $reason = '' ) {
		$src = ( new BL_EntityMap_Generator() )->static_path();
		if ( ! file_exists( $src ) ) {
			return '';
		}

		$stamp = gmdate( 'Ymd-His' );
		$name  = 'entitymap-' . $stamp . '.json';
		$dest  = trailingslashit( self::dir() ) . $name;

		// Avoid clobbering within the same second.
		$i = 1;
		while ( file_exists( $dest ) ) {
			$name = 'entitymap-' . $stamp . '-' . ( ++$i ) . '.json';
			$dest = trailingslashit( self::dir() ) . $name;
		}

		if ( ! @copy( $src, $dest ) ) { // phpcs:ignore
			return '';
		}

		if ( $reason !== '' ) {
			@file_put_contents( $dest . '.meta', sanitize_text_field( $reason ) ); // phpcs:ignore
		}

		self::prune();

		return $name;
	}

	/**
	 * List available backups, newest first.
	 *
	 * @return array[] Each: [ name, path, time (unix), size (bytes), reason ].
	 */
	public static function all() {
		$dir  = self::dir();
		$list = array();

		foreach ( (array) glob( trailingslashit( $dir ) . 'entitymap-*.json' ) as $path ) {
			$list[] = array(
				'name'   => basename( $path ),
				'path'   => $path,
				'time'   => (int) filemtime( $path ),
				'size'   => (int) filesize( $path ),
				'reason' => file_exists( $path . '.meta' ) ? trim( (string) file_get_contents( $path . '.meta' ) ) : '',
			);
		}

		usort( $list, function ( $a, $b ) {
			return $b['time'] <=> $a['time'];
		} );

		return $list;
	}

	/**
	 * Restore a backup: snapshot the current file, re-import the backup into the
	 * database (full sync), and regenerate the published files.
	 *
	 * @param string $name Backup basename (validated against the backups dir).
	 * @return array|WP_Error Import result [created, updated, removed] or an error.
	 */
	public static function restore( $name ) {
		$path = self::resolve( $name );
		if ( ! $path ) {
			return new WP_Error( 'bad_backup', 'Backup not found.' );
		}

		// The restore is itself undoable.
		self::archive( 'pre-restore' );

		$result = BL_EntityMap_Importer::import_file( $path, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		( new BL_EntityMap_Generator() )->regenerate();

		return $result;
	}

	/** Permanently delete a single backup. */
	public static function delete( $name ) {
		$path = self::resolve( $name );
		if ( ! $path ) {
			return false;
		}
		@unlink( $path );        // phpcs:ignore
		@unlink( $path . '.meta' ); // phpcs:ignore
		return true;
	}

	/** Download URL is intentionally absent — backups are private; served via a
	 *  nonce-checked admin action instead (see BL_EntityMap_Admin::handle_tools). */

	/** Read a backup's raw contents for a gated download. */
	public static function read( $name ) {
		$path = self::resolve( $name );
		return $path ? (string) file_get_contents( $path ) : '';
	}

	/** How many backups to retain. */
	public static function keep() {
		$n = (int) get_option( self::KEEP_OPTION, self::KEEP_DEFAULT );
		return $n > 0 ? $n : self::KEEP_DEFAULT;
	}

	/** Delete the oldest backups beyond the retention limit. */
	private static function prune() {
		$all  = self::all();
		$keep = self::keep();
		if ( count( $all ) <= $keep ) {
			return;
		}
		foreach ( array_slice( $all, $keep ) as $old ) {
			@unlink( $old['path'] );          // phpcs:ignore
			@unlink( $old['path'] . '.meta' ); // phpcs:ignore
		}
	}

	/**
	 * Validate a basename and return its absolute path inside the backups dir,
	 * or '' if it doesn't match the expected pattern / doesn't exist. Guards
	 * against path traversal.
	 */
	private static function resolve( $name ) {
		$name = basename( (string) $name );
		if ( ! preg_match( '/^entitymap-\d{8}-\d{6}(?:-\d+)?\.json$/', $name ) ) {
			return '';
		}
		$path = trailingslashit( self::dir() ) . $name;
		return file_exists( $path ) ? $path : '';
	}
}
