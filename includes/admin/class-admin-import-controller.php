<?php
/**
 * Controller for the Import screen: chunked upload, the pre-flight
 * comparison report, starting the import job, and the rollback / discard
 * actions that operate on a completed or failed import's snapshot.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Admin_Import_Controller {

	public static function boot() {
		add_filter( 'avix_migration_ajax_handlers', array( __CLASS__, 'register_ajax_handlers' ) );
	}

	public static function register_ajax_handlers( array $handlers ) {
		$handlers['avix_upload_chunk']   = array( __CLASS__, 'upload_chunk' );
		$handlers['avix_upload_status']  = array( __CLASS__, 'upload_status' );
		$handlers['avix_import_preflight'] = array( __CLASS__, 'preflight' );
		$handlers['avix_start_import']   = array( __CLASS__, 'start_import' );
		$handlers['avix_rollback_import'] = array( __CLASS__, 'rollback_import' );
		$handlers['avix_discard_rollback'] = array( __CLASS__, 'discard_rollback' );
		return $handlers;
	}

	public static function upload_chunk() {
		if ( empty( $_FILES['chunk'] ) || UPLOAD_ERR_OK !== $_FILES['chunk']['error'] || ! is_uploaded_file( $_FILES['chunk']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Chunk upload failed.', 'avix-migration' ) ), 400 );
		}

		$upload_id    = isset( $_POST['upload_id'] ) ? sanitize_text_field( wp_unslash( $_POST['upload_id'] ) ) : '';
		$chunk_index  = isset( $_POST['chunk_index'] ) ? (int) $_POST['chunk_index'] : -1;
		$total_chunks = isset( $_POST['total_chunks'] ) ? (int) $_POST['total_chunks'] : 0;
		$filename     = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : 'upload.avix';

		if ( '' === $upload_id || $chunk_index < 0 || $total_chunks < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Missing upload metadata.', 'avix-migration' ) ), 400 );
		}

		$result = Avix_Migration_Util_Chunked_Upload::append_chunk(
			$upload_id, $chunk_index, $total_chunks, $filename, $_FILES['chunk']['tmp_name']
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_send_json_success( $result );
	}

	public static function upload_status() {
		$upload_id = isset( $_POST['upload_id'] ) ? sanitize_text_field( wp_unslash( $_POST['upload_id'] ) ) : '';
		wp_send_json_success( Avix_Migration_Util_Chunked_Upload::status( $upload_id ) );
	}

	/**
	 * Reads the manifest of an archive (freshly uploaded, or already sitting
	 * in local storage) and returns the comparison report — no job is
	 * created here, this is purely informational before the operator
	 * confirms.
	 */
	public static function preflight() {
		$path = self::resolve_archive_path();
		if ( null === $path ) {
			wp_send_json_error( array( 'message' => __( 'Archive not found.', 'avix-migration' ) ), 404 );
		}

		$manifest = Avix_Migration_Archive_Reader::read_manifest_only( $path );
		$valid    = Avix_Migration_Archive_Manifest::validate( $manifest );
		if ( is_wp_error( $valid ) ) {
			wp_send_json_error( array( 'message' => $valid->get_error_message() ), 422 );
		}

		wp_send_json_success(
			array(
				'manifest' => $manifest,
				'warnings' => Avix_Migration_Util_Sysinfo::compare_warnings( $manifest['site'] ?? array() ),
			)
		);
	}

	public static function start_import() {
		$path = self::resolve_archive_path();
		if ( null === $path ) {
			wp_send_json_error( array( 'message' => __( 'Archive not found.', 'avix-migration' ) ), 404 );
		}

		$manifest = Avix_Migration_Archive_Reader::read_manifest_only( $path );
		$archive_type = $manifest['archive_type'] ?? Avix_Migration_Archive_Manifest::TYPE_FULL;

		if ( Avix_Migration_Archive_Manifest::TYPE_CONTENT === $archive_type ) {
			$requested_mode = isset( $_POST['conflict_mode'] ) ? sanitize_key( wp_unslash( $_POST['conflict_mode'] ) ) : '';
			$meta = array(
				'archive_path'      => $path,
				'conflict_mode'     => in_array( $requested_mode, array( 'skip', 'overwrite', 'duplicate' ), true )
					? $requested_mode
					: Avix_Migration_Content_Importer::CONFLICT_SKIP,
				'default_author_id' => get_current_user_id(),
			);

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
			$keep_admin = ! empty( $_POST['keep_current_admin'] );

			$meta = array(
				'archive_path'       => $path,
				'restore_database'  => ! isset( $_POST['restore_database'] ) || ! empty( $_POST['restore_database'] ),
				'restore_files'      => ! isset( $_POST['restore_files'] ) || ! empty( $_POST['restore_files'] ),
				'keep_current_admin' => $keep_admin,
				'keep_admin_user_id' => $keep_admin ? get_current_user_id() : 0,
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

		Avix_Migration_Util_Logger::info( $job->id, 'Import job created.', array( 'archive' => basename( $path ), 'type' => $archive_type ) );

		wp_send_json_success( array( 'job_id' => $job->id ) );
	}

	/**
	 * Renames the pre-import snapshot's tables back into place, undoing
	 * whatever the import wrote — available for both a failed import
	 * (undo the partial write) and a completed one the operator decides
	 * they don't want after all.
	 */
	public static function rollback_import() {
		$job = self::get_job_or_fail();
		$map = (array) ( $job->meta['rollback_map'] ?? array() );

		if ( empty( $map ) ) {
			wp_send_json_error( array( 'message' => __( 'No rollback snapshot exists for this import.', 'avix-migration' ) ), 404 );
		}

		$result = Avix_Migration_Rollback_Manager::restore( $map );
		Avix_Migration_Util_Logger::info( $job->id, 'Rollback performed.', $result );

		if ( ! empty( $result['errors'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Some tables could not be restored — see the log.', 'avix-migration' ), 'result' => $result ), 500 );
		}

		wp_send_json_success( $result );
	}

	/** Confirms the import is good and the pre-import safety tables can be dropped. */
	public static function discard_rollback() {
		$job = self::get_job_or_fail();
		$map = (array) ( $job->meta['rollback_map'] ?? array() );

		Avix_Migration_Rollback_Manager::discard( $map );
		Avix_Migration_Util_Logger::info( $job->id, 'Rollback snapshot discarded by user.' );

		wp_send_json_success( array( 'discarded' => true ) );
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
	 * An archive is identified to the client either by an upload_id (just
	 * finished uploading) or a filename (picked from the existing Backups
	 * list) — never a raw path, which would let a request read an
	 * arbitrary file off disk.
	 */
	private static function resolve_archive_path() {
		if ( ! empty( $_POST['filename'] ) ) {
			return Avix_Migration_Archive_Store::path_for( sanitize_file_name( wp_unslash( $_POST['filename'] ) ) );
		}
		return null;
	}
}
