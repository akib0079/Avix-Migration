<?php
/**
 * Drives a Job through its step pipeline for one bounded "tick", which is
 * however this request got invoked:
 *
 *  - Browser:  wp_ajax_avix_run_step posts in a loop while status is running.
 *  - Cron:     a scheduled event calls run() once per cron tick, plus a
 *              non-blocking self-ping (see Schedule_Scheduler) to chain ticks
 *              without waiting for the next cron minute.
 *  - WP-CLI:   `wp avix export` calls run() in a tight loop with no browser
 *              involved, so it finishes in one process.
 *
 * All three drivers call exactly this one method — nothing about chunking
 * lives in the drivers themselves.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Runner {

	/**
	 * Runs steps until the job finishes, fails, or the request's time/memory
	 * budget is exhausted. Persists the job after every step call so a
	 * crash mid-tick loses at most one batch of work, not the whole job.
	 *
	 * @param Avix_Migration_Job $job
	 * @return array Progress snapshot, see Job::progress_snapshot().
	 */
	public function run( Avix_Migration_Job $job ) {
		// Exactly one tick may execute a job at a time. The browser polls on
		// a timer, and any tick that outruns the poll interval (a slow table,
		// a big file) overlaps the next one — two requests then execute the
		// SAME step concurrently. That double-ran the rollback snapshot,
		// where both ticks called time() inside the same second, derived the
		// same avix_rb_<ts>_ names, and the loser died on "table already
		// exists". Cursor-based steps would double-process a batch the same
		// way; this is a whole class of bug, not one step's problem.
		//
		// A file lock, not a transient or option: an import REPLACES the
		// database underneath the running process, so anything DB-backed is
		// unreliable at exactly the moment the lock matters most.
		$lock = $this->acquire_lock( $job->id );
		if ( null === $lock ) {
			// Another tick holds it — report current state and let the
			// poller come back. Not an error: this is the mechanism working.
			return $job->progress_snapshot();
		}

		try {
			return $this->run_locked( $job );
		} finally {
			$this->release_lock( $lock );
		}
	}

	/** @return array{handle:resource,path:string}|null Null when another tick holds the lock. */
	private function acquire_lock( $job_id ) {
		$path = Avix_Migration_Util_Filesystem::jobs_dir() . '/' . $job_id . '.lock';

		$handle = @fopen( $path, 'c' );
		if ( false === $handle ) {
			// Can't create a lock file (permissions) — proceed unlocked
			// rather than blocking the migration outright; the pre-existing
			// behaviour, which is still better than refusing to run.
			return array( 'handle' => null, 'path' => $path );
		}

		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );
			return null;
		}

		return array( 'handle' => $handle, 'path' => $path );
	}

	private function release_lock( array $lock ) {
		if ( is_resource( $lock['handle'] ) ) {
			flock( $lock['handle'], LOCK_UN );
			fclose( $lock['handle'] );
		}
	}

	private function run_locked( Avix_Migration_Job $job ) {
		// Re-read state now that we hold the lock. The caller loaded this job
		// BEFORE the lock, so a tick that queued behind another one is
		// holding a stale step index and cursor — running from that would
		// re-execute a step the other tick already completed, which is the
		// very thing the lock exists to prevent. Copy onto the caller's
		// instance rather than returning a new one, since the caller keeps
		// using its own reference after run() returns.
		$fresh = Avix_Migration_Job_Store::load( $job->id );
		if ( null !== $fresh && $fresh !== $job ) {
			foreach ( array( 'status', 'steps', 'current_step_index', 'cursor', 'totals', 'meta', 'error', 'stage_label', 'stage_message', 'updated_at' ) as $prop ) {
				$job->{$prop} = $fresh->{$prop};
			}
		}

		$budget = Avix_Migration_Job_Budget::for_current_request();

		if ( Avix_Migration_Job::STATUS_QUEUED === $job->status ) {
			$job->status = Avix_Migration_Job::STATUS_RUNNING;
		}

		while ( ! $job->is_terminal() ) {
			if ( $budget->expired() ) {
				break;
			}

			$step_class = $job->current_step_class();
			if ( null === $step_class ) {
				// No steps left — the pipeline is exhausted without an
				// explicit JOB_COMPLETE from the last step. Treat as done
				// rather than looping forever.
				$job->status = Avix_Migration_Job::STATUS_DONE;
				break;
			}

			$step = $this->instantiate( $step_class );
			if ( null === $step ) {
				$job->fail(
					sprintf(
						/* translators: %s: PHP class name */
						__( 'Internal error: step class %s could not be loaded.', 'avix-migration' ),
						$step_class
					)
				);
				break;
			}

			$job->stage_label = $step->label();

			try {
				$result = $step->execute( $job );
			} catch ( Throwable $e ) {
				// Record WHERE it blew up, not just what was said. A bare
				// message is undiagnosable when the throw comes from WP core
				// or another plugin running inside a step (which is common
				// here — a restore executes against a database that has just
				// been swapped underneath the running process). file:line plus
				// the originating frames name the culprit directly instead of
				// leaving it to guesswork.
				$origin = sprintf( '%s:%d', $e->getFile(), $e->getLine() );

				$frames = array();
				foreach ( array_slice( $e->getTrace(), 0, 8 ) as $frame ) {
					$frames[] = sprintf(
						'%s%s%s() @ %s:%s',
						$frame['class'] ?? '',
						isset( $frame['class'] ) ? ( $frame['type'] ?? '::' ) : '',
						$frame['function'] ?? '?',
						isset( $frame['file'] ) ? basename( $frame['file'] ) : '?',
						$frame['line'] ?? '?'
					);
				}

				Avix_Migration_Util_Logger::error(
					$job->id,
					sprintf( '%s threw %s: %s', $step_class, get_class( $e ), $e->getMessage() ),
					array(
						'step'   => $step_class,
						'origin' => $origin,
						'trace'  => $frames,
					)
				);

				$result = Avix_Migration_Job_Step_Result::failed(
					sprintf( '%s: %s (in %s, during %s)', get_class( $e ), $e->getMessage(), $origin, $step_class )
				);
			}

			if ( '' !== $result->message ) {
				$job->stage_message = $result->message;
			}

			$this->log( $job, $step, $result );

			switch ( $result->status ) {
				case Avix_Migration_Job_Step_Result::STEP_COMPLETE:
					$job->current_step_index++;
					if ( $job->current_step_index >= count( $job->steps ) ) {
						$job->status = Avix_Migration_Job::STATUS_DONE;
					}
					break;

				case Avix_Migration_Job_Step_Result::JOB_COMPLETE:
					$job->status = Avix_Migration_Job::STATUS_DONE;
					break;

				case Avix_Migration_Job_Step_Result::FAILED:
					$job->fail( $result->message );
					break;

				case Avix_Migration_Job_Step_Result::CONTINUE:
				default:
					// Same step again next loop iteration (budget permitting).
					break;
			}

			$job->touch();
			Avix_Migration_Job_Store::save( $job );

			if ( $job->is_terminal() ) {
				break;
			}
		}

		return $job->progress_snapshot();
	}

	/**
	 * Loads a job by id and runs it. Returns null if the job doesn't exist
	 * so the AJAX/CLI caller can 404 instead of operating on a null job.
	 */
	public function run_by_id( $id ) {
		$job = Avix_Migration_Job_Store::load( $id );
		if ( null === $job ) {
			return null;
		}
		return $this->run( $job );
	}

	private function instantiate( $step_class ) {
		if ( ! class_exists( $step_class ) ) {
			return null;
		}
		if ( ! is_subclass_of( $step_class, 'Avix_Migration_Job_Step' ) ) {
			return null;
		}
		return new $step_class();
	}

	private function log( Avix_Migration_Job $job, Avix_Migration_Job_Step $step, Avix_Migration_Job_Step_Result $result ) {
		$level = Avix_Migration_Job_Step_Result::FAILED === $result->status
			? Avix_Migration_Util_Logger::LEVEL_ERROR
			: Avix_Migration_Util_Logger::LEVEL_DEBUG;

		Avix_Migration_Util_Logger::log(
			$job->id,
			$level,
			sprintf( '[%s] %s: %s', $step->label(), $result->status, $result->message )
		);
	}
}
