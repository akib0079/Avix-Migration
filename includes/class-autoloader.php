<?php
/**
 * PSR-4-ish autoloader for the Avix_Migration_* class hierarchy.
 *
 * Maps class name segments after the "Avix_Migration_" prefix onto a
 * directory path under includes/, WordPress-style:
 *
 *   Avix_Migration_Plugin                 => includes/class-plugin.php
 *   Avix_Migration_Job_Runner             => includes/job/class-job-runner.php
 *   Avix_Migration_Storage_Provider_S3    => includes/storage/providers/class-storage-provider-s3.php
 *
 * The first segment after the prefix is treated as a subdirectory; a
 * "Provider" second segment nests one level deeper into providers/, since
 * storage and step classes both cluster heavily and a flat directory of
 * 20 files is harder to scan than two directories of ~5.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Autoloader {

	/** @var string Class name prefix this autoloader is responsible for. */
	const PREFIX = 'Avix_Migration_';

	/** @var bool Guards against double-registration. */
	private static $registered = false;

	public static function register() {
		if ( self::$registered ) {
			return;
		}
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
		self::$registered = true;
	}

	public static function autoload( $class ) {
		if ( 0 !== strpos( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$segments = explode( '_', $relative );

		if ( empty( $segments ) ) {
			return;
		}

		// First segment picks the subdirectory (lowercased, as-is).
		$subdir = strtolower( array_shift( $segments ) );

		// Route known top-level groups to their actual directory names.
		$dir_map = array(
			'plugin'    => '',
			'autoloader' => '',
			'cli'       => 'cli',
			'admin'     => 'admin',
			'archive'   => 'archive',
			'job'       => 'job',
			'db'        => 'db',
			'fs'        => 'fs',
			'storage'   => 'storage',
			'remote'    => 'remote',
			'schedule'  => 'schedule',
			'rollback'  => 'rollback',
			'util'      => 'util',
		);

		$path = isset( $dir_map[ $subdir ] ) ? $dir_map[ $subdir ] : $subdir;

		// Step classes live one level deeper: job/steps/{export,import,content,transfer}.
		if ( 'job' === $subdir && ! empty( $segments ) && 'steps' === strtolower( $segments[0] ) ) {
			array_shift( $segments );
			$group = ! empty( $segments ) ? strtolower( array_shift( $segments ) ) : '';
			$path  = 'job/steps/' . $group;
		}

		// Storage providers (Avix_Migration_Storage_Provider_S3, etc.) stay
		// flat in includes/storage/ alongside the Storage_Provider interface
		// — one small directory rather than an extra nesting level.
		$dashed = strtolower( str_replace( '_', '-', $relative ) );
		$dir    = AVIX_MIGRATION_DIR . 'includes/' . ( $path ? $path . '/' : '' );

		// Interfaces follow WordPress's own "interface-*.php" convention
		// rather than "class-*.php"; try that first since it's the more
		// specific match, falling back to class- for everything else
		// (the overwhelming majority of files).
		foreach ( array( 'interface-', 'class-', 'trait-' ) as $prefix ) {
			$file = $dir . $prefix . $dashed . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
}
