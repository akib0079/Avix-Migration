<?php
/**
 * Collects terms (once, small), then posts, then attachment records, each
 * written incrementally to their own temp file rather than accumulated in
 * job->meta — bounded batches per tick, same discipline as the full-site
 * exporter, even though a content export's scale is normally much smaller.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Content_Collect extends Avix_Migration_Job_Step {

	const POSTS_PER_TICK       = 20;
	const ATTACHMENTS_PER_TICK = 50;

	public function execute( Avix_Migration_Job $job ) {
		$cursor = $this->cursor( $job );
		if ( ! isset( $cursor['phase'] ) ) {
			$cursor['phase'] = 'terms';
			$cursor['post_index'] = 0;
			$cursor['attachment_index'] = 0;
		}

		if ( 'terms' === $cursor['phase'] ) {
			$terms = Avix_Migration_Content_Exporter::collect_all_terms_for_posts( $job->meta['all_post_ids'] );
			file_put_contents( $this->terms_path( $job ), wp_json_encode( array_values( $terms ) ) );
			$cursor['phase'] = 'posts';
			$this->set_cursor( $job, $cursor );
			return Avix_Migration_Job_Step_Result::cont(
				sprintf(
					/* translators: %d: term count */
					__( 'Collected %d terms.', 'avix-migration' ),
					count( $terms )
				)
			);
		}

		if ( 'posts' === $cursor['phase'] ) {
			$ids   = $job->meta['all_post_ids'];
			$batch = array_slice( $ids, $cursor['post_index'], self::POSTS_PER_TICK );

			$lines = array();
			foreach ( $batch as $post_id ) {
				$record = Avix_Migration_Content_Exporter::collect_post( $post_id );
				if ( $record ) {
					$lines[] = wp_json_encode( $record );
				}
			}
			if ( ! empty( $lines ) ) {
				file_put_contents( $this->posts_path( $job ), implode( "\n", $lines ) . "\n", FILE_APPEND | LOCK_EX );
			}

			$cursor['post_index'] += count( $batch );
			$job->totals['rows_done'] = $cursor['post_index'];

			if ( $cursor['post_index'] >= count( $ids ) ) {
				$cursor['phase'] = 'attachments';
			}
			$this->set_cursor( $job, $cursor );

			return Avix_Migration_Job_Step_Result::cont(
				sprintf(
					/* translators: 1: posts processed, 2: total posts */
					__( 'Collecting posts… %1$d / %2$d', 'avix-migration' ),
					$cursor['post_index'],
					count( $ids )
				)
			);
		}

		if ( 'attachments' === $cursor['phase'] ) {
			$ids   = $job->meta['attachment_ids'];
			$batch = array_slice( $ids, $cursor['attachment_index'], self::ATTACHMENTS_PER_TICK );

			$lines = array();
			foreach ( $batch as $attachment_id ) {
				$record = Avix_Migration_Content_Exporter::collect_attachment( $attachment_id );
				if ( $record ) {
					$lines[] = wp_json_encode( $record );
				}
			}
			if ( ! empty( $lines ) ) {
				file_put_contents( $this->attachments_path( $job ), implode( "\n", $lines ) . "\n", FILE_APPEND | LOCK_EX );
			}

			$cursor['attachment_index'] += count( $batch );
			$job->totals['files_done'] = $cursor['attachment_index'];

			$this->set_cursor( $job, $cursor );

			if ( $cursor['attachment_index'] >= count( $ids ) ) {
				Avix_Migration_Util_Logger::info( $job->id, 'Content collection complete.' );
				return Avix_Migration_Job_Step_Result::step_complete( __( 'Collection complete.', 'avix-migration' ) );
			}

			return Avix_Migration_Job_Step_Result::cont(
				sprintf(
					/* translators: 1: attachments processed, 2: total attachments */
					__( 'Collecting media info… %1$d / %2$d', 'avix-migration' ),
					$cursor['attachment_index'],
					count( $ids )
				)
			);
		}

		return Avix_Migration_Job_Step_Result::step_complete( __( 'Collection complete.', 'avix-migration' ) );
	}

	public function terms_path( Avix_Migration_Job $job ) {
		return Avix_Migration_Util_Filesystem::tmp_dir() . '/' . $job->id . '-content-terms.json';
	}
	public function posts_path( Avix_Migration_Job $job ) {
		return Avix_Migration_Util_Filesystem::tmp_dir() . '/' . $job->id . '-content-posts.jsonl';
	}
	public function attachments_path( Avix_Migration_Job $job ) {
		return Avix_Migration_Util_Filesystem::tmp_dir() . '/' . $job->id . '-content-attachments.jsonl';
	}

	public function label() {
		return __( 'Collecting content', 'avix-migration' );
	}
}
