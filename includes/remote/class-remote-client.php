<?php
/**
 * Outbound signed requests to a remote site's avix/v1 REST API — the one
 * place that knows how to actually talk to a remote, used by both push
 * (Storage_Provider_Remote_Site, the AJAX proxy that triggers/polls a
 * remote import) and pull (the AJAX proxy that triggers/polls a remote
 * export, and Import_Download_Remote that fetches the finished archive).
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Remote_Client {

	/**
	 * @param array  $remote Decrypted record from Remote_Store::get_remote().
	 * @param string $method
	 * @param string $path   e.g. '/avix/v1/handshake' — no host, no /wp-json prefix.
	 * @param string $body   Raw body to sign and send.
	 * @param array  $args   Extra wp_remote_request() args (timeout, stream, filename, etc.).
	 * @return array|WP_Error
	 */
	public static function request( array $remote, $method, $path, $body = '', array $args = array() ) {
		$path = '/' . ltrim( $path, '/' );
		$signed_headers = Avix_Migration_Remote_Auth::sign_request( $method, $path, $body, $remote['key_id'], $remote['secret'] );

		// A plain array_merge() on the whole $args would let a caller's own
		// 'headers' entry (e.g. request_json()'s Content-Type) silently
		// REPLACE the signed X-Avix-* headers rather than add to them —
		// array_merge doesn't recurse into nested arrays. Merge the
		// headers sub-array explicitly so both survive.
		$extra_headers = $args['headers'] ?? array();
		unset( $args['headers'] );

		$url = untrailingslashit( $remote['site_url'] ) . '/wp-json' . $path;

		return wp_remote_request(
			$url,
			array_merge(
				array(
					'method'  => $method,
					'headers' => array_merge( $signed_headers, $extra_headers ),
					'body'    => $body,
					'timeout' => 30,
				),
				$args
			)
		);
	}

	/** @return array|WP_Error Decoded JSON body, or the original WP_Error. */
	public static function request_json( array $remote, $method, $path, array $body_data = array() ) {
		$body = empty( $body_data ) ? '' : wp_json_encode( $body_data );
		$response = self::request( $remote, $method, $path, $body, array( 'headers' => array( 'Content-Type' => 'application/json' ) ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $decoded ) && isset( $decoded['message'] ) ? $decoded['message'] : sprintf( 'HTTP %d', $code );
			return new WP_Error( 'avix_remote_request_failed', $message, array( 'status' => $code ) );
		}

		return is_array( $decoded ) ? $decoded : array();
	}
}
