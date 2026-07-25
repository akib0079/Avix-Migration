<?php
/**
 * Small crypto helpers used across the plugin: random tokens for archive
 * filenames and connection-key secrets, at-rest encryption for stored
 * storage-provider credentials, and HMAC signing for site-to-site requests.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Util_Crypto {

	const CIPHER = 'aes-256-gcm';

	/**
	 * A cryptographically random lowercase-hex token, e.g. for archive
	 * filenames (wp-content/avix-backups/*.avix must not be guessable even
	 * on a host where .htaccess is ignored, such as nginx) and connection
	 * key secrets.
	 *
	 * @param int $bytes Number of random bytes; hex output is 2x this length.
	 */
	public static function random_token( $bytes = 16 ) {
		try {
			return bin2hex( random_bytes( $bytes ) );
		} catch ( Exception $e ) {
			// random_bytes() only throws if the platform CSPRNG is
			// unavailable, which practically never happens on PHP 7+, but
			// fall back rather than fatal on a still-somewhat-random source.
			return bin2hex( wp_generate_password( $bytes * 2, false, false ) );
		}
	}

	/**
	 * One-way hash of a secret for storage — connection-key secrets are
	 * never stored in plaintext on the issuing site, only their hash, so a
	 * database leak doesn't hand over live migration credentials.
	 */
	public static function hash_secret( $secret ) {
		return hash( 'sha256', (string) $secret );
	}

	public static function hmac( $data, $secret ) {
		return hash_hmac( 'sha256', $data, $secret );
	}

	/**
	 * Encrypts a string for storage (storage-provider credentials: S3 secret
	 * keys, FTP passwords, OAuth refresh tokens). Returns a single base64
	 * blob of iv + tag + ciphertext, or false if the platform lacks the
	 * cipher (extremely rare on PHP 7.4+, checked defensively rather than
	 * fataling).
	 *
	 * @param string $plaintext
	 * @return string|false
	 */
	public static function encrypt( $plaintext ) {
		if ( ! in_array( self::CIPHER, openssl_get_cipher_methods(), true ) ) {
			return false;
		}

		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		$iv     = openssl_random_pseudo_bytes( $iv_len );
		$tag    = '';

		$ciphertext = openssl_encrypt( (string) $plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $ciphertext ) {
			return false;
		}

		return base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * @param string $blob Output of encrypt().
	 * @return string|false Original plaintext, or false on failure/tamper.
	 */
	public static function decrypt( $blob ) {
		$raw = base64_decode( (string) $blob, true );
		if ( false === $raw ) {
			return false;
		}

		$iv_len  = openssl_cipher_iv_length( self::CIPHER );
		$tag_len = 16;

		if ( strlen( $raw ) < ( $iv_len + $tag_len ) ) {
			return false;
		}

		$iv         = substr( $raw, 0, $iv_len );
		$tag        = substr( $raw, $iv_len, $tag_len );
		$ciphertext = substr( $raw, $iv_len + $tag_len );

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return $plaintext;
	}

	/**
	 * Derives a stable 32-byte key from WordPress's own auth salts, so
	 * credentials are unreadable from a raw DB export without also having
	 * the target site's wp-config.php — and require no separate key
	 * management of our own.
	 */
	private static function key() {
		$material = '';
		if ( defined( 'AUTH_KEY' ) ) {
			$material .= AUTH_KEY;
		}
		if ( defined( 'SECURE_AUTH_KEY' ) ) {
			$material .= SECURE_AUTH_KEY;
		}
		if ( '' === $material ) {
			// wp-config.php without unique salts (default install skeleton
			// before wp-cli/wp-admin fills them in) — fall back to
			// something site-specific rather than a hardcoded constant.
			$material = DB_NAME . DB_HOST . ( defined( 'ABSPATH' ) ? ABSPATH : '' );
		}
		return hash( 'sha256', 'avix-migration:' . $material, true );
	}
}
