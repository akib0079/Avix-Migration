<?php
/**
 * CRUD for saved backup schedules. Stored as a single WP option (an array
 * of small config records) rather than one option per schedule or a custom
 * table — schedules are edited rarely (unlike Job state, which changes on
 * every progress tick), so there's no autoload-churn concern here the way
 * there is for jobs.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Schedule_Store {

	const OPTION_NAME = 'avix_migration_schedules';

	const FREQUENCIES = array( 'hourly', 'avix_six_hourly', 'daily', 'avix_weekly', 'avix_monthly' );

	public static function all() {
		$schedules = get_option( self::OPTION_NAME, array() );
		return is_array( $schedules ) ? $schedules : array();
	}

	public static function get( $id ) {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	/**
	 * @param array $config { frequency, time_of_day (H:i, only meaningful
	 *              for daily+), enabled, include_database, include_files,
	 *              excluded_dirs, skip_transients, skip_revisions,
	 *              skip_spam_trash_comments, all_tables, retention_keep_last,
	 *              retention_older_than_days, notify_email, notify_on }
	 * @return string The new schedule's id.
	 */
	public static function create( array $config ) {
		$all = self::all();
		$id  = 'sched_' . Avix_Migration_Util_Crypto::random_token( 6 );

		$all[ $id ] = self::sanitize_config( $config );
		$all[ $id ]['id']         = $id;
		$all[ $id ]['created_at'] = time();
		$all[ $id ]['last_run_at'] = 0;
		$all[ $id ]['last_status'] = '';
		$all[ $id ]['last_job_id'] = '';

		update_option( self::OPTION_NAME, $all, false );
		return $id;
	}

	public static function update( $id, array $config ) {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		$sanitized = self::sanitize_config( $config );
		$all[ $id ] = array_merge( $all[ $id ], $sanitized );
		update_option( self::OPTION_NAME, $all, false );
		return true;
	}

	public static function delete( $id ) {
		$all = self::all();
		unset( $all[ $id ] );
		update_option( self::OPTION_NAME, $all, false );
	}

	public static function record_run( $id, $job_id ) {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return;
		}
		$all[ $id ]['last_run_at'] = time();
		$all[ $id ]['last_job_id'] = $job_id;
		$all[ $id ]['last_status'] = 'running';
		update_option( self::OPTION_NAME, $all, false );
	}

	public static function record_result( $id, $status ) {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return;
		}
		$all[ $id ]['last_status'] = $status;
		update_option( self::OPTION_NAME, $all, false );
	}

	private static function sanitize_config( array $config ) {
		$frequency = in_array( $config['frequency'] ?? '', self::FREQUENCIES, true ) ? $config['frequency'] : 'daily';

		$time_of_day = $config['time_of_day'] ?? '02:00';
		if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time_of_day ) ) {
			$time_of_day = '02:00';
		}

		return array(
			'name'                       => sanitize_text_field( $config['name'] ?? __( 'Backup schedule', 'avix-migration' ) ),
			'frequency'                  => $frequency,
			'time_of_day'                => $time_of_day,
			'enabled'                    => ! empty( $config['enabled'] ),
			'include_database'           => ! isset( $config['include_database'] ) || ! empty( $config['include_database'] ),
			'include_files'              => ! isset( $config['include_files'] ) || ! empty( $config['include_files'] ),
			'excluded_dirs'              => array_values( array_map( 'sanitize_text_field', (array) ( $config['excluded_dirs'] ?? array() ) ) ),
			'excluded_top_dirs'          => (array) ( $config['excluded_top_dirs'] ?? array() ),
			'skip_transients'            => ! empty( $config['skip_transients'] ),
			'skip_revisions'             => ! empty( $config['skip_revisions'] ),
			'skip_spam_trash_comments'   => ! empty( $config['skip_spam_trash_comments'] ),
			'all_tables'                 => ! empty( $config['all_tables'] ),
			'destination_id'             => sanitize_text_field( $config['destination_id'] ?? 'local' ),
			'retention_keep_last'        => max( 0, (int) ( $config['retention_keep_last'] ?? 5 ) ),
			'retention_older_than_days'  => max( 0, (int) ( $config['retention_older_than_days'] ?? 0 ) ),
			'notify_email'               => sanitize_email( $config['notify_email'] ?? get_option( 'admin_email' ) ),
			'notify_on_success'          => ! empty( $config['notify_on_success'] ),
			'notify_on_failure'          => ! isset( $config['notify_on_failure'] ) || ! empty( $config['notify_on_failure'] ),
		);
	}

	/**
	 * Seconds between runs for a given frequency — used only to decide
	 * whether a schedule is "due"; the actual firing still happens on the
	 * plugin's own hourly housekeeping tick, not a per-schedule wp-cron
	 * event, so schedules can be added/edited/removed without ever needing
	 * to keep a matching set of wp_schedule_event() calls in sync.
	 */
	public static function interval_seconds( $frequency ) {
		switch ( $frequency ) {
			case 'hourly':
				return HOUR_IN_SECONDS;
			case 'avix_six_hourly':
				return 6 * HOUR_IN_SECONDS;
			case 'daily':
				return DAY_IN_SECONDS;
			case 'avix_weekly':
				return WEEK_IN_SECONDS;
			case 'avix_monthly':
				return 30 * DAY_IN_SECONDS;
			default:
				return DAY_IN_SECONDS;
		}
	}
}
