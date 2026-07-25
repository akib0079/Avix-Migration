<?php
/**
 * S3-compatible object storage — Amazon S3, Cloudflare R2, Wasabi,
 * DigitalOcean Spaces, MinIO — via a custom endpoint and a path-style
 * addressing toggle (some of those need path-style; AWS defaults to
 * virtual-hosted). Signs every request with Storage_Sigv4, verified
 * against AWS's own published test vectors (see sigv4-test.php).
 *
 * Uploads as a real multipart upload (initiate -> upload part -> complete),
 * one part per upload_chunk() call — this is what makes upload resumable
 * across job ticks: each part is its own signed PUT, and only the
 * completed part's ETag needs to be remembered in $state to finish later.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Storage_Provider_S3 implements Avix_Migration_Storage_Provider {

	/** S3's own minimum part size (except the last part), 5 MB. */
	const PART_SIZE = 5242880;

	private $config;

	public function __construct( array $config ) {
		$this->config = $config;
	}

	public function id() {
		return 's3';
	}

	public function label() {
		return __( 'S3-compatible storage', 'avix-migration' );
	}

	public function test_connection() {
		$response = $this->request( 'GET', '', array( 'query_string' => 'list-type=2&max-keys=1' ) );
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return array( 'success' => true, 'message' => __( 'Connected successfully.', 'avix-migration' ) );
		}
		return array( 'success' => false, 'message' => $this->error_from_response( $response ) );
	}

	public function upload_chunk( $local_path, $remote_name, $offset, array $state ) {
		$size = filesize( $local_path );

		if ( empty( $state['upload_id'] ) ) {
			$initiate = $this->request( 'POST', $remote_name, array( 'query_string' => 'uploads=' ) );
			if ( is_wp_error( $initiate ) ) {
				return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => $initiate->get_error_message() );
			}
			$body = wp_remote_retrieve_body( $initiate );
			if ( ! preg_match( '#<UploadId>([^<]+)</UploadId>#', $body, $m ) ) {
				return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => __( 'S3 did not return an upload id.', 'avix-migration' ) );
			}
			$state['upload_id'] = $m[1];
			$state['part_number'] = 1;
			$state['parts'] = array();
		}

		$fp = fopen( $local_path, 'rb' );
		fseek( $fp, $offset, SEEK_SET );
		$chunk = fread( $fp, self::PART_SIZE );
		fclose( $fp );

		if ( '' === $chunk ) {
			// Nothing left to read — finalize.
			$complete = $this->complete_multipart( $remote_name, $state['upload_id'], $state['parts'] );
			if ( is_wp_error( $complete ) ) {
				return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => $complete->get_error_message() );
			}
			return array( 'done' => true, 'bytes_sent' => 0, 'state' => $state, 'error' => null );
		}

		$query = 'partNumber=' . $state['part_number'] . '&uploadId=' . rawurlencode( $state['upload_id'] );
		$response = $this->request( 'PUT', $remote_name, array( 'query_string' => $query, 'body' => $chunk ) );

		if ( is_wp_error( $response ) ) {
			return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return array( 'done' => false, 'bytes_sent' => 0, 'state' => $state, 'error' => $this->error_from_response( $response ) );
		}

		$etag = trim( wp_remote_retrieve_header( $response, 'etag' ), '"' );
		$state['parts'][] = array( 'PartNumber' => $state['part_number'], 'ETag' => $etag );
		$state['part_number']++;

		$bytes_sent = strlen( $chunk );
		$done = ( $offset + $bytes_sent ) >= $size;

		if ( $done ) {
			$complete = $this->complete_multipart( $remote_name, $state['upload_id'], $state['parts'] );
			if ( is_wp_error( $complete ) ) {
				return array( 'done' => false, 'bytes_sent' => $bytes_sent, 'state' => $state, 'error' => $complete->get_error_message() );
			}
		}

		return array( 'done' => $done, 'bytes_sent' => $bytes_sent, 'state' => $state, 'error' => null );
	}

	private function complete_multipart( $remote_name, $upload_id, array $parts ) {
		$xml = '<CompleteMultipartUpload>';
		foreach ( $parts as $part ) {
			$xml .= '<Part><PartNumber>' . (int) $part['PartNumber'] . '</PartNumber><ETag>"' . esc_html( $part['ETag'] ) . '"</ETag></Part>';
		}
		$xml .= '</CompleteMultipartUpload>';

		$response = $this->request( 'POST', $remote_name, array( 'query_string' => 'uploadId=' . rawurlencode( $upload_id ), 'body' => $xml ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'avix_s3_complete_failed', $this->error_from_response( $response ) );
		}
		return true;
	}

	public function download( $remote_name, $local_path ) {
		$response = $this->request( 'GET', $remote_name );
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
		$response = $this->request( 'DELETE', $remote_name );
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		return array( 'success' => ( $code >= 200 && $code < 300 ) || 404 === $code, 'message' => 404 === $code ? '' : $this->error_from_response( $response ) );
	}

	public function list_files() {
		$response = $this->request( 'GET', '', array( 'query_string' => 'list-type=2' ) );
		if ( is_wp_error( $response ) ) {
			return array();
		}
		$body = wp_remote_retrieve_body( $response );
		preg_match_all( '#<Key>([^<]+)</Key>#', $body, $m );
		return $m[1] ?? array();
	}

	/**
	 * Signs and sends one request. $path is the object key (no leading
	 * slash needed — added here), empty for bucket-level operations.
	 */
	private function request( $method, $path, array $opts = array() ) {
		$body = $opts['body'] ?? '';
		$path_style = ! empty( $this->config['path_style'] );
		$bucket     = $this->config['bucket'];
		$endpoint_host = preg_replace( '#^https?://#', '', rtrim( $this->config['endpoint'], '/' ) );

		$host = $path_style ? $endpoint_host : "{$bucket}.{$endpoint_host}";
		$uri_path = $path_style ? '/' . $bucket . '/' . ltrim( $path, '/' ) : '/' . ltrim( $path, '/' );
		if ( '' === $path && $path_style ) {
			$uri_path = '/' . $bucket;
		}

		$amz_date = gmdate( 'Ymd\THis\Z' );
		$payload_hash = hash( 'sha256', $body );

		$signed = Avix_Migration_Storage_Sigv4::sign(
			array(
				'method'       => $method,
				'host'         => $host,
				'path'         => $uri_path,
				'query_string' => $opts['query_string'] ?? '',
				'headers'      => array(),
				'payload_hash' => $payload_hash,
				'amz_date'     => $amz_date,
			),
			$this->config['access_key'],
			$this->config['secret_key'],
			$this->config['region'],
			's3'
		);

		$url = 'https://' . $host . $uri_path . ( ! empty( $opts['query_string'] ) ? '?' . $opts['query_string'] : '' );

		return wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'headers' => array_merge(
					$signed['headers'],
					array( 'Authorization' => $signed['authorization'] )
				),
				'body'    => $body,
				'timeout' => 30,
			)
		);
	}

	private function error_from_response( $response ) {
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( preg_match( '#<Message>([^<]+)</Message>#', $body, $m ) ) {
			return sprintf( 'S3 error (%d): %s', $code, $m[1] );
		}
		return sprintf( 'S3 error: HTTP %d', $code );
	}
}
