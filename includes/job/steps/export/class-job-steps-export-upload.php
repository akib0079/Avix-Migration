<?php
/**
 * Last step of every export pipeline (full-site and content both): if the
 * job specifies a non-local destination, uploads the finished archive
 * there in chunks via the configured Storage_Provider. A no-op — instant
 * STEP_COMPLETE — when no destination_id is set, which is the default for
 * a manually-started backup (local storage only).
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Export_Upload extends Avix_Migration_Job_Step {

	public function label() {
		return __( 'Uploading to destination', 'avix-migration' );
	}

	public function execute( Avix_Migration_Job $job ) {
		$destination_id = $job->meta['destination_id'] ?? '';
		if ( '' === $destination_id || 'local' === $destination_id ) {
			return Avix_Migration_Job_Step_Result::step_complete( __( 'Stored locally.', 'avix-migration' ) );
		}

		if ( empty( $job->meta['archive_path'] ) || ! is_readable( $job->meta['archive_path'] ) ) {
			return Avix_Migration_Job_Step_Result::failed( __( 'No finished archive to upload.', 'avix-migration' ) );
		}

		if ( 0 === strpos( $destination_id, 'remote:' ) ) {
			$remote_id = substr( $destination_id, strlen( 'remote:' ) );
			$remote = Avix_Migration_Remote_Store::get_remote( $remote_id );
			if ( null === $remote ) {
				return Avix_Migration_Job_Step_Result::failed( __( 'The configured remote site no longer exists.', 'avix-migration' ) );
			}
			$provider = new Avix_Migration_Storage_Provider_Remote_Site( $remote );
		} else {
			$provider = Avix_Migration_Storage_Manager::for_destination( $destination_id );
		}
		if ( null === $provider ) {
			return Avix_Migration_Job_Step_Result::failed( __( 'The configured storage destination no longer exists.', 'avix-migration' ) );
		}

		$cursor = $this->cursor( $job );
		if ( ! isset( $cursor['offset'] ) ) {
			$cursor['offset'] = 0;
			$cursor['provider_state'] = array();
			$job->totals['bytes_total'] = max( $job->totals['bytes_total'], (int) filesize( $job->meta['archive_path'] ) );
		}

		$result = $provider->upload_chunk( $job->meta['archive_path'], $job->meta['archive_filename'], $cursor['offset'], $cursor['provider_state'] );

		if ( null !== $result['error'] ) {
			return Avix_Migration_Job_Step_Result::failed(
				sprintf(
					/* translators: %s: storage provider error message */
					__( 'Upload failed: %s', 'avix-migration' ),
					$result['error']
				)
			);
		}

		$cursor['offset'] += $result['bytes_sent'];
		$cursor['provider_state'] = $result['state'];
		$this->set_cursor( $job, $cursor );

		$job->totals['bytes_done'] = min( $job->totals['bytes_total'], $cursor['offset'] );

		if ( $result['done'] ) {
			// Surfaced at the top level (not buried in this step's own
			// cursor state) so the admin UI polling THIS local job can
			// find the remote's import job id and switch to polling that
			// one next, without knowing anything about Export_Upload's
			// internals.
			if ( ! empty( $cursor['provider_state']['remote_import_job_id'] ) ) {
				$job->meta['remote_import_job_id'] = $cursor['provider_state']['remote_import_job_id'];
			}
			Avix_Migration_Util_Logger::info( $job->id, 'Archive uploaded to destination.', array( 'destination_id' => $destination_id ) );
			return Avix_Migration_Job_Step_Result::step_complete( __( 'Uploaded.', 'avix-migration' ) );
		}

		$percent = $job->totals['bytes_total'] > 0 ? round( ( $cursor['offset'] / $job->totals['bytes_total'] ) * 100 ) : 0;
		return Avix_Migration_Job_Step_Result::cont(
			sprintf(
				/* translators: %d: upload percent */
				__( 'Uploading to destination… %d%%', 'avix-migration' ),
				$percent
			)
		);
	}
}
