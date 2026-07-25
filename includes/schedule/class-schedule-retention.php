<?php
/**
 * Applies a schedule's retention policy after one of its backups finishes
 * successfully, scoped to just the archives THIS schedule created (via a
 * schedule_id tag recorded in the manifest at export time) — never touching
 * manually-created backups or another schedule's archives.
 *
 * "Keep last N" acts as a protective floor, not a simple count trigger: the
 * N most recent archives are NEVER deleted regardless of age. "Delete
 * older than X days" is then evaluated only against whatever sits beyond
 * that floor — so with keep_last=2 and older_than_days=15, a 20-day-old
 * archive beyond the floor gets pruned, but a 10-day-old one beyond the
 * floor survives (not old enough yet), and the 2 newest survive no matter
 * how old they are. This is the same semantic restic, borg, and
 * UpdraftPlus all use — "always keep my last N copies" is a stronger,
 * more useful guarantee than "N and days act as independent AND filters",
 * which could otherwise delete an operator's only recent backup if it
 * happened to be older than the day cutoff.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Schedule_Retention {

	public static function enforce( $schedule_id, array $schedule ) {
		$keep_last  = (int) ( $schedule['retention_keep_last'] ?? 0 );
		$older_days = (int) ( $schedule['retention_older_than_days'] ?? 0 );

		if ( $keep_last <= 0 && $older_days <= 0 ) {
			return array(); // No retention configured — keep everything.
		}

		$mine = self::archives_for_schedule( $schedule_id );

		// Newest first (list_all() already sorts this way), so anything
		// beyond index $keep_last is a deletion candidate under the
		// keep-last-N rule.
		$candidates_by_count = $keep_last > 0 ? array_slice( $mine, $keep_last ) : $mine;

		$cutoff = $older_days > 0 ? ( time() - $older_days * DAY_IN_SECONDS ) : null;

		$deleted = array();
		foreach ( $candidates_by_count as $archive ) {
			if ( null !== $cutoff && $archive['created_at'] >= $cutoff ) {
				continue; // Not old enough yet under the day-based rule.
			}
			if ( Avix_Migration_Archive_Store::delete( $archive['filename'] ) ) {
				$deleted[] = $archive['filename'];
			}
		}

		if ( ! empty( $deleted ) ) {
			Avix_Migration_Util_Logger::info( 'plugin', 'Retention policy deleted old backups.', array( 'schedule_id' => $schedule_id, 'deleted' => $deleted ) );
		}

		return $deleted;
	}

	/** Archives whose manifest records this exact schedule_id, newest first. */
	private static function archives_for_schedule( $schedule_id ) {
		$mine = array();
		foreach ( Avix_Migration_Archive_Store::list_all() as $archive ) {
			$manifest = Avix_Migration_Archive_Reader::read_manifest_only( $archive['path'] );
			if ( is_array( $manifest ) && ( $manifest['schedule_id'] ?? null ) === $schedule_id ) {
				$mine[] = $archive;
			}
		}
		return $mine;
	}
}
