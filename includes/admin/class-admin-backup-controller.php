<?php
/**
 * Controller for the Backup wizard: turns the form choices into a job's
 * $meta and hands back a job id for the browser to start polling. All the
 * actual work happens in the Export_* step pipeline (includes/job/steps/export/) —
 * this class only translates "what the user picked" into "what the job needs to know".
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Admin_Backup_Controller {

	const CONTENT_DIR_TOGGLES = array( 'uploads', 'plugins', 'themes', 'mu-plugins' );

	public static function boot() {
		add_filter( 'avix_migration_ajax_handlers', array( __CLASS__, 'register_ajax_handlers' ) );
	}

	public static function register_ajax_handlers( array $handlers ) {
		$handlers['avix_start_backup']    = array( __CLASS__, 'start_backup' );
		$handlers['avix_detect_exclusions'] = array( __CLASS__, 'detect_exclusions' );
		return $handlers;
	}

	/** Re-run auto-detection on demand (the wizard calls this once on load). */
	public static function detect_exclusions() {
		wp_send_json_success( array( 'detected' => Avix_Migration_Fs_Exclusions::detect() ) );
	}

	public static function start_backup() {
		$include_database = ! empty( $_POST['include_database'] );
		$include_files    = ! empty( $_POST['include_files'] );

		$content_toggles = array();
		foreach ( self::CONTENT_DIR_TOGGLES as $key ) {
			// Checkbox absent in POST = unchecked = excluded.
			$content_toggles[ $key ] = ! empty( $_POST[ 'include_' . str_replace( '-', '_', $key ) ] );
		}

		$excluded_dirs = array();
		foreach ( $content_toggles as $dir => $enabled ) {
			if ( ! $enabled ) {
				$excluded_dirs[] = $dir;
			}
		}

		if ( ! empty( $_POST['exclude_auto'] ) && is_array( $_POST['exclude_auto'] ) ) {
			foreach ( wp_unslash( $_POST['exclude_auto'] ) as $dir ) {
				$excluded_dirs[] = sanitize_text_field( $dir );
			}
		}

		if ( ! empty( $_POST['exclude_custom'] ) ) {
			$custom = sanitize_textarea_field( wp_unslash( $_POST['exclude_custom'] ) );
			foreach ( preg_split( '/[\r\n]+/', $custom ) as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$excluded_dirs[] = $line;
				}
			}
		}

		$meta = array(
			'include_database'         => $include_database,
			'include_files'            => $include_files,
			'all_tables'                => ! empty( $_POST['all_tables'] ),
			'skip_transients'           => ! empty( $_POST['skip_transients'] ),
			'skip_revisions'            => ! empty( $_POST['skip_revisions'] ),
			'skip_spam_trash_comments'  => ! empty( $_POST['skip_spam_trash_comments'] ),
			'excluded_dirs'             => array_values( array_unique( $excluded_dirs ) ),
			// array_fill_keys, NOT array_flip: array_flip's VALUES are the
			// original array's numeric index, which is legitimately 0 for
			// whichever excluded dir happens to come first — and
			// empty($arr['uploads']) treats a 0 value as "not set", so
			// array_flip would silently misreport an actually-excluded
			// top-level dir as included whenever it landed at index 0.
			'excluded_top_dirs'         => array_fill_keys( array_intersect( $excluded_dirs, self::CONTENT_DIR_TOGGLES ), true ),
			'destination_id'            => isset( $_POST['destination_id'] ) ? sanitize_text_field( wp_unslash( $_POST['destination_id'] ) ) : 'local',
		);

		if ( ! $include_database && ! $include_files ) {
			wp_send_json_error( array( 'message' => __( 'Select at least one of database or files to back up.', 'avix-migration' ) ), 400 );
		}

		$steps = array(
			'Avix_Migration_Job_Steps_Export_Prepare',
			'Avix_Migration_Job_Steps_Export_Scan_Files',
			'Avix_Migration_Job_Steps_Export_Database',
			'Avix_Migration_Job_Steps_Export_Write_Archive',
			'Avix_Migration_Job_Steps_Export_Finalize',
			'Avix_Migration_Job_Steps_Export_Upload',
		);

		$job = Avix_Migration_Job_Store::create( 'export_full', $steps, $meta );

		Avix_Migration_Util_Logger::info(
			$job->id,
			'Backup job created.',
			array(
				'include_database' => $include_database,
				'include_files'    => $include_files,
				'excluded_dirs'    => $meta['excluded_dirs'],
			)
		);

		wp_send_json_success( array( 'job_id' => $job->id ) );
	}
}
