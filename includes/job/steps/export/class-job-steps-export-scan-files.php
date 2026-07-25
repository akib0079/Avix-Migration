<?php
/**
 * Walks wp-content (via Fs_Scanner) and records every file that will go
 * into the archive as a JSON line in a temp manifest file — not yet copied,
 * just listed, so job->totals has an accurate files/bytes total before the
 * (much longer) copy phase starts.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Export_Scan_Files extends Avix_Migration_Job_Step {

	public function label() {
		return __( 'Scanning files', 'avix-migration' );
	}

	public function execute( Avix_Migration_Job $job ) {
		if ( empty( $job->meta['include_files'] ) ) {
			return Avix_Migration_Job_Step_Result::step_complete( 'Files not included in this backup.' );
		}

		$cursor = $this->cursor( $job );
		$filelist_path = $this->filelist_path( $job );

		if ( empty( $cursor['files_found'] ) ) {
			$cursor['files_found'] = 0;
			$cursor['bytes_found'] = 0;
		}

		$lines = array();
		$excluded_dirs = (array) ( $job->meta['excluded_dirs'] ?? array() );

		$on_file = function ( $record ) use ( &$lines, &$cursor ) {
			$lines[] = wp_json_encode( $record );
			$cursor['files_found']++;
			$cursor['bytes_found'] += $record['size'];
		};

		$done = Avix_Migration_Fs_Scanner::tick( $cursor, $on_file, $excluded_dirs );

		if ( ! empty( $lines ) ) {
			file_put_contents( $filelist_path, implode( "\n", $lines ) . "\n", FILE_APPEND | LOCK_EX );
		}

		$this->set_cursor( $job, $cursor );

		$job->totals['files_total'] = $cursor['files_found'];
		$job->totals['bytes_total'] = $cursor['bytes_found'];

		if ( $done ) {
			Avix_Migration_Util_Logger::info(
				$job->id,
				'File scan complete.',
				array( 'files' => $cursor['files_found'], 'bytes' => $cursor['bytes_found'] )
			);
			return Avix_Migration_Job_Step_Result::step_complete( 'Scan complete.' );
		}

		return Avix_Migration_Job_Step_Result::cont(
			sprintf(
				/* translators: 1: file count, 2: human-readable size */
				__( 'Scanning… %1$d files found (%2$s)', 'avix-migration' ),
				$cursor['files_found'],
				Avix_Migration_Util_Filesystem::human_size( $cursor['bytes_found'] )
			)
		);
	}

	public function filelist_path( Avix_Migration_Job $job ) {
		return Avix_Migration_Util_Filesystem::tmp_dir() . '/' . $job->id . '-filelist.jsonl';
	}
}
