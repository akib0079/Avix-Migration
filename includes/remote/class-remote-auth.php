<?php
/**
 * HMAC-SHA256 request signing and verification for the site-to-site REST
 * API. The secret itself never crosses the wire — only a signature
 * computed from it — and every signed element (method, path, body hash,
 * timestamp, nonce) is covered, so a captured request can't be replayed
 * against a different endpoint, with a different body, or (thanks to the
 * nonce) even against the exact same endpoint twice.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Remote_Auth {

	/** Requests timestamped further than this from our own clock are rejected. */
	const MAX_CLOCK_SKEW = 300; // 5 minutes.

	/** How long a (key_id, nonce) pair is remembered to block replay — must exceed MAX_CLOCK_SKEW. */
	const NONCE_TTL = 600;

	const MAX_AUTH_FAILURES = 10;
	const AUTH_FAILURE_WINDOW = 300;

	/**
	 * @return array Headers to add to an outbound request: X-Avix-Key-Id,
	 *               X-Avix-Timestamp, X-Avix-Nonce, X-Avix-Signature.
	 */
	public static function sign_request( $method, $path, $body, $key_id, $secret ) {
		$timestamp = (string) time();
		$nonce     = Avix_Migration_Util_Crypto::random_token( 16 );
		$signature = self::compute_signature( $method, $path, $body, $timestamp, $nonce, $secret );

		return array(
			'X-Avix-Key-Id'    => $key_id,
			'X-Avix-Timestamp' => $timestamp,
			'X-Avix-Nonce'     => $nonce,
			'X-Avix-Signature' => $signature,
		);
	}

	/**
	 * @param WP_REST_Request $request
	 * @return true|WP_Error
	 */
	public static function verify_inbound( WP_REST_Request $request ) {
		$key_id    = $request->get_header( 'x-avix-key-id' );
		$timestamp = $request->get_header( 'x-avix-timestamp' );
		$nonce     = $request->get_header( 'x-avix-nonce' );
		$signature = $request->get_header( 'x-avix-signature' );

		if ( ! $key_id || ! $timestamp || ! $nonce || ! $signature ) {
			return new WP_Error( 'avix_remote_auth_missing', __( 'Missing authentication headers.', 'avix-migration' ), array( 'status' => 401 ) );
		}

		if ( self::is_rate_limited( $key_id ) ) {
			return new WP_Error( 'avix_remote_auth_rate_limited', __( 'Too many failed authentication attempts — try again later.', 'avix-migration' ), array( 'status' => 429 ) );
		}

		$key = Avix_Migration_Remote_Store::get_issued_key( $key_id );
		if ( null === $key ) {
			self::record_auth_failure( $key_id );
			return new WP_Error( 'avix_remote_auth_unknown_key', __( 'Unknown or expired connection key.', 'avix-migration' ), array( 'status' => 401 ) );
		}

		if ( abs( time() - (int) $timestamp ) > self::MAX_CLOCK_SKEW ) {
			self::record_auth_failure( $key_id );
			return new WP_Error( 'avix_remote_auth_clock_skew', __( 'Request timestamp is too far from the server clock.', 'avix-migration' ), array( 'status' => 401 ) );
		}

		$nonce_flag = 'avix_nonce_' . $key_id . '_' . $nonce;
		if ( false !== get_transient( $nonce_flag ) ) {
			self::record_auth_failure( $key_id );
			return new WP_Error( 'avix_remote_auth_replay', __( 'This request has already been used (replay detected).', 'avix-migration' ), array( 'status' => 401 ) );
		}

		$path = '/' . ltrim( $request->get_route(), '/' );
		$body = $request->get_body();
		$expected = self::compute_signature( $request->get_method(), $path, $body, $timestamp, $nonce, $key['secret'] );

		if ( ! hash_equals( $expected, $signature ) ) {
			self::record_auth_failure( $key_id );
			return new WP_Error( 'avix_remote_auth_bad_signature', __( 'Signature verification failed.', 'avix-migration' ), array( 'status' => 401 ) );
		}

		// Only mark the nonce spent once the signature is confirmed valid —
		// an attacker sending garbage signatures shouldn't be able to burn
		// through nonce slots as a denial-of-service angle.
		set_transient( $nonce_flag, 1, self::NONCE_TTL );

		return true;
	}

	private static function compute_signature( $method, $path, $body, $timestamp, $nonce, $secret ) {
		$message = implode(
			"\n",
			array(
				strtoupper( $method ),
				$path,
				hash( 'sha256', (string) $body ),
				(string) $timestamp,
				$nonce,
			)
		);
		return hash_hmac( 'sha256', $message, $secret );
	}

	private static function is_rate_limited( $key_id ) {
		$count = (int) get_transient( 'avix_auth_fail_' . $key_id );
		return $count >= self::MAX_AUTH_FAILURES;
	}

	private static function record_auth_failure( $key_id ) {
		$flag  = 'avix_auth_fail_' . $key_id;
		$count = (int) get_transient( $flag );
		set_transient( $flag, $count + 1, self::AUTH_FAILURE_WINDOW );
		Avix_Migration_Util_Logger::warning( 'plugin', 'Remote auth failure.', array( 'key_id' => $key_id, 'count' => $count + 1 ) );
	}
}
