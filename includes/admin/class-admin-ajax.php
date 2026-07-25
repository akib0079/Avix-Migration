<?php
/**
 * Central AJAX router. Every wp_ajax_avix_* callback funnels through
 * dispatch(), which enforces the nonce + capability check once instead of
 * repeating it in every handler — a handler that forgets the check is the
 * classic way a WordPress plugin ships an unauthenticated action.
 *
 * Job-progress endpoints (run_step / progress / cancel) live here because
 * every wizard (backup, import, content export, transfer) polls the same
 * generic Job_Runner — they are shell infrastructure, not feature-specific.
 * Feature-specific endpoints (start a backup, start an import, etc.) are
 * registered by their own milestone's admin controller and added to
 * self::handlers() when built.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Admin_Ajax {

	const NONCE_ACTION = 'avix_migration_ajax';

	public static function boot() {
		foreach ( array_keys( self::handlers() ) as $action ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, 'dispatch' ) );
		}
	}

	/**
	 * action => callable. Extend this list (via a filter, so later
	 * milestones don't need to edit this file) as each feature area's
	 * controller is built.
	 */
	public static function handlers() {
		$handlers = array(
			'avix_job_run_step' => array( __CLASS__, 'job_run_step' ),
			'avix_job_progress'  => array( __CLASS__, 'job_progress' ),
			'avix_job_cancel'    => array( __CLASS__, 'job_cancel' ),
			'avix_job_log'       => array( __CLASS__, 'job_log' ),
		);
		return apply_filters( 'avix_migration_ajax_handlers', $handlers );
	}

	public static function dispatch() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( Avix_Migration_Admin_Menu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'avix-migration' ) ), 403 );
		}

		$action   = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		$handlers = self::handlers();

		if ( ! isset( $handlers[ $action ] ) || ! is_callable( $handlers[ $action ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown action.', 'avix-migration' ) ), 400 );
		}

		call_user_func( $handlers[ $action ] );
		wp_send_json_error( array( 'message' => __( 'Handler did not respond.', 'avix-migration' ) ), 500 );
	}

	private static function get_job_or_fail() {
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$job    = $job_id ? Avix_Migration_Job_Store::load( $job_id ) : null;
		if ( null === $job ) {
			wp_send_json_error( array( 'message' => __( 'Job not found.', 'avix-migration' ) ), 404 );
		}
		return $job;
	}

	/**
	 * Advances the job by one runner tick — this is what the browser calls
	 * in a loop while a wizard's progress screen is open.
	 */
	public static function job_run_step() {
		$job    = self::get_job_or_fail();
		$runner = new Avix_Migration_Job_Runner();
		$snapshot = $runner->run( $job );
		wp_send_json_success( $snapshot );
	}

	/** Read-only status check, e.g. on initial page load before polling starts. */
	public static function job_progress() {
		$job = self::get_job_or_fail();
		wp_send_json_success( $job->progress_snapshot() );
	}

	public static function job_cancel() {
		$job = self::get_job_or_fail();
		if ( ! $job->is_terminal() ) {
			$job->status = Avix_Migration_Job::STATUS_CANCELLED;
			$job->touch();
			Avix_Migration_Job_Store::save( $job );
			Avix_Migration_Util_Logger::info( $job->id, 'Job cancelled by user.' );
		}
		wp_send_json_success( $job->progress_snapshot() );
	}

	/** Live log tail for the progress screen's collapsible log panel. */
	public static function job_log() {
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		if ( '' === $job_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing job id.', 'avix-migration' ) ), 400 );
		}
		wp_send_json_success( array( 'entries' => Avix_Migration_Util_Logger::tail( $job_id, 100 ) ) );
	}
}
