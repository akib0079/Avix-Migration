<?php
/**
 * The avix/v1 REST API: handshake, manifest, export/import start+status,
 * and the raw-byte chunk endpoints that carry an archive between two
 * sites. Every route shares one permission_callback (HMAC verification —
 * see Remote_Auth) and, for status endpoints, doubles as the mechanism
 * that keeps a long-running remote job moving: each status poll also
 * advances the job by one Job_Runner tick, the same "client polls, each
 * poll does one unit of work" pattern the browser-driven AJAX endpoints
 * already use — no separate cron-chain needed for remote-triggered jobs.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Remote_Rest_Controller {

	const NAMESPACE_ = 'avix/v1';

	public static function boot() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$auth = array( __CLASS__, 'check_auth' );

		register_rest_route( self::NAMESPACE_, '/handshake', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'handshake' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NAMESPACE_, '/manifest', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'manifest' ), 'permission_callback' => $auth ) );

		register_rest_route( self::NAMESPACE_, '/export/start', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'export_start' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NAMESPACE_, '/export/status', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'export_status' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NAMESPACE_, '/send-chunk', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'send_chunk' ), 'permission_callback' => $auth ) );

		register_rest_route( self::NAMESPACE_, '/receive-chunk', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'receive_chunk' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NAMESPACE_, '/import/start', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'import_start' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NAMESPACE_, '/import/status', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'import_status' ), 'permission_callback' => $auth ) );
	}

	public static function check_auth( WP_REST_Request $request ) {
		return Avix_Migration_Remote_Auth::verify_inbound( $request );
	}

	public static function handshake( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'ok' => true, 'site_url' => home_url(), 'plugin_version' => AVIX_MIGRATION_VERSION ) );
	}

	public static function manifest( WP_REST_Request $request ) {
		return rest_ensure_response( Avix_Migration_Util_Sysinfo::snapshot() );
	}

	public static function export_start( WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: array();

		$meta = array(
			'include_database'        => ! isset( $params['include_database'] ) || ! empty( $params['include_database'] ),
			'include_files'           => ! isset( $params['include_files'] ) || ! empty( $params['include_files'] ),
			'excluded_dirs'            => array_values( array_map( 'sanitize_text_field', (array) ( $params['excluded_dirs'] ?? array() ) ) ),
			'excluded_top_dirs'        => (array) ( $params['excluded_top_dirs'] ?? array() ),
			'skip_transients'          => ! empty( $params['skip_transients'] ),
			'skip_revisions'           => ! empty( $params['skip_revisions'] ),
			'skip_spam_trash_comments' => ! empty( $params['skip_spam_trash_comments'] ),
			'all_tables'               => ! empty( $params['all_tables'] ),
			'destination_id'           => 'local', // A pull request downloads via send-chunk; no cloud upload on this side.
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
		Avix_Migration_Util_Logger::info( $job->id, 'Export started by a remote pull request.' );

		return rest_ensure_response( array( 'job_id' => $job->id ) );
	}

	public static function export_status( WP_REST_Request $request ) {
		return self::advance_and_report( $request->get_param( 'job_id' ) );
	}

	public static function import_status( WP_REST_Request $request ) {
		return self::advance_and_report( $request->get_param( 'job_id' ) );
	}

	private static function advance_and_report( $job_id ) {
		$job = Avix_Migration_Job_Store::load( sanitize_text_field( (string) $job_id ) );
		if ( null === $job ) {
			return new WP_Error( 'avix_remote_job_not_found', __( 'Job not found.', 'avix-migration' ), array( 'status' => 404 ) );
		}
		if ( ! $job->is_terminal() ) {
			( new Avix_Migration_Job_Runner() )->run( $job );
		}
		return rest_ensure_response( $job->progress_snapshot() );
	}

	public static function send_chunk( WP_REST_Request $request ) {
		$job_id = sanitize_text_field( (string) $request->get_param( 'job_id' ) );
		$offset = max( 0, (int) $request->get_param( 'offset' ) );

		$job = Avix_Migration_Job_Store::load( $job_id );
		if ( null === $job || Avix_Migration_Job::STATUS_DONE !== $job->status || empty( $job->meta['archive_path'] ) ) {
			return new WP_Error( 'avix_remote_archive_not_ready', __( 'Archive is not ready to send.', 'avix-migration' ), array( 'status' => 409 ) );
		}

		$path = $job->meta['archive_path'];
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'avix_remote_archive_missing', __( 'Archive file is missing.', 'avix-migration' ), array( 'status' => 404 ) );
		}

		$size = filesize( $path );
		$chunk_size = 4194304; // 4 MB.

		$fp = fopen( $path, 'rb' );
		fseek( $fp, $offset, SEEK_SET );
		$chunk = fread( $fp, $chunk_size );
		fclose( $fp );

		return new WP_REST_Response(
			$chunk,
			200,
			array(
				'Content-Type'        => 'application/octet-stream',
				'X-Avix-Total-Size'   => (string) $size,
				'X-Avix-Chunk-Offset' => (string) $offset,
				'X-Avix-Chunk-Length' => (string) strlen( $chunk ),
				'X-Avix-Done'         => ( $offset + strlen( $chunk ) ) >= $size ? '1' : '0',
			)
		);
	}

	/**
	 * Accepts one raw chunk of an archive being pushed in, appending it to
	 * a staging file under a job-agnostic "incoming" directory. The
	 * filename is validated against the exact pattern this plugin's own
	 * Export_Prepare step generates — never trust a client-supplied
	 * filename used to build a filesystem path, even though this endpoint
	 * is itself authenticated; a compromised or misbehaving legitimate
	 * peer is still an untrusted filesystem-path source.
	 */
	public static function receive_chunk( WP_REST_Request $request ) {
		$filename = (string) $request->get_param( 'filename' );
		$offset   = max( 0, (int) $request->get_param( 'offset' ) );

		if ( ! Avix_Migration_Util_Filesystem::is_safe_archive_filename( $filename ) ) {
			return new WP_Error( 'avix_remote_bad_filename', __( 'Invalid filename.', 'avix-migration' ), array( 'status' => 400 ) );
		}

		$incoming_dir = Avix_Migration_Util_Filesystem::tmp_dir() . '/incoming';
		Avix_Migration_Util_Filesystem::ensure_dir( $incoming_dir );
		$path = $incoming_dir . '/' . $filename;

		$body = $request->get_body();
		$fp = fopen( $path, $offset > 0 ? 'r+b' : 'w+b' );
		if ( false === $fp ) {
			return new WP_Error( 'avix_remote_write_failed', __( 'Could not write incoming data.', 'avix-migration' ), array( 'status' => 500 ) );
		}
		fseek( $fp, $offset, SEEK_SET );
		fwrite( $fp, $body );
		fclose( $fp );

		return rest_ensure_response( array( 'received' => strlen( $body ), 'offset' => $offset ) );
	}

	/**
	 * Called once the pusher has sent every chunk: moves the assembled
	 * file from the incoming staging area into the real archives
	 * directory and starts a normal import against it — from here on,
	 * this is indistinguishable from a manually-uploaded archive.
	 *
	 * There is no logged-in WP user on an HMAC-authenticated REST request
	 * (get_current_user_id() is 0), so "keep me logged in as the current
	 * admin" — which only makes sense for an interactive operator watching
	 * a progress bar — is never applied here; a remote-triggered import
	 * always replaces the target's admin accounts with whatever the
	 * source archive contains, same as importing an uploaded file with
	 * that toggle switched off.
	 */
	public static function import_start( WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: array();
		$filename = (string) ( $params['filename'] ?? '' );

		if ( ! Avix_Migration_Util_Filesystem::is_safe_archive_filename( $filename ) ) {
			return new WP_Error( 'avix_remote_bad_filename', __( 'Invalid filename.', 'avix-migration' ), array( 'status' => 400 ) );
		}

		$incoming_path = Avix_Migration_Util_Filesystem::tmp_dir() . '/incoming/' . $filename;
		if ( ! is_readable( $incoming_path ) ) {
			return new WP_Error( 'avix_remote_not_received', __( 'No such incoming archive.', 'avix-migration' ), array( 'status' => 404 ) );
		}

		Avix_Migration_Util_Filesystem::create_storage_dirs();
		$archive_path = Avix_Migration_Util_Filesystem::archives_dir() . '/' . $filename;
		if ( ! @rename( $incoming_path, $archive_path ) ) {
			return new WP_Error( 'avix_remote_move_failed', __( 'Could not finalize the received archive.', 'avix-migration' ), array( 'status' => 500 ) );
		}

		$manifest = Avix_Migration_Archive_Reader::read_manifest_only( $archive_path );
		$archive_type = $manifest['archive_type'] ?? Avix_Migration_Archive_Manifest::TYPE_FULL;

		if ( Avix_Migration_Archive_Manifest::TYPE_CONTENT === $archive_type ) {
			$meta = array( 'archive_path' => $archive_path, 'conflict_mode' => Avix_Migration_Content_Importer::CONFLICT_SKIP, 'default_author_id' => 0 );
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
			$meta = array( 'archive_path' => $archive_path, 'restore_database' => true, 'restore_files' => true, 'keep_current_admin' => false, 'keep_admin_user_id' => 0 );
			$steps = array(
				'Avix_Migration_Job_Steps_Import_Validate',
				'Avix_Migration_Job_Steps_Import_Rollback_Snapshot',
				'Avix_Migration_Job_Steps_Import_Extract_Database',
				'Avix_Migration_Job_Steps_Import_Extract_Files',
				'Avix_Migration_Job_Steps_Import_Database_Replay',
				'Avix_Migration_Job_Steps_Import_Search_Replace',
				'Avix_Migration_Job_Steps_Import_Finalize',
			);
		}

		$job = Avix_Migration_Job_Store::create( 'import', $steps, $meta );
		Avix_Migration_Util_Logger::info( $job->id, 'Import started by a remote push request.', array( 'filename' => $filename ) );

		return rest_ensure_response( array( 'job_id' => $job->id ) );
	}
}
