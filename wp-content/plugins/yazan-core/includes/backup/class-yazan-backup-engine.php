<?php
/**
 * Yazan Core — full-site backup & restore engine.
 *
 * A dependency-free backup subsystem: it produces a single compressed archive containing a pure-PHP
 * SQL dump of the whole database plus the entire `wp-content` tree, and can restore either half of it
 * back. No external tools (mysqldump / exec) and no third-party libraries are used — only the bundled
 * `ZipArchive` extension and `$wpdb`, so it runs anywhere the plugin does.
 *
 * The archive layout is:
 *
 *     yazan-backup.json     ← manifest (site url, versions, prefix, scope, counts, created_at)
 *     database.sql          ← the SQL dump (statements separated by a sentinel line)
 *     wp-content/…          ← the file tree (omitted for a `db`-scope backup)
 *
 * This class is UI-agnostic: it is called by both the dashboard REST controller
 * ({@see Yazan_REST_Backup}) and the wp-admin page ({@see Yazan_Backup_Admin}). Every entry point is
 * gated on `manage_options` by its caller, because a backup exposes the entire database (including
 * password hashes and secret keys) and a restore overwrites the whole site.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, lists, deletes and restores full-site backups.
 */
class Yazan_Backup_Engine {

	/** Sub-directory (under uploads) that stores the archives + their sidecar metadata. */
	const DIR_NAME = 'yazan-backups';

	/** Manifest filename inside each archive. */
	const MANIFEST = 'yazan-backup.json';

	/** SQL dump filename inside each archive. */
	const SQL_FILE = 'database.sql';

	/** Separator written after every SQL statement, so restore can split without parsing SQL. */
	const STMT_SENTINEL = "\n-- YAZAN-STMT --\n";

	/** Rows per INSERT statement during the dump (keeps any single statement modest). */
	const INSERT_BATCH = 200;

	/** Re-open the zip after this many files, so we never hold thousands of file handles at once. */
	const ZIP_FLUSH_EVERY = 400;

	/** Extensions stored (not deflated) — already-compressed data only wastes CPU on re-compression. */
	const STORE_EXTENSIONS = array(
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'ico', 'bmp',
		'mp4', 'webm', 'mov', 'avi', 'mp3', 'wav', 'ogg',
		'zip', 'gz', 'rar', '7z', 'woff', 'woff2', 'pdf',
	);

	/** Directory names pruned from the file backup wherever they appear. */
	const EXCLUDE_DIR_NAMES = array( 'node_modules', '.git', '.svn', 'cache', '.DS_Store' );

	/* --------------------------------------------------------------------- */
	/* Storage location                                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * Absolute path to the backups directory, created and hardened on first use.
	 *
	 * @return string
	 */
	public static function dir() {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . self::DIR_NAME;

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		self::harden_dir( $dir );

		return $dir;
	}

	/**
	 * Drop the standard "silence is golden" guards so the directory can't be browsed or (on Apache)
	 * downloaded directly. On nginx the .htaccess is inert, which is why archive filenames also carry
	 * a 32-char random token and downloads are only ever served through an authenticated endpoint.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private static function harden_dir( $dir ) {
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Is the ZipArchive extension available? Both create and restore need it.
	 *
	 * @return bool
	 */
	public static function is_supported() {
		return class_exists( 'ZipArchive' );
	}

	/* --------------------------------------------------------------------- */
	/* Listing                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * All stored backups, newest first. Each entry is the sidecar metadata plus the live file size.
	 *
	 * @return array[]
	 */
	public static function all() {
		$dir  = self::dir();
		$list = array();

		foreach ( (array) glob( trailingslashit( $dir ) . 'yazan-backup-*.zip' ) as $zip_path ) {
			$id   = self::id_from_path( $zip_path );
			$meta = self::read_sidecar( $id );

			$list[] = array(
				'id'         => $id,
				'filename'   => basename( $zip_path ),
				'size'       => (int) ( @filesize( $zip_path ) ?: 0 ),
				'created_at' => $meta['created_at'] ?? '',
				'scope'      => $meta['scope'] ?? 'full',
				'tables'     => isset( $meta['tables'] ) ? (int) $meta['tables'] : null,
				'files'      => isset( $meta['files'] ) ? (int) $meta['files'] : null,
				'wp_version' => $meta['wp_version'] ?? '',
				'wc_version' => $meta['wc_version'] ?? '',
				'db_prefix'  => $meta['db_prefix'] ?? '',
				'note'       => $meta['note'] ?? '',
			);
		}

		usort(
			$list,
			static function ( $a, $b ) {
				return strcmp( (string) $b['created_at'], (string) $a['created_at'] );
			}
		);

		return $list;
	}

