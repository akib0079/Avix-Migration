<?php
/**
 * Shared OAuth2 access-token refresh logic for Google Drive and Dropbox —
 * both use the same "exchange a long-lived refresh_token for a short-lived
 * access_token" flow, just against different endpoints. A class using this
 * trait must set $this->config (with client_id/client_secret/refresh_token/
 * access_token/token_expires_at) and $this->destination_id, and implement
 * token_endpoint().
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Avix_Migration_Storage_Oauth2 {

	/**
	 * Returns a valid access token, refreshing it first if it's expired or
	 * about to expire — called before every API request rather than only
	 * on failure, since a request made with a stale token would otherwise
	 * need to be retried anyway.
	 *
	 * @return string|WP_Error
	 */
	protected function get_access_token() {
		$expires_at = (int) ( $this->config['token_expires_at'] ?? 0 );

		if ( ! empty( $this->config['access_token'] ) && $expires_at > ( time() + 60 ) ) {
			return $this->config['access_token'];
		}

		if ( empty( $this->config['refresh_token'] ) ) {
			return new WP_Error( 'avix_oauth_no_refresh_token', __( 'Not connected — no refresh token on file. Reconnect this destination.', 'avix-migration' ) );
		}

		$response = wp_remote_post(
			$this->token_endpoint(),
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'     => $this->config['client_id'],
					'client_secret' => $this->config['client_secret'],
					'refresh_token' => $this->config['refresh_token'],
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			$message = $body['error_description'] ?? $body['error'] ?? __( 'Unknown OAuth error.', 'avix-migration' );
			return new WP_Error( 'avix_oauth_refresh_failed', $message );
		}

		$this->config['access_token']     = $body['access_token'];
		$this->config['token_expires_at'] = time() + (int) ( $body['expires_in'] ?? 3600 );

		if ( $this->destination_id ) {
			Avix_Migration_Storage_Credentials::update(
				$this->destination_id,
				array(
					'access_token'     => $this->config['access_token'],
					'token_expires_at' => $this->config['token_expires_at'],
				)
			);
		}

		return $this->config['access_token'];
	}

	/** @return string The token refresh endpoint URL for this provider. */
	abstract protected function token_endpoint();
}
