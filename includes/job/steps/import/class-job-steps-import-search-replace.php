<?php
/**
 * After the raw SQL replay, this walks every table under the (now-live)
 * target prefix and rewrites the source site's URL/paths to the target's —
 * the step that actually makes a migrated site work on its new domain.
 * Runs on every table generally, not just the well-known WP ones, since a
 * third-party plugin's custom table is exactly as likely to store a URL as
 * wp_options is; the tradeoff is more rows touched than a
 * known-tables-only shortcut would touch, which is the right tradeoff for
 * a migration tool where a missed rewrite means broken links, not a
 * performance nit.
 *
 * Skips the primary key column (never rewritten) and binary/blob columns
 * entirely (treating arbitrary binary data as text risks corrupting it —
 * Db_Search_Replace::replace() is a text/serialization/JSON transform, not
 * a binary-safe one).
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Import_Search_Replace extends Avix_Migration_Job_Step {

	const ROWS_PER_BATCH = 200;

	public function label() {
		return __( 'Updating links and paths', 'avix-migration' );
	}

	public function execute( Avix_Migration_Job $job ) {
		if ( empty( $job->meta['has_database'] ) ) {
			return Avix_Migration_Job_Step_Result::step_complete( __( 'No database to update.', 'avix-migration' ) );
		}

		$cursor = $this->cursor( $job );

		if ( ! isset( $cursor['tables'] ) ) {
			$cursor['tables']      = Avix_Migration_Db_Exporter::discover_tables( false );
			$cursor['table_index'] = 0;
			$cursor['replacements'] = 0;
			$this->set_cursor( $job, $cursor );
			return Avix_Migration_Job_Step_Result::cont( __( 'Preparing to update links…', 'avix-migration' ) );
		}

		if ( $cursor['table_index'] >= count( $cursor['tables'] ) ) {
			Avix_Migration_Util_Logger::info( $job->id, 'Link/path rewrite complete.', array( 'replacements' => $cursor['replacements'] ) );
			return Avix_Migration_Job_Step_Result::step_complete(
				sprintf(
					/* translators: %d: number of values changed */
					__( 'Updated %d values.', 'avix-migration' ),
					$cursor['replacements']
				)
			);
		}

		$table = $cursor['tables'][ $cursor['table_index'] ];

		if ( ! isset( $cursor['pk_column'] ) || null === $cursor['pk_column'] || ! array_key_exists( 'columns', $cursor ) ) {
			$cursor['pk_column']      = Avix_Migration_Db_Exporter::single_column_pk( $table );
			$cursor['binary_columns'] = Avix_Migration_Db_Exporter::binary_columns( $table );
			$cursor['columns']        = $this->all_columns( $table );
			$cursor['last_pk']        = null;
			$cursor['offset']         = 0;
		}

		if ( null === $cursor['pk_column'] ) {
			// No single-column PK to safely target rows for UPDATE by — skip
			// this table rather than risk rewriting the wrong row. Logged
			// so it's visible, not silently dropped.
			Avix_Migration_Util_Logger::warning( $job->id, 'Skipped table with no single-column primary key.', array( 'table' => $table ) );
			$cursor['table_index']++;
			unset( $cursor['pk_column'], $cursor['columns'], $cursor['binary_columns'] );
			$this->set_cursor( $job, $cursor );
			return Avix_Migration_Job_Step_Result::cont( __( 'Skipped a table.', 'avix-migration' ) );
		}

		$rows = $this->fetch_batch( $table, $cursor );

		if ( empty( $rows ) ) {
			$cursor['table_index']++;
			unset( $cursor['pk_column'], $cursor['columns'], $cursor['binary_columns'] );
			$this->set_cursor( $job, $cursor );
			return Avix_Migration_Job_Step_Result::cont(
				sprintf(
					/* translators: %s: table name */
					__( 'Finished table %s.', 'avix-migration' ),
					$table
				)
			);
		}

		global $wpdb;
		$pairs = $this->pairs( $job );
		$stats = array( 'replacements' => 0, 'warnings' => array() );

		foreach ( $rows as $row ) {
			// Per-row containment, catching Throwable (so Error, not just
			// Exception). By the time this step runs the database has
			// ALREADY been replayed — aborting here would strand the site
			// half-migrated, with the new content in place but its URLs
			// still pointing at the source. One unrewritable row is a
			// warning; losing the whole rewrite pass is a broken site.
			try {
				$changed = array();
				foreach ( $row as $col => $value ) {
					if ( $col === $cursor['pk_column'] || in_array( $col, $cursor['binary_columns'], true ) ) {
						continue;
					}
					$new_value = Avix_Migration_Db_Search_Replace::replace( $value, $pairs, $stats );
					if ( $new_value !== $value ) {
						$changed[ $col ] = $new_value;
					}
				}

				if ( ! empty( $changed ) ) {
					$wpdb->update( $table, $changed, array( $cursor['pk_column'] => $row[ $cursor['pk_column'] ] ) );
				}
			} catch ( \Throwable $e ) {
				$stats['warnings'][] = sprintf(
					'Row %s in %s could not be rewritten and was left unchanged: %s',
					$row[ $cursor['pk_column'] ],
					$table,
					$e->getMessage()
				);
			}

			$cursor['last_pk'] = $row[ $cursor['pk_column'] ];
		}

		$cursor['replacements'] += $stats['replacements'];
		foreach ( $stats['warnings'] as $warning ) {
			Avix_Migration_Util_Logger::warning( $job->id, $warning, array( 'table' => $table ) );
			// Also surface on the success screen, not just buried in the
			// log: "some URLs were left pointing at the old site" is
			// something the operator has to act on, and nobody opens the
			// log after an import that reported success. Capped so a
			// pathological table can't bloat the job file.
			if ( count( $job->meta['warnings'] ?? array() ) < 25 ) {
				$job->meta['warnings'][] = $warning;
			}
		}

		$this->set_cursor( $job, $cursor );

		return Avix_Migration_Job_Step_Result::cont(
			sprintf(
				/* translators: 1: table name, 2: replacement count so far */
				__( 'Updating %1$s… (%2$s values changed so far)', 'avix-migration' ),
				$table,
				number_format_i18n( $cursor['replacements'] )
			)
		);
	}

	/**
	 * The (from => to) URL/path pairs for this migration — built once per
	 * tick rather than cached in the cursor since it's cheap to compute and
	 * keeping it out of persisted job state avoids ever writing the
	 * source/target URLs into the job file more times than necessary.
	 */
	private function pairs( Avix_Migration_Job $job ) {
		$source_site = $job->meta['manifest']['site'] ?? array();
		$target      = Avix_Migration_Util_Sysinfo::snapshot();

		return Avix_Migration_Db_Search_Replace::build_pairs(
			$source_site['site_url'] ?? '',
			$target['site_url'],
			$source_site['abspath'] ?? '',
			$target['abspath']
		);
	}

	private function all_columns( $table ) {
		global $wpdb;
		$cols = $wpdb->get_results( 'SHOW COLUMNS FROM `' . str_replace( '`', '``', $table ) . '`', ARRAY_A );
		return array_column( (array) $cols, 'Field' );
	}

	private function fetch_batch( $table, array $cursor ) {
		global $wpdb;
		$ident = '`' . str_replace( '`', '``', $table ) . '`';
		$pk    = '`' . str_replace( '`', '``', $cursor['pk_column'] ) . '`';

		if ( null === $cursor['last_pk'] ) {
			$sql = "SELECT * FROM {$ident} ORDER BY {$pk} ASC LIMIT " . self::ROWS_PER_BATCH;
		} else {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$ident} WHERE {$pk} > %s ORDER BY {$pk} ASC LIMIT " . self::ROWS_PER_BATCH,
				$cursor['last_pk']
			);
		}

		return (array) $wpdb->get_results( $sql, ARRAY_A );
	}
}