	/**
	 * Metadata for a single backup, or null if the id is unknown.
	 *
	 * @param string $id Backup id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$path = self::path_for( $id );
		if ( ! $path ) {
			return null;
		}
		foreach ( self::all() as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}
		return null;
	}

	/* --------------------------------------------------------------------- */
	/* Creation                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Build a new backup.
	 *
	 * @param string $scope 'full' (database + wp-content) or 'db' (database only).
	 * @param string $note  Optional human note stored in the manifest (e.g. a safety-snapshot label).
	 * @return array|WP_Error Backup metadata on success.
	 */
	public static function create( $scope = 'full', $note = '' ) {
		if ( ! self::is_supported() ) {
			return new WP_Error( 'yazan_no_zip', __( 'The PHP ZipArchive extension is not available on this server.', 'yazan' ), array( 'status' => 501 ) );
		}

		$scope = 'db' === $scope ? 'db' : 'full';
		self::raise_limits();

		$dir       = self::dir();
		$id        = self::new_id();
		$final_zip = trailingslashit( $dir ) . 'yazan-backup-' . $id . '.zip';
		$tmp_zip   = $final_zip . '.tmp';
		$sql_path  = trailingslashit( $dir ) . 'db-' . $id . '.sql.tmp';

		// 1. Dump the database to a temporary .sql file.
		$dump = self::dump_database( $sql_path );
		if ( is_wp_error( $dump ) ) {
			@unlink( $sql_path );
			return $dump;
		}

		// 2. Assemble the archive.
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			@unlink( $sql_path );
			return new WP_Error( 'yazan_zip_open', __( 'Could not create the archive file.', 'yazan' ), array( 'status' => 500 ) );
		}

		$zip->addFile( $sql_path, self::SQL_FILE );

		$file_count = 0;
		if ( 'full' === $scope ) {
			// add_content_tree flushes by closing/re-opening the archive periodically; on return $zip
			// is always a valid, open handle pointing at $tmp_zip.
			$file_count = self::add_content_tree( $zip, $tmp_zip );
		}

		$manifest = array(
			'generator'  => 'yazan-core',
			'version'    => defined( 'YAZAN_CORE_VERSION' ) ? YAZAN_CORE_VERSION : '',
			'scope'      => $scope,
			'note'       => (string) $note,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
			'site_url'   => home_url(),
			'wp_version' => get_bloginfo( 'version' ),
			'wc_version' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'db_name'    => defined( 'DB_NAME' ) ? DB_NAME : '',
			'db_prefix'  => $GLOBALS['wpdb']->prefix,
			'tables'     => (int) $dump['tables'],
			// Restore compares against this. A count that does not match is a failed restore.
			'statements' => isset( $dump['statements'] ) ? (int) $dump['statements'] : 0,
			'files'      => (int) $file_count,
			'php'        => PHP_VERSION,
		);
		$zip->addFromString( self::MANIFEST, wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

		if ( true !== $zip->close() ) {
			@unlink( $sql_path );
			@unlink( $tmp_zip );
			return new WP_Error( 'yazan_zip_write', __( 'Could not finalise the archive. Check disk space.', 'yazan' ), array( 'status' => 500 ) );
		}

		@unlink( $sql_path );

		// 3. Atomic reveal: only now does a valid, complete .zip appear in the listing.
		if ( ! @rename( $tmp_zip, $final_zip ) ) {
			@unlink( $tmp_zip );
			return new WP_Error( 'yazan_finalise', __( 'Could not finalise the backup file.', 'yazan' ), array( 'status' => 500 ) );
		}

		// 4. Sidecar metadata (so listing never has to open the archive).
		self::write_sidecar( $id, $manifest );

		return self::get( $id );
	}

