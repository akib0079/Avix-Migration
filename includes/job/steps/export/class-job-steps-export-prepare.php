<?php
/**
 * First step of a full-site export job: creates the archive file and writes
 * its manifest entry. Everything after this step assumes
 * $job->meta['archive_filename'] and $job->meta['archive_path'] exist.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Export_Prepare extends Avix_Migration_Job_Step {

	public function label() {
		return __( 'Preparing backup', 'avix-migration' );
	}

	public function execute( Avix_Migration_Job $job ) {
		if ( ! empty( $job->meta['archive_filename'] ) ) {
			// Already prepared (shouldn't normally re-enter, but idempotent
			// just in case of a retried call).
			return Avix_Migration_Job_Step_Result::step_complete( 'Already prepared.' );
		}

		Avix_Migration_Util_Filesystem::create_storage_dirs();

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$slug = sanitize_title( $host ? $host : 'site' );
		$filename = sprintf(
			'%s-%s-%s.avix',
			$slug,
			gmdate( 'Ymd-His' ),
			Avix_Migration_Util_Crypto::random_token( 4 )
		);
		$path = Avix_Migration_Util_Filesystem::archives_dir() . '/' . $filename;

		$writer = new Avix_Migration_Archive_Writer( $path );
		$writer->open_for_resume();

		$manifest = Avix_Migration_Archive_Manifest::build(
			Avix_Migration_Archive_Manifest::TYPE_FULL,
			array(
				'include' => array(
					'database'   => ! empty( $job->meta['include_database'] ),
					'uploads'    => empty( $job->meta['excluded_top_dirs']['uploads'] ),
					'plugins'    => empty( $job->meta['excluded_top_dirs']['plugins'] ),
					'themes'     => empty( $job->meta['excluded_top_dirs']['themes'] ),
					'mu_plugins' => empty( $job->meta['excluded_top_dirs']['mu-plugins'] ),
				),
				// Recorded so Schedule_Retention can find "this schedule's
				// own archives" without guessing from filenames — null for
				// a manually-started backup, which retention never touches.
				'schedule_id' => $job->meta['schedule_id'] ?? null,
			)
		);
		$writer->append_string( Avix_Migration_Archive_Manifest::ENTRY_NAME, '', wp_json_encode( $manifest ) );
		$writer->close();

		$job->meta['archive_filename'] = $filename;
		$job->meta['archive_path']     = $path;
		// A remote pull needs to know the archive's type before its own
		// import job exists yet (it can't peek at a manifest that isn't
		// downloaded locally) — recording it directly in job->meta lets
		// the REST export/status response expose it.
		$job->meta['archive_type']     = Avix_Migration_Archive_Manifest::TYPE_FULL;

		Avix_Migration_Util_Logger::info( $job->id, 'Backup prepared.', array( 'file' => $filename ) );

		return Avix_Migration_Job_Step_Result::step_complete( 'Archive created.' );
	}
}
