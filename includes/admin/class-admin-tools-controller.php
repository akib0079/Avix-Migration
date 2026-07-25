<?php
/**
 * Controller for the Tools screen: system diagnostics, log viewer, and the
 * two housekeeping actions every self-hosted backup tool eventually needs —
 * un-sticking a job that will never finish, and a clean full uninstall
 * without waiting on WordPress's own (all-or-nothing) plugin deletion flow.
 *
 * Built during the foundation milestone rather than deferred to Milestone 8
 * because every other milestone produces jobs and log entries from day one,
 * and this is where an operator goes to see them.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Admin_Tools_Controller {

	public static function boot() {
		add_filter( 'avix_migration_ajax_handlers', array( __CLASS__, 'register_ajax_handlers' ) );
	}

	public static function register_ajax_handlers( array $handlers ) {
		$handlers['avix_reset_stuck_jobs'] = array( __CLASS__, 'reset_stuck_jobs' );
		$handlers['avix_delete_all_data']  = array( __CLASS__, 'delete_all_data' );
		$handlers['avix_purge_snapshot']   = array( __CLASS__, 'purge_snapshot' );
		$handlers['avix_restore_snapshot'] = array( __CLASS__, 'restore_snapshot' );
		$handlers['avix_db_probe']         = array( __CLASS__, 'db_probe' );
		return $handlers;
	}

	/**
	 * Performs the exact operation WordPress does when a media upload is
	 * saved — an INSERT into wp_posts — and reports MySQL's real error.
	 *
	 * WordPress swallows the driver error behind "Could not insert
	 * attachment into the database", which is unactionable: a missing
	 * table, a broken AUTO_INCREMENT, a crashed InnoDB table and a column
	 * mismatch all produce that same sentence. Reproducing the insert here
	 * and surfacing $wpdb->last_error is the difference between diagnosing
	 * this and guessing at it.
	 *
	 * The probe row is deleted immediately afterwards. No transaction is
	 * used deliberately — a MyISAM table would silently ignore a ROLLBACK
	 * and strand the row, so an explicit delete by insert id is the safer
	 * cleanup across engines.
	 */
	public static function db_probe() {
		global $wpdb;

		$report = array( 'tables' => array(), 'insert_error' => null, 'insert_ok' => false, 'cleanup_ok' => null );

		$expected = array(
			$wpdb->posts    => 'posts',
			$wpdb->postmeta => 'postmeta',
			$wpdb->options  => 'options',
			$wpdb->users    => 'users',
			$wpdb->usermeta => 'usermeta',
			$wpdb->terms    => 'terms',
		);

		foreach ( $expected as $table => $label ) {
			$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

			$row = array( 'table' => $table, 'exists' => $exists, 'rows' => null, 'engine' => null, 'auto_increment' => null, 'max_id' => null );

			if ( $exists ) {
				$row['rows'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $table ) . '`' );

				$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
				if ( is_array( $status ) ) {
					$row['engine']         = $status['Engine'] ?? null;
					$row['auto_increment'] = isset( $status['Auto_increment'] ) ? (int) $status['Auto_increment'] : null;
				}

				// An AUTO_INCREMENT counter at or below the largest existing
				// id makes the very next insert collide on the primary key —
				// a classic outcome of a half-replayed dump, and invisible
				// unless compared directly.
				if ( $table === $wpdb->posts || $table === $wpdb->users ) {
					$row['max_id'] = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(ID),0) FROM `' . esc_sql( $table ) . '`' );
				}
			}

			$report['tables'][ $label ] = $row;
		}

		// The actual reproduction.
		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert(
			$wpdb->posts,
			array(
				'post_author'   => 0,
				'post_date'     => current_time( 'mysql' ),
				'post_date_gmt' => current_time( 'mysql', 1 ),
				'post_title'    => 'avix-db-probe (safe to delete)',
				'post_status'   => 'draft',
				'post_type'     => 'avix_probe',
			)
		);
		$report['insert_error'] = $wpdb->last_error ? $wpdb->last_error : null;
		$report['insert_ok']    = ( false !== $inserted );
		$probe_id               = $wpdb->insert_id;
		$wpdb->suppress_errors( $suppress );

		if ( $report['insert_ok'] && $probe_id ) {
			$report['cleanup_ok'] = ( false !== $wpdb->delete( $wpdb->posts, array( 'ID' => $probe_id ) ) );
		}

		Avix_Migration_Util_Logger::info( 'plugin', 'Database probe run.', $report );

		wp_send_json_success( $report );
	}

	/**
	 * Puts a rollback snapshot back into place. Deliberately available from
	 * Tools rather than only from a failed import's own screen: if an import
	 * dies badly the job may be unreachable, and this snapshot is then the
	 * only copy of the operator's real data.
	 */
	public static function restore_snapshot() {
		$timestamp = isset( $_POST['timestamp'] ) ? (int) $_POST['timestamp'] : 0;
		if ( $timestamp <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Which snapshot?', 'avix-migration' ) ), 400 );
		}

		$result = Avix_Migration_Rollback_Manager::restore_snapshot( $timestamp );

		Avix_Migration_Util_Logger::info(
			'plugin',
			'Rollback snapshot restored from Tools.',
			array( 'timestamp' => $timestamp, 'restored' => count( $result['restored'] ), 'errors' => $result['errors'] )
		);

		if ( empty( $result['restored'] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Nothing was restored — that snapshot no longer has any tables.', 'avix-migration' ) ),
				409
			);
		}

		wp_send_json_success(
			array(
				'restored' => count( $result['restored'] ),
				'errors'   => $result['errors'],
				'message'  => sprintf(
					/* translators: %d: number of tables restored */
					_n( 'Restored %d table.', 'Restored %d tables.', count( $result['restored'] ), 'avix-migration' ),
					count( $result['restored'] )
				),
			)
		);
	}

	/**
	 * Drops one rollback snapshot's tables. Snapshots are kept after a
	 * successful import on purpose (they're the undo point), so this is the
	 * only way to reclaim that space — and the only way to clear a snapshot
	 * left behind by an import that failed partway.
	 */
	public static function purge_snapshot() {
		$timestamp = isset( $_POST['timestamp'] ) ? (int) $_POST['timestamp'] : 0;
		if ( $timestamp <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Which snapshot?', 'avix-migration' ) ), 400 );
		}

		$dropped = Avix_Migration_Rollback_Manager::purge_snapshot( $timestamp );
		Avix_Migration_Util_Logger::info( 'plugin', 'Rollback snapshot purged.', array( 'timestamp' => $timestamp, 'tables' => $dropped ) );

		wp_send_json_success(
			array(
				'dropped' => $dropped,
				'message' => sprintf(
					/* translators: %d: number of tables dropped */
					_n( 'Dropped %d table.', 'Dropped %d tables.', $dropped, 'avix-migration' ),
					$dropped
				),
			)
		);
	}

	/**
	 * Force-fails every non-terminal job regardless of the housekeeping
	 * watchdog's normal 1-hour threshold — the manual "I know this one is
	 * dead, stop showing me a spinner" button.
	 */
	public static function reset_stuck_jobs() {
		$count = 0;
		foreach ( Avix_Migration_Job_Store::all_ids() as $id ) {
			$job = Avix_Migration_Job_Store::load( $id );
			if ( $job && ! $job->is_terminal() ) {
				$job->fail( __( 'Manually reset from the Tools screen.', 'avix-migration' ) );
				Avix_Migration_Job_Store::save( $job );
				$count++;
			}
		}
		wp_send_json_success( array( 'reset_count' => $count ) );
	}

	/**
	 * Deletes every archive, job, and log file plus this plugin's options —
	 * everything uninstall.php also does, exposed here so an operator can
	 * wipe state without deactivating/deleting the plugin itself (useful
	 * mid-development and for "start over" after a botched test import).
	 */
	public static function delete_all_data() {
		Avix_Migration_Util_Uninstaller::wipe_all();
		wp_send_json_success( array( 'wiped' => true ) );
	}
}
