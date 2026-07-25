<?php
/**
 * First step of a pull-initiated import: downloads the archive a remote
 * site already finished exporting, in chunks via GET /avix/v1/send-chunk,
 * writing into this site's own archives directory. Once done, the
 * remaining steps (Validate, Extract_Database, …) run exactly as they
 * would for a manually-uploaded archive — this step's only job is turning
 * "an archive sitting on another site" into "an archive sitting on disk
 * here", set at job->meta['archive_path'].
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Import_Download_Remote extends Avix_Migration_Job_Step {

	public function label() {
		return __( 'Downloading from remote site', 'avix-migration' );
	}

	public function execute( Avix_Migration_Job $job ) {
		$remote = Avix_Migration_Remote_Store::get_remote( $job->meta['remote_id'] );
		if ( null === $remote ) {
			return Avix_Migration_Job_Step_Result::failed( __( 'The remote site no longer exists.', 'avix-migration' ) );
		}

		Avix_Migration_Util_Filesystem::create_storage_dirs();
		if ( empty( $job->meta['archive_path'] ) ) {
			$job->meta['archive_path'] = Avix_Migration_Util_Filesystem::archives_dir() . '/' . $job->meta['archive_filename'];
		}

		$cursor = $this->cursor( $job );
		$offset = $cursor['offset'] ?? 0;

		$path = '/avix/v1/send-chunk?' . http_build_query( array( 'job_id' => $job->meta['remote_export_job_id'], 'offset' => $offset ) );
		$response = Avix_Migration_Remote_Client::request( $remote, 'GET', $path, '', array( 'timeout' => 60 ) );

		if ( is_wp_error( $response ) ) {
			return Avix_Migration_Job_Step_Result::failed(
				sprintf(
					/* translators: %s: error message */
					__( 'Download failed: %s', 'avix-migration' ),
					$response->get_error_message()
				)
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			return Avix_Migration_Job_Step_Result::failed( $body['message'] ?? sprintf( 'HTTP %d', $code ) );
		}

		$chunk = wp_remote_retrieve_body( $response );
		$total_size = (int) wp_remote_retrieve_header( $response, 'x-avix-total-size' );
		$is_done = '1' === wp_remote_retrieve_header( $response, 'x-avix-done' );

		$fp = fopen( $job->meta['archive_path'], $offset > 0 ? 'r+b' : 'w+b' );
		fseek( $fp, $offset, SEEK_SET );
		fwrite( $fp, $chunk );
		fclose( $fp );

		$cursor['offset'] = $offset + strlen( $chunk );
		$this->set_cursor( $job, $cursor );

		$job->totals['bytes_total'] = max( $job->totals['bytes_total'], $total_size );
		$job->totals['bytes_done']  = $cursor['offset'];

		if ( $is_done ) {
			Avix_Migration_Util_Logger::info( $job->id, 'Archive downloaded from remote site.', array( 'bytes' => $cursor['offset'] ) );
			return Avix_Migration_Job_Step_Result::step_complete( __( 'Downloaded.', 'avix-migration' ) );
		}

		$percent = $total_size > 0 ? round( ( $cursor['offset'] / $total_size ) * 100 ) : 0;
		return Avix_Migration_Job_Step_Result::cont(
			sprintf(
				/* translators: %d: download percent */
				__( 'Downloading from remote site… %d%%', 'avix-migration' ),
				$percent
			)
		);
	}
}
