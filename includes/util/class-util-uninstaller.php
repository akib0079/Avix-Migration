<?php
/**
 * Removes everything this plugin has written: storage directory (archives,
 * jobs, tmp, logs), options, transients, and scheduled cron events. Shared
 * between uninstall.php (runs when the plugin is deleted from wp-admin) and
 * the Tools screen's manual "delete all plugin data" action, so there is
 * exactly one place that knows the full list of what the plugin owns.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Util_Uninstaller {

	/** Option names the plugin creates directly (later milestones append here as they add settings). */
	public static function option_names() {
		return apply_filters(
			'avix_migration_own_options',
			array(
				'avix_migration_activated_at',
				'avix_migration_schedules',
				'avix_migration_destinations',
				'avix_migration_issued_keys',
				'avix_migration_remotes',
			)
		);
	}

	/** Transient names, without the leading '_transient_' prefix WordPress adds internally. */
	public static function transient_names() {
		return apply_filters(
			'avix_migration_own_transients',
			array(
				'avix_migration_wpcontent_size',
				'avix_migration_db_size',
			)
		);
	}

	public static function wipe_all() {
		Avix_Migration_Util_Filesystem::delete_dir( Avix_Migration_Util_Filesystem::storage_dir() );

		foreach ( self::option_names() as $option ) {
			delete_option( $option );
		}
		foreach ( self::transient_names() as $transient ) {
			delete_transient( $transient );
		}

		$timestamp = wp_next_scheduled( Avix_Migration_Schedule_Scheduler::HOUSEKEEPING_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, Avix_Migration_Schedule_Scheduler::HOUSEKEEPING_HOOK );
		}
		wp_clear_scheduled_hook( Avix_Migration_Schedule_Scheduler::HOUSEKEEPING_HOOK );

		do_action( 'avix_migration_wiped_all_data' );
	}
}
