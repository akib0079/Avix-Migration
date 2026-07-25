<?php
/**
 * Rollback point for a database import: before replay touches anything,
 * every existing table under the target's OWN prefix is renamed aside
 * (RENAME TABLE is near-instant — no row copy) rather than dropped. If the
 * import fails partway through, restore() renames them straight back,
 * undoing exactly what replay did. If it succeeds, the renamed-aside tables
 * are simply left in place under their `avix_rb_...` names — cheap
 * insurance the operator can manually drop later, or that a future
 * milestone's retention sweep can clean up.
 *
 * DDL in MySQL auto-commits and can't be wrapped in a transaction, which is
 * exactly why this exists: a crash mid-replay leaves the target database in
 * a partially-replaced state with no way to roll back at the SQL level —
 * renaming instead of dropping is what makes "undo" possible at all.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Rollback_Manager {

	const BACKUP_INFIX = 'avix_rb_';

	/**
	 * Renames every table under $wpdb->prefix aside. Safe to call even when
	 * some of those tables don't exist yet (a bare-DB target) — it simply
	 * renames whatever is actually there.
	 *
	 * @return array<string,string> original table name => renamed-aside name.
	 */
	public static function snapshot( $timestamp = null ) {
		global $wpdb;
		$timestamp = $timestamp ?: time();

		$existing = $wpdb->get_col(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' )
		);

		$map = array();
		foreach ( (array) $existing as $table ) {
			$backup_name = self::BACKUP_INFIX . $timestamp . '_' . $table;
			// MySQL identifiers cap at 64 chars — truncate the middle
			// (table name) rather than the infix/timestamp if this would
			// overflow, since the infix+timestamp is what makes it findable.
			if ( strlen( $backup_name ) > 64 ) {
				$budget      = 64 - strlen( self::BACKUP_INFIX . $timestamp . '_' );
				$backup_name = self::BACKUP_INFIX . $timestamp . '_' . substr( $table, 0, max( 1, $budget ) );
			}

			// If the backup name is already taken, this table was snapshotted
			// by an earlier attempt. Renaming again would move a
			// partially-imported table over the good backup and destroy the
			// only clean copy of the operator's data — so keep the existing
			// backup and just record the mapping. (The lock in Job_Runner
			// should prevent a concurrent double-run reaching here at all;
			// this guards the sequential retry case, and refuses to trade a
			// valid rollback point for a failed rename either way.)
			$backup_exists = (bool) $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $backup_name ) )
			);
			if ( $backup_exists ) {
				$map[ $table ] = $backup_name;
				continue;
			}

			$ok = $wpdb->query(
				'RENAME TABLE `' . self::esc_ident( $table ) . '` TO `' . self::esc_ident( $backup_name ) . '`'
			);
			if ( false !== $ok ) {
				$map[ $table ] = $backup_name;
			}
		}

		return $map;
	}

	/**
	 * Every rollback snapshot currently sitting in the database, newest
	 * first, as timestamp => table count. Snapshots are deliberately kept
	 * after a successful import (they're the undo), so without a way to see
	 * and clear them they accumulate silently and the operator has no idea
	 * how much space they're holding.
	 *
	 * @return array<int,int> unix timestamp => number of tables in that snapshot.
	 */
	public static function list_snapshots() {
		global $wpdb;

		$tables = $wpdb->get_col(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::BACKUP_INFIX ) . '%' )
		);

		$snapshots = array();
		foreach ( (array) $tables as $table ) {
			if ( preg_match( '/^' . preg_quote( self::BACKUP_INFIX, '/' ) . '(\d+)_/', $table, $m ) ) {
				$ts = (int) $m[1];
				$snapshots[ $ts ] = ( $snapshots[ $ts ] ?? 0 ) + 1;
			}
		}

		krsort( $snapshots );
		return $snapshots;
	}

	/**
	 * Rebuilds a snapshot's original=>backup map purely from the table names
	 * in the database, so a snapshot can be restored WITHOUT the job that
	 * created it. That matters: if an import dies badly the job file may be
	 * gone, cleared, or simply unreachable from the UI — and the snapshot is
	 * the operator's only copy of their real data. Recovery must not depend
	 * on the thing that just failed.
	 *
	 * @return array<string,string> original table name => backup table name.
	 */
	public static function map_for_snapshot( $timestamp ) {
		global $wpdb;

		$timestamp = (int) $timestamp;
		$prefix    = self::BACKUP_INFIX . $timestamp . '_';

		$tables = $wpdb->get_col(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' )
		);

		$map = array();
		foreach ( (array) $tables as $backup_name ) {
			if ( 0 !== strpos( $backup_name, $prefix ) ) {
				continue;
			}
			$original = substr( $backup_name, strlen( $prefix ) );
			if ( '' !== $original ) {
				$map[ $original ] = $backup_name;
			}
		}

		return $map;
	}

	/**
	 * Puts a snapshot back: whatever currently occupies each original table
	 * name is dropped and the backup renamed into its place. This is the
	 * "my import broke the site, give me my data back" path.
	 *
	 * @return array{restored:string[], errors:string[]}
	 */
	public static function restore_snapshot( $timestamp ) {
		return self::restore( self::map_for_snapshot( $timestamp ) );
	}

	/**
	 * Drops one snapshot's tables. Irreversible — this is the operator
	 * explicitly discarding an undo point they no longer want.
	 *
	 * @param int $timestamp Which snapshot, from list_snapshots().
	 * @return int Tables dropped.
	 */
	public static function purge_snapshot( $timestamp ) {
		global $wpdb;

		$timestamp = (int) $timestamp;
		if ( $timestamp <= 0 ) {
			return 0;
		}

		$tables = $wpdb->get_col(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::BACKUP_INFIX . $timestamp . '_' ) . '%' )
		);

		$dropped = 0;
		foreach ( (array) $tables as $table ) {
			// Belt-and-braces: only ever drop something that still matches
			// this class's own naming scheme, so a malformed timestamp can
			// never widen into dropping real site tables.
			if ( 0 !== strpos( $table, self::BACKUP_INFIX . $timestamp . '_' ) ) {
				continue;
			}
			if ( false !== $wpdb->query( 'DROP TABLE IF EXISTS `' . self::esc_ident( $table ) . '`' ) ) {
				$dropped++;
			}
		}

		return $dropped;
	}

	/**
	 * Undoes a snapshot: drops whatever now sits at each original table
	 * name (the partially- or fully-imported replacement) and renames the
	 * backed-up table back into place.
	 *
	 * @param array<string,string> $map From snapshot().
	 */
	public static function restore( array $map ) {
		global $wpdb;
		$restored = array();
		$errors   = array();

		foreach ( $map as $original => $backup_name ) {
			$backup_exists = (bool) $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $backup_name )
			);
			if ( ! $backup_exists ) {
				$errors[] = $original;
				continue;
			}

			$wpdb->query( 'DROP TABLE IF EXISTS `' . self::esc_ident( $original ) . '`' );
			$ok = $wpdb->query(
				'RENAME TABLE `' . self::esc_ident( $backup_name ) . '` TO `' . self::esc_ident( $original ) . '`'
			);

			if ( false !== $ok ) {
				$restored[] = $original;
			} else {
				$errors[] = $original;
			}
		}

		return array( 'restored' => $restored, 'errors' => $errors );
	}

	/**
	 * Permanently discards a snapshot without restoring it — used once an
	 * import is confirmed good and the operator no longer needs the
	 * pre-import state kept around.
	 */
	public static function discard( array $map ) {
		global $wpdb;
		foreach ( $map as $backup_name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . self::esc_ident( $backup_name ) . '`' );
		}
	}

	private static function esc_ident( $ident ) {
		return str_replace( '`', '``', $ident );
	}
}
