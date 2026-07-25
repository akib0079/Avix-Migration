<?php
/**
 * Last step of an import: post-restore fixups (flush rewrite rules and the
 * object cache so this request's own runtime — already loaded from the
 * pre-import DB state — doesn't serve stale reads for whatever runs next),
 * an at-least-one-administrator sanity check, and temp-file cleanup.
 *
 * The rolled-aside pre-import tables from Rollback_Snapshot are
 * deliberately NOT dropped here — they're the undo button, and stay until
 * the operator explicitly discards them (Tools screen, or a future
 * retention sweep) or uses Rollback to restore them.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Import_Finalize extends Avix_Migration_Job_Step {

	public function label() {
		return __( 'Finishing up', 'avix-migration' );
	}

	public function execute( Avix_Migration_Job $job ) {
		if ( ! empty( $job->meta['has_database'] ) ) {
			global $wpdb;

			// Direct SQL, not the options API: get_option()/wp_cache would
			// still be holding this same request's pre-import reads. A
			// fresh request after this one sees everything cleanly.
			$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE option_name = 'rewrite_rules'" );

			wp_cache_flush();

			$admin_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$wpdb->usermeta}` WHERE meta_key = %s AND meta_value LIKE %s",
					$wpdb->prefix . 'capabilities',
					'%"administrator"%'
				)
			);
			if ( 0 === $admin_count ) {
				$job->meta['warnings'][] = __( 'No administrator account was found after import — you may need to reset a password via wp-cli or direct database access.', 'avix-migration' );
			}
		}

		$this->cleanup_temp_files( $job );

		Avix_Migration_Util_Logger::info( $job->id, 'Import finished.' );

		return Avix_Migration_Job_Step_Result::step_complete( __( 'Import complete.', 'avix-migration' ) );
	}

	private function cleanup_temp_files( Avix_Migration_Job $job ) {
		foreach ( array( $job->id . '-restore.sql', $job->id . '-restore.sql.gz' ) as $name ) {
			$path = Avix_Migration_Util_Filesystem::tmp_dir() . '/' . $name;
			if ( file_exists( $path ) ) {
				@unlink( $path );
			}
		}
	}
}
