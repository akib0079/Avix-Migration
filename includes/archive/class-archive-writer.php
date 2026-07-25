<?php
/**
 * Sequential writer for .avix archives. Entries are appended one at a time
 * (header + raw bytes); the archive is only "finished" once write_eof() and
 * finalize() have run, which is what makes an interrupted export detectable
 * on the next request — validate_and_count() simply won't find a clean EOF
 * marker on a partial file.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Archive_Writer {

	/** @var resource|null */
	private $handle;

	/** @var string */
	private $path;

	const COPY_CHUNK = 1048576; // 1 MB, matches Archive_Reader::COPY_CHUNK.

	public function __construct( $path ) {
		$this->path = $path;
	}

	/**
	 * Opens the archive for appending, repairing it first if a previous
	 * write was interrupted mid-entry.
	 *
	 * On first call for a brand-new archive, creates an empty file. On a
	 * resumed job, walks the existing file via Archive_Reader's
	 * ground-truth validator and truncates off any trailing garbage from a
	 * crash — so a step that resumes always appends onto a file whose every
	 * existing byte is a complete, valid entry.
	 *
	 * @return int Number of whole entries already present after repair (the
	 *             caller — typically an export step — uses this to know how
	 *             many entries to skip re-writing).
	 */
	public function open_for_resume() {
		Avix_Migration_Util_Filesystem::ensure_dir( dirname( $this->path ) );

		if ( ! file_exists( $this->path ) ) {
			touch( $this->path );
		}

		$validation = Avix_Migration_Archive_Reader::validate_and_count( $this->path );

		clearstatcache( true, $this->path );
		if ( filesize( $this->path ) !== $validation['valid_bytes'] ) {
			// Truncate off a partially-written trailing entry (or a stray
			// EOF marker we're about to re-append past) so appends land
			// exactly where the last good entry ended.
			$fp = fopen( $this->path, 'r+b' );
			if ( $fp ) {
				ftruncate( $fp, $validation['valid_bytes'] );
				fclose( $fp );
			}
		}

		$this->handle = fopen( $this->path, 'ab' );

		return count( $validation['entries'] );
	}

	public function close() {
		if ( $this->handle ) {
			fclose( $this->handle );
			$this->handle = null;
		}
	}

	public function bytes_written() {
		clearstatcache( true, $this->path );
		return (int) @filesize( $this->path );
	}

	/**
	 * Appends an in-memory string as one entry — used for the manifest and
	 * for the gzipped database dump (already fully materialized as a temp
	 * file's contents by the time this is called).
	 */
	public function append_string( $name, $dir, $contents, $mtime = null ) {
		if ( ! $this->handle ) {
			return false;
		}
		$mtime = null === $mtime ? time() : $mtime;
		$header = Avix_Migration_Archive_Header::encode( $name, strlen( $contents ), $mtime, $dir );
		fwrite( $this->handle, $header );
		fwrite( $this->handle, $contents );
		return true;
	}

	/**
	 * Appends an existing file's contents as one entry, streaming it in
	 * fixed-size chunks so a multi-hundred-MB media file never sits fully in
	 * PHP memory.
	 *
	 * @param string $abs_source_path Absolute path of the file to copy in.
	 * @param string $name            Entry basename.
	 * @param string $dir             Relative directory within the archive.
	 * @return int|false Bytes copied, or false if the source couldn't be read.
	 */
	public function append_file( $abs_source_path, $name, $dir ) {
		if ( ! $this->handle ) {
			return false;
		}

		$size  = @filesize( $abs_source_path );
		$mtime = @filemtime( $abs_source_path );
		if ( false === $size ) {
			return false;
		}

		$in = @fopen( $abs_source_path, 'rb' );
		if ( ! $in ) {
			return false;
		}

		$header = Avix_Migration_Archive_Header::encode( $name, $size, $mtime ?: time(), $dir );
		fwrite( $this->handle, $header );

		$copied = 0;
		while ( ! feof( $in ) ) {
			$chunk = fread( $in, self::COPY_CHUNK );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			fwrite( $this->handle, $chunk );
			$copied += strlen( $chunk );
		}
		fclose( $in );

		return $copied;
	}

	/**
	 * Writes the all-zero EOF marker. Must be the very last thing written —
	 * any append_* call after this would corrupt the archive's readability,
	 * since a reader stops at the first EOF marker it sees.
	 */
	public function write_eof() {
		if ( ! $this->handle ) {
			return false;
		}
		fwrite( $this->handle, Avix_Migration_Archive_Header::eof_marker() );
		return true;
	}

	/**
	 * Closes the file and writes a checksum sidecar next to it — the import
	 * side reads this before extracting anything, so a corrupted download
	 * is caught immediately rather than failing confusingly partway through
	 * a database restore.
	 *
	 * @return array{sha256:string,bytes:int}
	 */
	public function finalize() {
		$this->close();

		$bytes = (int) @filesize( $this->path );
		$hash  = hash_file( 'sha256', $this->path );

		$sidecar = array(
			'sha256'         => $hash,
			'bytes'          => $bytes,
			'format_version' => AVIX_MIGRATION_FORMAT_VERSION,
		);

		file_put_contents( $this->path . '.checksum.json', wp_json_encode( $sidecar ) );

		return $sidecar;
	}
}
