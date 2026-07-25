<?php
/**
 * Dropbox via OAuth2 + the upload_session API (start / append_v2 / finish),
 * mirroring the Drive provider's internal-use rationale: the agency
 * registers its own Dropbox app key/secret, so this never needs Dropbox's
 * public-app review either.
 *
 * The final chunk is sent directly in the `finish` call rather than a
 * separate append+finish pair — Dropbox's API accepts trailing data on
 * finish, saving one round trip on every upload.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Storage_Provider_Dropbox implements Avix_Migration_Storage_Provider {

	use Avix_Migration_Storage_Oauth2;

	const CHUNK_SIZE = 4194304; // 4 MB.
	const CONTENT_BASE = 'https://content.dropboxapi.com/2';
	const API_BASE = 'https://api.dropboxapi.com/2';

	private $config;
	private $destination_id;

	public function __construct( array $config, $destination_id = null ) {
		$this->config = $config;
		$this->destination_id = $destination_id;
	}

	public function id() {
		return 'dropbox';
	}

	public function label() {
		return __( 'Dropbox', 'avix-migration' );
	}

	protected function token_endpoint() {
		return 'https://api.dropboxapi.com/oauth2/token';
	}

	public function test_connection() {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return array( 'success' => false, 'message' => $token->get_error_message() );
		}
		$response = wp_remote_post(
			self::API_BASE . '/users/get_current_account',
			array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ), 'timeout' => 15 )
		);
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300
			? array( 'success' => true, 'message' => __( 'Connected successfully.', 'avix-migration' ) )
			: array( 'success' => false, 'message' => $this->error_from_response( $response ) );
	}

	public function upload_chunk( $local_path, $remote_name, $offset, array $state ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => $token->get_error_message() );
		}

		$size = filesize( $local_path );

		$fp = fopen( $local_path, 'rb' );
		fseek( $fp, $offset, SEEK_SET );
		$chunk = fread( $fp, self::CHUNK_SIZE );
		fclose( $fp );
		$chunk_len = strlen( $chunk );
		$is_last = ( $offset + $chunk_len ) >= $size;

		if ( empty( $state['session_id'] ) ) {
			$response = $this->content_request( $token, '/files/upload_session/start', array( 'close' => false ), $chunk );
			if ( is_wp_error( $response ) ) {
				return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => $response->get_error_message() );
			}
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( empty( $body['session_id'] ) ) {
				return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => $this->error_from_response( $response ) );
			}
			$state['session_id'] = $body['session_id'];

			if ( $is_last ) {
				$finish = $this->finish_session( $token, $state['session_id'], $chunk_len, $remote_name, '' );
				if ( is_wp_error( $finish ) ) {
					return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => $finish->get_error_message() );
				}
				return array( 'done' => true, 'bytes_sent' => $chunk_len, 'state' => $state, 'error' => null );
			}

			return array( 'done' => false, 'bytes_sent' => $chunk_len, 'state' => $state, 'error' => null );
		}

		if ( $is_last ) {
			$finish = $this->finish_session( $token, $state['session_id'], $offset, $remote_name, $chunk );
			if ( is_wp_error( $finish ) ) {
				return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => $finish->get_error_message() );
			}
			return array( 'done' => true, 'bytes_sent' => $chunk_len, 'state' => $state, 'error' => null );
		}

		$response = $this->content_request(
			$token,
			'/files/upload_session/append_v2',
			array( 'cursor' => array( 'session_id' => $state['session_id'], 'offset' => $offset ), 'close' => false ),
			$chunk
		);
		if ( is_wp_error( $response ) ) {
			return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => $this->error_from_response( $response ) );
		}

		return array( 'done' => false, 'bytes_sent' => $chunk_len, 'state' => $state, 'error' => null );
	}

	private function finish_session( $token, $session_id, $offset, $remote_name, $trailing_data ) {
		$response = $this->content_request(
			$token,
			'/files/upload_session/finish',
			array(
				'cursor' => array( 'session_id' => $session_id, 'offset' => $offset ),
				'commit' => array( 'path' => $this->remote_path( $remote_name ), 'mode' => 'overwrite' ),
			),
			$trailing_data
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'avix_dropbox_finish_failed', $this->error_from_response( $response ) );
		}
		return true;
	}

	public function download( $remote_name, $local_path ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return array( 'success' => false, 'message' => $token->get_error_message() );
		}
		$response = wp_remote_post(
			self::CONTENT_BASE . '/files/download',
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization'   => 'Bearer ' . $token,
					'Dropbox-API-Arg' => wp_json_encode( array( 'path' => $this->remote_path( $remote_name ) ) ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return array( 'success' => false, 'message' => $this->error_from_response( $response ) );
		}
		file_put_contents( $local_path, wp_remote_retrieve_body( $response ) );
		return array( 'success' => true, 'message' => '' );
	}

	public function delete( $remote_name ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return array( 'success' => false, 'message' => $token->get_error_message() );
		}
		$response = wp_remote_post(
			self::API_BASE . '/files/delete_v2',
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'path' => $this->remote_path( $remote_name ) ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		return array( 'success' => $code >= 200 && $code < 300, 'message' => $code >= 200 && $code < 300 ? '' : $this->error_from_response( $response ) );
	}

	public function list_files() {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return array();
		}
		$path = trim( $this->config['remote_dir'] ?? '', '/' );
		$response = wp_remote_post(
			self::API_BASE . '/files/list_folder',
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'path' => '' !== $path ? '/' . $path : '' ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array();
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return array_column( $body['entries'] ?? array(), 'name' );
	}

	private function remote_path( $remote_name ) {
		$dir = trim( $this->config['remote_dir'] ?? '', '/' );
		return ( '' !== $dir ? '/' . $dir : '' ) . '/' . ltrim( $remote_name, '/' );
	}

	private function content_request( $token, $path, array $api_arg, $body ) {
		return wp_remote_post(
			self::CONTENT_BASE . $path,
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization'   => 'Bearer ' . $token,
					'Dropbox-API-Arg' => wp_json_encode( $api_arg ),
					'Content-Type'    => 'application/octet-stream',
				),
				'body'    => $body,
			)
		);
	}

	private function error_from_response( $response ) {
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		$summary = $decoded['error_summary'] ?? $body;
		return sprintf( 'Dropbox error (%d): %s', $code, $summary );
	}
}
