<?php
/**
 * Pulls the database.sql.gz entry out of the archive and decompresses it to
 * a flat .sql file. Cheap to locate regardless of how many files follow it
 * in the archive: the manifest is always entry #1 and the database dump is
 * always entry #2 (see Export_Write_Archive), so this only ever reads two
 * headers before it finds what it's looking for.
 *
 * Decompression uses a gzopen()+gzread() loop, never gzdecode()/gzinflate()
 * on the raw bytes — the exporter writes each export batch as its own
 * concatenated gzip member (see Db_Exporter), and gzdecode() silently
 * decodes only the first member, which would truncate every dump after its
 * first ~800 KB with no error at all. Verified empirically during
 * Milestone 2; see the matching warning in Db_Exporter::write_chunk().
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Import_Extract_Database extends Avix_Migration_Job_Step {

	const COPY_CHUNK = 1048576; // 1 MB.

	public function label() {
		return __( 'Extracting database', 'avix-migration' );
	}

	public function execute( Avix_Migration_Job $job ) {
		if ( empty( $job->meta['has_database'] ) ) {
			return Avix_Migration_Job_Step_Result::step_complete( __( 'No database in this import.', 'avix-migration' ) );
		}

		$reader = new Avix_Migration_Archive_Reader( $job->meta['archive_path'] );
		if ( ! $reader->open() ) {
			return Avix_Migration_Job_Step_Result::failed( __( 'Could not open archive to extract database.', 'avix-migration' ) );
		}

		$manifest_header = $reader->read_header();
		if ( ! $manifest_header || Avix_Migration_Archive_Manifest::ENTRY_NAME !== $manifest_header['name'] ) {
			$reader->close();
			return Avix_Migration_Job_Step_Result::failed( __( 'Archive is malformed: expected the manifest as the first entry.', 'avix-migration' ) );
		}
		$reader->skip_content( $manifest_header['size'] );

		$db_header = $reader->read_header();
		if ( ! $db_header || 'database.sql.gz' !== $db_header['name'] || '' !== $db_header['dir'] ) {
			$reader->close();
			return Avix_Migration_Job_Step_Result::failed( __( 'Archive is malformed: expected the database dump as the second entry.', 'avix-migration' ) );
		}

		$gz_tmp = Avix_Migration_Util_Filesystem::tmp_dir() . '/' . $job->id . '-restore.sql.gz';
		$copied = $reader->stream_content_to( $gz_tmp, $db_header['size'] );
		$reader->close();

		if ( $copied !== $db_header['size'] ) {
			return Avix_Migration_Job_Step_Result::failed( __( 'Could not extract the full database dump from the archive.', 'avix-migration' ) );
		}

		$sql_tmp = Avix_Migration_Util_Filesystem::tmp_dir() . '/' . $job->id . '-restore.sql';
		if ( ! $this->decompress( $gz_tmp, $sql_tmp ) ) {
			return Avix_Migration_Job_Step_Result::failed( __( 'Could not decompress the database dump.', 'avix-migration' ) );
		}
		@unlink( $gz_tmp );

		$job->meta['sql_tmp_path'] = $sql_tmp;

		Avix_Migration_Util_Logger::info( $job->id, 'Database dump extracted and decompressed.', array( 'bytes' => filesize( $sql_tmp ) ) );

		return Avix_Migration_Job_Step_Result::step_complete( __( 'Database extracted.', 'avix-migration' ) );
	}

	private function decompress( $gz_path, $dest_path ) {
		$in = @gzopen( $gz_path, 'rb' );
		if ( ! $in ) {
			return false;
		}
		$out = @fopen( $dest_path, 'wb' );
		if ( ! $out ) {
			gzclose( $in );
			return false;
		}

		while ( ! gzeof( $in ) ) {
			$chunk = gzread( $in, self::COPY_CHUNK );
			if ( false === $chunk ) {
				break;
			}
			fwrite( $out, $chunk );
		}

		gzclose( $in );
		fclose( $out );
		return true;
	}
}
