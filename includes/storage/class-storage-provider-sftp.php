<?php
/**
 * SFTP via the vendored phpseclib3 library — a pure-PHP SSH2/SFTP client,
 * used because the compiled `ssh2` PECL extension this would otherwise
 * need is absent on this dev machine and rare on real shared hosting.
 * Hand-rolling SSH2's protocol and crypto from scratch would be a genuine
 * security risk rather than a reasonable engineering shortcut, which is
 * why this is a real, security-maintained dependency rather than
 * something written for this plugin.
 *
 * Chunked upload uses phpseclib's native offset-based write (SFTP's
 * SSH_FXP_WRITE carries its own byte offset in the protocol itself, unlike
 * FTP which needs a REST-command workaround) — each call writes one chunk
 * starting exactly where the previous one left off, resumable across job
 * ticks the same way the FTP provider is.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Storage_Provider_Sftp implements Avix_Migration_Storage_Provider {

	const CHUNK_SIZE = 4194304; // 4 MB per tick.

	private $config;

	public function __construct( array $config ) {
		$this->config = $config;
	}

	public function id() {
		return 'sftp';
	}

	public function label() {
		return __( 'SFTP', 'avix-migration' );
	}

	public function test_connection() {
		$sftp = $this->connect();
		if ( is_wp_error( $sftp ) ) {
			return array( 'success' => false, 'message' => $sftp->get_error_message() );
		}
		return array( 'success' => true, 'message' => __( 'Connected successfully.', 'avix-migration' ) );
	}

	public function upload_chunk( $local_path, $remote_name, $offset, array $state ) {
		if ( ! class_exists( '\phpseclib3\Net\SFTP' ) ) {
			return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => __( 'SFTP support (phpseclib) is not available.', 'avix-migration' ) );
		}

		$size = filesize( $local_path );

		$sftp = $this->connect();
		if ( is_wp_error( $sftp ) ) {
			return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => $sftp->get_error_message() );
		}

		$in = fopen( $local_path, 'rb' );
		fseek( $in, $offset, SEEK_SET );
		$chunk = fread( $in, self::CHUNK_SIZE );
		fclose( $in );

		if ( '' === $chunk ) {
			return array( 'done' => true, 'bytes_sent' => 0, 'state' => $state, 'error' => null );
		}

		$remote_path = $this->remote_path( $remote_name );
		$ok = $sftp->put( $remote_path, $chunk, \phpseclib3\Net\SFTP::SOURCE_STRING, $offset );

		if ( ! $ok ) {
			return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => __( 'SFTP upload failed: ', 'avix-migration' ) . $sftp->getLastSFTPError() );
		}

		$bytes_sent = strlen( $chunk );
		return array( 'done' => ( $offset + $bytes_sent ) >= $size, 'bytes_sent' => $bytes_sent, 'state' => $state, 'error' => null );
	}

	public function download( $remote_name, $local_path ) {
		$sftp = $this->connect();
		if ( is_wp_error( $sftp ) ) {
			return array( 'success' => false, 'message' => $sftp->get_error_message() );
		}
		$ok = $sftp->get( $this->remote_path( $remote_name ), $local_path );
		return array( 'success' => (bool) $ok, 'message' => $ok ? '' : __( 'SFTP download failed.', 'avix-migration' ) );
	}

	public function delete( $remote_name ) {
		$sftp = $this->connect();
		if ( is_wp_error( $sftp ) ) {
			return array( 'success' => false, 'message' => $sftp->get_error_message() );
		}
		$ok = $sftp->delete( $this->remote_path( $remote_name ) );
		return array( 'success' => (bool) $ok, 'message' => $ok ? '' : __( 'SFTP delete failed.', 'avix-migration' ) );
	}

	public function list_files() {
		$sftp = $this->connect();
		if ( is_wp_error( $sftp ) ) {
			return array();
		}
		$dir = '' !== ( $this->config['remote_dir'] ?? '' ) ? $this->config['remote_dir'] : '.';
		$list = $sftp->nlist( $dir );
		if ( ! $list ) {
			return array();
		}
		return array_values( array_diff( $list, array( '.', '..' ) ) );
	}

	private function remote_path( $remote_name ) {
		$dir = trim( $this->config['remote_dir'] ?? '', '/' );
		return ( '' !== $dir ? '/' . $dir : '' ) . '/' . ltrim( $remote_name, '/' );
	}

	/** @return \phpseclib3\Net\SFTP|WP_Error */
	private function connect() {
		if ( ! class_exists( '\phpseclib3\Net\SFTP' ) ) {
			return new WP_Error( 'avix_sftp_missing', __( 'SFTP support (phpseclib) is not available on this install.', 'avix-migration' ) );
		}

		$sftp = new \phpseclib3\Net\SFTP( $this->config['host'], (int) ( $this->config['port'] ?: 22 ), 15 );

		if ( ! empty( $this->config['private_key'] ) ) {
			try {
				$key = \phpseclib3\Crypt\PublicKeyLoader::load( $this->config['private_key'], $this->config['passphrase'] ?? false );
			} catch ( \Exception $e ) {
				return new WP_Error( 'avix_sftp_key', __( 'Could not parse the provided private key: ', 'avix-migration' ) . $e->getMessage() );
			}
			$auth = $key;
		} else {
			$auth = $this->config['password'] ?? '';
		}

		if ( ! $sftp->login( $this->config['username'], $auth ) ) {
			return new WP_Error( 'avix_sftp_login', __( 'SFTP login failed — check the username and password/key.', 'avix-migration' ) );
		}

		return $sftp;
	}
}
