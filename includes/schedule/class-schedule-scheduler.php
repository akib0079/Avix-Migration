<?php
/**
 * WP-Cron wiring for scheduled backups.
 *
 * Rather than registering one wp_schedule_event() per saved schedule (which
 * needs careful add/edit/remove bookkeeping to keep in sync), a single
 * hourly housekeeping tick evaluates every enabled schedule's "is this due"
 * logic itself. Frequencies below daily than an hour aren't meaningful
 * (hourly is as granular as it gets), and daily+ schedules land within the
 * hour that contains their configured time-of-day — acceptable precision
 * for a backup scheduler, and consistent with how real wp-cron behaves
 * everywhere else (it only fires on a page load in the first place).
 *
 * A scheduled backup job is normally far too long-running to finish inside
 * one housekeeping tick's budget, so once a scheduled job starts, this
 * class chains short-lived single wp-cron events (avix_migration_advance_job)
 * a few seconds apart, each running the job forward by one more Job_Runner
 * budget, until it reaches a terminal status. This only fires at all when
 * something visits the site (wp-cron's universal limitation) — the
 * Schedules screen says as much and recommends a real system cron hitting
 * wp-cron.php on any site where reliability matters, exactly as every other
 * serious WordPress backup plugin also has to.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Schedule_Scheduler {

	const HOUSEKEEPING_HOOK = 'avix_migration_housekeeping';
	const ADVANCE_JOB_HOOK  = 'avix_migration_advance_job';

	/** Seconds between chained advance-job ticks for a running scheduled job. */
	const ADVANCE_DELAY = 5;

	public static function register_intervals( $schedules ) {
		$schedules['avix_six_hourly'] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 hours', 'avix-migration' ),
		);
		$schedules['avix_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Weekly', 'avix-migration' ),
		);
		$schedules['avix_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Monthly', 'avix-migration' ),
		);
		return $schedules;
	}

	public static function boot() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_intervals' ) );
		add_action( self::HOUSEKEEPING_HOOK, array( __CLASS__, 'run_housekeeping' ) );
		add_action( self::ADVANCE_JOB_HOOK, array( __CLASS__, 'advance_job' ) );
	}

	public static function activate() {
		Avix_Migration_Util_Filesystem::create_storage_dirs();

		if ( ! wp_next_scheduled( self::HOUSEKEEPING_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOUSEKEEPING_HOOK );
		}
	}

	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::HOUSEKEEPING_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOUSEKEEPING_HOOK );
		}
		// User-defined backup schedules are intentionally left in place
		// across a deactivate/reactivate cycle (e.g. a plugin update) —
		// that shouldn't silently cancel someone's nightly backup. Any
		// in-flight advance_job chain naturally stops on its own since
		// nothing re-fires it once deactivated.
	}

	public static function run_housekeeping() {
		self::fail_stuck_jobs();
		self::check_due_schedules();
	}

	/** Jobs with no update in this long are considered abandoned. */
	const STUCK_THRESHOLD = 3600; // 1 hour.

	private static function fail_stuck_jobs() {
		foreach ( Avix_Migration_Job_Store::all_ids() as $id ) {
			$job = Avix_Migration_Job_Store::load( $id );
			if ( ! $job || $job->is_terminal() ) {
				continue;
			}
			if ( ( time() - $job->updated_at ) > self::STUCK_THRESHOLD ) {
				$job->fail( __( 'Job abandoned: no progress for over an hour.', 'avix-migration' ) );
				Avix_Migration_Job_Store::save( $job );
				Avix_Migration_Util_Logger::warning( $job->id, 'Marked stuck job as failed.' );
			}
		}
	}

	private static function check_due_schedules() {
		$now = current_time( 'timestamp' );
		foreach ( self::due_schedule_ids( Avix_Migration_Schedule_Store::all(), $now ) as $id ) {
			self::start_scheduled_backup( $id, Avix_Migration_Schedule_Store::get( $id ) );
		}
	}

	/**
	 * Pure decision logic, deliberately separated from check_due_schedules()'s
	 * side effects (starting jobs, writing state) so it can be exercised
	 * directly with a controlled $now and a fixture schedule list — the
	 * only way to test "is a daily-at-2am schedule due" deterministically,
	 * since PHP has no way to fake the passage of time inside a plain
	 * function call otherwise.
	 *
	 * @param array $schedules Schedule_Store::all()'s shape: id => config.
	 * @param int   $now       Unix timestamp to evaluate against.
	 * @return string[] IDs of enabled schedules that are due right now.
	 */
	public static function due_schedule_ids( array $schedules, $now ) {
		$due = array();
		foreach ( $schedules as $id => $schedule ) {
			if ( ! empty( $schedule['enabled'] ) && self::is_due( $schedule, $now ) ) {
				$due[] = $id;
			}
		}
		return $due;
	}

	private static function is_due( array $schedule, $now ) {
		$interval = Avix_Migration_Schedule_Store::interval_seconds( $schedule['frequency'] );
		$last_run = (int) ( $schedule['last_run_at'] ?? 0 );

		if ( 0 === $last_run ) {
			// Never run before — due once we're inside the configured
			// time-of-day window for daily+ frequencies, or immediately
			// for hourly/6-hourly.
			return self::within_time_window( $schedule, $now );
		}

		if ( ( $now - $last_run ) < $interval ) {
			return false;
		}

		return self::within_time_window( $schedule, $now );
	}

	/**
	 * For daily/weekly/monthly frequencies, only fires within the hour that
	 * contains the configured time-of-day (site timezone) — this is what
	 * makes "daily at 2am" actually mean 2am-ish rather than "once every 24
	 * hours starting from whenever it happened to first run".
	 */
	private static function within_time_window( array $schedule, $now ) {
		if ( in_array( $schedule['frequency'], array( 'hourly', 'avix_six_hourly' ), true ) ) {
			return true;
		}

		list( $target_hour, $target_minute ) = array_map( 'intval', explode( ':', $schedule['time_of_day'] ) );
		$current_hour = (int) gmdate( 'G', $now );

		return $current_hour === $target_hour;
	}

	private static function start_scheduled_backup( $schedule_id, array $schedule ) {
		$meta = array(
			'include_database'         => $schedule['include_database'],
			'include_files'            => $schedule['include_files'],
			'excluded_dirs'             => $schedule['excluded_dirs'],
			'excluded_top_dirs'         => $schedule['excluded_top_dirs'],
			'skip_transients'           => $schedule['skip_transients'],
			'skip_revisions'            => $schedule['skip_revisions'],
			'skip_spam_trash_comments'  => $schedule['skip_spam_trash_comments'],
			'all_tables'                => $schedule['all_tables'],
			'schedule_id'               => $schedule_id,
			'destination_id'            => $schedule['destination_id'],
		);

		$steps = array(
			'Avix_Migration_Job_Steps_Export_Prepare',
			'Avix_Migration_Job_Steps_Export_Scan_Files',
			'Avix_Migration_Job_Steps_Export_Database',
			'Avix_Migration_Job_Steps_Export_Write_Archive',
			'Avix_Migration_Job_Steps_Export_Finalize',
			'Avix_Migration_Job_Steps_Export_Upload',
		);

		$job = Avix_Migration_Job_Store::create( 'export_full', $steps, $meta );

		Avix_Migration_Schedule_Store::record_run( $schedule_id, $job->id );
		Avix_Migration_Util_Logger::info( $job->id, 'Scheduled backup started.', array( 'schedule_id' => $schedule_id, 'schedule_name' => $schedule['name'] ) );

		self::run_and_maybe_chain( $job->id );
	}

	/**
	 * Advances a cron-driven job one Job_Runner budget and, if it's still
	 * not terminal, schedules another tick a few seconds out — this is the
	 * "chain of single events" that lets a backup which takes many minutes
	 * actually finish, instead of only advancing once per hourly tick.
	 */
	public static function advance_job( $job_id ) {
		self::run_and_maybe_chain( $job_id );
	}

	private static function run_and_maybe_chain( $job_id ) {
		$job = Avix_Migration_Job_Store::load( $job_id );
		if ( null === $job ) {
			return;
		}

		$runner = new Avix_Migration_Job_Runner();
		$runner->run( $job );

		if ( ! $job->is_terminal() ) {
			wp_schedule_single_event( time() + self::ADVANCE_DELAY, self::ADVANCE_JOB_HOOK, array( $job_id ) );
			return;
		}

		self::on_job_finished( $job );
	}

	private static function on_job_finished( Avix_Migration_Job $job ) {
		$schedule_id = $job->meta['schedule_id'] ?? null;
		if ( null === $schedule_id ) {
			return; // Not a scheduled job (e.g. a manually-started backup that happened to be cron-advanced somehow) — nothing schedule-specific to do.
		}

		$schedule = Avix_Migration_Schedule_Store::get( $schedule_id );
		if ( null === $schedule ) {
			return; // Schedule was deleted while its job was still running.
		}

		Avix_Migration_Schedule_Store::record_result( $schedule_id, $job->status );
		Avix_Migration_Schedule_Notifier::notify( $schedule, $job );

		if ( Avix_Migration_Job::STATUS_DONE === $job->status ) {
			Avix_Migration_Schedule_Retention::enforce( $schedule_id, $schedule );
		}
	}
}
