<?php
/**
 * Collects a snapshot of "what is this site" facts. Shared by two
 * consumers that need the same underlying data for different purposes:
 * Archive_Manifest (what to record about the source site when exporting)
 * and the Import pre-flight report (what to compare source vs. target
 * before an import runs).
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Util_Sysinfo {

	public static function snapshot() {
		global $wpdb, $wp_version;

		$upload_dir = wp_get_upload_dir();

		return array(
			'site_url'       => site_url(),
			'home_url'       => home_url(),
			'abspath'        => wp_normalize_path( ABSPATH ),
			'content_dir'    => wp_normalize_path( WP_CONTENT_DIR ),
			'uploads_dir'    => wp_normalize_path( $upload_dir['basedir'] ),
			'uploads_baseurl' => $upload_dir['baseurl'],
			'table_prefix'   => $wpdb->prefix,
			'is_multisite'   => is_multisite(),
			'wp_version'     => $wp_version,
			'php_version'    => PHP_VERSION,
			'mysql_version'  => method_exists( $wpdb, 'db_version' ) ? $wpdb->db_version() : '',
			'active_theme'   => self::active_theme(),
			'active_plugins' => self::active_plugins(),
			'plugin_count'   => count( self::active_plugins() ),
			'memory_limit'   => ini_get( 'memory_limit' ),
			'max_execution_time' => ini_get( 'max_execution_time' ),
			'disk_free'      => Avix_Migration_Util_Filesystem::disk_free(),
			'generated_at'   => time(),
		);
	}

	private static function active_theme() {
		$theme = wp_get_theme();
		return array(
			'name'    => $theme->get( 'Name' ),
			'version' => $theme->get( 'Version' ),
			'stylesheet' => $theme->get_stylesheet(),
		);
	}

	private static function active_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', array() );
		$out    = array();

		foreach ( $active as $file ) {
			if ( isset( $all[ $file ] ) ) {
				$out[] = array(
					'file'    => $file,
					'name'    => $all[ $file ]['Name'],
					'version' => $all[ $file ]['Version'],
				);
			}
		}
		return $out;
	}

	/**
	 * Approximate on-disk size of wp-content, cached for an hour — used by
	 * the Dashboard's size estimate, not by the actual export (which walks
	 * the tree fresh via Fs_Scanner so its accounting is exact rather than
	 * a stale cached figure). Summing a multi-GB tree on every dashboard
	 * page load would make the admin noticeably slower, hence the cache.
	 *
	 * @return array{bytes:int,measured_at:int}
	 */
	public static function wp_content_size() {
		$cached = get_transient( 'avix_migration_wpcontent_size' );
		if ( is_array( $cached ) && isset( $cached['bytes'] ) ) {
			return $cached;
		}

		$bytes = 0;
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( WP_CONTENT_DIR, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$bytes += $file->getSize();
				}
			}
		} catch ( Exception $e ) {
			// Unreadable directory somewhere in the tree — report what we
			// got rather than failing the whole dashboard.
		}

		$result = array( 'bytes' => $bytes, 'measured_at' => time() );
		set_transient( 'avix_migration_wpcontent_size', $result, HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * Approximate database size in bytes via information_schema, cached
	 * alongside the file size for the same reason.
	 */
	public static function db_size() {
		global $wpdb;

		$cached = get_transient( 'avix_migration_db_size' );
		if ( is_array( $cached ) && isset( $cached['bytes'] ) ) {
			return $cached;
		}

		$bytes = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s AND table_name LIKE %s",
				DB_NAME,
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			)
		);

		$result = array( 'bytes' => $bytes, 'measured_at' => time() );
		set_transient( 'avix_migration_db_size', $result, HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * Builds the human-readable warning list the Import pre-flight report
	 * shows when comparing a manifest (source) against this site (target).
	 *
	 * @param array $manifest_site Sysinfo-shaped array read from the archive manifest.
	 * @return string[] Warning messages, empty if nothing notable differs.
	 */
	public static function compare_warnings( array $manifest_site ) {
		$target = self::snapshot();
		$warnings = array();

		if ( ! empty( $manifest_site['wp_version'] ) && ! empty( $target['wp_version'] )
			&& version_compare( $manifest_site['wp_version'], $target['wp_version'], '>' ) ) {
			$warnings[] = sprintf(
				/* translators: 1: source WP version, 2: target WP version */
				__( 'Source site runs WordPress %1$s, newer than this site\'s %2$s. Consider updating this site first.', 'avix-migration' ),
				$manifest_site['wp_version'],
				$target['wp_version']
			);
		}

		if ( ! empty( $manifest_site['php_version'] ) && ! empty( $target['php_version'] )
			&& version_compare( $manifest_site['php_version'], $target['php_version'], '>' ) ) {
			$warnings[] = sprintf(
				/* translators: 1: source PHP version, 2: target PHP version */
				__( 'Source site ran PHP %1$s, newer than this server\'s %2$s. Some plugins may not be compatible.', 'avix-migration' ),
				$manifest_site['php_version'],
				$target['php_version']
			);
		}

		if ( ! empty( $manifest_site['table_prefix'] ) && ! empty( $target['table_prefix'] )
			&& $manifest_site['table_prefix'] !== $target['table_prefix'] ) {
			$warnings[] = sprintf(
				/* translators: 1: source table prefix, 2: target table prefix */
				__( 'Table prefix will change from "%1$s" to "%2$s" during import.', 'avix-migration' ),
				$manifest_site['table_prefix'],
				$target['table_prefix']
			);
		}

		if ( ! empty( $manifest_site['site_url'] ) && ! empty( $target['site_url'] )
			&& untrailingslashit( $manifest_site['site_url'] ) !== untrailingslashit( $target['site_url'] ) ) {
			$warnings[] = sprintf(
				/* translators: 1: source URL, 2: target URL */
				__( 'Site URL will change from %1$s to %2$s — all content URLs will be rewritten.', 'avix-migration' ),
				$manifest_site['site_url'],
				$target['site_url']
			);
		}

		if ( ! empty( $manifest_site['is_multisite'] ) !== ! empty( $target['is_multisite'] ) ) {
			$warnings[] = __( 'Multisite status differs between source and target — this import path does not support converting single-site ↔ multisite.', 'avix-migration' );
		}

		return $warnings;
	}
}
