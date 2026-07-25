<?php
/**
 * Google Drive via OAuth2 + the resumable upload session API. Internal-use
 * only, per the design brief: the agency registers its own OAuth
 * application (client id/secret) and this plugin's redirect URI, which
 * sidesteps Google's public-app verification review entirely — that
 * review is only required for apps requesting access on OTHER people's
 * behalf, not for an agency authorizing its own Drive.
 *
 * Drive chunk size must be a multiple of 256 KiB except the final chunk
 * (Google's own requirement); 4 MiB is exactly 16 * 256 KiB.
 *
 * Files are identified by name search within the configured folder rather
 * than a persisted id map — Drive has no concept of a path, so "does
 * archive.avix already exist" is answered by querying, which also makes
 * this self-healing if state is ever lost.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Storage_Provider_Drive implements Avix_Migration_Storage_Provider {

	use Avix_Migration_Storage_Oauth2;

	const CHUNK_SIZE = 4194304; // 4 MiB — multiple of the required 256 KiB.
	const API_BASE = 'https://www.googleapis.com/drive/v3';
	const UPLOAD_BASE = 'https://www.googleapis.com/upload/drive/v3';

	private $config;
	private $destination_id;

	public function __construct( array $config, $destination_id = null ) {
		$this->config = $config;
		$this->destination_id = $destination_id;
	}

	public function id() {
		return 'drive';
	}

	public function label() {
		return __( 'Google Drive', 'avix-migration' );
	}

	protected function token_endpoint() {
		return 'https://oauth2.googleapis.com/token';
	}

	public function test_connection() {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return array( 'success' => false, 'message' => $token->get_error_message() );
		}
		$response = wp_remote_get(
			self::API_BASE . '/about?fields=user',
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

		if ( empty( $state['session_uri'] ) ) {
			$metadata = array( 'name' => $remote_name );
			if ( ! empty( $this->config['folder_id'] ) ) {
				$metadata['parents'] = array( $this->config['folder_id'] );
			}

			$init = wp_remote_post(
				self::UPLOAD_BASE . '/files?uploadType=resumable',
				array(
					'timeout' => 20,
					'headers' => array(
						'Authorization'          => 'Bearer ' . $token,
						'Content-Type'           => 'application/json; charset=UTF-8',
						'X-Upload-Content-Type'  => 'application/octet-stream',
					),
					'body'    => wp_json_encode( $metadata ),
				)
			);
			if ( is_wp_error( $init ) ) {
				return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => $init->get_error_message() );
			}
			$location = wp_remote_retrieve_header( $init, 'location' );
			if ( ! $location ) {
				return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => $this->error_from_response( $init ) );
			}
			$state['session_uri'] = $location;
		}

		$fp = fopen( $local_path, 'rb' );
		fseek( $fp, $offset, SEEK_SET );
		$chunk = fread( $fp, self::CHUNK_SIZE );
		fclose( $fp );

		$chunk_len = strlen( $chunk );
		$range_end = $offset + $chunk_len - 1;

		$response = wp_remote_request(
			$state['session_uri'],
			array(
				'method'  => 'PUT',
				'timeout' => 60,
				'headers' => array(
					'Content-Length' => (string) $chunk_len,
					'Content-Range'  => "bytes {$offset}-{$range_end}/{$size}",
				),
				'body'    => $chunk,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 308 === $code ) {
			return array( 'done' => false, 'bytes_sent' => $chunk_len, 'state' => $state, 'error' => null );
		}
		if ( $code >= 200 && $code < 300 ) {
			return array( 'done' => true, 'bytes_sent' => $chunk_len, 'state' => $state, 'error' => null );
		}

		return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => $this->error_from_response( $response ) );
	}

	public function download( $remote_name, $local_path ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return array( 'success' => false, 'message' => $token->get_error_message() );
		}
		$file_id = $this->find_file_id( $remote_name );
		if ( ! $file_id ) {
			return array( 'success' => false, 'message' => __( 'File not found in Drive.', 'avix-migration' ) );
		}
		$response = wp_remote_get(
			self::API_BASE . "/files/{$file_id}?alt=media",
			array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ), 'timeout' => 60, 'stream' => true, 'filename' => $local_path )
		);
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300
			? array( 'success' => true, 'message' => '' )
			: array( 'success' => false, 'message' => $this->error_from_response( $response ) );
	}

	public function delete( $remote_name ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return array( 'success' => false, 'message' => $token->get_error_message() );
		}
		$file_id = $this->find_file_id( $remote_name );
		if ( ! $file_id ) {
			return array( 'success' => true, 'message' => '' ); // Already gone.
		}
		$response = wp_remote_request(
			self::API_BASE . "/files/{$file_id}",
			array( 'method' => 'DELETE', 'headers' => array( 'Authorization' => 'Bearer ' . $token ), 'timeout' => 15 )
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
		$query = ! empty( $this->config['folder_id'] )
			? "'" . $this->config['folder_id'] . "' in parents and trashed=false"
			: 'trashed=false';

		$response = wp_remote_get(
			self::API_BASE . '/files?' . http_build_query( array( 'q' => $query, 'fields' => 'files(name)', 'pageSize' => 1000 ) ),
			array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ), 'timeout' => 20 )
		);
		if ( is_wp_error( $response ) ) {
			return array();
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return array_column( $body['files'] ?? array(), 'name' );
	}

	private function find_file_id( $remote_name ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return null;
		}
		$folder_clause = ! empty( $this->config['folder_id'] ) ? " and '" . $this->config['folder_id'] . "' in parents" : '';
		$query = "name='" . str_replace( "'", "\\'", $remote_name ) . "' and trashed=false" . $folder_clause;

		$response = wp_remote_get(
			self::API_BASE . '/files?' . http_build_query( array( 'q' => $query, 'fields' => 'files(id)' ) ),
			array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ), 'timeout' => 15 )
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return $body['files'][0]['id'] ?? null;
	}

	private function error_from_response( $response ) {
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );
		$message = $body['error']['message'] ?? '';
		return $message ? sprintf( 'Google Drive error (%d): %s', $code, $message ) : sprintf( 'Google Drive error: HTTP %d', $code );
	}
}