	/**
	 * Stream the whole wp-content tree into the open archive, flushing periodically.
	 *
	 * @param ZipArchive $zip     Open archive (passed by reference; may be re-opened).
	 * @param string     $tmp_zip Archive path, needed to re-open after a flush.
	 * @return int Number of files added.
	 */
	private static function add_content_tree( ZipArchive &$zip, $tmp_zip ) {
		$root         = wp_normalize_path( WP_CONTENT_DIR );
		$backups_dir  = wp_normalize_path( self::dir() );
		$root_len     = strlen( trailingslashit( $root ) );
		$added        = 0;
		$since_flush  = 0;

		$dir_iter = new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS );
		$filtered = new RecursiveCallbackFilterIterator(
			$dir_iter,
			static function ( $current ) use ( $backups_dir ) {
				$path = wp_normalize_path( $current->getPathname() );
				// Never recurse into our own backups directory.
				if ( 0 === strpos( $path . '/', $backups_dir . '/' ) ) {
					return false;
				}
				if ( $current->isDir() && in_array( $current->getFilename(), self::EXCLUDE_DIR_NAMES, true ) ) {
					return false;
				}
				return true;
			}
		);
		$iter = new RecursiveIteratorIterator( $filtered, RecursiveIteratorIterator::LEAVES_ONLY );

		foreach ( $iter as $file ) {
			if ( ! $file->isFile() || $file->isLink() ) {
				continue;
			}
			$abs   = wp_normalize_path( $file->getPathname() );
			$rel   = substr( $abs, $root_len );
			$local = 'wp-content/' . $rel;

			if ( ! $zip->addFile( $abs, $local ) ) {
				continue; // Skip a locked/unreadable file rather than abort the whole backup.
			}
			if ( in_array( strtolower( $file->getExtension() ), self::STORE_EXTENSIONS, true ) ) {
				$zip->setCompressionName( $local, ZipArchive::CM_STORE );
			}

			++$added;
			++$since_flush;

			if ( $since_flush >= self::ZIP_FLUSH_EVERY ) {
				$zip->close();
				$zip = new ZipArchive();
				$zip->open( $tmp_zip ); // Re-open the existing archive and keep appending.
				$since_flush = 0;
			}
		}

