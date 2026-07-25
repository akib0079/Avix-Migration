<?php
/**
 * Controller for the "Backups" screen: list/download/delete archives
 * already sitting in local storage. Creating new archives is the Backup
 * wizard's job (Milestone 2) — this controller only manages what already
 * exists, which is why it can be built and be genuinely useful in the
 * foundation milestone.
 *
 * Registers its own AJAX handler (delete) via the generic Admin_Ajax
 * filter hook, and its own admin-post handler (download) since a file
 * download is a raw GET response, not a JSON AJAX call.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Admin_Backups_Controller {

	const DOWNLOAD_ACTION = 'avix_download_archive';
	const DOWNLOAD_NONCE  = 'avix_download_archive';

	/** Bytes per read() cycle when streaming a download — keeps memory flat regardless of archive size. */
	const STREAM_CHUNK = 1048576; // 1 MB.

	public static function boot() {
		add_filter( 'avix_migration_ajax_handlers', array( __CLASS__, 'register_ajax_handlers' ) );
		add_action( 'admin_post_' . self::DOWNLOAD_ACTION, array( __CLASS__, 'download' ) );
	}

	public static function register_ajax_handlers( array $handlers ) {
		$handlers['avix_delete_archive'] = array( __CLASS__, 'delete_archive' );
		return $handlers;
	}

	/**
	 * Builds the signed download URL used by the Backups list — a plain
	 * static link would work for the current admin's browser session, but
	 * routing it through admin-post.php with its own nonce keeps the same
	 * capability + nonce discipline as every other action in the plugin.
	 */
	public static function download_url( $filename ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::DOWNLOAD_ACTION . '&file=' . rawurlencode( $filename ) ),
			self::DOWNLOAD_NONCE
		);
	}

	public static function download() {
		if ( ! current_user_can( Avix_Migration_Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permission denied.', 'avix-migration' ), 403 );
		}
		check_admin_referer( self::DOWNLOAD_NONCE );

		$filename = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		$path     = Avix_Migration_Archive_Store::path_for( $filename );

		if ( null === $path || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'Archive not found.', 'avix-migration' ), 404 );
		}

		$size = filesize( $path );

		// Turn off anything that might buffer or mutate the response body —
		// a gzip-compressing output handler would invalidate Content-Length
		// and corrupt the download of an already-binary .avix file.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . $size );
		header( 'X-Content-Type-Options: nosniff' );

		$fp = fopen( $path, 'rb' );
		if ( $fp ) {
			while ( ! feof( $fp ) ) {
				echo fread( $fp, self::STREAM_CHUNK ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				flush();
			}
			fclose( $fp );
		}
		exit;
	}

	public static function delete_archive() {
		$filename = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';
		if ( '' === $filename ) {
			wp_send_json_error( array( 'message' => __( 'Missing filename.', 'avix-migration' ) ), 400 );
		}

		$ok = Avix_Migration_Archive_Store::delete( $filename );
		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => __( 'Could not delete that archive.', 'avix-migration' ) ), 404 );
		}

		wp_send_json_success( array( 'deleted' => $filename ) );
	}
}
