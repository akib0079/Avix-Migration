<?php
/**
 * Plain FTP via PHP's built-in ftp_* extension — no external library
 * needed. Each upload_chunk() call opens a fresh connection (a job tick is
 * always a separate PHP process, so no connection can be kept open across
 * ticks) and writes ONE chunk directly at the correct remote byte offset
 * using ftp_fput()'s $offset parameter, which issues an FTP REST command
 * before STOR — this is what makes upload genuinely resumable across
 * ticks rather than needing to restart the whole transfer every time:
 * only the new chunk's bytes are read from the source file and sent, and
 * the remote file is written to starting exactly where the last chunk
 * left off, in FTP_BINARY mode (REST is unreliable in ASCII mode, and
 * archives are binary regardless).
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Storage_Provider_Ftp implements Avix_Migration_Storage_Provider {

	const CHUNK_SIZE = 4194304; // 4 MB per tick.

	private $config;

	public function __construct( array $config ) {
		$this->config = $config;
	}

	public function id() {
		return 'ftp';
	}

	public function label() {
		return __( 'FTP', 'avix-migration' );
	}

	public function test_connection() {
		$conn = $this->connect();
		if ( is_wp_error( $conn ) ) {
			return array( 'success' => false, 'message' => $conn->get_error_message() );
		}
		ftp_close( $conn );
		return array( 'success' => true, 'message' => __( 'Connected successfully.', 'avix-migration' ) );
	}

	public function upload_chunk( $local_path, $remote_name, $offset, array $state ) {
		if ( ! extension_loaded( 'ftp' ) ) {
			return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => __( 'The PHP ftp extension is not available on this server.', 'avix-migration' ) );
		}

		$size = filesize( $local_path );

		$conn = $this->connect();
		if ( is_wp_error( $conn ) ) {
			return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => $conn->get_error_message() );
		}

		$in = fopen( $local_path, 'rb' );
		fseek( $in, $offset, SEEK_SET );
		$chunk = fread( $in, self::CHUNK_SIZE );
		fclose( $in );

		if ( '' === $chunk ) {
			ftp_close( $conn );
			return array( 'done' => true, 'bytes_sent' => 0, 'state' => $state, 'error' => null );
		}

		$tmp = fopen( 'php://temp', 'r+b' );
		fwrite( $tmp, $chunk );
		rewind( $tmp );

		$remote_path = $this->remote_path( $remote_name );

		// First chunk (offset 0): the remote file may not exist yet, so a
		// REST-before-STOR at offset 0 is equivalent to a plain fresh
		// upload — no special-casing needed either way.
		$ok = @ftp_fput( $conn, $remote_path, $tmp, FTP_BINARY, $offset );
		fclose( $tmp );
		ftp_close( $conn );

		if ( ! $ok ) {
			return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => __( 'FTP upload failed — the server may not support resumable (REST) uploads.', 'avix-migration' ) );
		}

		$bytes_sent = strlen( $chunk );
		return array( 'done' => ( $offset + $bytes_sent ) >= $size, 'bytes_sent' => $bytes_sent, 'state' => $state, 'error' => null );
	}

	public function download( $remote_name, $local_path ) {
		$conn = $this->connect();
		if ( is_wp_error( $conn ) ) {
			return array( 'success' => false, 'message' => $conn->get_error_message() );
		}
		$ok = @ftp_get( $conn, $local_path, $this->remote_path( $remote_name ), FTP_BINARY );
		ftp_close( $conn );
		return array( 'success' => (bool) $ok, 'message' => $ok ? '' : __( 'FTP download failed.', 'avix-migration' ) );
	}

	public function delete( $remote_name ) {
		$conn = $this->connect();
		if ( is_wp_error( $conn ) ) {
			return array( 'success' => false, 'message' => $conn->get_error_message() );
		}
		$ok = @ftp_delete( $conn, $this->remote_path( $remote_name ) );
		ftp_close( $conn );
		return array( 'success' => (bool) $ok, 'message' => $ok ? '' : __( 'FTP delete failed.', 'avix-migration' ) );
	}

	public function list_files() {
		$conn = $this->connect();
		if ( is_wp_error( $conn ) ) {
			return array();
		}
		$base = '' !== $this->config['remote_dir'] ? $this->config['remote_dir'] : '.';
		$list = @ftp_nlist( $conn, $base );
		ftp_close( $conn );
		if ( ! $list ) {
			return array();
		}
		return array_map( 'basename', $list );
	}

	private function remote_path( $remote_name ) {
		$dir = trim( $this->config['remote_dir'] ?? '', '/' );
		return ( '' !== $dir ? '/' . $dir : '' ) . '/' . ltrim( $remote_name, '/' );
	}

	/** @return resource|WP_Error */
	private function connect() {
		if ( ! extension_loaded( 'ftp' ) ) {
			return new WP_Error( 'avix_ftp_missing', __( 'The PHP ftp extension is not available on this server.', 'avix-migration' ) );
		}

		$use_ssl = ! empty( $this->config['use_ftps'] );
		$conn = $use_ssl
			? @ftp_ssl_connect( $this->config['host'], (int) ( $this->config['port'] ?: 21 ), 15 )
			: @ftp_connect( $this->config['host'], (int) ( $this->config['port'] ?: 21 ), 15 );

		if ( ! $conn ) {
			return new WP_Error( 'avix_ftp_connect', __( 'Could not connect to the FTP server.', 'avix-migration' ) );
		}

		if ( ! @ftp_login( $conn, $this->config['username'], $this->config['password'] ) ) {
			ftp_close( $conn );
			return new WP_Error( 'avix_ftp_login', __( 'FTP login failed — check the username and password.', 'avix-migration' ) );
		}

		ftp_pasv( $conn, empty( $this->config['active_mode'] ) );

		return $conn;
	}
}
