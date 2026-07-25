<?php
/**
 * In-memory representation of a single job's persisted state. Deliberately a
 * plain data holder — Job_Store handles (de)serialization to disk, Job_Runner
 * handles execution, individual Job_Step subclasses own the meaning of
 * $cursor for their own step.
 *
 * Stored as JSON at wp-content/avix-backups/jobs/{id}.json rather than a WP
 * option: job state changes on every polling request during a backup, and an
 * autoloaded option that churns that often bloats every page load on the
 * site; a job file is read only by requests that are actually polling it.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job {

	const STATUS_QUEUED    = 'queued';
	const STATUS_RUNNING   = 'running';
	const STATUS_DONE      = 'done';
	const STATUS_FAILED    = 'failed';
	const STATUS_CANCELLED = 'cancelled';

	/** @var string */
	public $id;

	/** @var string e.g. 'export_full', 'import', 'export_content', 'transfer_push'. */
	public $type;

	/** @var string One of the STATUS_* constants. */
	public $status = self::STATUS_QUEUED;

	/** @var string[] Fully-qualified step class names, in execution order. */
	public $steps = array();

	/** @var int Index into $steps of the step currently being executed. */
	public $current_step_index = 0;

	/**
	 * Free-form per-step working state (table name + last primary key seen,
	 * file list index + byte offset, etc). Only the current step's own code
	 * should read or write into this — it is opaque to the runner.
	 *
	 * @var array
	 */
	public $cursor = array();

	/**
	 * Running totals for the progress UI. Steps update these as they work;
	 * *_total fields may start at 0 and be filled in by an early "scan" step.
	 *
	 * @var array
	 */
	public $totals = array(
		'bytes_total' => 0,
		'bytes_done'  => 0,
		'files_total' => 0,
		'files_done'  => 0,
		'rows_total'  => 0,
		'rows_done'   => 0,
	);

	/** @var array Job-type-specific configuration chosen by the user (wizard options, destination, etc). */
	public $meta = array();

	/** @var string|null Set when status is FAILED. */
	public $error = null;

	/** @var string Human label of the step currently (or last) executing — for the UI's stage heading. */
	public $stage_label = '';

	/** @var string Human detail message from the most recent step result — for the UI's stage subtext. */
	public $stage_message = '';

	/** @var int Unix timestamp. */
	public $created_at;

	/** @var int Unix timestamp. */
	public $updated_at;

	public function __construct( $id, $type ) {
		$this->id         = $id;
		$this->type       = $type;
		$this->created_at = time();
		$this->updated_at = time();
	}

	public function touch() {
		$this->updated_at = time();
	}

	public function is_terminal() {
		return in_array( $this->status, array( self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_CANCELLED ), true );
	}

	public function current_step_class() {
		return isset( $this->steps[ $this->current_step_index ] ) ? $this->steps[ $this->current_step_index ] : null;
	}

	public function fail( $message ) {
		$this->status = self::STATUS_FAILED;
		$this->error  = $message;
		$this->touch();
	}

	/**
	 * A lightweight summary safe to send to the browser as JSON — no need
	 * to expose internal step cursors to the client.
	 */
	public function progress_snapshot() {
		$percent = 0;
		if ( $this->totals['bytes_total'] > 0 ) {
			$percent = min( 100, round( ( $this->totals['bytes_done'] / $this->totals['bytes_total'] ) * 100 ) );
		} elseif ( $this->totals['rows_total'] > 0 ) {
			// Byte totals aren't meaningful for every step (e.g. a content
			// import's post-insertion phase tracks rows, not bytes) — row
			// counts are the next-best granular signal before falling all
			// the way back to a coarse step-index estimate.
			$percent = min( 100, round( ( $this->totals['rows_done'] / $this->totals['rows_total'] ) * 100 ) );
		} elseif ( ! empty( $this->steps ) ) {
			$percent = min( 100, round( ( $this->current_step_index / count( $this->steps ) ) * 100 ) );
		}

		return array(
			'id'              => $this->id,
			'type'            => $this->type,
			'status'          => $this->status,
			'percent'         => (int) $percent,
			'step_index'      => $this->current_step_index,
			'step_count'      => count( $this->steps ),
			'stage_label'     => $this->stage_label,
			'stage_message'   => $this->stage_message,
			'totals'          => $this->totals,
			'error'           => $this->error,
			'updated_at'      => $this->updated_at,
			// Deliberately NOT the full $meta blob — later milestones store
			// things like storage credentials there. Only specific,
			// known-safe fields the UI needs are surfaced explicitly.
			'archive_filename'      => $this->meta['archive_filename'] ?? null,
			'archive_type'          => $this->meta['archive_type'] ?? null,
			'warnings'              => array_values( array_unique( array_merge( (array) ( $this->meta['warnings'] ?? array() ), (array) ( $this->meta['import_warnings'] ?? array() ) ) ) ),
			'remote_import_job_id'  => $this->meta['remote_import_job_id'] ?? null,
		);
	}

	public function to_array() {
		return array(
			'id'                  => $this->id,
			'type'                => $this->type,
			'status'              => $this->status,
			'steps'               => $this->steps,
			'current_step_index'  => $this->current_step_index,
			'cursor'              => $this->cursor,
			'totals'              => $this->totals,
			'meta'                => $this->meta,
			'error'               => $this->error,
			'stage_label'         => $this->stage_label,
			'stage_message'       => $this->stage_message,
			'created_at'          => $this->created_at,
			'updated_at'          => $this->updated_at,
		);
	}

	public static function from_array( array $data ) {
		$job = new self( $data['id'], $data['type'] );
		$job->status              = isset( $data['status'] ) ? $data['status'] : self::STATUS_QUEUED;
		$job->steps               = isset( $data['steps'] ) ? (array) $data['steps'] : array();
		$job->current_step_index  = isset( $data['current_step_index'] ) ? (int) $data['current_step_index'] : 0;
		$job->cursor              = isset( $data['cursor'] ) ? (array) $data['cursor'] : array();
		$job->totals              = isset( $data['totals'] ) ? (array) $data['totals'] : $job->totals;
		$job->meta                = isset( $data['meta'] ) ? (array) $data['meta'] : array();
		$job->error               = isset( $data['error'] ) ? $data['error'] : null;
		$job->stage_label         = isset( $data['stage_label'] ) ? (string) $data['stage_label'] : '';
		$job->stage_message       = isset( $data['stage_message'] ) ? (string) $data['stage_message'] : '';
		$job->created_at          = isset( $data['created_at'] ) ? (int) $data['created_at'] : time();
		$job->updated_at          = isset( $data['updated_at'] ) ? (int) $data['updated_at'] : time();
		return $job;
	}
}
