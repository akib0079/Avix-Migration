<?php
/**
 * Streams each attachment's file into the archive under a "media/" entry
 * namespace (distinct from a full-site backup's wp-content-relative
 * paths) — resumable via a byte offset into the attachments.jsonl temp
 * file, same pattern as the full-site exporter's filelist consumption.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Content_Copy_Media extends Avix_Migration_Job_Step {

	const MAX_FILES_PER_TICK = 100;
	const MAX_BYTES_PER_TICK = 52428800; // 50 MB.

	public function execute( Avix_Migration_Job $job ) {
		$cursor = $this->cursor( $job );
		if ( ! isset( $cursor['offset'] ) ) {
			$cursor['offset'] = 0;
		}

		$path = ( new Avix_Migration_Job_Steps_Content_Collect() )->attachments_path( $job );
		if ( ! is_readable( $path ) ) {
			return Avix_Migration_Job_Step_Result::step_complete( __( 'No media to copy.', 'avix-migration' ) );
		}

		$upload_dir = wp_get_upload_dir();
		$writer     = new Avix_Migration_Archive_Writer( $job->meta['archive_path'] );
		$writer->open_for_resume();

		$fp = fopen( $path, 'rb' );
		fseek( $fp, $cursor['offset'], SEEK_SET );

		$copied_files = 0;
		$copied_bytes = 0;
		$reached_eof  = false;

		while ( $copied_files < self::MAX_FILES_PER_TICK && $copied_bytes < self::MAX_BYTES_PER_TICK ) {
			$line = fgets( $fp );
			if ( false === $line ) {
				$reached_eof = true;
				break;
			}
			$cursor['offset'] = ftell( $fp );

			$record = json_decode( trim( $line ), true );
			if ( ! is_array( $record ) || empty( $record['file_exists'] ) || '' === $record['relative_path'] ) {
				continue;
			}

			$source = $upload_dir['basedir'] . '/' . $record['relative_path'];
			$dir    = dirname( $record['relative_path'] );
			$entry_dir = 'media' . ( '.' === $dir ? '' : '/' . $dir );

			$copied = $writer->append_file( $source, basename( $record['relative_path'] ), $entry_dir );
			if ( false === $copied ) {
				Avix_Migration_Util_Logger::warning( $job->id, 'Could not read media file, skipped.', array( 'path' => $source ) );
				continue;
			}

			$copied_files++;
			$copied_bytes += $copied;
			$job->totals['bytes_done'] += $copied;
		}

		fclose( $fp );
		$writer->close();
		$this->set_cursor( $job, $cursor );

		if ( $reached_eof ) {
			Avix_Migration_Util_Logger::info( $job->id, 'Media copy complete.' );
			return Avix_Migration_Job_Step_Result::step_complete( __( 'Media files copied.', 'avix-migration' ) );
		}

		return Avix_Migration_Job_Step_Result::cont( __( 'Copying media files…', 'avix-migration' ) );
	}

	public function label() {
		return __( 'Copying media', 'avix-migration' );
	}
}
