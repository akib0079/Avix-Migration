<?php
/**
 * Append-only JSONL logger. One file per job (wp-content/avix-backups/logs/
 * {job_id}.log) plus a general plugin.log for events with no job context
 * (activation, cron ticks, connection-key issuance).
 *
 * JSON Lines rather than a single JSON array: an array would need the whole
 * file rewritten on every append, which is both slower and riskier (a crash
 * mid-rewrite corrupts the entire log) than an append-only file where each
 * line is independently valid.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Util_Logger {

	const LEVEL_DEBUG   = 'debug';
	const LEVEL_INFO    = 'info';
	const LEVEL_WARNING = 'warning';
	const LEVEL_ERROR   = 'error';

	/** Max log file size before old lines are trimmed, in bytes. */
	const MAX_BYTES = 5242880; // 5 MB.

	/**
	 * @param string $scope   Job id, or 'plugin' for job-less events.
	 * @param string $level   One of the LEVEL_* constants.
	 * @param string $message
	 * @param array  $context Extra structured data.
	 */
	public static function log( $scope, $level, $message, array $context = array() ) {
		$scope = preg_match( '/^[a-z0-9_\-]+$/', (string) $scope ) ? $scope : 'plugin';

		$line = wp_json_encode(
			array(
				'time'    => time(),
				'level'   => $level,
				'message' => $message,
				'context' => $context,
			)
		);
		if ( false === $line ) {
			return;
		}

		$dir = Avix_Migration_Util_Filesystem::logs_dir();
		Avix_Migration_Util_Filesystem::ensure_dir( $dir );

		$file = $dir . '/' . $scope . '.log';
		self::maybe_trim( $file );

		$fp = @fopen( $file, 'ab' );
		if ( ! $fp ) {
			return;
		}
		if ( flock( $fp, LOCK_EX ) ) {
			fwrite( $fp, $line . "\n" );
			flock( $fp, LOCK_UN );
		}
		fclose( $fp );
	}

	public static function info( $scope, $message, array $context = array() ) {
		self::log( $scope, self::LEVEL_INFO, $message, $context );
	}

	public static function warning( $scope, $message, array $context = array() ) {
		self::log( $scope, self::LEVEL_WARNING, $message, $context );
	}

	public static function error( $scope, $message, array $context = array() ) {
		self::log( $scope, self::LEVEL_ERROR, $message, $context );
	}

	/**
	 * Reads the most recent $limit entries, newest first — used by the
	 * progress UI's live log tail and the Tools screen's log viewer.
	 */
	public static function tail( $scope, $limit = 200 ) {
		$scope = preg_match( '/^[a-z0-9_\-]+$/', (string) $scope ) ? $scope : 'plugin';
		$file  = Avix_Migration_Util_Filesystem::logs_dir() . '/' . $scope . '.log';

		if ( ! is_readable( $file ) ) {
			return array();
		}

		$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( ! $lines ) {
			return array();
		}

		$slice   = array_slice( $lines, -1 * max( 1, (int) $limit ) );
		$entries = array();
		foreach ( array_reverse( $slice ) as $line ) {
			$decoded = json_decode( $line, true );
			if ( is_array( $decoded ) ) {
				$entries[] = $decoded;
			}
		}
		return $entries;
	}

	/**
	 * Keeps a runaway job (e.g. a huge site logging every file it touches)
	 * from filling the disk — drops the oldest half once the file exceeds
	 * MAX_BYTES.
	 */
	private static function maybe_trim( $file ) {
		clearstatcache( true, $file );
		if ( ! file_exists( $file ) || filesize( $file ) < self::MAX_BYTES ) {
			return;
		}

		$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( ! $lines ) {
			return;
		}
		$kept = array_slice( $lines, (int) floor( count( $lines ) / 2 ) );
		file_put_contents( $file, implode( "\n", $kept ) . "\n", LOCK_EX );
	}
}
