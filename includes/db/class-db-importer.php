<?php
/**
 * Chunked, resumable SQL replay against a flat (already-decompressed) .sql
 * file, rewriting the source site's table prefix to the target's as each
 * statement executes.
 *
 * Statement splitting is a hand-rolled single-quote-aware scanner rather
 * than a naive split on ";\n" — post content and other text columns can
 * legitimately contain that exact byte sequence, and naively splitting on
 * it there would execute a truncated, broken statement. The scanner only
 * needs to track single-quoted strings with backslash-escaping (never
 * double-quoted strings or doubled-quote escaping), because this plugin's
 * own exporter is the only thing that ever produces the file being read
 * here, and it always uses addslashes()-style single-quote + backslash
 * escaping (see Db_Exporter::format_tuple()).
 *
 * Reads a growing window from the current byte offset rather than the
 * whole file, so a multi-GB dump never sits fully in memory; the window
 * only needs to be larger than the single biggest statement, which starts
 * at 4 MB (comfortably above the exporter's own ~800 KB per-INSERT cap)
 * and doubles up to a 64 MB safety cap if a single statement is unusually
 * large.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Db_Importer {

	const BASE_WINDOW      = 4194304;   // 4 MB
	const MAX_WINDOW       = 67108864;  // 64 MB
	const MAX_STATEMENTS_PER_TICK = 200;
	const MAX_BYTES_PER_TICK      = 4194304; // 4 MB of SQL text executed per tick.

	/**
	 * @param array  $cursor         By reference: { byte_offset }.
	 * @param string $sql_path       Flat, decompressed .sql file.
	 * @param string $source_prefix  Table prefix recorded in the archive's manifest.
	 * @param string $target_prefix  This site's own $wpdb->prefix.
	 * @return array{done:bool, statements_executed:int, bytes_advanced:int, error:string|null}
	 */
	public static function tick( array &$cursor, $sql_path, $source_prefix, $target_prefix ) {
		global $wpdb;

		if ( ! isset( $cursor['byte_offset'] ) ) {
			$cursor['byte_offset'] = 0;
		}

		clearstatcache( true, $sql_path );
		$total_size = filesize( $sql_path );
		if ( false === $total_size || $cursor['byte_offset'] >= $total_size ) {
			return array( 'done' => true, 'statements_executed' => 0, 'bytes_advanced' => 0, 'error' => null );
		}

		$window_start = $cursor['byte_offset'];
		$window_size  = self::BASE_WINDOW;
		$statements   = array();
		$consumed     = 0;
		$buffer       = '';
		$reached_real_eof = false;

		// Grow the window until we find at least one complete statement, or
		// hit the safety cap (a real parse failure at that point, not just
		// "statement is unusually large").
		while ( true ) {
			$fp = fopen( $sql_path, 'rb' );
			fseek( $fp, $window_start, SEEK_SET );
			$buffer = fread( $fp, $window_size );
			fclose( $fp );

			$reached_real_eof = ( $window_start + strlen( $buffer ) ) >= $total_size;

			$parsed = self::extract_complete_statements( $buffer );
			if ( ! empty( $parsed['statements'] ) || $window_size >= self::MAX_WINDOW || $reached_real_eof ) {
				// Either we found something, or we've read every remaining
				// byte of the file into this window (nothing left to grow
				// into), or we've hit the hard cap — stop growing either way.
				$statements = $parsed['statements'];
				$consumed   = $parsed['consumed_bytes'];
				break;
			}
			$window_size *= 2;
		}

		if ( empty( $statements ) ) {
			if ( $window_size >= self::MAX_WINDOW && ! $reached_real_eof ) {
				return array(
					'done' => true,
					'statements_executed' => 0,
					'bytes_advanced' => 0,
					'error' => __( 'A single SQL statement exceeded the 64 MB safety limit — the dump may be corrupt.', 'avix-migration' ),
				);
			}
			// Reached real EOF with no further statement in the tail — if
			// what's left is genuinely empty/whitespace, we're done; if
			// there's non-blank content with no terminator, the dump is
			// missing its final ";\n" — surface that as a warning-free
			// completion rather than looping forever either way.
			return array( 'done' => true, 'statements_executed' => 0, 'bytes_advanced' => 0, 'error' => null );
		}

		$executed    = 0;
		$bytes_this_tick = 0;

		foreach ( $statements as $statement ) {
			if ( $executed >= self::MAX_STATEMENTS_PER_TICK || $bytes_this_tick >= self::MAX_BYTES_PER_TICK ) {
				break;
			}

			$trimmed = trim( $statement );
			if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) ) {
				$executed++; // Comment-only "statement" — nothing to execute, still counts as consumed.
				continue;
			}

			$rewritten = self::rewrite_prefix( $trimmed, $source_prefix, $target_prefix );

			$wpdb->suppress_errors( true );
			$wpdb->query( $rewritten );
			$wpdb->suppress_errors( false );

			if ( ! empty( $wpdb->last_error ) ) {
				return array(
					'done' => false,
					'statements_executed' => $executed,
					'bytes_advanced' => 0, // Don't advance past a failed statement — surfacing the failure is more useful than silently skipping it.
					'error' => sprintf(
						/* translators: %s: database error message */
						__( 'Database error during import: %s', 'avix-migration' ),
						$wpdb->last_error
					),
				);
			}

			$executed++;
			$bytes_this_tick += strlen( $statement );
		}

		// Only advance the cursor by what we actually executed, not by
		// everything the window happened to parse — if the tick's caps cut
		// execution short partway through $statements, the next tick must
		// re-read starting from the first un-executed statement.
		$advance = 0;
		for ( $i = 0; $i < $executed; $i++ ) {
			$advance += strlen( $statements[ $i ] );
		}
		$cursor['byte_offset'] += $advance;

		// Done only when every statement this window found was executed
		// AND this window's read reached the real end of the file AND the
		// unconsumed tail beyond the last statement is nothing but
		// whitespace (a trailing newline, typically) — not merely when
		// byte_offset reaches total_size, which a whitespace-only tail
		// would otherwise never quite do, causing an infinite "not done".
		$tail = substr( $buffer, $consumed );
		$done = $reached_real_eof && ( $executed === count( $statements ) ) && ( '' === trim( $tail ) );

		return array(
			'done'                => $done,
			'statements_executed' => $executed,
			'bytes_advanced'      => $advance,
			'error'               => null,
		);
	}

	/**
	 * Scans $buffer from the start, tracking single-quoted-string state
	 * with backslash-escaping, and returns every statement whose
	 * terminating ";\n" was found completely within the buffer. Each
	 * returned statement STRING INCLUDES its trailing ";\n" so byte-offset
	 * accounting stays exact.
	 *
	 * @return array{statements:string[], consumed_bytes:int}
	 */
	private static function extract_complete_statements( $buffer ) {
		$len          = strlen( $buffer );
		$statements   = array();
		$in_string    = false;
		$stmt_start   = 0;
		$consumed     = 0;

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $buffer[ $i ];

			if ( $in_string ) {
				if ( '\\' === $ch ) {
					$i++; // Skip the escaped character entirely, whatever it is.
					continue;
				}
				if ( "'" === $ch ) {
					$in_string = false;
				}
				continue;
			}

			if ( "'" === $ch ) {
				$in_string = true;
				continue;
			}

			if ( ';' === $ch && $i + 1 < $len && "\n" === $buffer[ $i + 1 ] ) {
				$end = $i + 2; // Include the ";\n".
				$statements[] = substr( $buffer, $stmt_start, $end - $stmt_start );
				$consumed     = $end;
				$stmt_start   = $end;
			}
		}

		return array( 'statements' => $statements, 'consumed_bytes' => $consumed );
	}

	/**
	 * Rewrites backtick-quoted identifiers beginning with $source_prefix to
	 * begin with $target_prefix instead — only backtick-quoted tokens are
	 * touched, so a string literal that happens to contain the prefix text
	 * (e.g. a post mentioning "wp_posts" in its content) is never altered.
	 */
	public static function rewrite_prefix( $statement, $source_prefix, $target_prefix ) {
		if ( '' === $source_prefix || $source_prefix === $target_prefix ) {
			return $statement;
		}
		$pattern = '/`' . preg_quote( $source_prefix, '/' ) . '([a-zA-Z0-9_]*)`/';
		return preg_replace( $pattern, '`' . addcslashes( $target_prefix, '\\$' ) . '$1`', $statement );
	}
}
