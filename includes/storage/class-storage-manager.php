<?php
/**
 * Factory: given a saved destination's decrypted config, returns the right
 * Storage_Provider instance. The only place that needs to know the
 * mapping from provider id to class.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Storage_Manager {

	public static function provider_ids() {
		return array( 's3', 'ftp', 'sftp', 'drive', 'dropbox' );
	}

	public static function labels() {
		return array(
			's3'      => __( 'S3-compatible (AWS S3, R2, Wasabi, Spaces…)', 'avix-migration' ),
			'ftp'     => __( 'FTP', 'avix-migration' ),
			'sftp'    => __( 'SFTP', 'avix-migration' ),
			'drive'   => __( 'Google Drive', 'avix-migration' ),
			'dropbox' => __( 'Dropbox', 'avix-migration' ),
		);
	}

	/**
	 * @param string $provider_id
	 * @param array  $config         Decrypted destination config.
	 * @param string|null $destination_id Needed only by OAuth2 providers, to self-persist a refreshed access token.
	 * @return Avix_Migration_Storage_Provider|null
	 */
	public static function make( $provider_id, array $config, $destination_id = null ) {
		switch ( $provider_id ) {
			case 's3':
				return new Avix_Migration_Storage_Provider_S3( $config );
			case 'ftp':
				return new Avix_Migration_Storage_Provider_Ftp( $config );
			case 'sftp':
				return new Avix_Migration_Storage_Provider_Sftp( $config );
			case 'drive':
				return new Avix_Migration_Storage_Provider_Drive( $config, $destination_id );
			case 'dropbox':
				return new Avix_Migration_Storage_Provider_Dropbox( $config, $destination_id );
			default:
				return null;
		}
	}

	/** @return Avix_Migration_Storage_Provider|null For a saved destination id. */
	public static function for_destination( $destination_id ) {
		$config = Avix_Migration_Storage_Credentials::get( $destination_id );
		if ( null === $config ) {
			return null;
		}
		return self::make( $config['provider'], $config, $destination_id );
	}
}
