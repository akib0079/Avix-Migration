<?php
/**
 * Builds and validates the manifest that is always the first entry in an
 * .avix archive — reading just this one entry (a few KB) tells a caller
 * everything needed to validate compatibility and show a pre-flight report,
 * without touching the (potentially multi-GB) rest of the archive.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Archive_Manifest {

	const ENTRY_NAME = 'avix-manifest.json';

	const TYPE_FULL    = 'full';
	const TYPE_CONTENT = 'content';

	/**
	 * @param string $type  TYPE_FULL or TYPE_CONTENT.
	 * @param array  $extra Type-specific fields (e.g. content export's post
	 *                      id list, or full export's include/exclude choices).
	 */
	public static function build( $type, array $extra = array() ) {
		$manifest = array(
			'format_version' => AVIX_MIGRATION_FORMAT_VERSION,
			'plugin_version' => AVIX_MIGRATION_VERSION,
			'archive_type'   => $type,
			'site'           => Avix_Migration_Util_Sysinfo::snapshot(),
			'created_at'     => time(),
		);

		return array_merge( $manifest, $extra );
	}

	/**
	 * Structural validation only (right shape, known format version) —
	 * semantic compatibility (WP/PHP version mismatches, etc.) is surfaced
	 * separately as non-blocking warnings via Sysinfo::compare_warnings().
	 *
	 * @return true|WP_Error
	 */
	public static function validate( $manifest ) {
		if ( ! is_array( $manifest ) ) {
			return new WP_Error( 'avix_bad_manifest', __( 'Archive manifest is missing or unreadable.', 'avix-migration' ) );
		}
		if ( empty( $manifest['format_version'] ) ) {
			return new WP_Error( 'avix_bad_manifest', __( 'Archive manifest has no format version.', 'avix-migration' ) );
		}
		if ( (int) $manifest['format_version'] > AVIX_MIGRATION_FORMAT_VERSION ) {
			return new WP_Error(
				'avix_manifest_too_new',
				sprintf(
					/* translators: %s: plugin version */
					__( 'This archive was created by a newer version of Avix Migration. Please update the plugin on this site before importing (current: %s).', 'avix-migration' ),
					AVIX_MIGRATION_VERSION
				)
			);
		}
		if ( empty( $manifest['archive_type'] ) || ! in_array( $manifest['archive_type'], array( self::TYPE_FULL, self::TYPE_CONTENT ), true ) ) {
			return new WP_Error( 'avix_bad_manifest', __( 'Archive manifest has an unrecognized archive type.', 'avix-migration' ) );
		}
		if ( empty( $manifest['site'] ) || ! is_array( $manifest['site'] ) ) {
			return new WP_Error( 'avix_bad_manifest', __( 'Archive manifest is missing site information.', 'avix-migration' ) );
		}
		return true;
	}
}
