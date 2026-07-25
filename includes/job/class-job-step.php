<?php
/**
 * Base class for one stage of a job's step pipeline (e.g. "scan files",
 * "export database", "write archive entries", "search-replace pass").
 *
 * A single execute() call MUST do a bounded amount of work (one batch of
 * rows, one file, or one capped chunk of bytes within a large file) and then
 * return — never loop until "done", since the runner relies on being able to
 * check its time/memory budget between calls. Concrete steps read/write
 * $job->cursor to remember where they left off between calls, and are the
 * only code that understands the shape of their own cursor data.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Avix_Migration_Job_Step {

	/**
	 * Do one bounded batch of work.
	 *
	 * @param Avix_Migration_Job $job
	 * @return Avix_Migration_Job_Step_Result
	 */
	abstract public function execute( Avix_Migration_Job $job );

	/**
	 * Machine-readable label used as a key in $job->cursor and in log lines
	 * — defaults to the short class name (e.g. "scan_files").
	 */
	public function key() {
		$class = get_class( $this );
		$short = preg_replace( '/^Avix_Migration_Job_Steps_/', '', $class );
		return strtolower( $short );
	}

	/**
	 * Human label for the progress UI's "stage" text.
	 */
	abstract public function label();

	/**
	 * Convenience accessor: this step's slice of $job->cursor, so unrelated
	 * steps in the same job never collide on cursor keys.
	 */
	protected function cursor( Avix_Migration_Job $job ) {
		$key = $this->key();
		if ( ! isset( $job->cursor[ $key ] ) || ! is_array( $job->cursor[ $key ] ) ) {
			$job->cursor[ $key ] = array();
		}
		return $job->cursor[ $key ];
	}

	protected function set_cursor( Avix_Migration_Job $job, array $data ) {
		$job->cursor[ $this->key() ] = $data;
	}
}
