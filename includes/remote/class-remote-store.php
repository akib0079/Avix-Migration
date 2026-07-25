<?php
/**
 * Two related but distinct stores, both option-backed (edited rarely, no
 * autoload-churn concern the way Job state has):
 *
 *  - "issued keys": connection keys THIS site has generated for an OTHER
 *    site to use when connecting IN (pushing to, or being pulled from, this
 *    site). Verifying an inbound HMAC signature requires the actual shared
 *    secret, not a hash of it — a hash only lets you verify a directly
 *    supplied value, not recompute a MAC — so the secret is encrypted at
 *    rest via Util_Crypto rather than hashed, giving the same
 *    protection against a raw DB leak while still letting this site
 *    compute the expected signature for a real HMAC scheme.
 *
 *  - "remotes": other sites THIS site has added by pasting a key generated
 *    over there, used to connect OUT (push to, or pull from, that site).
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Remote_Store {

	const ISSUED_KEYS_OPTION = 'avix_migration_issued_keys';
	const REMOTES_OPTION     = 'avix_migration_remotes';
	const SECRET_FIELD       = 'secret';

	// ---------------------------------------------------------------
	// Issued keys (inbound — someone else connects to THIS site).
	// ---------------------------------------------------------------

	public static function all_issued_keys() {
		$raw = get_option( self::ISSUED_KEYS_OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/** @return array{key_id:string, secret:string, connection_string:string} secret is the plaintext, shown once. */
	public static function issue_key( $label, $expires_in_seconds = 0 ) {
		$key_id = 'key_' . Avix_Migration_Util_Crypto::random_token( 8 );
		$secret = Avix_Migration_Util_Crypto::random_token( 32 );
		$expires_at = $expires_in_seconds > 0 ? ( time() + $expires_in_seconds ) : 0;

		$all = self::all_issued_keys();
		$all[ $key_id ] = array(
			'key_id'     => $key_id,
			'label'      => sanitize_text_field( $label ),
			'secret'     => Avix_Migration_Util_Crypto::encrypt( $secret ),
			'expires_at' => $expires_at,
			'created_at' => time(),
		);
		update_option( self::ISSUED_KEYS_OPTION, $all, false );

		$connection_string = base64_encode(
			wp_json_encode(
				array(
					'site_url'   => home_url(),
					'key_id'     => $key_id,
					'secret'     => $secret,
					'expires_at' => $expires_at,
				)
			)
		);

		return array( 'key_id' => $key_id, 'secret' => $secret, 'connection_string' => $connection_string );
	}

	/** @return array|null Decrypted (secret is plaintext) issued-key record, or null if unknown/expired. */
	public static function get_issued_key( $key_id ) {
		$all = self::all_issued_keys();
		if ( ! isset( $all[ $key_id ] ) ) {
			return null;
		}
		$record = $all[ $key_id ];
		if ( $record['expires_at'] > 0 && $record['expires_at'] < time() ) {
			return null;
		}
		$record['secret'] = Avix_Migration_Util_Crypto::decrypt( $record['secret'] );
		return $record;
	}

	public static function revoke_issued_key( $key_id ) {
		$all = self::all_issued_keys();
		unset( $all[ $key_id ] );
		update_option( self::ISSUED_KEYS_OPTION, $all, false );
	}

	// ---------------------------------------------------------------
	// Remotes (outbound — THIS site connects to someone else).
	// ---------------------------------------------------------------

	public static function all_remotes() {
		$raw = get_option( self::REMOTES_OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/** Safe to send to the browser — no secret. */
	public static function all_remotes_public() {
		$out = array();
		foreach ( self::all_remotes() as $id => $remote ) {
			unset( $remote[ self::SECRET_FIELD ] );
			$out[ $id ] = $remote;
		}
		return $out;
	}

	/**
	 * @param string $connection_string As produced by issue_key() on the OTHER site.
	 * @return string|WP_Error New remote id, or an error if the string is malformed.
	 */
	public static function add_remote( $label, $connection_string ) {
		$decoded = json_decode( base64_decode( trim( $connection_string ) ), true );
		if ( ! is_array( $decoded ) || empty( $decoded['site_url'] ) || empty( $decoded['key_id'] ) || empty( $decoded['secret'] ) ) {
			return new WP_Error( 'avix_remote_bad_key', __( 'That connection key could not be read — check it was copied in full.', 'avix-migration' ) );
		}

		$id = 'remote_' . Avix_Migration_Util_Crypto::random_token( 8 );
		$all = self::all_remotes();
		$all[ $id ] = array(
			'id'         => $id,
			'label'      => sanitize_text_field( $label ),
			'site_url'   => esc_url_raw( $decoded['site_url'] ),
			'key_id'     => sanitize_text_field( $decoded['key_id'] ),
			self::SECRET_FIELD => Avix_Migration_Util_Crypto::encrypt( $decoded['secret'] ),
			'expires_at' => (int) ( $decoded['expires_at'] ?? 0 ),
			'added_at'   => time(),
		);
		update_option( self::REMOTES_OPTION, $all, false );

		return $id;
	}

	/** @return array|null Decrypted (secret is plaintext), or null if unknown. */
	public static function get_remote( $id ) {
		$all = self::all_remotes();
		if ( ! isset( $all[ $id ] ) ) {
			return null;
		}
		$remote = $all[ $id ];
		$remote[ self::SECRET_FIELD ] = Avix_Migration_Util_Crypto::decrypt( $remote[ self::SECRET_FIELD ] );
		return $remote;
	}

	public static function delete_remote( $id ) {
		$all = self::all_remotes();
		unset( $all[ $id ] );
		update_option( self::REMOTES_OPTION, $all, false );
	}
}
