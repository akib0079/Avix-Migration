<?php
/**
 * Places staged media files into the live uploads directory (with
 * WordPress's own collision-safe naming) and creates the corresponding
 * attachment posts, building the source_id -> new_id map that
 * Content_Import_Posts needs to rewrite image references.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Content_Import_Attachments extends Avix_Migration_Job_Step {

	const BATCH_SIZE = 20;

	public function execute( Avix_Migration_Job $job ) {
		$content = $this->read_content_json( $job );
		if ( null === $content ) {
			return Avix_Migration_Job_Step_Result::failed( __( 'Content index is missing.', 'avix-migration' ) );
		}

		$cursor = $this->cursor( $job );
		if ( ! isset( $cursor['index'] ) ) {
			$cursor['index'] = 0;
			$job->meta['attachment_id_map'] = array();
		}

		$attachments = $content['attachments'];
		$batch       = array_slice( $attachments, $cursor['index'], self::BATCH_SIZE );

		if ( empty( $batch ) ) {
			Avix_Migration_Util_Logger::info( $job->id, 'Attachments imported.', array( 'count' => count( $job->meta['attachment_id_map'] ) ) );
			return Avix_Migration_Job_Step_Result::step_complete( __( 'Media imported.', 'avix-migration' ) );
		}

		$url_pairs  = Avix_Migration_Content_Importer::url_pairs_for_manifest( $job->meta['manifest'] );
		$stage_dir  = ( new Avix_Migration_Job_Steps_Content_Import_Extract_Media() )->staging_dir( $job );
		$upload_dir = wp_get_upload_dir();

		foreach ( $batch as $record ) {
			$staged_path = $stage_dir . '/' . $record['relative_path'];
			if ( '' === $record['relative_path'] || ! is_readable( $staged_path ) ) {
				continue; // Wasn't in the archive (export-time file was missing) — nothing to place.
			}

			$sub_dir   = dirname( $record['relative_path'] );
			$real_dir  = $upload_dir['basedir'] . ( '.' === $sub_dir ? '' : '/' . $sub_dir );
			Avix_Migration_Util_Filesystem::ensure_dir( $real_dir );

			$safe_name = wp_unique_filename( $real_dir, basename( $record['relative_path'] ) );
			$real_path = $real_dir . '/' . $safe_name;

			if ( ! @copy( $staged_path, $real_path ) ) {
				Avix_Migration_Util_Logger::warning( $job->id, 'Could not place media file.', array( 'path' => $real_path ) );
				continue;
			}

			$new_id = Avix_Migration_Content_Importer::import_attachment( $record, $real_path, $url_pairs );
			if ( $new_id ) {
				$job->meta['attachment_id_map'][ $record['source_id'] ] = $new_id;
				$job->totals['files_done']++;
			} else {
				@unlink( $real_path );
				Avix_Migration_Util_Logger::warning( $job->id, 'Could not create attachment.', array( 'source_id' => $record['source_id'] ) );
			}
		}

		$cursor['index'] += count( $batch );
		$this->set_cursor( $job, $cursor );

		return Avix_Migration_Job_Step_Result::cont(
			sprintf(
				/* translators: 1: attachments imported so far, 2: total */
				__( 'Importing media… %1$d / %2$d', 'avix-migration' ),
				$cursor['index'],
				count( $attachments )
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

	public function label() {
		return __( 'Importing media', 'avix-migration' );
	}
}
