<?php
/**
 * Imports (or reuses) every term from the content index in one pass —
 * term counts are inherently small even for a large content export, so
 * this doesn't need per-batch chunking the way posts/attachments do.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Content_Import_Terms extends Avix_Migration_Job_Step {

	public function label() {
		return __( 'Importing categories and tags', 'avix-migration' );
	}

	public function execute( Avix_Migration_Job $job ) {
		$content = $this->read_content_json( $job );
		if ( null === $content ) {
			return Avix_Migration_Job_Step_Result::failed( __( 'Content index is missing.', 'avix-migration' ) );
		}

		$term_records = array();
		foreach ( $content['terms'] as $term ) {
			$term_records[ $term['taxonomy'] . ':' . $term['source_id'] ] = $term;
		}

		$map = Avix_Migration_Content_Importer::import_terms( $term_records );
		$job->meta['term_id_map'] = $map;

		Avix_Migration_Util_Logger::info( $job->id, 'Terms imported.', array( 'count' => count( $map ) ) );

		return Avix_Migration_Job_Step_Result::step_complete(
			sprintf(
				/* translators: %d: term count */
				__( 'Imported %d terms.', 'avix-migration' ),
				count( $map )
			)
		);
	}

	private function read_content_json( Avix_Migration_Job $job ) {
		if ( empty( $job->meta['content_json_path'] ) || ! is_readable( $job->meta['content_json_path'] ) ) {
			return null;
		}
		$decoded = json_decode( (string) file_get_contents( $job->meta['content_json_path'] ), true );
		return is_array( $decoded ) ? $decoded : null;
	}
}
