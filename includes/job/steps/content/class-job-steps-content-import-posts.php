<?php
/**
 * Imports posts in two phases: 'insert' creates/updates every post with
 * post_parent left at 0 and records the source_id -> new_id mapping;
 * 'fixup' then sets post_parent on whichever posts had one, now that every
 * post in this export has a resolved new ID. This ordering is what lets
 * posts.json contain parents and children in any order — nothing here
 * depends on parents appearing before children.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Content_Import_Posts extends Avix_Migration_Job_Step {

	const BATCH_SIZE = 20;

	public function execute( Avix_Migration_Job $job ) {
		$content = $this->read_content_json( $job );
		if ( null === $content ) {
			return Avix_Migration_Job_Step_Result::failed( __( 'Content index is missing.', 'avix-migration' ) );
		}

		$cursor = $this->cursor( $job );
		if ( ! isset( $cursor['phase'] ) ) {
			$cursor['phase'] = 'insert';
			$cursor['index'] = 0;
			$job->meta['post_id_map']      = array();
			$job->meta['pending_parents']  = array(); // new_id => source_parent
			$job->meta['import_warnings']  = array();
		}

		$posts = $content['posts'];

		if ( 'insert' === $cursor['phase'] ) {
			return $this->do_insert_phase( $job, $cursor, $posts );
		}

		return $this->do_fixup_phase( $job, $cursor );
	}

	private function do_insert_phase( Avix_Migration_Job $job, array $cursor, array $posts ) {
		$batch = array_slice( $posts, $cursor['index'], self::BATCH_SIZE );

		if ( empty( $batch ) ) {
			$cursor['phase'] = 'fixup';
			$cursor['index'] = 0;
			$this->set_cursor( $job, $cursor );
			return Avix_Migration_Job_Step_Result::cont( __( 'Posts imported — fixing up page hierarchy…', 'avix-migration' ) );
		}

		$url_pairs          = Avix_Migration_Content_Importer::url_pairs_for_manifest( $job->meta['manifest'] );
		$attachment_id_map  = $job->meta['attachment_id_map'] ?? array();
		$conflict_mode      = $job->meta['conflict_mode'] ?? Avix_Migration_Content_Importer::CONFLICT_SKIP;
		$default_author_id  = $job->meta['default_author_id'] ?? get_current_user_id();

		foreach ( $batch as $record ) {
			$result = Avix_Migration_Content_Importer::import_post(
				$record,
				$attachment_id_map,
				$job->meta['term_id_map'] ?? array(),
				$url_pairs,
				$conflict_mode,
				$default_author_id
			);

			if ( ! $result['id'] ) {
				$job->meta['import_warnings'][] = sprintf( 'Could not import post (source ID %d).', $record['source_id'] );
				continue;
			}

			$job->meta['post_id_map'][ $result['source_id'] ] = $result['id'];
			if ( $result['source_parent'] > 0 ) {
				$job->meta['pending_parents'][ $result['id'] ] = $result['source_parent'];
			}
			$job->totals['rows_done']++;
		}

		$cursor['index'] += count( $batch );
		$this->set_cursor( $job, $cursor );

		return Avix_Migration_Job_Step_Result::cont(
			sprintf(
				/* translators: 1: posts imported so far, 2: total */
				__( 'Importing posts… %1$d / %2$d', 'avix-migration' ),
				$cursor['index'],
				count( $posts )
			)
		);
	}

	private function do_fixup_phase( Avix_Migration_Job $job, array $cursor ) {
		$pending = $job->meta['pending_parents'] ?? array();
		$pairs   = array_slice( $pending, $cursor['index'], self::BATCH_SIZE, true );

		if ( empty( $pairs ) ) {
			Avix_Migration_Util_Logger::info( $job->id, 'Post import complete.', array( 'count' => count( $job->meta['post_id_map'] ) ) );
			return Avix_Migration_Job_Step_Result::step_complete( __( 'Posts imported.', 'avix-migration' ) );
		}

		$post_id_map = $job->meta['post_id_map'];

		foreach ( $pairs as $new_id => $source_parent ) {
			if ( isset( $post_id_map[ $source_parent ] ) ) {
				Avix_Migration_Content_Importer::fixup_parent( $new_id, $post_id_map[ $source_parent ] );
			} else {
				$job->meta['import_warnings'][] = sprintf(
					'Post ID %d\'s parent (source ID %d) was not part of this export — imported as a top-level item instead.',
					$new_id,
					$source_parent
				);
			}
		}

		$cursor['index'] += count( $pairs );
		$this->set_cursor( $job, $cursor );

		return Avix_Migration_Job_Step_Result::cont( __( 'Fixing up page hierarchy…', 'avix-migration' ) );
	}

	private function read_content_json( Avix_Migration_Job $job ) {
		if ( empty( $job->meta['content_json_path'] ) || ! is_readable( $job->meta['content_json_path'] ) ) {
			return null;
		}
		$decoded = json_decode( (string) file_get_contents( $job->meta['content_json_path'] ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	public function label() {
		return __( 'Importing posts', 'avix-migration' );
	}
}
