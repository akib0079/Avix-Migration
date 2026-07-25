<?php
/**
 * Drives Db_Exporter one batch per call, writing wp-content/avix-backups/
 * tmp/{job}-database.sql.gz. Skipped entirely (immediate STEP_COMPLETE) if
 * the wizard's "database" include toggle was off.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Export_Database extends Avix_Migration_Job_Step {

	public function label() {
		return __( 'Exporting database', 'avix-migration' );
	}

	public function execute( Avix_Migration_Job $job ) {
		if ( empty( $job->meta['include_database'] ) ) {
			return Avix_Migration_Job_Step_Result::step_complete( 'Database not included in this backup.' );
		}

		$cursor  = $this->cursor( $job );
		$gz_path = $this->gz_path( $job );

		if ( ! isset( $cursor['tables'] ) ) {
			@unlink( $gz_path ); // Clean slate — this branch only runs once, on the very first tick.

			$cursor['tables']       = Avix_Migration_Db_Exporter::discover_tables( ! empty( $job->meta['all_tables'] ) );
			$cursor['table_index']  = 0;
			$cursor['wrote_schema'] = false;

			$job->totals['rows_total'] = Avix_Migration_Db_Exporter::estimate_total_rows( $cursor['tables'] );
			$job->meta['db_tmp_path']  = $gz_path;

			$this->set_cursor( $job, $cursor );

			return Avix_Migration_Job_Step_Result::cont(
				sprintf(
					/* translators: %d: table count */
					__( 'Found %d tables to export.', 'avix-migration' ),
					count( $cursor['tables'] )
				)
			);
		}

		$result = Avix_Migration_Db_Exporter::tick( $cursor, $gz_path, $this->where_overrides( $job ) );
		$this->set_cursor( $job, $cursor );

		if ( $result['rows_written'] > 0 ) {
			$job->totals['rows_done'] += $result['rows_written'];
		}

		if ( $result['done'] ) {
			// Fold the dump's on-disk size into bytes_total now, before the
			// write-archive step starts — so the progress total is
			// complete from the first tick of the copy phase instead of
			// jumping partway through it.
			if ( is_readable( $gz_path ) ) {
				$job->totals['bytes_total'] += (int) filesize( $gz_path );
			}
			Avix_Migration_Util_Logger::info( $job->id, 'Database export complete.', array( 'rows' => $job->totals['rows_done'] ) );
			return Avix_Migration_Job_Step_Result::step_complete( 'Database export complete.' );
		}

		return Avix_Migration_Job_Step_Result::cont(
			sprintf(
				/* translators: 1: table name, 2: row count so far */
				__( 'Exporting table %1$s… (%2$s rows so far)', 'avix-migration' ),
				$result['table'],
				number_format_i18n( $job->totals['rows_done'] )
			)
		);
	}

	public function gz_path( Avix_Migration_Job $job ) {
		return Avix_Migration_Util_Filesystem::tmp_dir() . '/' . $job->id . '-database.sql.gz';
	}

	/**
	 * Translates the wizard's Advanced toggles into per-table SQL WHERE
	 * fragments. Table names are resolved against the *current* $wpdb
	 * prefix since this only ever runs against the local, live database.
	 */
	private function where_overrides( Avix_Migration_Job $job ) {
		global $wpdb;
		$overrides = array();

		if ( ! empty( $job->meta['skip_transients'] ) ) {
			$overrides[ $wpdb->options ] = "option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'";
		}
		if ( ! empty( $job->meta['skip_revisions'] ) ) {
			$overrides[ $wpdb->posts ] = "post_type != 'revision'";
		}
		if ( ! empty( $job->meta['skip_spam_trash_comments'] ) ) {
			$overrides[ $wpdb->comments ] = "comment_approved NOT IN ('spam','trash')";
		}

		return $overrides;
	}
}
