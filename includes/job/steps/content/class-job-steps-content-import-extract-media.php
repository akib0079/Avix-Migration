<?php
/**
 * Extracts every media entry (archive entries after the manifest and
 * content.json.gz) into a job-private staging directory — NOT directly
 * into the live uploads folder. Staging first means extraction can never
 * collide with or overwrite an unrelated file that happens to already
 * exist at the same relative path on the target; Content_Import_Attachments
 * is what actually places files into uploads, using WordPress's own
 * collision-safe naming at that point.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Content_Import_Extract_Media extends Avix_Migration_Job_Step {

	const MAX_FILES_PER_TICK = 100;
	const MAX_BYTES_PER_TICK = 52428800; // 50 MB.

	public function execute( Avix_Migration_Job $job ) {
		$cursor = $this->cursor( $job );

		if ( ! isset( $cursor['archive_offset'] ) ) {
			$start = $this->find_media_start_offset( $job );
			if ( null === $start ) {
				return Avix_Migration_Job_Step_Result::failed( __( 'Could not locate media entries in the archive.', 'avix-migration' ) );
			}
			$cursor['archive_offset'] = $start;
			$this->set_cursor( $job, $cursor );
		}

		$reader = new Avix_Migration_Archive_Reader( $job->meta['archive_path'] );
		if ( ! $reader->open() ) {
			return Avix_Migration_Job_Step_Result::failed( __( 'Could not open archive for media extraction.', 'avix-migration' ) );
		}
		$reader->seek( $cursor['archive_offset'] );

		$stage_dir = $this->staging_dir( $job );
		$processed_files = 0;
		$processed_bytes = 0;
		$done = false;

		while ( $processed_files < self::MAX_FILES_PER_TICK && $processed_bytes < self::MAX_BYTES_PER_TICK ) {
			$header = $reader->read_header();
			if ( null === $header ) {
				$done = true;
				break;
			}

			if ( Avix_Migration_Util_Filesystem::is_unsafe_relative_path( $header['dir'] )
				|| Avix_Migration_Util_Filesystem::is_unsafe_relative_path( $header['name'] )
			) {
				Avix_Migration_Util_Logger::warning( $job->id, 'Skipped an unsafe media entry path.', array( 'dir' => $header['dir'] ) );
				$reader->skip_content( $header['size'] );
			} else {
				// Strip the "media" (or "media/...") prefix Content_Copy_Media
				// added on export, so the staged path matches the
				// relative_path recorded in content.json's attachment records.
				$dir_without_prefix = 'media' === $header['dir'] ? '' : preg_replace( '#^media/#', '', $header['dir'] );
				$dest_dir  = $stage_dir . ( '' === $dir_without_prefix ? '' : '/' . $dir_without_prefix );
				$dest_path = $dest_dir . '/' . $header['name'];

				if ( ! Avix_Migration_Util_Filesystem::is_path_within( $stage_dir, $dest_path ) ) {
					Avix_Migration_Util_Logger::warning( $job->id, 'Skipped a media entry that resolved outside the staging area.', array( 'path' => $dest_path ) );
					$reader->skip_content( $header['size'] );
				} else {
					Avix_Migration_Util_Filesystem::ensure_dir( $dest_dir );
					$reader->stream_content_to( $dest_path, $header['size'] );
					$job->totals['files_done']++;
					$job->totals['bytes_done'] += $header['size'];
				}
			}

			$cursor['archive_offset'] = $reader->tell();
			$processed_files++;
			$processed_bytes += $header['size'];
		}

		$this->set_cursor( $job, $cursor );
		$reader->close();

		if ( $done ) {
			Avix_Migration_Util_Logger::info( $job->id, 'Media staged.', array( 'files' => $job->totals['files_done'] ) );
			return Avix_Migration_Job_Step_Result::step_complete( __( 'Media extracted.', 'avix-migration' ) );
		}

		return Avix_Migration_Job_Step_Result::cont(
			sprintf(
				/* translators: %d: files staged so far */
				__( 'Extracting media… %d done', 'avix-migration' ),
				$job->totals['files_done']
			)
		);
	}

	public function staging_dir( Avix_Migration_Job $job ) {
		return Avix_Migration_Util_Filesystem::tmp_dir() . '/' . $job->id . '-media';
	}

	private function find_media_start_offset( Avix_Migration_Job $job ) {
		$reader = new Avix_Migration_Archive_Reader( $job->meta['archive_path'] );
		if ( ! $reader->open() ) {
			return null;
		}
		$manifest_header = $reader->read_header();
		if ( ! $manifest_header ) {
			$reader->close();
			return null;
		}
		$reader->skip_content( $manifest_header['size'] );

		$json_header = $reader->read_header();
		if ( $json_header ) {
			$reader->skip_content( $json_header['size'] );
		}

		$offset = $reader->tell();
		$reader->close();
		return $offset;
	}

	public function label() {
		return __( 'Extracting media', 'avix-migration' );
	}
}
