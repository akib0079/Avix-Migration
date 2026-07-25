<?php
/**
 * Exclusion rules for the wp-content file scanner. Two layers:
 *
 *  - Hard exclusions: always skipped, no UI toggle (our own storage dir —
 *    backing up your own backups is never correct).
 *  - Auto-detected: directories belonging to other backup plugins or cache
 *    plugins, found on disk and presented PRE-CHECKED in the wizard's
 *    Exclusions step. The user can un-check any of them; nothing is ever
 *    silently dropped without being shown.
 *
 * This split matters in practice: a site running both this plugin and (say)
 * All-in-One WP Migration or UpdraftPlus can easily have gigabytes of dead
 * weight sitting in wp-content that has no business being backed up again —
 * the export wizard should surface that instead of quietly producing a
 * bloated archive.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Fs_Exclusions {

	/**
	 * Directory basenames (top-level under wp-content), matched
	 * case-insensitively, glob-style ('*' wildcard supported). Reason is
	 * shown in the wizard so the user understands *why* it's suggested.
	 */
	public static function known_patterns() {
		return apply_filters(
			'avix_migration_known_exclusion_patterns',
			array(
				array( 'pattern' => 'ai1wm-backups',      'reason' => __( 'All-in-One WP Migration backup storage', 'avix-migration' ) ),
				array( 'pattern' => 'updraft',             'reason' => __( 'UpdraftPlus backup storage', 'avix-migration' ) ),
				array( 'pattern' => 'backwpup-*',          'reason' => __( 'BackWPup backup storage', 'avix-migration' ) ),
				array( 'pattern' => 'wpvivid-backups',     'reason' => __( 'WPvivid backup storage', 'avix-migration' ) ),
				array( 'pattern' => 'backup-guard',        'reason' => __( 'BackupGuard backup storage', 'avix-migration' ) ),
				array( 'pattern' => 'backups-dup-pro',     'reason' => __( 'Duplicator Pro backup storage', 'avix-migration' ) ),
				array( 'pattern' => 'duplicator*',         'reason' => __( 'Duplicator backup storage', 'avix-migration' ) ),
				array( 'pattern' => 'boldgrid_backup',     'reason' => __( 'BoldGrid Backup storage', 'avix-migration' ) ),
				array( 'pattern' => 'wp-clone',            'reason' => __( 'WP Clone backup storage', 'avix-migration' ) ),
				array( 'pattern' => 'cache',                'reason' => __( 'Page/object cache — regenerated automatically', 'avix-migration' ) ),
				array( 'pattern' => 'et-cache',             'reason' => __( 'Divi builder cache — regenerated automatically', 'avix-migration' ) ),
				array( 'pattern' => 'wphb-cache',           'reason' => __( 'Hummingbird cache — regenerated automatically', 'avix-migration' ) ),
				array( 'pattern' => 'sg-cachepress',        'reason' => __( 'SiteGround Optimizer cache — regenerated automatically', 'avix-migration' ) ),
				array( 'pattern' => 'wc-logs',               'reason' => __( 'WooCommerce log files', 'avix-migration' ) ),
				array( 'pattern' => 'uploads/woocommerce_uploads/*_uploads', 'reason' => __( 'Temporary WooCommerce upload files', 'avix-migration' ) ),
			)
		);
	}

	/**
	 * Directory names under wp-content that are never backed up, regardless
	 * of user configuration — not shown as a toggle because there is no
	 * legitimate reason to include them.
	 */
	public static function hard_excluded_dirs() {
		return array( AVIX_MIGRATION_STORAGE_DIRNAME );
	}

	/**
	 * Scans immediate children of wp-content and returns any that match a
	 * known pattern, with an on-disk size so the wizard can show "1.6 GB"
	 * next to each suggestion rather than an unqualified checkbox.
	 *
	 * @return array[] { dir, reason, bytes }
	 */
	public static function detect() {
		$content_dir = WP_CONTENT_DIR;
		if ( ! is_dir( $content_dir ) ) {
			return array();
		}

		$children = @scandir( $content_dir );
		if ( ! $children ) {
			return array();
		}

		$patterns = self::known_patterns();
		$found    = array();

		foreach ( $children as $child ) {
			if ( '.' === $child || '..' === $child ) {
				continue;
			}
			$full = $content_dir . '/' . $child;
			if ( ! is_dir( $full ) ) {
				continue;
			}

			foreach ( $patterns as $rule ) {
				// Only match single-segment top-level patterns here; the
				// woocommerce_uploads example above is illustrative of a
				// nested pattern a filter could add, but detect() itself
				// only surfaces top-level directories as toggles.
				if ( false !== strpos( $rule['pattern'], '/' ) ) {
					continue;
				}
				if ( fnmatch( strtolower( $rule['pattern'] ), strtolower( $child ) ) ) {
					$found[] = array(
						'dir'    => $child,
						'reason' => $rule['reason'],
						'bytes'  => self::dir_size( $full ),
					);
					break;
				}
			}
		}

		return $found;
	}

	private static function dir_size( $dir ) {
		$bytes = 0;
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$bytes += $file->getSize();
				}
			}
		} catch ( Exception $e ) {
			// Unreadable subtree — report what we could measure.
		}
		return $bytes;
	}

	/**
	 * Whether a wp-content-relative path (forward slashes, no leading
	 * slash, e.g. "plugins/foo/bar.php") should be skipped, given the set
	 * of directory names the user chose to exclude in the wizard.
	 *
	 * @param string   $relative_path Forward-slash relative path from wp-content.
	 * @param string[] $excluded_dirs Top-level dir names the user excluded (from the auto-detected list and/or custom patterns).
	 */
	public static function is_excluded( $relative_path, array $excluded_dirs ) {
		$relative_path = ltrim( str_replace( '\\', '/', $relative_path ), '/' );
		$top_segment   = strtok( $relative_path, '/' );

		if ( in_array( $top_segment, self::hard_excluded_dirs(), true ) ) {
			return true;
		}

		foreach ( $excluded_dirs as $pattern ) {
			if ( '' === $pattern ) {
				continue;
			}
			// Support both a bare top-level name ("cache") and a glob
			// pattern anywhere in the relative path ("*.log", "*/node_modules/*").
			if ( false === strpos( $pattern, '/' ) && false === strpos( $pattern, '*' ) ) {
				if ( 0 === strcasecmp( $top_segment, $pattern ) ) {
					return true;
				}
				continue;
			}
			if ( fnmatch( $pattern, $relative_path ) || fnmatch( $pattern, basename( $relative_path ) ) ) {
				return true;
			}
		}

		return false;
	}
}
