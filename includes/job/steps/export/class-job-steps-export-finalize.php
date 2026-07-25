<?php
/**
 * Writes the archive's EOF marker and checksum sidecar, then cleans up the
 * temp filelist/database files. This is what makes an archive "real" —
 * validate_and_count() won't report ended_clean until this has run, which
 * is exactly the signal that lets an interrupted export be told apart
 * from a finished one.
 *
 * Returns STEP_COMPLETE, not JOB_COMPLETE — this step is not necessarily
 * the pipeline's last one (Export_Upload follows it when a cloud
 * destination is configured), and JOB_COMPLETE would terminate the job
 * immediately, skipping whatever comes after. The runner already marks a
 * job done on its own once the step index runs past the end of whatever
 * pipeline was configured, so there's never a need to force early
 * termination from inside a step.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Export_Finalize extends Avix_Migration_Job_Step {

	public function label() {
		return __( 'Finishing up', 'avix-migration' );
	}

	public function execute( Avix_Migration_Job $job ) {
		if ( empty( $job->meta['archive_path'] ) ) {
			return Avix_Migration_Job_Step_Result::failed( 'No archive to finalize.' );
		}

		$writer = new Avix_Migration_Archive_Writer( $job->meta['archive_path'] );
		$writer->open_for_resume();
		$writer->write_eof();
		$sidecar = $writer->finalize();

		$this->cleanup_temp_files( $job );

		$job->meta['final_bytes']  = $sidecar['bytes'];
		$job->meta['final_sha256'] = $sidecar['sha256'];

		Avix_Migration_Util_Logger::info(
			$job->id,
			'Backup finished.',
			array( 'file' => $job->meta['archive_filename'], 'bytes' => $sidecar['bytes'] )
		);

		return Avix_Migration_Job_Step_Result::step_complete(
			sprintf(
				/* translators: %s: archive filename */
				__( 'Backup complete: %s', 'avix-migration' ),
				$job->meta['archive_filename']
			)
		);
	}

	private function cleanup_temp_files( Avix_Migration_Job $job ) {
		$tmp_dir = Avix_Migration_Util_Filesystem::tmp_dir();
		foreach ( array( $job->id . '-filelist.jsonl', $job->id . '-database.sql.gz' ) as $name ) {
			$path = $tmp_dir . '/' . $name;
			if ( file_exists( $path ) ) {
				@unlink( $path );
			}
		}
	}
}
