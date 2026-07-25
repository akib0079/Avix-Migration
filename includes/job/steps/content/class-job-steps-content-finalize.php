<?php
/**
 * EOF marker, checksum sidecar, and cleanup of the one temp file that
 * survives until now (attachments.jsonl, needed by Copy_Media).
 *
 * Returns STEP_COMPLETE, not JOB_COMPLETE — Export_Upload (cloud
 * destination support) follows this step too, exactly like the full-site
 * pipeline; see the fuller explanation in Export_Finalize.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Content_Finalize extends Avix_Migration_Job_Step {

	public function label() {
		return __( 'Finishing up', 'avix-migration' );
	}

	public function execute( Avix_Migration_Job $job ) {
		$writer = new Avix_Migration_Archive_Writer( $job->meta['archive_path'] );
		$writer->open_for_resume();
		$writer->write_eof();
		$sidecar = $writer->finalize();

		@unlink( ( new Avix_Migration_Job_Steps_Content_Collect() )->attachments_path( $job ) );

		$job->meta['final_bytes']  = $sidecar['bytes'];
		$job->meta['final_sha256'] = $sidecar['sha256'];

		Avix_Migration_Util_Logger::info( $job->id, 'Content export finished.', array( 'file' => $job->meta['archive_filename'] ) );

		return Avix_Migration_Job_Step_Result::step_complete(
			sprintf(
				/* translators: %s: archive filename */
				__( 'Content export complete: %s', 'avix-migration' ),
				$job->meta['archive_filename']
			)
		);
	}
}
