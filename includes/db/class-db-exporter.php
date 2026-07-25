<?php
/**
 * Chunked, resumable MySQL dumper. Writes gzipped SQL directly to disk in
 * small batches — each tick() call opens the destination in gzip-append
 * mode, writes one batch's worth of INSERT statements (and a table's
 * CREATE TABLE the first time that table is touched), and closes it again,
 * since a "tick" is a separate PHP request and no file handle can be kept
 * open across them. PHP's gzopen() append mode writes each batch as its own
 * gzip member; concatenated gzip members decompress transparently as one
 * continuous stream (standard gzip behavior, not an Avix-specific trick),
 * so the resulting database.sql.gz is a perfectly ordinary gzip file to
 * anything that reads it back.
 *
 * Uses WordPress's own $wpdb connection (via the global esc_sql() /
 * $wpdb->get_results() etc.) rather than opening a second database
 * connection — wpdb already parses DB_HOST correctly for host:port and
 * host:socket forms, handles charset/collation, and there's no reason to
 * duplicate that.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Db_Exporter {

	const ROWS_PER_BATCH   = 500;
	const INSERT_SIZE_CAP  = 819200; // ~800 KB per individual INSERT statement.

	/**
	 * Builds the ordered table list for a job, once, at the very start of
	 * export. Kept in $cursor so it survives across ticks.
	 *
	 * @param bool $all_tables Export every table in the database, not just
	 *                         ones under this site's prefix (needed when
	 *                         multiple installs share one database).
	 */
	public static function discover_tables( $all_tables = false ) {
		global $wpdb;

		if ( $all_tables ) {
			$tables = $wpdb->get_col( 'SHOW TABLES' );
		} else {
			$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
			$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		}

		return array_values( array_filter( (array) $tables ) );
	}

	/**
	 * Approximate total row count across every table, for the progress
	 * bar's rows_total — deliberately uses information_schema's estimate
	 * rather than COUNT(*), which can be slow on large InnoDB tables and
	 * isn't worth the accuracy for a progress percentage.
	 */
	public static function estimate_total_rows( array $tables ) {
		global $wpdb;
		$total = 0;
		foreach ( $tables as $table ) {
			$estimate = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT TABLE_ROWS FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = %s',
					$table
				)
			);
			$total += (int) $estimate;
		}
		return $total;
	}

	/**
	 * Does one bounded batch of export work: either the current table's
	 * CREATE TABLE statement (first visit) or up to ROWS_PER_BATCH data
	 * rows, then writes the resulting SQL as one gzip member.
	 *
	 * @param array  $cursor          By reference. Shape: tables[],
	 *                                table_index, pk_column, binary_columns,
	 *                                last_pk, offset, wrote_schema,
	 *                                rows_done_current_table.
	 * @param string $gz_path         Destination .sql.gz file (append mode).
	 * @param array  $where_overrides table name => extra SQL WHERE fragment
	 *                                (no leading AND/WHERE) implementing the
	 *                                wizard's row-skip toggles — e.g. "skip
	 *                                transients" narrows the options table,
	 *                                "skip revisions" narrows posts, etc.
	 *                                Combined with the pagination clause via
	 *                                AND; never applied to the schema dump.
	 * @return array{done:bool, rows_written:int, table:string|null}
	 */
	public static function tick( array &$cursor, $gz_path, array $where_overrides = array() ) {
		global $wpdb;

		if ( empty( $cursor['tables'] ) ) {
			return array( 'done' => true, 'rows_written' => 0, 'table' => null );
		}

		if ( $cursor['table_index'] >= count( $cursor['tables'] ) ) {
			return array( 'done' => true, 'rows_written' => 0, 'table' => null );
		}

		$table = $cursor['tables'][ $cursor['table_index'] ];

		if ( empty( $cursor['wrote_schema'] ) ) {
			self::write_chunk( $gz_path, self::build_schema_sql( $table ) );
			$cursor['wrote_schema']            = true;
			$cursor['pk_column']               = self::single_column_pk( $table );
			$cursor['binary_columns']          = self::binary_columns( $table );
			$cursor['last_pk']                 = null;
			$cursor['offset']                  = 0;
			$cursor['rows_done_current_table'] = 0;
			return array( 'done' => false, 'rows_written' => 0, 'table' => $table );
		}

		$extra_where = $where_overrides[ $table ] ?? '';
		$rows        = self::fetch_batch( $table, $cursor, $extra_where );

		if ( empty( $rows ) ) {
			// Table exhausted — advance to the next one.
			$cursor['table_index']++;
			$cursor['wrote_schema'] = false;
			return array(
				'done'         => $cursor['table_index'] >= count( $cursor['tables'] ),
				'rows_written' => 0,
				'table'        => $table,
			);
		}

		$sql = self::build_insert_sql( $table, $rows, $cursor['binary_columns'] );
		self::write_chunk( $gz_path, $sql );

		$last_row = end( $rows );
		if ( $cursor['pk_column'] && isset( $last_row[ $cursor['pk_column'] ] ) ) {
			$cursor['last_pk'] = $last_row[ $cursor['pk_column'] ];
		} else {
			$cursor['offset'] += count( $rows );
		}
		$cursor['rows_done_current_table'] += count( $rows );

		return array( 'done' => false, 'rows_written' => count( $rows ), 'table' => $table );
	}

	/**
	 * Verified empirically (not just per the gzip spec): PHP's gzopen()+
	 * gzread() loop correctly traverses concatenated gzip members exactly
	 * like system `gunzip` does. PHP's gzdecode() does NOT — it silently
	 * decodes only the FIRST member and returns success, which would
	 * truncate every database dump after its first ~800 KB batch with no
	 * error at all. The importer (Milestone 3) MUST decompress
	 * database.sql.gz via gzopen()+gzread(), never gzdecode()/gzinflate()
	 * on the raw file contents.
	 */
	private static function write_chunk( $gz_path, $sql ) {
		if ( '' === $sql ) {
			return;
		}
		$fp = gzopen( $gz_path, 'ab9' );
		if ( ! $fp ) {
			throw new RuntimeException( 'Could not open database export file for writing: ' . $gz_path );
		}
		gzwrite( $fp, $sql );
		gzclose( $fp );
	}

	private static function build_schema_sql( $table ) {
		global $wpdb;
		$row = $wpdb->get_row( 'SHOW CREATE TABLE `' . self::esc_ident( $table ) . '`', ARRAY_N );
		$create = $row[1] ?? '';

		return "\n-- Table: {$table}\n"
			. 'DROP TABLE IF EXISTS `' . self::esc_ident( $table ) . "`;\n"
			. $create . ";\n\n";
	}

	/**
	 * Returns the single-column primary key name for keyset pagination, or
	 * null if the table has a composite key or none at all — callers fall
	 * back to LIMIT/OFFSET in that case.
	 *
	 * Public: also reused by Db_Search_Replace's table-walking step, which
	 * needs the same schema introspection for its own pagination and to
	 * know which column to leave untouched.
	 */
	public static function single_column_pk( $table ) {
		global $wpdb;
		$cols = $wpdb->get_results( 'SHOW COLUMNS FROM `' . self::esc_ident( $table ) . '`', ARRAY_A );
		$pk_cols = array_values(
			array_filter(
				(array) $cols,
				function ( $c ) {
					return isset( $c['Key'] ) && 'PRI' === $c['Key'];
				}
			)
		);
		return 1 === count( $pk_cols ) ? $pk_cols[0]['Field'] : null;
	}

	/** Public for the same reason as single_column_pk() above. */
	public static function binary_columns( $table ) {
		global $wpdb;
		$cols = $wpdb->get_results( 'SHOW COLUMNS FROM `' . self::esc_ident( $table ) . '`', ARRAY_A );
		$binary = array();
		foreach ( (array) $cols as $c ) {
			if ( preg_match( '/(blob|binary)/i', $c['Type'] ) ) {
				$binary[] = $c['Field'];
			}
		}
		return $binary;
	}

	private static function fetch_batch( $table, array $cursor, $extra_where = '' ) {
		global $wpdb;
		$ident = '`' . self::esc_ident( $table ) . '`';
		$and_extra = '' !== $extra_where ? " AND ({$extra_where})" : '';

		if ( $cursor['pk_column'] ) {
			$pk = '`' . self::esc_ident( $cursor['pk_column'] ) . '`';
			if ( null === $cursor['last_pk'] ) {
				$where = '' !== $extra_where ? "WHERE ({$extra_where})" : '';
				$sql   = "SELECT * FROM {$ident} {$where} ORDER BY {$pk} ASC LIMIT " . self::ROWS_PER_BATCH;
			} else {
				$sql = $wpdb->prepare(
					"SELECT * FROM {$ident} WHERE {$pk} > %s{$and_extra} ORDER BY {$pk} ASC LIMIT " . self::ROWS_PER_BATCH,
					$cursor['last_pk']
				);
			}
		} else {
			// LIMIT/OFFSET fallback (no single-column PK): a row-skip filter
			// here would shift which rows subsequent offsets land on in a
			// way that's still correct (offset counts *matching* rows,
			// MySQL applies WHERE before LIMIT/OFFSET) but slower on large
			// tables — acceptable given this path is already the documented
			// slow-path fallback.
			$where = '' !== $extra_where ? "WHERE ({$extra_where})" : '';
			$sql   = "SELECT * FROM {$ident} {$where} LIMIT " . (int) $cursor['offset'] . ',' . self::ROWS_PER_BATCH;
		}

		return (array) $wpdb->get_results( $sql, ARRAY_A );
	}

	private static function build_insert_sql( $table, array $rows, array $binary_columns ) {
		if ( empty( $rows ) ) {
			return '';
		}

		$columns   = array_keys( $rows[0] );
		$col_list  = '`' . implode( '`, `', array_map( array( __CLASS__, 'esc_ident' ), $columns ) ) . '`';
		$ident     = '`' . self::esc_ident( $table ) . '`';
		$prefix    = "INSERT INTO {$ident} ({$col_list}) VALUES\n";

		$sql        = '';
		$value_rows = array();
		$size       = strlen( $prefix );

		foreach ( $rows as $row ) {
			$tuple = self::format_tuple( $row, $binary_columns );
			$len   = strlen( $tuple );

			if ( ! empty( $value_rows ) && ( $size + $len ) > self::INSERT_SIZE_CAP ) {
				$sql       .= $prefix . implode( ",\n", $value_rows ) . ";\n";
				$value_rows = array();
				$size       = strlen( $prefix );
			}

			$value_rows[] = $tuple;
			$size        += $len;
		}

		if ( ! empty( $value_rows ) ) {
			$sql .= $prefix . implode( ",\n", $value_rows ) . ";\n";
		}

		return $sql;
	}

	private static function format_tuple( array $row, array $binary_columns ) {
		$values = array();
		foreach ( $row as $col => $value ) {
			if ( null === $value ) {
				$values[] = 'NULL';
			} elseif ( in_array( $col, $binary_columns, true ) ) {
				// Hex literal — immune to quoting/escaping issues entirely,
				// which matters because $wpdb->get_results() returns binary
				// column values as plain PHP strings that may contain any
				// byte sequence, including unescaped quotes and NUL bytes.
				$values[] = '' === $value ? "''" : '0x' . bin2hex( $value );
			} else {
				$values[] = "'" . esc_sql( $value ) . "'";
			}
		}
		return '(' . implode( ', ', $values ) . ')';
	}

	/**
	 * Backtick-identifier escaping (table/column names) — distinct from
	 * value escaping. WordPress core tables never contain backticks in
	 * identifiers, but third-party plugins' custom table names are outside
	 * our control, so this is defensive rather than load-bearing.
	 */
	private static function esc_ident( $ident ) {
		return str_replace( '`', '``', $ident );
	}
}
