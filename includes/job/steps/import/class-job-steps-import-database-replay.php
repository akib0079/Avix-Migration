<?php
/**
 * Drives Db_Importer one window of statements per call, rewriting the
 * source prefix to this site's own prefix as it goes. If this fails
 * partway through, the job stops with status FAILED but the tables
 * Rollback_Snapshot renamed aside are left exactly as they are — the
 * Rollback screen action (not this step) is what renames them back, so the
 * operator sees the failure and its log before anything is undone.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Import_Database_Replay extends Avix_Migration_Job_Step {

	public function label() {
		return __( 'Restoring database', 'avix-migration' );
	}

	public function execute( Avix_Migration_Job $job ) {
		if ( empty( $job->meta['has_database'] ) || empty( $job->meta['sql_tmp_path'] ) ) {
			return Avix_Migration_Job_Step_Result::step_complete( __( 'No database to restore.', 'avix-migration' ) );
		}

		$cursor = $this->cursor( $job );

		if ( empty( $cursor['bytes_total'] ) ) {
			clearstatcache( true, $job->meta['sql_tmp_path'] );
			$cursor['bytes_total'] = (int) @filesize( $job->meta['sql_tmp_path'] );
			$job->totals['rows_total'] = 0; // Statement count, not row count, is what we can cheaply know here.
		}

		$result = Avix_Migration_Db_Importer::tick(
			$cursor,
			$job->meta['sql_tmp_path'],
			$job->meta['source_prefix'],
			$job->meta['target_prefix']
		);
		$this->set_cursor( $job, $cursor );

		if ( null !== $result['error'] ) {
			return Avix_Migration_Job_Step_Result::failed( $result['error'] );
		}

		$job->totals['rows_done'] += $result['statements_executed'];

		if ( $result['done'] ) {
			Avix_Migration_Util_Logger::info( $job->id, 'Database restore complete.', array( 'statements' => $job->totals['rows_done'] ) );
			return Avix_Migration_Job_Step_Result::step_complete( __( 'Database restored.', 'avix-migration' ) );
		}

		$percent = $cursor['bytes_total'] > 0 ? round( ( $cursor['byte_offset'] / $cursor['bytes_total'] ) * 100 ) : 0;

		return Avix_Migration_Job_Step_Result::cont(
			sprintf(
				/* translators: %d: percent of the SQL dump processed so far */
				__( 'Restoring database… %d%%', 'avix-migration' ),
				$percent
			)
		);
	}
}
