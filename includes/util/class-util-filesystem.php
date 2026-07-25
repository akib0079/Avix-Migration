<?php
/**
 * Filesystem layout and path-safety helpers shared by the archive, job, and
 * import modules.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Util_Filesystem {

	/**
	 * Root storage directory: wp-content/avix-backups. Lives in wp-content
	 * rather than inside the plugin's own directory so backups survive a
	 * plugin reinstall/update, and outside uploads/ so it's not swept up by
	 * media-library tooling or exposed the way uploads often are.
	 */
	public static function storage_dir() {
		$upload_dir = wp_get_upload_dir();
		// WP_CONTENT_DIR is always defined by WP core; avoid relying on
		// upload_dir's 'basedir' since that can be redirected off-server by
		// offload plugins, which is exactly what we don't want for backups.
		return rtrim( WP_CONTENT_DIR, '/\\' ) . '/' . AVIX_MIGRATION_STORAGE_DIRNAME;
	}

	public static function archives_dir() {
		return self::storage_dir() . '/archives';
	}

	public static function jobs_dir() {
		return self::storage_dir() . '/jobs';
	}

	public static function tmp_dir() {
		return self::storage_dir() . '/tmp';
	}

	public static function logs_dir() {
		return self::storage_dir() . '/logs';
	}

	/**
	 * Creates the full storage tree and hardens it against direct web
	 * access. Called on activation and defensively at the start of any job
	 * that writes to it, in case a host wipes empty-looking directories.
	 */
	public static function create_storage_dirs() {
		foreach ( array( self::archives_dir(), self::jobs_dir(), self::tmp_dir(), self::logs_dir() ) as $dir ) {
			self::ensure_dir( $dir );
		}
		self::harden_dir( self::storage_dir() );
	}

	/**
	 * mkdir -p plus an index.php stub so directory listing never leaks
	 * filenames even on a host with autoindex on.
	 */
	public static function ensure_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$index = rtrim( $dir, '/\\' ) . '/index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
		return is_dir( $dir );
	}

	/**
	 * Blocks direct HTTP access to the storage directory on Apache/LiteSpeed
	 * (.htaccess) and IIS (web.config). Deliberately NOT relied on as the
	 * only protection — this plugin's dev/test environment (Local by
	 * Flywheel) serves via nginx, which ignores .htaccess entirely, so
	 * archive filenames also carry a random token and are only ever served
	 * through the authenticated download endpoint, never a raw static URL.
	 */
	public static function harden_dir( $dir ) {
		$htaccess = rtrim( $dir, '/\\' ) . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "# Avix Migration — block direct access to backup storage.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";
			@file_put_contents( $htaccess, $rules );
		}

		$web_config = rtrim( $dir, '/\\' ) . '/web.config';
		if ( ! file_exists( $web_config ) ) {
			$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
				. "<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n";
			@file_put_contents( $web_config, $xml );
		}
	}

	/**
	 * Rejects a raw archive-entry relative path (the un-joined dir/name
	 * strings straight out of the entry header) before it is ever
	 * concatenated onto a real filesystem base. This is the first line of
	 * defense — is_path_within() below is the second, applied after
	 * joining and resolving symlinks.
	 *
	 * @param string $relative Entry path as stored in the archive header.
	 */
	public static function is_unsafe_relative_path( $relative ) {
		$relative = str_replace( '\\', '/', (string) $relative );

		if ( '' === $relative ) {
			return false; // Empty relative dir (root-level entry) is fine.
		}
		if ( false !== strpos( $relative, "\0" ) ) {
			return true;
		}
		if ( false !== strpos( $relative, '../' ) || false !== strpos( $relative, '/..' ) || '..' === $relative ) {
			return true;
		}
		// Absolute POSIX path or Windows drive letter — a well-formed entry
		// only ever stores paths relative to the archive's own namespace.
		if ( 0 === strpos( $relative, '/' ) || preg_match( '#^[A-Za-z]:#', $relative ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Whether $filename matches exactly the pattern this plugin's own
	 * Export_Prepare/Content_Prepare steps generate — used to validate a
	 * filename received over the site-to-site REST API before it's used
	 * to build a filesystem path. Never trust a client-supplied filename
	 * for path construction, even from an authenticated peer: a
	 * compromised or buggy remote is still an untrusted source of
	 * "what should this path be".
	 */
	public static function is_safe_archive_filename( $filename ) {
		return is_string( $filename ) && 1 === preg_match( '/^[a-z0-9-]+-\d{8}-\d{6}-[0-9a-f]{8}\.avix$/', $filename );
	}

	/**
	 * Resolves $target and confirms it is actually inside $base once
	 * symlinks/`..` are resolved — the core defense against a malicious
	 * archive entry writing outside the intended extraction directory.
	 *
	 * Because the destination file may not exist yet (extraction hasn't
	 * written it), this checks the parent directory's realpath rather than
	 * the file's. Callers must reject raw entry paths with
	 * is_unsafe_relative_path() BEFORE building $target — this function only
	 * catches what survives into a real path, e.g. a symlink planted inside
	 * $base by an earlier entry in the same archive that redirects a later,
	 * textually-innocent path back out.
	 *
	 * @param string $base   Absolute directory the result must stay within.
	 * @param string $target Absolute candidate path (not yet required to exist).
	 */
	public static function is_path_within( $base, $target ) {
		$base = rtrim( str_replace( '\\', '/', $base ), '/' );
		$target = str_replace( '\\', '/', $target );

		if ( '' === $base || '' === $target ) {
			return false;
		}

		$real_base = realpath( $base );
		if ( false === $real_base ) {
			return false;
		}
		$real_base = str_replace( '\\', '/', $real_base );

		$parent      = dirname( $target );
		$real_parent = realpath( $parent );

		if ( false === $real_parent ) {
			// Parent doesn't exist yet (nested new directory) — walk up
			// until we find an existing ancestor, confirming each missing
			// segment is a plain name, not another traversal attempt.
			$to_create = array();
			$walker    = $target;
			while ( false === ( $real = realpath( dirname( $walker ) ) ) ) {
				$to_create[] = basename( $walker );
				$walker      = dirname( $walker );
				if ( '/' === $walker || '.' === $walker || '' === $walker ) {
					return false;
				}
			}
			$real_parent = str_replace( '\\', '/', $real ) . '/' . implode( '/', array_reverse( $to_create ) );
		} else {
			$real_parent = str_replace( '\\', '/', $real_parent );
		}

		return 0 === strpos( $real_parent . '/', $real_base . '/' );
	}

	public static function human_size( $bytes ) {
		$bytes = (float) $bytes;
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$i     = 0;
		while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
			$bytes /= 1024;
			$i++;
		}
		return round( $bytes, $i === 0 ? 0 : 1 ) . ' ' . $units[ $i ];
	}

	public static function disk_free() {
		$dir = self::storage_dir();
		if ( ! is_dir( $dir ) ) {
			$dir = WP_CONTENT_DIR;
		}
		$free = @disk_free_space( $dir );
		return false === $free ? null : (int) $free;
	}

	/**
	 * Recursively deletes a directory and everything in it. Used by the
	 * uninstaller and the Tools screen's "delete all plugin data" action —
	 * deliberately not used anywhere near archive extraction, which only
	 * ever creates files, never removes directory trees.
	 */
	public static function delete_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
			}
		}

		@rmdir( $dir );
	}
}
