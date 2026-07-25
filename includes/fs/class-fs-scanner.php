<?php
/**
 * Chunked, resumable walker over wp-content. Operates on a small cursor
 * (a queue of pending directory paths, plus running totals) rather than
 * accumulating the full file list in memory or in job state — the caller
 * supplies an $on_file callback that does something with each file as it's
 * discovered (in practice: append a metadata line to a temp manifest file).
 *
 * Bounded batch = a fixed number of directories popped from the queue per
 * call, not a time budget — directory listings are cheap regardless of how
 * many files they contain, so a count-based cap keeps this simple while
 * staying comfortably inside any request's time budget in practice.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Fs_Scanner {

	const DIRS_PER_TICK = 50;

	/**
	 * @param array    $cursor        By reference; holds { queue: string[], initialized: bool }.
	 *                                Paths in the queue are wp-content-relative ('' = the wp-content root itself).
	 * @param callable $on_file       function( array $record ): void — record = { dir, name, size, mtime }.
	 * @param string[] $excluded_dirs Top-level dir names / glob patterns to skip, per Fs_Exclusions::is_excluded().
	 * @return bool True once the whole tree has been walked (queue empty).
	 */
	public static function tick( array &$cursor, callable $on_file, array $excluded_dirs ) {
		if ( empty( $cursor['initialized'] ) ) {
			$cursor['queue']       = array( '' );
			$cursor['initialized'] = true;
		}

		$processed = 0;

		while ( $processed < self::DIRS_PER_TICK && ! empty( $cursor['queue'] ) ) {
			$rel_dir = array_shift( $cursor['queue'] );
			self::process_dir( $rel_dir, $cursor, $on_file, $excluded_dirs );
			$processed++;
		}

		return empty( $cursor['queue'] );
	}

	private static function process_dir( $rel_dir, array &$cursor, callable $on_file, array $excluded_dirs ) {
		$full_dir = '' === $rel_dir ? WP_CONTENT_DIR : WP_CONTENT_DIR . '/' . $rel_dir;

		$entries = @scandir( $full_dir );
		if ( false === $entries ) {
			Avix_Migration_Util_Logger::warning( 'plugin', 'Could not read directory during scan.', array( 'dir' => $full_dir ) );
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$rel_path  = '' === $rel_dir ? $entry : $rel_dir . '/' . $entry;
			$full_path = $full_dir . '/' . $entry;

			if ( Avix_Migration_Fs_Exclusions::is_excluded( $rel_path, $excluded_dirs ) ) {
				continue;
			}

			if ( is_link( $full_path ) ) {
				// Skip symlinks entirely: following them risks pulling in
				// files from outside wp-content into the archive (a mirror
				// image of the extraction-side path-traversal risk), and a
				// symlink cycle could make the queue grow forever.
				Avix_Migration_Util_Logger::warning( 'plugin', 'Skipped symlink during scan.', array( 'path' => $rel_path ) );
				continue;
			}

			if ( is_dir( $full_path ) ) {
				$cursor['queue'][] = $rel_path;
				continue;
			}

			if ( ! is_file( $full_path ) ) {
				continue; // Device file, socket, etc. — not a real file to back up.
			}

			$size  = @filesize( $full_path );
			$mtime = @filemtime( $full_path );
			if ( false === $size ) {
				continue;
			}

			$slash = strrpos( $rel_path, '/' );
			$dir   = false === $slash ? '' : substr( $rel_path, 0, $slash );
			$name  = false === $slash ? $rel_path : substr( $rel_path, $slash + 1 );

			$on_file(
				array(
					'dir'   => $dir,
					'name'  => $name,
					'size'  => (int) $size,
					'mtime' => (int) ( $mtime ?: time() ),
				)
			);
		}
	}
}
