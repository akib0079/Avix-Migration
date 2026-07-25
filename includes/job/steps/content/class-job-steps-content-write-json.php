<?php
/**
 * Combines the three temp files Content_Collect produced into a single
 * content.json.gz archive entry, written as entry #2 (right after the
 * manifest) — matching the full-site format's "manifest, then the
 * structured-data entry, then files" order so an importer only ever walks
 * two headers to reach it. A single gzencode() call is enough here (unlike
 * the database dump) because a content export's combined JSON is bounded
 * in size — dozens to low hundreds of posts, not a whole site's database —
 * so there is no need for the chunked, multi-gzip-member append pattern
 * Db_Exporter uses for potentially multi-GB dumps.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Content_Write_Json extends Avix_Migration_Job_Step {

	public function label() {
		return __( 'Writing content index', 'avix-migration' );
	}

	public function execute( Avix_Migration_Job $job ) {
		$collect_step = new Avix_Migration_Job_Steps_Content_Collect();

		$terms = json_decode( (string) @file_get_contents( $collect_step->terms_path( $job ) ), true );
		$posts = $this->read_jsonl( $collect_step->posts_path( $job ) );
		$attachments = $this->read_jsonl( $collect_step->attachments_path( $job ) );

		$content = array(
			'terms'       => is_array( $terms ) ? $terms : array(),
			'posts'       => $posts,
			'attachments' => $attachments,
		);

		$json = wp_json_encode( $content );
		$gzipped = gzencode( $json, 9 );

		$writer = new Avix_Migration_Archive_Writer( $job->meta['archive_path'] );
		$writer->open_for_resume();
		$writer->append_string( 'content.json.gz', '', $gzipped );
		$writer->close();

		@unlink( $collect_step->terms_path( $job ) );
		@unlink( $collect_step->posts_path( $job ) );
		// attachments.jsonl is kept — Content_Copy_Media reads it next.

		Avix_Migration_Util_Logger::info(
			$job->id,
			'Content index written.',
			array( 'posts' => count( $posts ), 'attachments' => count( $attachments ), 'terms' => count( $content['terms'] ) )
		);

		return Avix_Migration_Job_Step_Result::step_complete( __( 'Content index written.', 'avix-migration' ) );
	}

	private function read_jsonl( $path ) {
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$out   = array();
		foreach ( (array) $lines as $line ) {
			$decoded = json_decode( $line, true );
			if ( is_array( $decoded ) ) {
				$out[] = $decoded;
			}
		}
		return $out;
	}
}
