<?php
/**
 * Last step of a content import: cleans up the staging directory and temp
 * content.json, and folds any accumulated warnings (orphaned parents,
 * unparseable Elementor data, etc.) into job->meta for the success screen
 * to display — a content import can finish successfully while still having
 * a few things worth telling the operator about.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Content_Import_Finalize extends Avix_Migration_Job_Step {

	public function label() {
		return __( 'Finishing up', 'avix-migration' );
	}

	public function execute( Avix_Migration_Job $job ) {
		if ( ! empty( $job->meta['content_json_path'] ) ) {
			@unlink( $job->meta['content_json_path'] );
		}

		$stage_dir = ( new Avix_Migration_Job_Steps_Content_Import_Extract_Media() )->staging_dir( $job );
		Avix_Migration_Util_Filesystem::delete_dir( $stage_dir );

		wp_cache_flush();

		$imported = count( $job->meta['post_id_map'] ?? array() );
		$warnings = array_merge( (array) ( $job->meta['warnings'] ?? array() ), (array) ( $job->meta['import_warnings'] ?? array() ) );
		$job->meta['warnings'] = array_values( array_unique( $warnings ) );

		Avix_Migration_Util_Logger::info( $job->id, 'Content import finished.', array( 'posts_imported' => $imported, 'warning_count' => count( $job->meta['warnings'] ) ) );

		return Avix_Migration_Job_Step_Result::step_complete(
			sprintf(
				/* translators: %d: number of posts imported */
				__( 'Imported %d posts.', 'avix-migration' ),
				$imported
			)
		);
	}
}
