<?php
/**
 * Treats "push this archive to another Avix Migration install" as just
 * another chunked storage destination — reusing the exact same
 * Storage_Provider contract and Export_Upload step that S3/FTP/SFTP/Drive/
 * Dropbox use, rather than inventing a parallel upload mechanism for
 * remote sites specifically. Each chunk is POSTed (HMAC-signed) to the
 * remote's /avix/v1/receive-chunk endpoint.
 *
 * Not registered in Storage_Manager's provider_ids()/labels() — it's only
 * ever constructed directly by the push flow (Admin_Remote_Controller),
 * never offered as a pickable destination in the generic destinations
 * panel, since it needs a Remote_Store record rather than a Storage_Credentials one.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Storage_Provider_Remote_Site implements Avix_Migration_Storage_Provider {

	const CHUNK_SIZE = 4194304; // 4 MB.

	private $remote;

	/** @param array $remote Decrypted record from Remote_Store::get_remote(). */
	public function __construct( array $remote ) {
		$this->remote = $remote;
	}

	public function id() {
		return 'remote_site';
	}

	public function label() {
		return __( 'Remote Avix Migration site', 'avix-migration' );
	}

	public function test_connection() {
		$response = Avix_Migration_Remote_Client::request_json( $this->remote, 'POST', '/avix/v1/handshake' );
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		return array( 'success' => true, 'message' => __( 'Connected successfully.', 'avix-migration' ) );
	}

	public function upload_chunk( $local_path, $remote_name, $offset, array $state ) {
		$size = filesize( $local_path );

		$fp = fopen( $local_path, 'rb' );
		fseek( $fp, $offset, SEEK_SET );
		$chunk = fread( $fp, self::CHUNK_SIZE );
		fclose( $fp );

		if ( '' === $chunk ) {
			return array( 'done' => true, 'bytes_sent' => 0, 'state' => $state, 'error' => null );
		}

		$path = '/avix/v1/receive-chunk?' . http_build_query( array( 'filename' => $remote_name, 'offset' => $offset ) );
		$response = Avix_Migration_Remote_Client::request( $this->remote, 'POST', $path, $chunk, array( 'headers' => array( 'Content-Type' => 'application/octet-stream' ), 'timeout' => 60 ) );

		if ( is_wp_error( $response ) ) {
			return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => $body['message'] ?? sprintf( 'HTTP %d', $code ) );
		}

		$bytes_sent = strlen( $chunk );
		$done = ( $offset + $bytes_sent ) >= $size;

		if ( $done ) {
			$finish = Avix_Migration_Remote_Client::request_json( $this->remote, 'POST', '/avix/v1/import/start', array( 'filename' => $remote_name ) );
			if ( is_wp_error( $finish ) ) {
				return array( 'done' => false, 'bytes_sent' => $bytes_sent, 'state' => $state, 'error' => $finish->get_error_message() );
			}
			$state['remote_import_job_id'] = $finish['job_id'] ?? null;
		}

		return array( 'done' => $done, 'bytes_sent' => $bytes_sent, 'state' => $state, 'error' => null );
	}

	public function download( $remote_name, $local_path ) {
		return array( 'success' => false, 'message' => __( 'Not used for remote-site push — pull uses Import_Download_Remote instead.', 'avix-migration' ) );
	}

	public function delete( $remote_name ) {
		return array( 'success' => false, 'message' => __( 'Not supported.', 'avix-migration' ) );
	}

	public function list_files() {
		return array();
	}
}
