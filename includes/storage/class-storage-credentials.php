<?php
/**
 * CRUD for saved storage destination connections. Secret fields (API
 * keys, passwords, OAuth tokens) are encrypted at rest via Util_Crypto
 * before being written to the options table — a raw database export or
 * backup of the site's own DB should never hand over live cloud
 * credentials in plaintext.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Storage_Credentials {

	const OPTION_NAME = 'avix_migration_destinations';

	/** Field names treated as secrets for every provider — encrypted on write, decrypted only on read. */
	const SECRET_FIELDS = array( 'secret_key', 'password', 'access_token', 'refresh_token', 'private_key', 'passphrase' );

	public static function all() {
		$raw = get_option( self::OPTION_NAME, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/** Decrypted config for one destination, or null. */
	public static function get( $id ) {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return null;
		}
		return self::decrypt_fields( $all[ $id ] );
	}

	/** @return string New destination id. */
	public static function create( $provider_id, array $config ) {
		$all = self::all();
		$id  = 'dest_' . Avix_Migration_Util_Crypto::random_token( 6 );

		$config['provider']   = $provider_id;
		$config['id']         = $id;
		$config['created_at'] = time();

		$all[ $id ] = self::encrypt_fields( $config );
		update_option( self::OPTION_NAME, $all, false );

		return $id;
	}

	public static function update( $id, array $config ) {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		$merged = array_merge( self::decrypt_fields( $all[ $id ] ), $config );
		$all[ $id ] = self::encrypt_fields( $merged );
		update_option( self::OPTION_NAME, $all, false );
		return true;
	}

	public static function delete( $id ) {
		$all = self::all();
		unset( $all[ $id ] );
		update_option( self::OPTION_NAME, $all, false );
	}

	/**
	 * All saved destinations with secrets stripped entirely (not just
	 * un-decrypted — actually removed) — safe to send to the browser for
	 * a destination picker, since the UI never needs to see a stored
	 * secret again, only reference it by id.
	 */
	public static function all_public() {
		$out = array();
		foreach ( self::all() as $id => $config ) {
			$public = $config;
			foreach ( self::SECRET_FIELDS as $field ) {
				unset( $public[ $field ] );
			}
			$out[ $id ] = $public;
		}
		return $out;
	}

	private static function encrypt_fields( array $config ) {
		foreach ( self::SECRET_FIELDS as $field ) {
			if ( isset( $config[ $field ] ) && '' !== $config[ $field ] ) {
				$encrypted = Avix_Migration_Util_Crypto::encrypt( $config[ $field ] );
				if ( false !== $encrypted ) {
					$config[ $field ] = $encrypted;
				}
			}
		}
		return $config;
	}

	private static function decrypt_fields( array $config ) {
		foreach ( self::SECRET_FIELDS as $field ) {
			if ( isset( $config[ $field ] ) && '' !== $config[ $field ] ) {
				$decrypted = Avix_Migration_Util_Crypto::decrypt( $config[ $field ] );
				if ( false !== $decrypted ) {
					$config[ $field ] = $decrypted;
				}
			}
		}
		return $config;
	}
}
