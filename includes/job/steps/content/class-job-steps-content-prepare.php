<?php
/**
 * First step of a content-export job: resolves dependencies (attachments,
 * referenced Elementor templates) for the operator's selected posts, then
 * creates the archive and writes its manifest. Everything after this step
 * works from job->meta['all_post_ids'] / ['attachment_ids'], not the raw
 * selection the wizard submitted.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Content_Prepare extends Avix_Migration_Job_Step {

	public function label() {
		return __( 'Preparing content export', 'avix-migration' );
	}

	public function execute( Avix_Migration_Job $job ) {
		$selected = array_map( 'intval', (array) ( $job->meta['post_ids'] ?? array() ) );
		if ( empty( $selected ) ) {
			return Avix_Migration_Job_Step_Result::failed( __( 'No posts were selected for export.', 'avix-migration' ) );
		}

		$deps = Avix_Migration_Content_Dependency_Resolver::resolve( $selected );

		$job->meta['all_post_ids']   = array_values( array_unique( array_merge( $selected, $deps['template_ids'] ) ) );
		$job->meta['attachment_ids'] = $deps['attachment_ids'];
		$job->meta['warnings']       = array_merge( (array) ( $job->meta['warnings'] ?? array() ), $deps['warnings'] );

		Avix_Migration_Util_Filesystem::create_storage_dirs();

		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$slug     = sanitize_title( $host ? $host : 'site' );
		$filename = sprintf( '%s-content-%s-%s.avix', $slug, gmdate( 'Ymd-His' ), Avix_Migration_Util_Crypto::random_token( 4 ) );
		$path     = Avix_Migration_Util_Filesystem::archives_dir() . '/' . $filename;

		$writer = new Avix_Migration_Archive_Writer( $path );
		$writer->open_for_resume();

		$manifest = Avix_Migration_Archive_Manifest::build(
			Avix_Migration_Archive_Manifest::TYPE_CONTENT,
			array(
				'post_count'       => count( $job->meta['all_post_ids'] ),
				'attachment_count' => count( $job->meta['attachment_ids'] ),
			)
		);
		$writer->append_string( Avix_Migration_Archive_Manifest::ENTRY_NAME, '', wp_json_encode( $manifest ) );
		$writer->close();

		$job->meta['archive_filename'] = $filename;
		$job->meta['archive_path']     = $path;
		$job->meta['archive_type']     = Avix_Migration_Archive_Manifest::TYPE_CONTENT;

		$job->totals['rows_total']  = count( $job->meta['all_post_ids'] );
		$job->totals['files_total'] = count( $job->meta['attachment_ids'] );

		Avix_Migration_Util_Logger::info(
			$job->id,
			'Content export prepared.',
			array( 'posts' => count( $job->meta['all_post_ids'] ), 'attachments' => count( $job->meta['attachment_ids'] ) )
		);

		return Avix_Migration_Job_Step_Result::step_complete(
			sprintf(
				/* translators: 1: post count, 2: attachment count */
				__( 'Found %1$d posts and %2$d media files to export.', 'avix-migration' ),
				count( $job->meta['all_post_ids'] ),
				count( $job->meta['attachment_ids'] )
			)
		);
	}
}
