<?php
/**
 * Encodes/decodes the fixed 4381-byte entry header used by the .avix
 * archive format:
 *
 *   offset   len   field
 *   0        255   entry name (basename), null-padded
 *   255      14    content size in bytes, ASCII decimal, null-padded
 *   269      12    mtime, unix timestamp, ASCII decimal, null-padded
 *   281      4096  relative directory (archive-namespace-relative), null-padded
 *   4377     4     flags, big-endian uint32
 *
 * Total: 4381 bytes. This layout (255/14/12/4096) is the same shape used by
 * other WordPress migration tools' container formats — reusing a
 * battle-tested layout rather than inventing a new one.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Archive_Header {

	const LEN_NAME  = 255;
	const LEN_SIZE  = 14;
	const LEN_MTIME = 12;
	const LEN_PATH  = 4096;
	const LEN_FLAGS = 4;

	const TOTAL_LEN = self::LEN_NAME + self::LEN_SIZE + self::LEN_MTIME + self::LEN_PATH + self::LEN_FLAGS; // 4381

	const FLAG_NONE = 0;

	/**
	 * @param string $name  Entry basename, e.g. "style.css" or "avix-manifest.json".
	 * @param int    $size  Content byte length.
	 * @param int    $mtime Unix timestamp.
	 * @param string $dir   Relative directory within the archive namespace,
	 *                      e.g. "themes/twentytwentyone", or '' for root-level
	 *                      entries (manifest, database dump).
	 * @param int    $flags Bitmask, currently unused (reserved).
	 * @return string Exactly TOTAL_LEN bytes.
	 */
	public static function encode( $name, $size, $mtime, $dir = '', $flags = self::FLAG_NONE ) {
		$name_field  = self::pad( $name, self::LEN_NAME );
		$size_field  = self::pad( (string) (int) $size, self::LEN_SIZE );
		$mtime_field = self::pad( (string) (int) $mtime, self::LEN_MTIME );
		$path_field  = self::pad( $dir, self::LEN_PATH );
		$flags_field = pack( 'N', (int) $flags );

		return $name_field . $size_field . $mtime_field . $path_field . $flags_field;
	}

	/**
	 * @param string $bytes Exactly TOTAL_LEN bytes read from the archive.
	 * @return array{name:string,size:int,mtime:int,dir:string,flags:int}|null
	 *         Null if $bytes is the all-zero EOF marker or malformed.
	 */
	public static function decode( $bytes ) {
		if ( self::TOTAL_LEN !== strlen( $bytes ) ) {
			return null;
		}
		if ( self::is_eof_marker( $bytes ) ) {
			return null;
		}

		$name  = rtrim( substr( $bytes, 0, self::LEN_NAME ), "\0" );
		$size  = rtrim( substr( $bytes, self::LEN_NAME, self::LEN_SIZE ), "\0" );
		$mtime = rtrim( substr( $bytes, self::LEN_NAME + self::LEN_SIZE, self::LEN_MTIME ), "\0" );
		$dir   = rtrim( substr( $bytes, self::LEN_NAME + self::LEN_SIZE + self::LEN_MTIME, self::LEN_PATH ), "\0" );
		$flags_bytes = substr( $bytes, self::LEN_NAME + self::LEN_SIZE + self::LEN_MTIME + self::LEN_PATH, self::LEN_FLAGS );
		$unpacked    = unpack( 'N', $flags_bytes );
		$flags       = $unpacked ? (int) reset( $unpacked ) : 0;

		if ( '' === $name || ! ctype_digit( $size ) || ! ctype_digit( $mtime ) ) {
			return null;
		}

		return array(
			'name'  => $name,
			'size'  => (int) $size,
			'mtime' => (int) $mtime,
			'dir'   => $dir,
			'flags' => $flags,
		);
	}

	public static function eof_marker() {
		return str_repeat( "\0", self::TOTAL_LEN );
	}

	public static function is_eof_marker( $bytes ) {
		return self::TOTAL_LEN === strlen( $bytes ) && "\0" === $bytes[0] && str_repeat( "\0", self::TOTAL_LEN ) === $bytes;
	}

	private static function pad( $value, $len ) {
		$value = substr( (string) $value, 0, $len );
		return str_pad( $value, $len, "\0", STR_PAD_RIGHT );
	}
}
