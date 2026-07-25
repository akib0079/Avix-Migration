<?php
/**
 * Controller for the Remote Sites screen: issuing/revoking connection
 * keys, adding/removing remotes, and the push/pull orchestration.
 *
 * Push creates a normal local export job with destination_id =
 * "remote:{id}" — Export_Upload already knows how to route that to
 * Storage_Provider_Remote_Site, so the browser polls it exactly like any
 * other backup. Pull is the side that needs the proxy endpoints below:
 * the browser can't sign requests (it doesn't have the remote's secret,
 * by design), so this server has to make the "is the remote's export
 * done yet" and "start the local import" calls on its behalf.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Admin_Remote_Controller {

	public static function boot() {
		add_filter( 'avix_migration_ajax_handlers', array( __CLASS__, 'register_ajax_handlers' ) );
	}

	public static function register_ajax_handlers( array $handlers ) {
		$handlers['avix_remote_list']            = array( __CLASS__, 'list_all' );
		$handlers['avix_remote_issue_key']        = array( __CLASS__, 'issue_key' );
		$handlers['avix_remote_revoke_key']        = array( __CLASS__, 'revoke_key' );
		$handlers['avix_remote_add']              = array( __CLASS__, 'add_remote' );
		$handlers['avix_remote_delete']            = array( __CLASS__, 'delete_remote' );
		$handlers['avix_remote_check']             = array( __CLASS__, 'check_reachability' );
		$handlers['avix_remote_push']              = array( __CLASS__, 'push' );
		$handlers['avix_remote_pull_start']        = array( __CLASS__, 'pull_start' );
		$handlers['avix_remote_pull_poll_export']  = array( __CLASS__, 'pull_poll_export' );
		$handlers['avix_remote_pull_begin_import']  = array( __CLASS__, 'pull_begin_import' );
		$handlers['avix_remote_poll_remote_job']    = array( __CLASS__, 'poll_remote_job' );
		return $handlers;
	}

	public static function list_all() {
		wp_send_json_success(
			array(
				'issued_keys' => array_map(
					function ( $k ) {
						unset( $k['secret'] );
						return $k;
					},
					Avix_Migration_Remote_Store::all_issued_keys()
				),
				'remotes'     => Avix_Migration_Remote_Store::all_remotes_public(),
			)
		);
	}

	public static function issue_key() {
		$label = sanitize_text_field( wp_unslash( $_POST['label'] ?? __( 'Connection key', 'avix-migration' ) ) );
		$expires_hours = max( 0, (int) ( $_POST['expires_hours'] ?? 24 ) );
		$result = Avix_Migration_Remote_Store::issue_key( $label, $expires_hours * HOUR_IN_SECONDS );
		wp_send_json_success( $result );
	}

	public static function revoke_key() {
		Avix_Migration_Remote_Store::revoke_issued_key( sanitize_text_field( wp_unslash( $_POST['key_id'] ?? '' ) ) );
		wp_send_json_success();
	}

	public static function add_remote() {
		$label = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
		$connection_string = wp_unslash( $_POST['connection_string'] ?? '' );
		$result = Avix_Migration_Remote_Store::add_remote( $label, $connection_string );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'remote_id' => $result ) );
	}

	public static function delete_remote() {
		Avix_Migration_Remote_Store::delete_remote( sanitize_text_field( wp_unslash( $_POST['remote_id'] ?? '' ) ) );
		wp_send_json_success();
	}

	public static function check_reachability() {
		$remote = self::require_remote();
		$response = Avix_Migration_Remote_Client::request_json( $remote, 'POST', '/avix/v1/handshake' );
		if ( is_wp_error( $response ) ) {
			wp_send_json_success( array( 'reachable' => false, 'message' => $response->get_error_message() ) );
		}
		wp_send_json_success( array( 'reachable' => true, 'message' => __( 'Reachable.', 'avix-migration' ) ) );
	}

	/** Push: a normal local full-site export job, targeting the remote as its destination. */
	public static function push() {
		$remote = self::require_remote();
		$remote_id = sanitize_text_field( wp_unslash( $_POST['remote_id'] ) );

		$meta = array(
			'include_database'  => true,
			'include_files'     => true,
			'excluded_dirs'     => array_column( Avix_Migration_Fs_Exclusions::detect(), 'dir' ),
			'excluded_top_dirs' => array(),
			'destination_id'    => 'remote:' . $remote_id,
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
		Avix_Migration_Util_Logger::info( $job->id, 'Push to remote site started.', array( 'remote_id' => $remote_id ) );

		wp_send_json_success( array( 'job_id' => $job->id ) );
	}

	/** Pull, step 1: ask the remote to start exporting. */
	public static function pull_start() {
		$remote = self::require_remote();
		$response = Avix_Migration_Remote_Client::request_json( $remote, 'POST', '/avix/v1/export/start', array() );
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ), 502 );
		}
		wp_send_json_success( array( 'remote_job_id' => $response['job_id'] ) );
	}

	/** Pull, step 2 (polled repeatedly by the browser): check the remote export's progress. */
	public static function pull_poll_export() {
		$remote = self::require_remote();
		$remote_job_id = sanitize_text_field( wp_unslash( $_POST['remote_job_id'] ?? '' ) );

		$response = Avix_Migration_Remote_Client::request_json( $remote, 'GET', '/avix/v1/export/status?job_id=' . rawurlencode( $remote_job_id ) );
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ), 502 );
		}
		wp_send_json_success( $response );
	}

	/** Pull, step 3: once the remote export is done, start the local import (which downloads it first). */
	public static function pull_begin_import() {
		$remote = self::require_remote();
		$remote_id = sanitize_text_field( wp_unslash( $_POST['remote_id'] ) );
		$remote_job_id = sanitize_text_field( wp_unslash( $_POST['remote_job_id'] ?? '' ) );
		$archive_filename = sanitize_text_field( wp_unslash( $_POST['archive_filename'] ?? '' ) );
		$archive_type = sanitize_key( wp_unslash( $_POST['archive_type'] ?? 'full' ) );

		if ( ! Avix_Migration_Util_Filesystem::is_safe_archive_filename( $archive_filename ) ) {
			wp_send_json_error( array( 'message' => __( 'The remote reported an unexpected filename.', 'avix-migration' ) ), 400 );
		}

		$meta = array(
			'remote_id'            => $remote_id,
			'remote_export_job_id' => $remote_job_id,
			'archive_filename'     => $archive_filename,
		);

		if ( Avix_Migration_Archive_Manifest::TYPE_CONTENT === $archive_type ) {
			$meta['conflict_mode'] = Avix_Migration_Content_Importer::CONFLICT_SKIP;
			$meta['default_author_id'] = get_current_user_id();
			$steps = array(
				'Avix_Migration_Job_Steps_Import_Download_Remote',
				'Avix_Migration_Job_Steps_Import_Validate',
				'Avix_Migration_Job_Steps_Content_Import_Extract_Json',
				'Avix_Migration_Job_Steps_Content_Import_Extract_Media',
				'Avix_Migration_Job_Steps_Content_Import_Terms',
				'Avix_Migration_Job_Steps_Content_Import_Attachments',
				'Avix_Migration_Job_Steps_Content_Import_Posts',
				'Avix_Migration_Job_Steps_Content_Import_Finalize',
			);
		} else {
			$meta['restore_database']   = true;
			$meta['restore_files']      = true;
			$meta['keep_current_admin'] = true;
			$meta['keep_admin_user_id'] = get_current_user_id();
			$steps = array(
				'Avix_Migration_Job_Steps_Import_Download_Remote',
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
		Avix_Migration_Util_Logger::info( $job->id, 'Pull from remote site: import started.', array( 'remote_id' => $remote_id ) );

		wp_send_json_success( array( 'job_id' => $job->id ) );
	}

	/** Generic proxy: poll a job running on a remote (used after push completes, to watch the remote's import). */
	public static function poll_remote_job() {
		$remote = self::require_remote();
		$kind = 'import' === sanitize_key( wp_unslash( $_POST['kind'] ?? '' ) ) ? 'import' : 'export';
		$remote_job_id = sanitize_text_field( wp_unslash( $_POST['remote_job_id'] ?? '' ) );

		$response = Avix_Migration_Remote_Client::request_json( $remote, 'GET', "/avix/v1/{$kind}/status?job_id=" . rawurlencode( $remote_job_id ) );
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ), 502 );
		}
		wp_send_json_success( $response );
	}

	/** @return array Decrypted remote record; halts the request with a JSON error if not found. */
	private static function require_remote() {
		$remote_id = sanitize_text_field( wp_unslash( $_POST['remote_id'] ?? '' ) );
		$remote = Avix_Migration_Remote_Store::get_remote( $remote_id );
		if ( null === $remote ) {
			wp_send_json_error( array( 'message' => __( 'Remote site not found.', 'avix-migration' ) ), 404 );
		}
		return $remote;
	}
}
