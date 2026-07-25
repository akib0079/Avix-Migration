<?php
/**
 * Sends the success/failure email for a scheduled backup, per that
 * schedule's own notify_on_success/notify_on_failure toggles — a manual
 * backup started from the Backup wizard never emails anyone, since an
 * operator watching the progress bar doesn't need an email about the thing
 * they're already looking at.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Schedule_Notifier {

	public static function notify( array $schedule, Avix_Migration_Job $job ) {
		$is_success = Avix_Migration_Job::STATUS_DONE === $job->status;

		if ( $is_success && empty( $schedule['notify_on_success'] ) ) {
			return;
		}
		if ( ! $is_success && empty( $schedule['notify_on_failure'] ) ) {
			return;
		}

		$to = ! empty( $schedule['notify_email'] ) ? $schedule['notify_email'] : get_option( 'admin_email' );
		if ( ! is_email( $to ) ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		$subject = $is_success
			? sprintf( '[%s] Backup "%s" completed', $site_name, $schedule['name'] )
			: sprintf( '[%s] Backup "%s" FAILED', $site_name, $schedule['name'] );

		$log_lines = array_slice( Avix_Migration_Util_Logger::tail( $job->id, 15 ), 0, 15 );
		$log_text  = implode(
			"\n",
			array_map(
				function ( $entry ) {
					return sprintf( '[%s] %s: %s', gmdate( 'H:i:s', $entry['time'] ), strtoupper( $entry['level'] ), $entry['message'] );
				},
				$log_lines
			)
		);

		$body = $is_success
			? sprintf(
				"Backup \"%s\" finished successfully.\n\nFile: %s\nSize: %s\n\nRecent log:\n%s",
				$schedule['name'],
				$job->meta['archive_filename'] ?? '—',
				Avix_Migration_Util_Filesystem::human_size( $job->totals['bytes_done'] ),
				$log_text
			)
			: sprintf(
				"Backup \"%s\" failed.\n\nError: %s\n\nRecent log:\n%s",
				$schedule['name'],
				$job->error,
				$log_text
			);

		wp_mail( $to, $subject, $body );
	}
}
