<?php
/**
 * Persists Job objects as JSON files under wp-content/avix-backups/jobs/.
 *
 * Writes are atomic (write to a temp file, then rename) so a crash or a
 * second overlapping request can never observe a half-written job file —
 * important here specifically because job state is read on every progress
 * poll, often while another request is mid-save.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Store {

	/**
	 * Creates a new job, persists it immediately, and returns it.
	 *
	 * @param string $type  Job type identifier.
	 * @param array  $steps Fully-qualified step class names, in order.
	 * @param array  $meta  Job-specific configuration.
	 */
	public static function create( $type, array $steps, array $meta = array() ) {
		$id  = self::generate_id( $type );
		$job = new Avix_Migration_Job( $id, $type );
		$job->steps = $steps;
		$job->meta  = $meta;
		self::save( $job );
		return $job;
	}

	public static function load( $id ) {
		$id = self::sanitize_id( $id );
		if ( '' === $id ) {
			return null;
		}

		$file = self::path_for( $id );
		if ( ! is_readable( $file ) ) {
			return null;
		}

		$raw = file_get_contents( $file );
		if ( false === $raw || '' === $raw ) {
			return null;
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['id'] ) ) {
			return null;
		}

		return Avix_Migration_Job::from_array( $data );
	}

	public static function save( Avix_Migration_Job $job ) {
		$file = self::path_for( $job->id );
		$dir  = dirname( $file );

		if ( ! is_dir( $dir ) ) {
			Avix_Migration_Util_Filesystem::ensure_dir( $dir );
		}

		$json = wp_json_encode( $job->to_array() );
		if ( false === $json ) {
			return false;
		}

		$tmp = $file . '.tmp-' . wp_generate_password( 8, false, false );
		if ( false === file_put_contents( $tmp, $json, LOCK_EX ) ) {
			return false;
		}

		// rename() is atomic on both POSIX filesystems and NTFS-via-Windows
		// PHP builds — the reader either sees the old complete file or the
		// new complete file, never a partial write.
		return rename( $tmp, $file );
	}

	public static function delete( $id ) {
		$file = self::path_for( self::sanitize_id( $id ) );
		if ( file_exists( $file ) ) {
			return unlink( $file );
		}
		return true;
	}

	/**
	 * Lists job ids, most recently updated first. Used by the Tools screen
	 * and the stuck-job watchdog.
	 */
	public static function all_ids() {
		$dir = self::jobs_dir();
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$files = glob( $dir . '/*.json' );
		if ( ! $files ) {
			return array();
		}
		usort(
			$files,
			function ( $a, $b ) {
				return filemtime( $b ) <=> filemtime( $a );
			}
		);
		return array_map(
			function ( $f ) {
				return basename( $f, '.json' );
			},
			$files
		);
	}

	private static function jobs_dir() {
		return Avix_Migration_Util_Filesystem::storage_dir() . '/jobs';
	}

	private static function path_for( $id ) {
		return self::jobs_dir() . '/' . $id . '.json';
	}

	private static function generate_id( $type ) {
		$short = preg_replace( '/[^a-z0-9]+/', '', strtolower( $type ) );
		return substr( $short, 0, 12 ) . '_' . gmdate( 'Ymd-His' ) . '_' . Avix_Migration_Util_Crypto::random_token( 4 );
	}

	/**
	 * Job ids are used to build filesystem paths — only allow the exact
	 * charset generate_id() produces, closing off any path-traversal
	 * attempt via a crafted id in an AJAX request.
	 */
	private static function sanitize_id( $id ) {
		return preg_match( '/^[a-z0-9_\-]+$/', (string) $id ) ? $id : '';
	}
}
