<?php
/**
 * Server side of a browser-chunked upload: the client slices a File into
 * fixed-size Blobs (see assets/js/avix-import.js) and posts them one at a
 * time, so an archive many times larger than the server's
 * upload_max_filesize/post_max_size can still be uploaded — those limits
 * cap a single request's body, not the total file, and are frequently a
 * few MB on real shared hosting even though Local's dev config here is
 * generous (300M/1000M).
 *
 * State lives in a small JSON sidecar per upload id (not in a Job — this
 * happens before any job exists) so a dropped connection can resume by
 * asking how many chunks were already received, rather than re-uploading
 * from scratch.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Util_Chunked_Upload {

	public static function uploads_dir() {
		return Avix_Migration_Util_Filesystem::tmp_dir() . '/uploads';
	}

	/**
	 * @param string $upload_id     Client-generated random id.
	 * @param int    $chunk_index   0-based.
	 * @param int    $total_chunks
	 * @param string $filename      Original filename, for the final archive name.
	 * @param string $tmp_chunk_path Path PHP put the uploaded chunk at ($_FILES[...]['tmp_name']).
	 * @return array{done:bool, received_chunks:int, final_path:?string}|WP_Error
	 */
	public static function append_chunk( $upload_id, $chunk_index, $total_chunks, $filename, $tmp_chunk_path ) {
		$upload_id = self::sanitize_id( $upload_id );
		if ( '' === $upload_id ) {
			return new WP_Error( 'avix_bad_upload_id', __( 'Invalid upload id.', 'avix-migration' ) );
		}

		Avix_Migration_Util_Filesystem::ensure_dir( self::uploads_dir() );

		$state_path = self::state_path( $upload_id );
		$state      = self::load_state( $upload_id ) ?: array(
			'filename'      => sanitize_file_name( $filename ),
			'total_chunks'  => (int) $total_chunks,
			'received'      => array(),
		);

		$part_path = self::uploads_dir() . '/' . $upload_id . '.part';

		// Chunks are appended in order; if this chunk index was already
		// received (a retried request after a response got lost, even
		// though the chunk landed), skip re-appending it so the assembled
		// file doesn't get duplicated bytes.
		if ( ! in_array( (int) $chunk_index, $state['received'], true ) ) {
			$in  = fopen( $tmp_chunk_path, 'rb' );
			$out = fopen( $part_path, 'ab' );
			if ( ! $in || ! $out ) {
				return new WP_Error( 'avix_upload_io', __( 'Could not write uploaded chunk to disk.', 'avix-migration' ) );
			}
			stream_copy_to_stream( $in, $out );
			fclose( $in );
			fclose( $out );

			$state['received'][] = (int) $chunk_index;
		}

		file_put_contents( $state_path, wp_json_encode( $state ) );

		$done = count( $state['received'] ) >= $state['total_chunks'];

		if ( ! $done ) {
			return array( 'done' => false, 'received_chunks' => count( $state['received'] ), 'final_path' => null );
		}

		return self::finalize( $upload_id, $state );
	}

	/** Where a resumed upload should ask "what have you got so far". */
	public static function status( $upload_id ) {
		$state = self::load_state( self::sanitize_id( $upload_id ) );
		if ( ! $state ) {
			return array( 'received_chunks' => 0 );
		}
		return array( 'received_chunks' => count( $state['received'] ) );
	}

	private static function finalize( $upload_id, array $state ) {
		$part_path = self::uploads_dir() . '/' . $upload_id . '.part';

		$safe_name = sanitize_file_name( $state['filename'] );
		if ( '.avix' !== substr( $safe_name, -5 ) ) {
			$safe_name .= '.avix';
		}

		Avix_Migration_Util_Filesystem::ensure_dir( Avix_Migration_Util_Filesystem::archives_dir() );
		$dest = Avix_Migration_Util_Filesystem::archives_dir() . '/' . $safe_name;

		// Avoid clobbering an existing archive with the same name.
		if ( file_exists( $dest ) ) {
			$dest = Avix_Migration_Util_Filesystem::archives_dir() . '/'
				. pathinfo( $safe_name, PATHINFO_FILENAME ) . '-' . Avix_Migration_Util_Crypto::random_token( 3 ) . '.avix';
		}

		rename( $part_path, $dest );
		@unlink( self::state_path( $upload_id ) );

		return array( 'done' => true, 'received_chunks' => count( $state['received'] ), 'final_path' => $dest );
	}

	private static function load_state( $upload_id ) {
		$path = self::state_path( $upload_id );
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $data ) ? $data : null;
	}

	private static function state_path( $upload_id ) {
		return self::uploads_dir() . '/' . $upload_id . '.json';
	}

	private static function sanitize_id( $id ) {
		return preg_match( '/^[a-zA-Z0-9_\-]+$/', (string) $id ) ? $id : '';
	}
}
