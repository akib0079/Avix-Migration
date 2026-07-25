<?php
/**
 * A per-request time/memory allowance the Job_Runner uses to decide when to
 * stop looping steps and hand control back to whatever is driving it
 * (browser poll, cron tick, or WP-CLI loop).
 *
 * Built fresh at the start of every Job_Runner::run() call — it reflects
 * *this* request's PHP limits, which can differ between a browser AJAX hit
 * (short max_execution_time on shared hosting) and a WP-CLI run (usually 0 /
 * unlimited).
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Budget {

	/** @var float Hard ceiling regardless of ini settings, in seconds. */
	const TIME_CAP = 20.0;

	/** @var float Fraction of max_execution_time to actually use. */
	const TIME_FRACTION = 0.6;

	/** @var float Fraction of memory_limit to actually use. */
	const MEMORY_FRACTION = 0.7;

	/** @var float Absolute memory safety net even when memory_limit is unlimited (bytes). */
	const MEMORY_ABSOLUTE_CAP = 1073741824; // 1 GB.

	/** @var float microtime(true) when this budget was created. */
	private $start;

	/** @var float Seconds allowed before expired(). */
	private $time_limit;

	/** @var int|null Bytes allowed before expired(); null disables the check entirely. */
	private $memory_limit;

	private function __construct( $start, $time_limit, $memory_limit ) {
		$this->start        = $start;
		$this->time_limit    = $time_limit;
		$this->memory_limit  = $memory_limit;
	}

	/**
	 * Builds a budget from the current request's PHP ini settings.
	 */
	public static function for_current_request() {
		$ini_time = (int) ini_get( 'max_execution_time' );

		if ( $ini_time <= 0 ) {
			// 0 = "unlimited" per php.ini. We still chunk — many hosts kill
			// "unlimited" scripts via an outer timeout PHP can't see, and an
			// un-resumable single request is a bad idea regardless.
			$time_limit = self::TIME_CAP;
		} else {
			$time_limit = min( self::TIME_CAP, $ini_time * self::TIME_FRACTION );
		}
		// Never go below a few seconds — a step needs room to do at least
		// one meaningful batch of work.
		$time_limit = max( $time_limit, 3.0 );

		$ini_memory = ini_get( 'memory_limit' );
		$memory_limit = null;

		if ( '' !== $ini_memory && '-1' !== trim( (string) $ini_memory ) ) {
			$bytes = function_exists( 'wp_convert_hr_to_bytes' )
				? wp_convert_hr_to_bytes( $ini_memory )
				: self::parse_shorthand_bytes( $ini_memory );

			if ( $bytes > 0 ) {
				$memory_limit = (int) ( $bytes * self::MEMORY_FRACTION );
			}
		}

		if ( null === $memory_limit ) {
			// "Unlimited" memory_limit — still cap ourselves defensively so
			// one runaway step can't take the whole server down.
			$memory_limit = self::MEMORY_ABSOLUTE_CAP;
		}

		return new self( microtime( true ), $time_limit, $memory_limit );
	}

	/**
	 * Fallback shorthand-to-bytes parser for the rare case this runs before
	 * WordPress core is available (activation-time bootstrap paths).
	 */
	private static function parse_shorthand_bytes( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}
		$unit  = strtolower( substr( $value, -1 ) );
		$num   = (int) $value;
		switch ( $unit ) {
			case 'g':
				return $num * 1073741824;
			case 'm':
				return $num * 1048576;
			case 'k':
				return $num * 1024;
			default:
				return (int) $value;
		}
	}

	/**
	 * Whether the caller should stop doing work and yield control.
	 */
	public function expired() {
		if ( ( microtime( true ) - $this->start ) >= $this->time_limit ) {
			return true;
		}
		if ( null !== $this->memory_limit && memory_get_usage( true ) >= $this->memory_limit ) {
			return true;
		}
		return false;
	}

	public function elapsed() {
		return microtime( true ) - $this->start;
	}

	public function time_limit() {
		return $this->time_limit;
	}
}
