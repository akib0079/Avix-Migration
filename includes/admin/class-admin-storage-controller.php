<?php
/**
 * Controller for managing saved cloud storage destinations: list/save/
 * delete/test, plus the OAuth2 authorize-URL + callback exchange for
 * Google Drive and Dropbox (S3/FTP/SFTP just take credentials directly,
 * no redirect flow needed).
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Admin_Storage_Controller {

	const OAUTH_CALLBACK_ACTION = 'avix_oauth_callback';
	const OAUTH_STATE_TRANSIENT_PREFIX = 'avix_oauth_state_';

	public static function boot() {
		add_filter( 'avix_migration_ajax_handlers', array( __CLASS__, 'register_ajax_handlers' ) );
		add_action( 'admin_post_' . self::OAUTH_CALLBACK_ACTION, array( __CLASS__, 'handle_oauth_callback' ) );
	}

	public static function register_ajax_handlers( array $handlers ) {
		$handlers['avix_storage_list']       = array( __CLASS__, 'list_destinations' );
		$handlers['avix_storage_save']       = array( __CLASS__, 'save' );
		$handlers['avix_storage_delete']     = array( __CLASS__, 'delete' );
		$handlers['avix_storage_test']       = array( __CLASS__, 'test' );
		$handlers['avix_storage_oauth_url']  = array( __CLASS__, 'oauth_url' );
		return $handlers;
	}

	public static function list_destinations() {
		wp_send_json_success( array( 'destinations' => Avix_Migration_Storage_Credentials::all_public() ) );
	}

	public static function save() {
		$provider = sanitize_key( wp_unslash( $_POST['provider'] ?? '' ) );
		if ( ! in_array( $provider, Avix_Migration_Storage_Manager::provider_ids(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown provider.', 'avix-migration' ) ), 400 );
		}

		$id = isset( $_POST['destination_id'] ) ? sanitize_text_field( wp_unslash( $_POST['destination_id'] ) ) : '';
		$config = self::read_config_fields( $provider );
		$config['name'] = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

		if ( '' !== $id && Avix_Migration_Storage_Credentials::get( $id ) ) {
			Avix_Migration_Storage_Credentials::update( $id, $config );
		} else {
			$id = Avix_Migration_Storage_Credentials::create( $provider, $config );
		}

		wp_send_json_success( array( 'destination_id' => $id ) );
	}

	public static function delete() {
		$id = isset( $_POST['destination_id'] ) ? sanitize_text_field( wp_unslash( $_POST['destination_id'] ) ) : '';
		Avix_Migration_Storage_Credentials::delete( $id );
		wp_send_json_success( array( 'deleted' => $id ) );
	}

	/** Tests either a saved destination (by id) or an in-progress unsaved form (raw fields), so the user can verify before saving. */
	public static function test() {
		$id = isset( $_POST['destination_id'] ) ? sanitize_text_field( wp_unslash( $_POST['destination_id'] ) ) : '';

		if ( '' !== $id && Avix_Migration_Storage_Credentials::get( $id ) ) {
			$provider = Avix_Migration_Storage_Manager::for_destination( $id );
		} else {
			$provider_id = sanitize_key( wp_unslash( $_POST['provider'] ?? '' ) );
			$provider = Avix_Migration_Storage_Manager::make( $provider_id, self::read_config_fields( $provider_id ) );
		}

		if ( null === $provider ) {
			wp_send_json_error( array( 'message' => __( 'Unknown destination.', 'avix-migration' ) ), 400 );
		}

		wp_send_json_success( $provider->test_connection() );
	}

	/**
	 * Google Drive and Dropbox need a browser redirect through the
	 * provider's consent screen — this returns the URL to send the admin
	 * to, with a signed `state` value the callback verifies to prevent
	 * CSRF (a malicious site can't forge a callback that looks like it
	 * came from a legitimate authorize request).
	 */
	public static function oauth_url() {
		$provider = sanitize_key( wp_unslash( $_POST['provider'] ?? '' ) );
		$client_id = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
		$client_secret = sanitize_text_field( wp_unslash( $_POST['client_secret'] ?? '' ) );

		if ( ! in_array( $provider, array( 'drive', 'dropbox' ), true ) || '' === $client_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing client id.', 'avix-migration' ) ), 400 );
		}

		$state = Avix_Migration_Util_Crypto::random_token( 16 );
		set_transient(
			self::OAUTH_STATE_TRANSIENT_PREFIX . $state,
			array( 'provider' => $provider, 'client_id' => $client_id, 'client_secret' => $client_secret, 'user_id' => get_current_user_id() ),
			15 * MINUTE_IN_SECONDS
		);

		$redirect_uri = admin_url( 'admin-post.php?action=' . self::OAUTH_CALLBACK_ACTION );

		if ( 'drive' === $provider ) {
			$url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(
				array(
					'client_id'     => $client_id,
					'redirect_uri'  => $redirect_uri,
					'response_type' => 'code',
					'access_type'   => 'offline',
					'prompt'        => 'consent', // Forces a refresh_token even on a re-auth.
					'scope'         => 'https://www.googleapis.com/auth/drive.file',
					'state'         => $state,
				)
			);
		} else {
			$url = 'https://www.dropbox.com/oauth2/authorize?' . http_build_query(
				array(
					'client_id'              => $client_id,
					'redirect_uri'           => $redirect_uri,
					'response_type'          => 'code',
					'token_access_type'      => 'offline',
					'state'                  => $state,
				)
			);
		}

		wp_send_json_success( array( 'url' => $url ) );
	}

	/** Browser lands here after the provider's consent screen — exchanges the code for tokens and saves a new destination. */
	public static function handle_oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'avix-migration' ), 403 );
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$stored = get_transient( self::OAUTH_STATE_TRANSIENT_PREFIX . $state );

		if ( ! $stored || (int) $stored['user_id'] !== get_current_user_id() ) {
			wp_die( esc_html__( 'This authorization link is invalid or has expired. Please try connecting again.', 'avix-migration' ) );
		}
		delete_transient( self::OAUTH_STATE_TRANSIENT_PREFIX . $state );

		if ( '' === $code ) {
			self::redirect_with_notice( 'error', __( 'Authorization was cancelled or denied.', 'avix-migration' ) );
		}

		$redirect_uri = admin_url( 'admin-post.php?action=' . self::OAUTH_CALLBACK_ACTION );
		$token_endpoint = 'drive' === $stored['provider'] ? 'https://oauth2.googleapis.com/token' : 'https://api.dropboxapi.com/oauth2/token';

		$response = wp_remote_post(
			$token_endpoint,
			array(
				'timeout' => 20,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $stored['client_id'],
					'client_secret' => $stored['client_secret'],
					'redirect_uri'  => $redirect_uri,
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::redirect_with_notice( 'error', $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['refresh_token'] ) ) {
			self::redirect_with_notice( 'error', $body['error_description'] ?? __( 'The provider did not return a refresh token.', 'avix-migration' ) );
		}

		Avix_Migration_Storage_Credentials::create(
			$stored['provider'],
			array(
				'name'             => 'drive' === $stored['provider'] ? __( 'Google Drive', 'avix-migration' ) : __( 'Dropbox', 'avix-migration' ),
				'client_id'        => $stored['client_id'],
				'client_secret'    => $stored['client_secret'],
				'refresh_token'    => $body['refresh_token'],
				'access_token'     => $body['access_token'] ?? '',
				'token_expires_at' => time() + (int) ( $body['expires_in'] ?? 3600 ),
			)
		);

		self::redirect_with_notice( 'success', __( 'Connected successfully.', 'avix-migration' ) );
	}

	private static function redirect_with_notice( $type, $message ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'avix-migration-backup', 'avix_oauth_notice' => $type, 'avix_oauth_message' => rawurlencode( $message ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function read_config_fields( $provider ) {
		$fields = array(
			's3'      => array( 'endpoint', 'bucket', 'region', 'access_key', 'secret_key', 'path_style' ),
			'ftp'     => array( 'host', 'port', 'username', 'password', 'remote_dir', 'use_ftps', 'active_mode' ),
			'sftp'    => array( 'host', 'port', 'username', 'password', 'private_key', 'passphrase', 'remote_dir' ),
			'drive'   => array( 'folder_id' ),
			'dropbox' => array( 'remote_dir' ),
		);

		$config = array();
		foreach ( $fields[ $provider ] ?? array() as $field ) {
			if ( ! isset( $_POST[ $field ] ) ) {
				continue;
			}
			if ( in_array( $field, array( 'path_style', 'use_ftps', 'active_mode' ), true ) ) {
				$config[ $field ] = ! empty( $_POST[ $field ] );
			} elseif ( 'private_key' === $field ) {
				$config[ $field ] = wp_unslash( $_POST[ $field ] ); // Multi-line PEM — no sanitize_textarea_field, it would mangle line breaks.
			} else {
				$config[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
			}
		}
		return $config;
	}
}
