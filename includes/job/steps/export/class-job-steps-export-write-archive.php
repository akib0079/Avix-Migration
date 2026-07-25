<?php
/**
 * Appends the database dump (if any) as the archive's second entry —
 * immediately after the manifest, matching the documented .avix format so
 * an importer only ever has to walk past ONE entry (the manifest) to reach
 * the database, regardless of how many thousands of files follow it — then
 * copies every file listed in the scan step's filelist.jsonl. This is
 * normally the longest-running step, so it's also where
 * job->totals['bytes_done'] — the number driving the main progress bar —
 * actually advances.
 *
 * Resumable via a byte offset into filelist.jsonl (the same "cursor = byte
 * position in a file" pattern the archive format itself uses) rather than a
 * line number, since fseek() to a byte offset is O(1) regardless of how far
 * into a large file it is.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Export_Write_Archive extends Avix_Migration_Job_Step {

	/** Soft caps per tick — whichever is hit first ends the batch. */
	const MAX_FILES_PER_TICK = 200;
	const MAX_BYTES_PER_TICK = 52428800; // 50 MB.

	public function execute( Avix_Migration_Job $job ) {
		if ( empty( $job->meta['archive_path'] ) ) {
			return Avix_Migration_Job_Step_Result::failed( 'Archive was never prepared — cannot write entries.' );
		}

		$cursor = $this->cursor( $job );
		if ( ! isset( $cursor['filelist_offset'] ) ) {
			$cursor['filelist_offset'] = 0;
			$cursor['db_appended']     = false;
		}

		if ( ! $cursor['db_appended'] ) {
			$this->append_database( $job, $cursor );
			$cursor['db_appended'] = true;
			$this->set_cursor( $job, $cursor );
			return Avix_Migration_Job_Step_Result::cont( __( 'Database added to archive.', 'avix-migration' ) );
		}

		if ( empty( $job->meta['include_files'] ) ) {
			return Avix_Migration_Job_Step_Result::step_complete( __( 'All entries written.', 'avix-migration' ) );
		}

		$writer = new Avix_Migration_Archive_Writer( $job->meta['archive_path'] );
		$writer->open_for_resume();

		$filelist_path = $this->filelist_path( $job );
		$copied_files  = 0;
		$copied_bytes  = 0;
		$reached_eof   = false;

		if ( is_readable( $filelist_path ) ) {
			$fp = fopen( $filelist_path, 'rb' );
			fseek( $fp, $cursor['filelist_offset'], SEEK_SET );

			while ( $copied_files < self::MAX_FILES_PER_TICK && $copied_bytes < self::MAX_BYTES_PER_TICK ) {
				$line = fgets( $fp );
				if ( false === $line ) {
					$reached_eof = true;
					break;
				}

				$cursor['filelist_offset'] = ftell( $fp );

				$record = json_decode( trim( $line ), true );
				if ( ! is_array( $record ) || ! isset( $record['dir'], $record['name'], $record['size'] ) ) {
					continue; // Skip a malformed line rather than aborting the whole export.
				}

				$source = WP_CONTENT_DIR . ( '' === $record['dir'] ? '' : '/' . $record['dir'] ) . '/' . $record['name'];
				$copied = $writer->append_file( $source, $record['name'], $record['dir'] );

				if ( false === $copied ) {
					Avix_Migration_Util_Logger::warning( $job->id, 'Could not read source file, skipped.', array( 'path' => $source ) );
					continue;
				}

				$copied_files++;
				$copied_bytes            += $copied;
				$job->totals['files_done']++;
				$job->totals['bytes_done'] += $copied;
			}

			fclose( $fp );
		} else {
			$reached_eof = true; // No filelist at all — nothing to copy.
		}

		$this->set_cursor( $job, $cursor );
		$writer->close();

		if ( ! $reached_eof ) {
			return Avix_Migration_Job_Step_Result::cont(
				sprintf(
					/* translators: %d: files copied so far in this backup */
					__( 'Copying files… %d done', 'avix-migration' ),
					$job->totals['files_done']
				)
			);
		}

		return Avix_Migration_Job_Step_Result::step_complete( __( 'All entries written.', 'avix-migration' ) );
	}

	private function append_database( Avix_Migration_Job $job, array $cursor ) {
		if ( empty( $job->meta['include_database'] ) || empty( $job->meta['db_tmp_path'] ) ) {
			return;
		}

		$db_path = $job->meta['db_tmp_path'];
		if ( ! is_readable( $db_path ) ) {
			return;
		}

		$writer = new Avix_Migration_Archive_Writer( $job->meta['archive_path'] );
		$writer->open_for_resume();

		// bytes_total already includes this file's size — folded in by the
		// database export step the moment it finished, precisely so this
		// doesn't shift the progress total mid-copy.
		$size = filesize( $db_path );
		$writer->append_file( $db_path, 'database.sql.gz', '' );
		$job->totals['bytes_done'] += $size;

		$writer->close();

		Avix_Migration_Util_Logger::info( $job->id, 'Database dump appended to archive (entry #2, right after the manifest).' );
	}

	public function filelist_path( Avix_Migration_Job $job ) {
		return Avix_Migration_Util_Filesystem::tmp_dir() . '/' . $job->id . '-filelist.jsonl';
	}

	public function label() {
		return __( 'Writing archive', 'avix-migration' );
	}
}
