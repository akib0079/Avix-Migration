<?php
/**
 * Extracts and decompresses the content.json.gz entry (always #2, right
 * after the manifest — see Content_Write_Json). Safe to decompress with a
 * single gzdecode() call here, unlike the full-site database dump: this
 * entry was written with one gzencode() call, not Db_Exporter's
 * incremental gzopen-append pattern, so it's a single ordinary gzip
 * member, not a concatenated stream gzdecode() would silently truncate.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Content_Import_Extract_Json extends Avix_Migration_Job_Step {

	public function label() {
		return __( 'Reading content index', 'avix-migration' );
	}

	public function execute( Avix_Migration_Job $job ) {
		$reader = new Avix_Migration_Archive_Reader( $job->meta['archive_path'] );
		if ( ! $reader->open() ) {
			return Avix_Migration_Job_Step_Result::failed( __( 'Could not open archive.', 'avix-migration' ) );
		}

		$manifest_header = $reader->read_header();
		if ( ! $manifest_header || Avix_Migration_Archive_Manifest::ENTRY_NAME !== $manifest_header['name'] ) {
			$reader->close();
			return Avix_Migration_Job_Step_Result::failed( __( 'Archive is malformed: expected the manifest as the first entry.', 'avix-migration' ) );
		}
		$reader->skip_content( $manifest_header['size'] );

		$json_header = $reader->read_header();
		if ( ! $json_header || 'content.json.gz' !== $json_header['name'] ) {
			$reader->close();
			return Avix_Migration_Job_Step_Result::failed( __( 'Archive is malformed: expected the content index as the second entry.', 'avix-migration' ) );
		}

		$gzipped = $reader->read_content( $json_header['size'] );
		$reader->close();

		$json = gzdecode( $gzipped );
		if ( false === $json ) {
			return Avix_Migration_Job_Step_Result::failed( __( 'Could not decompress the content index.', 'avix-migration' ) );
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['posts'], $decoded['attachments'], $decoded['terms'] ) ) {
			return Avix_Migration_Job_Step_Result::failed( __( 'Content index is malformed.', 'avix-migration' ) );
		}

		$tmp_path = Avix_Migration_Util_Filesystem::tmp_dir() . '/' . $job->id . '-content.json';
		file_put_contents( $tmp_path, $json );

		$job->meta['content_json_path'] = $tmp_path;
		$job->totals['rows_total']  = count( $decoded['posts'] );
		$job->totals['files_total'] = count( $decoded['attachments'] );

		Avix_Migration_Util_Logger::info(
			$job->id,
			'Content index extracted.',
			array( 'posts' => count( $decoded['posts'] ), 'attachments' => count( $decoded['attachments'] ), 'terms' => count( $decoded['terms'] ) )
		);

		return Avix_Migration_Job_Step_Result::step_complete( __( 'Content index read.', 'avix-migration' ) );
	}
}
