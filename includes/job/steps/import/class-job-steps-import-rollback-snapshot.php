<?php
/**
 * Renames the target's existing tables aside before replay touches
 * anything, via Rollback_Manager — the pre-import safety net. Skipped
 * entirely if this import doesn't include a database at all (a files-only
 * restore has nothing to snapshot).
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Import_Rollback_Snapshot extends Avix_Migration_Job_Step {

	public function label() {
		return __( 'Creating safety snapshot', 'avix-migration' );
	}

	public function execute( Avix_Migration_Job $job ) {
		if ( empty( $job->meta['has_database'] ) ) {
			return Avix_Migration_Job_Step_Result::step_complete( __( 'No database to snapshot.', 'avix-migration' ) );
		}

		// Pin the timestamp to the job on first run. Calling time() afresh on
		// a retry would mint a SECOND set of avix_rb_ tables while the first
		// set still holds the operator's real pre-import data — two partial
		// snapshots, neither obviously the one to roll back to.
		if ( empty( $job->meta['rollback_timestamp'] ) ) {
			$job->meta['rollback_timestamp'] = time();
		}

		$map = Avix_Migration_Rollback_Manager::snapshot( $job->meta['rollback_timestamp'] );
		$job->meta['rollback_map'] = $map;

		Avix_Migration_Util_Logger::info( $job->id, 'Pre-import snapshot created.', array( 'tables' => count( $map ) ) );

		return Avix_Migration_Job_Step_Result::step_complete(
			sprintf(
				/* translators: %d: table count */
				__( 'Snapshotted %d existing tables.', 'avix-migration' ),
				count( $map )
			)
		);
	}
}
