<?php
/**
 * Return value of Job_Step::execute() — tells the runner what happened in
 * this one bounded batch of work and whether to advance the step index.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Step_Result {

	const CONTINUE      = 'continue';       // More work in this step; call it again.
	const STEP_COMPLETE = 'step_complete';  // This step is done; advance to the next.
	const JOB_COMPLETE  = 'job_complete';   // The whole job is done.
	const FAILED        = 'failed';         // Unrecoverable error; job stops.

	/** @var string One of the class constants above. */
	public $status;

	/** @var string Human-readable, for the log and the failed-job message. */
	public $message;

	/** @var array Arbitrary extra data merged into the job's progress snapshot. */
	public $context;

	public function __construct( $status, $message = '', array $context = array() ) {
		$this->status  = $status;
		$this->message = $message;
		$this->context = $context;
	}

	public static function cont( $message = '', array $context = array() ) {
		return new self( self::CONTINUE, $message, $context );
	}

	public static function step_complete( $message = '', array $context = array() ) {
		return new self( self::STEP_COMPLETE, $message, $context );
	}

	public static function job_complete( $message = '', array $context = array() ) {
		return new self( self::JOB_COMPLETE, $message, $context );
	}

	public static function failed( $message, array $context = array() ) {
		return new self( self::FAILED, $message, $context );
	}
}
