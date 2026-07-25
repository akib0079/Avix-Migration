<?php
/**
 * Contract every cloud storage destination implements. Upload is
 * deliberately chunked (an $offset in, bytes-sent-this-call out) rather
 * than "upload this whole file" — archives can be many GB, and every other
 * long operation in this plugin is chunked across Job_Runner ticks for the
 * same reason: a single blocking multi-GB transfer would blow both PHP's
 * execution time and the job engine's own time budget.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Avix_Migration_Storage_Provider {

	/**
	 * @return array{success:bool, message:string}
	 */
	public function test_connection();

	/**
	 * Uploads one bounded chunk starting at $offset, resuming a previous
	 * call's progress via whatever state the provider needs (an S3
	 * multipart upload id, an SFTP file handle, etc.) passed through
	 * $state and returned updated.
	 *
	 * @param string $local_path   Absolute path of the file being uploaded.
	 * @param string $remote_name  Destination filename (no directories — each
	 *                             provider uses its own configured base path/bucket).
	 * @param int    $offset       Byte offset already uploaded.
	 * @param array  $state        Provider-specific resume state, empty on the first call.
	 * @return array{done:bool, bytes_sent:int, state:array, error:?string}
	 */
	public function upload_chunk( $local_path, $remote_name, $offset, array $state );

	/**
	 * @param string $remote_name
	 * @param string $local_path Destination to write the downloaded file to.
	 * @return array{success:bool, message:string}
	 */
	public function download( $remote_name, $local_path );

	/** @return array{success:bool, message:string} */
	public function delete( $remote_name );

	/** @return string[] Remote filenames currently stored. */
	public function list_files();

	/** Machine-readable id, e.g. "s3", "ftp", "sftp", "drive", "dropbox". */
	public function id();

	/** Human label for the UI, e.g. "Amazon S3 / S3-compatible". */
	public function label();
}
