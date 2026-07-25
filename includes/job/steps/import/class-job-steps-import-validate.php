<?php
/**
 * First step of every import job: confirms the archive is structurally
 * sound (manifest present and understood, every entry header valid, no
 * path-traversal attempt, checksum matches if present) BEFORE a single
 * table or file is touched. This is what lets later steps trust the
 * archive completely rather than re-validating defensively at every turn.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Import_Validate extends Avix_Migration_Job_Step {

	public function label() {
		return __( 'Validating archive', 'avix-migration' );
	}

	public function execute( Avix_Migration_Job $job ) {
		$path = $job->meta['archive_path'] ?? '';

		if ( '' === $path || ! is_readable( $path ) ) {
			return Avix_Migration_Job_Step_Result::failed( __( 'Archive file not found or not readable.', 'avix-migration' ) );
		}

		$manifest = Avix_Migration_Archive_Reader::read_manifest_only( $path );
		$valid    = Avix_Migration_Archive_Manifest::validate( $manifest );
		if ( is_wp_error( $valid ) ) {
			return Avix_Migration_Job_Step_Result::failed( $valid->get_error_message() );
		}

		// Optional checksum sidecar — verify if present, but don't require
		// it (an archive downloaded from an older version, or copied by
		// hand, may not have one).
		$checksum_file = $path . '.checksum.json';
		if ( is_readable( $checksum_file ) ) {
			$sidecar = json_decode( (string) file_get_contents( $checksum_file ), true );
			if ( is_array( $sidecar ) && ! empty( $sidecar['sha256'] ) ) {
				$actual = hash_file( 'sha256', $path );
				if ( ! hash_equals( $sidecar['sha256'], $actual ) ) {
					return Avix_Migration_Job_Step_Result::failed(
						__( 'Checksum mismatch — this archive appears to be corrupted or was modified after export.', 'avix-migration' )
					);
				}
			}
		}

		// Full structural walk: every header well-formed, every size claim
		// backed by real bytes, every path safe. This is also where
		// job->totals gets its accurate numbers for the extraction phase.
		$validation = Avix_Migration_Archive_Reader::validate_and_count( $path );
		if ( ! $validation['ended_clean'] ) {
			return Avix_Migration_Job_Step_Result::failed(
				__( 'Archive is incomplete or corrupted — no valid end-of-archive marker was found.', 'avix-migration' )
			);
		}

		$bytes_total = 0;
		$files_total = 0;
		$has_database = false;
		foreach ( $validation['entries'] as $entry ) {
			if ( Avix_Migration_Archive_Manifest::ENTRY_NAME === $entry['name'] && '' === $entry['dir'] ) {
				continue;
			}
			if ( 'database.sql.gz' === $entry['name'] && '' === $entry['dir'] ) {
				$has_database = true;
				$bytes_total  += $entry['size'];
				continue;
			}
			$files_total++;
			$bytes_total += $entry['size'];
		}

		global $wpdb;
		$job->totals['files_total'] = $files_total;
		$job->totals['bytes_total'] = $bytes_total;

		$job->meta['manifest']       = $manifest;
		$job->meta['source_prefix']  = $manifest['site']['table_prefix'] ?? 'wp_';
		$job->meta['target_prefix']  = $wpdb->prefix;
		$job->meta['has_database']   = $has_database && ! empty( $job->meta['restore_database'] );
		$job->meta['has_files']      = $files_total > 0 && ! empty( $job->meta['restore_files'] );
		$job->meta['warnings']       = Avix_Migration_Util_Sysinfo::compare_warnings( $manifest['site'] ?? array() );

		Avix_Migration_Util_Logger::info(
			$job->id,
			'Archive validated.',
			array( 'files' => $files_total, 'bytes' => $bytes_total, 'has_database' => $has_database )
		);

		return Avix_Migration_Job_Step_Result::step_complete( __( 'Archive is valid.', 'avix-migration' ) );
	}
}
