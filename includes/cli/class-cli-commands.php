<?php
/**
 * `wp avix ...` commands. A WP-CLI process has no PHP execution time limit
 * the way a web request does, so these just loop Job_Runner::run() to
 * completion synchronously with a progress bar — no chunked-polling
 * complexity needed here, unlike the browser/cron/REST drivers.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Cli_Commands {

	public static function boot() {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}
		WP_CLI::add_command( 'avix export', array( __CLASS__, 'export' ) );
		WP_CLI::add_command( 'avix import', array( __CLASS__, 'import' ) );
		WP_CLI::add_command( 'avix status', array( __CLASS__, 'status' ) );
		WP_CLI::add_command( 'avix list', array( __CLASS__, 'list_backups' ) );
	}

	/**
	 * Creates a full-site backup.
	 *
	 * ## OPTIONS
	 *
	 * [--no-database]
	 * : Skip the database.
	 *
	 * [--no-files]
	 * : Skip wp-content files.
	 *
	 * [--exclude=<dirs>]
	 * : Comma-separated top-level wp-content dirs/patterns to exclude, on top of auto-detected ones.
	 *
	 * ## EXAMPLES
	 *
	 *     wp avix export
	 *     wp avix export --exclude=cache,uploads/2019
	 *
	 * @when after_wp_load
	 */
	public static function export( $args, $assoc_args ) {
		$excluded = array_map( 'trim', array_filter( explode( ',', $assoc_args['exclude'] ?? '' ) ) );
		foreach ( Avix_Migration_Fs_Exclusions::detect() as $auto ) {
			$excluded[] = $auto['dir'];
		}

		$meta = array(
			'include_database'  => empty( $assoc_args['no-database'] ),
			'include_files'     => empty( $assoc_args['no-files'] ),
			'excluded_dirs'     => array_values( array_unique( $excluded ) ),
			'excluded_top_dirs' => array(),
			'destination_id'    => 'local',
		);
		$steps = array(
			'Avix_Migration_Job_Steps_Export_Prepare',
			'Avix_Migration_Job_Steps_Export_Scan_Files',
			'Avix_Migration_Job_Steps_Export_Database',
			'Avix_Migration_Job_Steps_Export_Write_Archive',
			'Avix_Migration_Job_Steps_Export_Finalize',
			'Avix_Migration_Job_Steps_Export_Upload',
		);

		$job = Avix_Migration_Job_Store::create( 'export_full', $steps, $meta );
		self::run_to_completion( $job, 'Exporting' );

		if ( Avix_Migration_Job::STATUS_DONE === $job->status ) {
			WP_CLI::success( sprintf( 'Backup complete: %s', $job->meta['archive_filename'] ) );
		} else {
			WP_CLI::error( sprintf( 'Export failed: %s', $job->error ) );
		}
	}

	/**
	 * Restores a full-site or content archive.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a .avix file, or a filename already inside wp-content/avix-backups/.
	 *
	 * [--keep-current-admin=<user_login>]
	 * : For a full-site archive, re-inject this admin user so you stay logged in as them post-restore.
	 *
	 * [--conflict-mode=<mode>]
	 * : For a content archive: skip, overwrite, or duplicate. Default skip.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp avix import /path/to/site-20260101-120000-abcd1234.avix
	 *     wp avix import site-20260101-120000-abcd1234.avix --keep-current-admin=admin
	 *
	 * @when after_wp_load
	 */
	public static function import( $args, $assoc_args ) {
		$input = $args[0];
		$path = file_exists( $input ) ? $input : Avix_Migration_Archive_Store::path_for( basename( $input ) );

		if ( null === $path || ! is_readable( $path ) ) {
			WP_CLI::error( 'Archive not found: ' . $input );
		}

		$manifest = Avix_Migration_Archive_Reader::read_manifest_only( $path );
		if ( ! is_array( $manifest ) ) {
			WP_CLI::error( 'Could not read this archive\'s manifest — it may be corrupt.' );
		}

		WP_CLI::confirm(
			sprintf( 'This will restore "%s" (%s), overwriting current content. Continue?', basename( $path ), $manifest['archive_type'] ?? 'full' ),
			$assoc_args
		);

		// Import steps operate on an archive already inside the archives
		// dir — copy it in if a bare filesystem path was given.
		if ( dirname( $path ) !== rtrim( Avix_Migration_Util_Filesystem::archives_dir(), '/' ) ) {
			Avix_Migration_Util_Filesystem::create_storage_dirs();
			$dest = Avix_Migration_Util_Filesystem::archives_dir() . '/' . basename( $path );
			copy( $path, $dest );
			$path = $dest;
		}

		if ( Avix_Migration_Archive_Manifest::TYPE_CONTENT === ( $manifest['archive_type'] ?? '' ) ) {
			$mode = in_array( $assoc_args['conflict-mode'] ?? '', array( 'skip', 'overwrite', 'duplicate' ), true ) ? $assoc_args['conflict-mode'] : 'skip';
			$meta = array( 'archive_path' => $path, 'conflict_mode' => $mode, 'default_author_id' => get_current_user_id() );
			$steps = array(
				'Avix_Migration_Job_Steps_Import_Validate',
				'Avix_Migration_Job_Steps_Content_Import_Extract_Json',
				'Avix_Migration_Job_Steps_Content_Import_Extract_Media',
				'Avix_Migration_Job_Steps_Content_Import_Terms',
				'Avix_Migration_Job_Steps_Content_Import_Attachments',
				'Avix_Migration_Job_Steps_Content_Import_Posts',
				'Avix_Migration_Job_Steps_Content_Import_Finalize',
			);
		} else {
			$keep_login = $assoc_args['keep-current-admin'] ?? '';
			$keep_user  = $keep_login ? get_user_by( 'login', $keep_login ) : null;
			$meta = array(
				'archive_path'       => $path,
				'restore_database'  => true,
				'restore_files'      => true,
				'keep_current_admin' => (bool) $keep_user,
				'keep_admin_user_id' => $keep_user ? $keep_user->ID : 0,
			);
			$steps = array(
				'Avix_Migration_Job_Steps_Import_Validate',
				'Avix_Migration_Job_Steps_Import_Rollback_Snapshot',
				'Avix_Migration_Job_Steps_Import_Extract_Database',
				'Avix_Migration_Job_Steps_Import_Extract_Files',
				'Avix_Migration_Job_Steps_Import_Database_Replay',
				'Avix_Migration_Job_Steps_Import_Search_Replace',
				'Avix_Migration_Job_Steps_Import_Keep_Admin',
				'Avix_Migration_Job_Steps_Import_Finalize',
			);
		}

		$job = Avix_Migration_Job_Store::create( 'import', $steps, $meta );
		self::run_to_completion( $job, 'Importing' );

		if ( Avix_Migration_Job::STATUS_DONE === $job->status ) {
			WP_CLI::success( 'Import complete.' );
			foreach ( (array) ( $job->meta['warnings'] ?? array() ) as $warning ) {
				WP_CLI::warning( $warning );
			}
		} else {
			WP_CLI::error( sprintf( 'Import failed: %s', $job->error ) );
		}
	}

	/**
	 * Shows a job's current status.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 *
	 * @when after_wp_load
	 */
	public static function status( $args ) {
		$job = Avix_Migration_Job_Store::load( $args[0] );
		if ( null === $job ) {
			WP_CLI::error( 'Job not found.' );
		}
		WP_CLI::log( wp_json_encode( $job->progress_snapshot(), JSON_PRETTY_PRINT ) );
	}

	/**
	 * Lists stored backup archives.
	 *
	 * @when after_wp_load
	 */
	public static function list_backups() {
		$rows = array_map(
			function ( $a ) {
				return array(
					'filename'   => $a['filename'],
					'size'       => Avix_Migration_Util_Filesystem::human_size( $a['size'] ),
					'created_at' => gmdate( 'Y-m-d H:i:s', $a['created_at'] ),
				);
			},
			Avix_Migration_Archive_Store::list_all()
		);
		WP_CLI\Utils\format_items( 'table', $rows, array( 'filename', 'size', 'created_at' ) );
	}

	private static function run_to_completion( Avix_Migration_Job $job, $verb ) {
		$runner = new Avix_Migration_Job_Runner();
		$progress = \WP_CLI\Utils\make_progress_bar( $verb . '…', 100 );
		$last_percent = 0;

		while ( ! $job->is_terminal() ) {
			$runner->run( $job );
			$snapshot = $job->progress_snapshot();
			$tick = max( 0, $snapshot['percent'] - $last_percent );
			for ( $i = 0; $i < $tick; $i++ ) {
				$progress->tick();
			}
			$last_percent = $snapshot['percent'];
		}

		$progress->finish();
	}
}