		return $added;
	}

	/* --------------------------------------------------------------------- */
	/* Database dump (pure PHP)                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Dump every table in the database to a .sql file, statement-by-statement.
	 *
	 * @param string $sql_path Destination path.
	 * @return array|WP_Error { tables: int } on success.
	 */
	private static function dump_database( $sql_path ) {
		global $wpdb;

		$handle = fopen( $sql_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return new WP_Error( 'yazan_dump_open', __( 'Could not open a temporary file for the database dump.', 'yazan' ), array( 'status' => 500 ) );
		}

		/*
		 * Count what we emit so restore can prove it executed the same number. Without this the
		 * only evidence a restore worked was that it did not crash.
		 */
		$statements = 0;

		$write = static function ( $sql ) use ( $handle, &$statements ) {
			fwrite( $handle, $sql . self::STMT_SENTINEL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			++$statements;
		};

		/*
		 * THE HEADER GETS ITS OWN SENTINEL. Without one it fused to the statement that follows,
		 * and since the combined buffer then began with `--` the importer skipped the whole thing
		 * as a comment — so `SET FOREIGN_KEY_CHECKS=0` was NEVER executed on any restore. Every
		 * `DROP TABLE` ran with foreign-key checks on, which is exactly the condition under which
		 * dropping WooCommerce's constrained tables fails. Silently, because errors are suppressed.
		 */
		fwrite( $handle, '-- Yazan Core database backup' . "\n" . '-- Generated: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC' . self::STMT_SENTINEL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		$write( 'SET FOREIGN_KEY_CHECKS=0' );
		$write( 'SET NAMES ' . ( defined( 'DB_CHARSET' ) && DB_CHARSET ? DB_CHARSET : 'utf8mb4' ) );

		$tables = $wpdb->get_col( 'SHOW TABLES' );
		if ( empty( $tables ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'yazan_dump_empty', __( 'No database tables were found to back up.', 'yazan' ), array( 'status' => 500 ) );
		}

		foreach ( $tables as $table ) {
			// Table name comes from SHOW TABLES (trusted), but wrap in backticks defensively.
			$quoted = '`' . str_replace( '`', '``', $table ) . '`';

			$write( "DROP TABLE IF EXISTS {$quoted}" );

			$create = $wpdb->get_row( "SHOW CREATE TABLE {$quoted}", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( isset( $create[1] ) ) {
				$write( $create[1] );
			}

			self::dump_table_rows( $handle, $write, $table, $quoted );
		}

		$write( 'SET FOREIGN_KEY_CHECKS=1' );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return array(
			'tables'     => count( $tables ),
			'statements' => $statements,
		);
	}

	/**
	 * Append a table's rows as batched INSERT statements.
	 *
	 * @param resource $handle  File handle (for the columns line only).
	 * @param callable $write   Statement writer (adds the sentinel).
	 * @param string   $table   Raw table name.
	 * @param string   $quoted  Back-quoted table name.
	 * @return void
	 */
	/**
	 * Escape a value for a literal SQL string in the dump.
	 *
	 * ⚠️ NOT `$wpdb->_real_escape()` ALONE — THAT SILENTLY DESTROYS EVERY `%`.
	 *
	 * `_real_escape()` ends with `add_placeholder_escape()`, which swaps each `%` for a random
	 * per-request hash so that a later `prepare()` cannot mistake data for a placeholder. That is
	 * correct for a query about to be prepared and catastrophic for text about to be written to a
	 * file: the hash goes into the dump verbatim, and on restore it comes back as literal data.
	 * Every "20% off" coupon, every `width: 100%`, every percent-encoded URL was being replaced by
	 * a 66-character hash — and because the hash is regenerated per request, it was not even
	 * reversible afterwards.
	 *
	 * `remove_placeholder_escape()` undoes exactly that last step and nothing else, so quotes,
	 * backslashes, newlines and control bytes stay properly escaped. Verified to round-trip
	 * through MySQL for `%`, newlines, quotes and backslashes.
	 *
	 * @param string $value Raw column value.
	 * @return string
	 */
	private static function escape_value( $value ) {
		global $wpdb;

		return $wpdb->remove_placeholder_escape( $wpdb->_real_escape( (string) $value ) );
	}

	private static function dump_table_rows( $handle, callable $write, $table, $quoted ) {
		global $wpdb;

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$quoted}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( empty( $columns ) ) {
			return;
		}
		$col_list = implode(
			', ',
			array_map(
				static function ( $c ) {
					return '`' . str_replace( '`', '``', $c ) . '`';
				},
				$columns
			)
		);

		$offset = 0;
		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$quoted} LIMIT %d OFFSET %d", self::INSERT_BATCH, $offset ), ARRAY_A );
			if ( empty( $rows ) ) {
				break;
			}

			$values = array();
			foreach ( $rows as $row ) {
				$cells = array();
				foreach ( $columns as $col ) {
					$val = $row[ $col ] ?? null;
					if ( null === $val ) {
						$cells[] = 'NULL';
					} else {
						$cells[] = "'" . self::escape_value( $val ) . "'";
					}
				}
				$values[] = '(' . implode( ', ', $cells ) . ')';
			}

			$write( "INSERT INTO {$quoted} ({$col_list}) VALUES " . implode( ', ', $values ) );

			$offset += self::INSERT_BATCH;
		} while ( count( $rows ) === self::INSERT_BATCH );
	}

	/* --------------------------------------------------------------------- */
	/* Restore                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Restore from a stored backup id.
	 *
	 * @param string $id            Backup id.
	 * @param bool   $safety_first  Take an automatic DB-only snapshot before overwriting.
	 * @return array|WP_Error Summary on success.
	 */
	public static function restore( $id, $safety_first = true ) {
		$path = self::path_for( $id );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'yazan_not_found', __( 'That backup could not be found.', 'yazan' ), array( 'status' => 404 ) );
		}
		return self::restore_from_file( $path, $safety_first );
	}

	/**
	 * Does this archive's database identity differ from the one we are connected to?
	 *
	 * Returns a human-readable reason, or '' when the archive is safe to restore here. A value the
	 * manifest never recorded (older archives) is treated as "unknown", not as a mismatch —
	 * refusing those would strand every backup taken before this check existed.
	 *
	 * @param array $manifest Decoded archive manifest.
	 * @return string Reason to refuse, or '' to allow.
	 */
	private static function database_mismatch( array $manifest ) {
		global $wpdb;

		$archive_db     = (string) ( $manifest['db_name'] ?? '' );
		$archive_prefix = (string) ( $manifest['db_prefix'] ?? '' );
		$current_db     = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
		$current_prefix = (string) $wpdb->prefix;

		if ( '' !== $archive_db && '' !== $current_db && $archive_db !== $current_db ) {
			return sprintf(
				/* translators: 1: database in the archive, 2: database currently connected. */
				__( 'This archive was taken from the database "%1$s", but this site is connected to "%2$s". Restoring it would overwrite one database with another one\'s contents.', 'yazan' ),
				$archive_db,
				$current_db
			);
		}

		if ( '' !== $archive_prefix && $archive_prefix !== $current_prefix ) {
			return sprintf(
				/* translators: 1: table prefix in the archive, 2: table prefix in use. */
				__( 'This archive uses the table prefix "%1$s", but this site uses "%2$s". The restored tables would not be the ones WordPress reads.', 'yazan' ),
				$archive_prefix,
				$current_prefix
			);
		}

		return '';
	}

	/**
	 * Restore from any archive on disk (a stored backup or a freshly uploaded one).
	 *
	 * @param string $zip_path     Absolute path to the .zip.
	 * @param bool   $safety_first Take an automatic DB-only snapshot before overwriting.
	 * @return array|WP_Error
	 */
	public static function restore_from_file( $zip_path, $safety_first = true ) {
		if ( ! self::is_supported() ) {
			return new WP_Error( 'yazan_no_zip', __( 'The PHP ZipArchive extension is not available on this server.', 'yazan' ), array( 'status' => 501 ) );
		}
		self::raise_limits();

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'yazan_zip_bad', __( 'That file is not a readable archive.', 'yazan' ), array( 'status' => 400 ) );
		}

		// Validate the manifest — refuse to restore an archive that isn't ours.
		$manifest_json = $zip->getFromName( self::MANIFEST );
		$manifest      = $manifest_json ? json_decode( $manifest_json, true ) : null;
		if ( ! is_array( $manifest ) || ( $manifest['generator'] ?? '' ) !== 'yazan-core' ) {
			$zip->close();
			return new WP_Error( 'yazan_manifest', __( 'This archive was not created by Yazan Core, so it cannot be restored here.', 'yazan' ), array( 'status' => 400 ) );
		}

		$has_sql   = false !== $zip->locateName( self::SQL_FILE );
		$has_files = false !== $zip->locateName( 'wp-content/', ZipArchive::FL_NOCASE ) || self::zip_has_prefix( $zip, 'wp-content/' );

		if ( ! $has_sql && ! $has_files ) {
			$zip->close();
			return new WP_Error( 'yazan_empty', __( 'This archive contains neither a database nor files to restore.', 'yazan' ), array( 'status' => 400 ) );
		}

		/*
		 * Refuse to restore a dump taken from a DIFFERENT database or table prefix.
		 *
		 * This became load-bearing the moment a second schema existed: the test harness runs on
		 * `local_tests`, and a restore is the one operation that can silently overwrite an entire
		 * database with another one's contents. The manifest has always recorded `db_name` and
		 * `db_prefix` (see create()); nothing ever read them back until now.
		 *
		 * Missing values mean an archive written before this check shipped — those are allowed
		 * through, because refusing them would strand every existing backup.
		 */
		if ( $has_sql ) {
			$mismatch = self::database_mismatch( $manifest );

			if ( '' !== $mismatch && ! apply_filters( 'yazan_backup_allow_cross_database', false, $manifest, $zip_path ) ) {
				$zip->close();
				return new WP_Error(
					'yazan_db_mismatch',
					$mismatch,
					array( 'status' => 409 )
				);
			}
		}

		// Safety snapshot of the current database before we overwrite anything.
		$safety = null;
		if ( $safety_first && $has_sql ) {
			$snap = self::create( 'db', 'Automatic safety snapshot taken before a restore.' );
			if ( ! is_wp_error( $snap ) ) {
				$safety = $snap['id'];
			}
		}

		$summary = array(
			'scope'         => $manifest['scope'] ?? 'full',
			'db_restored'   => false,
			'files_restored'=> 0,
			'safety_backup' => $safety,
		);

		// 1. Files first — extract wp-content/* over the live tree.
		if ( $has_files ) {
			$summary['files_restored'] = self::extract_content( $zip );
		}

		// 2. Database — run each statement in the dump.
		if ( $has_sql ) {
			$sql = $zip->getStream( self::SQL_FILE );
			if ( $sql ) {
				$imported = self::import_database_stream( $sql );
				fclose( $sql ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				if ( is_wp_error( $imported ) ) {
					$zip->close();
					return $imported;
				}
				$summary['db_restored']    = true;
				$summary['db_statements']  = $imported;
			}
		}

		$zip->close();

		// Rewrite rules and object caches may reference the old dataset.
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		return $summary;
	}

	/**
	 * Extract every `wp-content/…` entry over the live content directory.
	 *
	 * @param ZipArchive $zip Open archive.
	 * @return int Files written.
	 */
	private static function extract_content( ZipArchive $zip ) {
		$content_root = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$written      = 0;

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( '' === $name || 0 !== strpos( $name, 'wp-content/' ) ) {
				continue;
			}
			$relative = substr( $name, strlen( 'wp-content/' ) );
			if ( '' === $relative ) {
				continue;
			}

			// Path-traversal (zip-slip) guard. wp_normalize_path() only flips slashes — it does NOT
			// collapse "../" segments — so a prefix check on the un-resolved path is insufficient: an
			// entry named "wp-content/../../wp-config.php" starts with $content_root yet resolves
			// OUTSIDE it once the OS follows the "..". Reject any traversal/absolute segment outright,
			// then confirm the canonicalised parent directory stays inside wp-content, and never write
			// through a symlink.
			$relative = wp_normalize_path( $relative );
			if ( '..' === $relative || 0 === strpos( $relative, '../' ) || false !== strpos( $relative, '/../' ) || 0 === strpos( $relative, '/' ) ) {
				continue;
			}

			// Path-traversal guard: the resolved target must stay inside wp-content.
			$target = wp_normalize_path( $content_root . $relative );
			if ( 0 !== strpos( $target, $content_root ) ) {
				continue;
			}

			if ( '/' === substr( $name, -1 ) ) {
				wp_mkdir_p( $target );
				continue;
			}

			wp_mkdir_p( dirname( $target ) );

			// Refuse to write if the (now-created) parent resolves outside wp-content, or if the
			// target is a symlink pointing elsewhere.
			$real_parent = realpath( dirname( $target ) );
			if ( false === $real_parent || 0 !== strpos( trailingslashit( wp_normalize_path( $real_parent ) ), $content_root ) ) {
				continue;
			}
			if ( is_link( $target ) ) {
				continue;
			}
			$stream = $zip->getStream( $name );
			if ( ! $stream ) {
				continue;
			}
			$out = fopen( $target, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( $out ) {
				stream_copy_to_stream( $stream, $out );
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				++$written;
			}
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		return $written;
	}

	/**
	 * Run a .sql dump produced by {@see dump_database()} from an open stream.
	 * Statements are split on the sentinel line, so data containing semicolons is never mis-parsed.
	 *
	 * @param resource $stream Readable stream of the .sql file.
	 * @return int|WP_Error Number of statements executed.
	 */
	private static function import_database_stream( $stream ) {
		global $wpdb;

		$sentinel = trim( self::STMT_SENTINEL );
		$buffer   = '';
		$count    = 0;
		$skipped  = 0;
		$failed   = array();
		$suppress = $wpdb->suppress_errors( true );

		/*
		 * Errors are suppressed so one bad statement does not abort the whole import — but they are
		 * now COLLECTED rather than discarded. Previously a restore in which half the statements
		 * failed reported success, which made the backup engine worse than useless as a rollback
		 * path: it told you that you were safe when you were not.
		 *
		 * Only the first few messages are kept; a systemic failure produces thousands of identical
		 * ones and the useful signal is the count, not the list.
		 */
		$run = static function ( $statement ) use ( $wpdb, &$count, &$skipped, &$failed ) {
			$statement = trim( $statement );
			if ( '' === $statement || 0 === strpos( $statement, '--' ) ) {
				++$skipped;
				return true;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query( $statement );

			if ( false === $result ) {
				if ( count( $failed ) < 10 ) {
					$failed[] = array(
						'error'     => (string) $wpdb->last_error,
						'statement' => substr( $statement, 0, 160 ),
					);
				}
			} else {
				++$count;
			}

			return $result;
		};

		while ( ! feof( $stream ) ) {
			$line = fgets( $stream );
			if ( false === $line ) {
				break;
			}
			if ( trim( $line ) === $sentinel ) {
				$run( $buffer );
				$buffer = '';
				continue;
			}
			$buffer .= $line;
		}
		if ( '' !== trim( $buffer ) ) {
			$run( $buffer );
		}

		$wpdb->suppress_errors( $suppress );

		return array(
			'executed' => $count,
			'skipped'  => $skipped,
			'failed'   => count( $failed ),
			'errors'   => $failed,
		);
	}

	/* --------------------------------------------------------------------- */
	/* Deletion                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Delete a stored backup (archive + sidecar).
	 *
	 * @param string $id Backup id.
	 * @return bool
	 */
	public static function delete( $id ) {
		$path = self::path_for( $id );
		if ( ! $path ) {
			return false;
		}
		$ok = @unlink( $path );
		@unlink( self::sidecar_path( $id ) );
		return (bool) $ok;
	}

	/**
	 * Delete every stored backup except the newest $keep. Called after a successful create.
	 *
	 * @param int $keep Number of most-recent backups to retain (0 = keep all).
	 * @return int Number pruned.
	 */
	public static function prune( $keep ) {
		$keep = (int) $keep;
		if ( $keep <= 0 ) {
			return 0;
		}
		$all     = self::all(); // Already newest-first.
		$pruned  = 0;
		foreach ( array_slice( $all, $keep ) as $entry ) {
			if ( self::delete( $entry['id'] ) ) {
				++$pruned;
			}
		}
		return $pruned;
	}

	/* --------------------------------------------------------------------- */
	/* Ids, paths, sidecars                                                    */
	/* --------------------------------------------------------------------- */

	/**
	 * A fresh backup id: a UTC timestamp plus a random token (the token is what makes the archive's
	 * URL unguessable on servers where the directory guard is inert, e.g. nginx).
	 *
	 * @return string
	 */
	private static function new_id() {
		return gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 24, false, false );
	}

	/**
	 * Validate an id and resolve it to its archive path, or false if unsafe/unknown.
	 * The id may only contain the characters {@see new_id()} produces — this is the traversal guard.
	 *
	 * @param string $id Backup id.
	 * @return string|false
	 */
	public static function path_for( $id ) {
		if ( ! is_string( $id ) || ! preg_match( '/^[A-Za-z0-9\-]{10,80}$/', $id ) ) {
			return false;
		}
		$path = trailingslashit( self::dir() ) . 'yazan-backup-' . $id . '.zip';
		return file_exists( $path ) ? $path : false;
	}

	/**
	 * Recover the id from a full archive path.
	 *
	 * @param string $path Archive path.
	 * @return string
	 */
	private static function id_from_path( $path ) {
		$base = basename( $path, '.zip' );
		return preg_replace( '/^yazan-backup-/', '', $base );
	}

	/**
	 * Sidecar (.json) path for an id.
	 *
	 * @param string $id Backup id.
	 * @return string
	 */
	private static function sidecar_path( $id ) {
		return trailingslashit( self::dir() ) . 'yazan-backup-' . $id . '.json';
	}

	/**
	 * Persist manifest metadata alongside the archive so listing never opens the zip.
	 *
	 * @param string $id       Backup id.
	 * @param array  $manifest Manifest.
	 * @return void
	 */
	private static function write_sidecar( $id, array $manifest ) {
		file_put_contents( self::sidecar_path( $id ), wp_json_encode( $manifest ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Read sidecar metadata for an id.
	 *
	 * @param string $id Backup id.
	 * @return array
	 */
	private static function read_sidecar( $id ) {
		$path = self::sidecar_path( $id );
		if ( ! file_exists( $path ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		return is_array( $data ) ? $data : array();
	}

	/* --------------------------------------------------------------------- */
	/* Misc helpers                                                            */
	/* --------------------------------------------------------------------- */

	/**
	 * Does the archive contain any entry under the given prefix?
	 *
	 * @param ZipArchive $zip    Archive.
	 * @param string     $prefix Prefix.
	 * @return bool
	 */
	private static function zip_has_prefix( ZipArchive $zip, $prefix ) {
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			if ( 0 === strpos( (string) $zip->getNameIndex( $i ), $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Give long-running backup/restore work room to finish.
	 *
	 * @return void
	 */
	private static function raise_limits() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		@ini_set( 'memory_limit', '512M' );
		ignore_user_abort( true );
	}
}
