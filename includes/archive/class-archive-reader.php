<?php
/**
 * Sequential reader for .avix archives. Supports both "read everything" (for
 * small archives / listing) and "stream one entry to disk" (for large media
 * files during extraction, so the whole file never sits in PHP memory at
 * once).
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Archive_Reader {

	/** @var resource|null */
	private $handle;

	/** @var string */
	private $path;

	/** Bytes copied per fread()/fwrite() cycle when streaming an entry to disk. */
	const COPY_CHUNK = 1048576; // 1 MB.

	public function __construct( $path ) {
		$this->path = $path;
	}

	public function open() {
		if ( $this->handle ) {
			return true;
		}
		$this->handle = @fopen( $this->path, 'rb' );
		return false !== $this->handle;
	}

	public function close() {
		if ( $this->handle ) {
			fclose( $this->handle );
			$this->handle = null;
		}
	}

	public function tell() {
		return $this->handle ? ftell( $this->handle ) : 0;
	}

	public function seek( $offset ) {
		if ( $this->handle ) {
			fseek( $this->handle, $offset, SEEK_SET );
		}
	}

	public function eof() {
		return ! $this->handle || feof( $this->handle );
	}

	/**
	 * Reads and decodes the next entry header without consuming its
	 * content — caller must then either read_content(), stream_content_to(),
	 * or skip_content() before reading the next header.
	 *
	 * @return array|null Decoded header (see Archive_Header::decode()), or
	 *                     null at the EOF marker or true end-of-file.
	 */
	public function read_header() {
		if ( ! $this->handle || feof( $this->handle ) ) {
			return null;
		}
		$bytes = fread( $this->handle, Avix_Migration_Archive_Header::TOTAL_LEN );
		if ( false === $bytes || Avix_Migration_Archive_Header::TOTAL_LEN !== strlen( $bytes ) ) {
			return null;
		}
		return Avix_Migration_Archive_Header::decode( $bytes );
	}

	/**
	 * Reads $size bytes of the current entry's content fully into memory —
	 * only safe for known-small entries (manifest, database dump handled as
	 * a stream instead, see stream_content_to()).
	 */
	public function read_content( $size ) {
		if ( ! $this->handle || $size <= 0 ) {
			return '';
		}
		$data = '';
		$remaining = $size;
		while ( $remaining > 0 && ! feof( $this->handle ) ) {
			$chunk = fread( $this->handle, min( self::COPY_CHUNK, $remaining ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$data      .= $chunk;
			$remaining -= strlen( $chunk );
		}
		return $data;
	}

	/**
	 * Streams the current entry's content to a destination file in fixed-size
	 * chunks — the extraction path for wp-content files, which may be many
	 * hundreds of MB (video uploads, etc).
	 *
	 * @return int Bytes actually copied.
	 */
	public function stream_content_to( $dest_path, $size ) {
		$out = @fopen( $dest_path, 'wb' );
		if ( ! $out ) {
			// Ensure the read cursor still advances past this entry's bytes
			// even if we can't open the destination, so the next header read
			// stays aligned.
			$this->skip_content( $size );
			return 0;
		}

		$remaining = $size;
		$copied    = 0;
		while ( $remaining > 0 && ! feof( $this->handle ) ) {
			$chunk = fread( $this->handle, min( self::COPY_CHUNK, $remaining ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			fwrite( $out, $chunk );
			$len        = strlen( $chunk );
			$copied    += $len;
			$remaining -= $len;
		}
		fclose( $out );
		return $copied;
	}

	public function skip_content( $size ) {
		if ( $this->handle && $size > 0 ) {
			fseek( $this->handle, $size, SEEK_CUR );
		}
	}

	/**
	 * Reads just the first entry (must be avix-manifest.json) without
	 * touching anything else — used for quick validation and the import
	 * pre-flight report, both of which need manifest data long before (or
	 * without ever) extracting the rest of a multi-GB archive.
	 *
	 * @return array|null Decoded manifest, or null if unreadable/invalid.
	 */
	public static function read_manifest_only( $path ) {
		$reader = new self( $path );
		if ( ! $reader->open() ) {
			return null;
		}

		$header = $reader->read_header();
		if ( ! $header || Avix_Migration_Archive_Manifest::ENTRY_NAME !== $header['name'] ) {
			$reader->close();
			return null;
		}

		$json = $reader->read_content( $header['size'] );
		$reader->close();

		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Walks every entry from the start, validating header sanity and that
	 * the file actually contains the bytes each header claims, without
	 * loading content into memory. Stops at the first invalid header, the
	 * EOF marker, or true end-of-file — whichever comes first.
	 *
	 * Used for two purposes: (1) Archive_Writer resume, to find the exact
	 * byte offset to truncate back to after a crash mid-write, and (2)
	 * pre-extraction validation, to reject a corrupt or tampered archive
	 * before any table is touched.
	 *
	 * @return array{valid_bytes:int,entries:array[],ended_clean:bool}
	 */
	public static function validate_and_count( $path ) {
		$reader = new self( $path );
		$result = array(
			'valid_bytes' => 0,
			'entries'     => array(),
			'ended_clean' => false,
		);

		if ( ! $reader->open() ) {
			return $result;
		}

		// fseek() on a read handle can report success past the real end of
		// file (the OS doesn't error until a subsequent read is attempted),
		// so ftell() deltas alone cannot be trusted to prove content bytes
		// actually exist. Stat the real file size up front and check offset
		// math against that ground truth instead of the seek result.
		clearstatcache( true, $path );
		$total_size = filesize( $path );
		if ( false === $total_size ) {
			$reader->close();
			return $result;
		}

		while ( true ) {
			$pos = $reader->tell();
			if ( $pos + Avix_Migration_Archive_Header::TOTAL_LEN > $total_size ) {
				// Not enough bytes left even for a full header.
				break;
			}

			$raw = fread( $reader->handle, Avix_Migration_Archive_Header::TOTAL_LEN );
			if ( false === $raw || Avix_Migration_Archive_Header::TOTAL_LEN !== strlen( $raw ) ) {
				break;
			}

			if ( Avix_Migration_Archive_Header::is_eof_marker( $raw ) ) {
				$result['valid_bytes'] = $reader->tell();
				$result['ended_clean'] = true;
				break;
			}

			$header = Avix_Migration_Archive_Header::decode( $raw );
			if ( null === $header ) {
				break; // Malformed header — stop before it.
			}

			// Path safety: reject entries whose dir escapes the archive
			// namespace before we ever consider extracting them.
			if ( Avix_Migration_Util_Filesystem::is_unsafe_relative_path( $header['dir'] )
				|| Avix_Migration_Util_Filesystem::is_unsafe_relative_path( $header['name'] ) ) {
				break;
			}

			$content_start = $reader->tell();

			// Ground-truth check: do this many content bytes actually exist
			// in the file? If not, the header's size field is either a lie
			// or the file was truncated mid-write — either way, stop here
			// rather than trusting fseek to tell us.
			if ( $content_start + $header['size'] > $total_size ) {
				break;
			}

			$reader->seek( $content_start + $header['size'] );

			$header['offset']    = $content_start;
			$result['entries'][] = $header;
			$result['valid_bytes'] = $content_start + $header['size'];
		}

		$reader->close();
		return $result;
	}
}
