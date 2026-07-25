<?php
/**
 * Walks the archive from just past the manifest (and the database entry,
 * if present) and extracts every remaining file entry into this site's own
 * wp-content — resumable via a byte offset directly into the archive file
 * itself, the same pattern used everywhere else in this plugin.
 *
 * Path safety is checked twice per entry, matching the two-layer defense
 * documented in Util_Filesystem: is_unsafe_relative_path() on the raw
 * header strings before they're ever concatenated into a real path, then
 * is_path_within() on the resolved result. Any entry that fails either
 * check is skipped (never written) and logged — Import_Validate already
 * ran this same check archive-wide before this step could even start, so
 * tripping it here would mean something modified the archive file on disk
 * between validation and extraction, which this treats as hostile by
 * default rather than as a bug to silently tolerate.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Import_Extract_Files extends Avix_Migration_Job_Step {

	const MAX_FILES_PER_TICK = 200;
	const MAX_BYTES_PER_TICK = 52428800; // 50 MB.

	public function execute( Avix_Migration_Job $job ) {
		$cursor = $this->cursor( $job );

		if ( ! isset( $cursor['archive_offset'] ) ) {
			$start = $this->find_files_start_offset( $job );
			if ( null === $start ) {
				return Avix_Migration_Job_Step_Result::failed( __( 'Could not locate the start of the file entries in the archive.', 'avix-migration' ) );
			}
			$cursor['archive_offset'] = $start;
			$this->set_cursor( $job, $cursor );
		}

		$reader = new Avix_Migration_Archive_Reader( $job->meta['archive_path'] );
		if ( ! $reader->open() ) {
			return Avix_Migration_Job_Step_Result::failed( __( 'Could not open archive for file extraction.', 'avix-migration' ) );
		}
		$reader->seek( $cursor['archive_offset'] );

		$processed_files = 0;
		$processed_bytes = 0;
		$done = false;

		while ( $processed_files < self::MAX_FILES_PER_TICK && $processed_bytes < self::MAX_BYTES_PER_TICK ) {
			$header = $reader->read_header();
			if ( null === $header ) {
				$done = true;
				break;
			}

			$write_this_one = ! empty( $job->meta['has_files'] );

			if ( $write_this_one
				&& ( Avix_Migration_Util_Filesystem::is_unsafe_relative_path( $header['dir'] )
					|| Avix_Migration_Util_Filesystem::is_unsafe_relative_path( $header['name'] ) )
			) {
				Avix_Migration_Util_Logger::warning( $job->id, 'Skipped an unsafe archive entry path.', array( 'dir' => $header['dir'], 'name' => $header['name'] ) );
				$write_this_one = false;
			}

			if ( $write_this_one ) {
				$dest_dir  = WP_CONTENT_DIR . ( '' === $header['dir'] ? '' : '/' . $header['dir'] );
				$dest_path = $dest_dir . '/' . $header['name'];

				if ( ! Avix_Migration_Util_Filesystem::is_path_within( WP_CONTENT_DIR, $dest_path ) ) {
					Avix_Migration_Util_Logger::warning( $job->id, 'Skipped an entry that resolved outside wp-content.', array( 'path' => $dest_path ) );
					$reader->skip_content( $header['size'] );
				} else {
					Avix_Migration_Util_Filesystem::ensure_dir( $dest_dir );
					$copied = $reader->stream_content_to( $dest_path, $header['size'] );
					if ( $copied !== $header['size'] ) {
						Avix_Migration_Util_Logger::warning( $job->id, 'File extracted with unexpected size — source may be inconsistent.', array( 'path' => $dest_path ) );
					}
					$job->totals['files_done']++;
					$job->totals['bytes_done'] += $copied;
				}
			} else {
				$reader->skip_content( $header['size'] );
			}

			$cursor['archive_offset'] = $reader->tell();
			$processed_files++;
			$processed_bytes += $header['size'];
		}

		$this->set_cursor( $job, $cursor );
		$reader->close();

		if ( $done ) {
			Avix_Migration_Util_Logger::info( $job->id, 'File extraction complete.', array( 'files' => $job->totals['files_done'] ) );
			return Avix_Migration_Job_Step_Result::step_complete( __( 'Files extracted.', 'avix-migration' ) );
		}

		return Avix_Migration_Job_Step_Result::cont(
			sprintf(
				/* translators: %d: files extracted so far */
				__( 'Extracting files… %d done', 'avix-migration' ),
				$job->totals['files_done']
			)
		);
	}

	/**
	 * Walks past the manifest and (if present) the database entry once, to
	 * find the byte offset the first real file entry starts at. Cheap
	 * regardless of archive size — at most two header reads plus one
	 * content skip.
	 */
	private function find_files_start_offset( Avix_Migration_Job $job ) {
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

		if ( ! empty( $job->meta['manifest']['archive_type'] ) ) {
			// Peek at the next header without consuming it if it's not the
			// database entry — but Archive_Reader has no "peek", so read it
			// and, if it isn't the database, we've already consumed it and
			// must rewind to before it instead.
			$before_second = $reader->tell();
			$second_header = $reader->read_header();
			if ( $second_header && 'database.sql.gz' === $second_header['name'] && '' === $second_header['dir'] ) {
				$reader->skip_content( $second_header['size'] );
			} else {
				$reader->seek( $before_second );
			}
		}

		$offset = $reader->tell();
		$reader->close();
		return $offset;
	}

	public function label() {
		return __( 'Extracting files', 'avix-migration' );
	}
}
