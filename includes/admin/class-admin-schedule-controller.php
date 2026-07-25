<?php
/**
 * Controller for the Schedules screen: create/update/delete schedules, and
 * "run now" — starts the same job pipeline a due-check would, driven by
 * the browser's own polling exactly like the manual Backup wizard (NOT the
 * cron self-chain mechanism in Schedule_Scheduler, which exists only for
 * schedule-triggered runs where nothing else is watching the job; running
 * both drivers against the same job at once would risk two concurrent
 * Job_Runner::run() calls racing on the same cursor).
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Admin_Schedule_Controller {

	const CONTENT_DIR_TOGGLES = array( 'uploads', 'plugins', 'themes', 'mu-plugins' );

	public static function boot() {
		add_filter( 'avix_migration_ajax_handlers', array( __CLASS__, 'register_ajax_handlers' ) );
	}

	public static function register_ajax_handlers( array $handlers ) {
		$handlers['avix_schedule_save']   = array( __CLASS__, 'save' );
		$handlers['avix_schedule_delete'] = array( __CLASS__, 'delete' );
		$handlers['avix_schedule_toggle'] = array( __CLASS__, 'toggle' );
		$handlers['avix_schedule_run_now'] = array( __CLASS__, 'run_now' );
		return $handlers;
	}

	public static function save() {
		$id = isset( $_POST['schedule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_id'] ) ) : '';

		$excluded_dirs = array();
		foreach ( self::CONTENT_DIR_TOGGLES as $key ) {
			if ( empty( $_POST[ 'include_' . str_replace( '-', '_', $key ) ] ) ) {
				$excluded_dirs[] = $key;
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

		$config = array(
			'name'                       => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'frequency'                  => sanitize_key( wp_unslash( $_POST['frequency'] ?? '' ) ),
			'time_of_day'                => sanitize_text_field( wp_unslash( $_POST['time_of_day'] ?? '' ) ),
			'enabled'                    => ! empty( $_POST['enabled'] ),
			'include_database'           => ! empty( $_POST['include_database'] ),
			'include_files'              => ! empty( $_POST['include_files'] ),
			'excluded_dirs'              => $excluded_dirs,
			'excluded_top_dirs'          => array_fill_keys( array_intersect( $excluded_dirs, self::CONTENT_DIR_TOGGLES ), true ),
			'skip_transients'            => ! empty( $_POST['skip_transients'] ),
			'skip_revisions'             => ! empty( $_POST['skip_revisions'] ),
			'skip_spam_trash_comments'   => ! empty( $_POST['skip_spam_trash_comments'] ),
			'all_tables'                 => ! empty( $_POST['all_tables'] ),
			'destination_id'             => isset( $_POST['destination_id'] ) ? sanitize_text_field( wp_unslash( $_POST['destination_id'] ) ) : 'local',
			'retention_keep_last'        => (int) ( $_POST['retention_keep_last'] ?? 5 ),
			'retention_older_than_days'  => (int) ( $_POST['retention_older_than_days'] ?? 0 ),
			'notify_email'               => sanitize_email( wp_unslash( $_POST['notify_email'] ?? '' ) ),
			'notify_on_success'          => ! empty( $_POST['notify_on_success'] ),
			'notify_on_failure'          => ! empty( $_POST['notify_on_failure'] ),
		);

		if ( '' !== $id && Avix_Migration_Schedule_Store::get( $id ) ) {
			Avix_Migration_Schedule_Store::update( $id, $config );
		} else {
			$id = Avix_Migration_Schedule_Store::create( $config );
		}

		Avix_Migration_Util_Logger::info( 'plugin', 'Schedule saved.', array( 'schedule_id' => $id, 'name' => $config['name'] ) );

		wp_send_json_success( array( 'schedule_id' => $id ) );
	}

	public static function delete() {
		$id = isset( $_POST['schedule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_id'] ) ) : '';
		Avix_Migration_Schedule_Store::delete( $id );
		wp_send_json_success( array( 'deleted' => $id ) );
	}

	public static function toggle() {
		$id = isset( $_POST['schedule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_id'] ) ) : '';
		$schedule = Avix_Migration_Schedule_Store::get( $id );
		if ( ! $schedule ) {
			wp_send_json_error( array( 'message' => __( 'Schedule not found.', 'avix-migration' ) ), 404 );
		}
		Avix_Migration_Schedule_Store::update( $id, array_merge( $schedule, array( 'enabled' => empty( $schedule['enabled'] ) ) ) );
		wp_send_json_success( array( 'enabled' => empty( $schedule['enabled'] ) ) );
	}

	public static function run_now() {
		$id = isset( $_POST['schedule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_id'] ) ) : '';
		$schedule = Avix_Migration_Schedule_Store::get( $id );
		if ( ! $schedule ) {
			wp_send_json_error( array( 'message' => __( 'Schedule not found.', 'avix-migration' ) ), 404 );
		}

		// Reuses the exact same job pipeline a due-check would start; the
		// browser then polls it exactly like the manual Backup wizard does.
		$meta = array(
			'include_database'         => $schedule['include_database'],
			'include_files'            => $schedule['include_files'],
			'excluded_dirs'             => $schedule['excluded_dirs'],
			'excluded_top_dirs'         => $schedule['excluded_top_dirs'],
			'skip_transients'           => $schedule['skip_transients'],
			'skip_revisions'            => $schedule['skip_revisions'],
			'skip_spam_trash_comments'  => $schedule['skip_spam_trash_comments'],
			'all_tables'                => $schedule['all_tables'],
			'schedule_id'               => $id,
			'destination_id'            => $schedule['destination_id'],
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
		Avix_Migration_Schedule_Store::record_run( $id, $job->id );

		wp_send_json_success( array( 'job_id' => $job->id ) );
	}
}
