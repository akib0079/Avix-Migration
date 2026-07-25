<?php
/**
 * Enqueues the plugin's admin CSS/JS — only on Avix Migration's own screens,
 * never sitewide, so we never contend with the rest of wp-admin's assets.
 *
 * No build step: these are the literal files served, editable directly.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Admin_Assets {

	public static function boot() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, 'avix-migration' ) ) {
			return;
		}

		wp_enqueue_style(
			'avix-migration-admin',
			AVIX_MIGRATION_URL . 'assets/css/avix-admin.css',
			array(),
			AVIX_MIGRATION_VERSION
		);

		wp_enqueue_script(
			'avix-migration-admin',
			AVIX_MIGRATION_URL . 'assets/js/avix-admin.js',
			array(),
			AVIX_MIGRATION_VERSION,
			true
		);

		wp_localize_script(
			'avix-migration-admin',
			'AvixMigration',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'adminPostUrl' => admin_url( 'admin-post.php' ),
				'nonce'        => wp_create_nonce( Avix_Migration_Admin_Ajax::NONCE_ACTION ),
				'downloadNonce' => wp_create_nonce( Avix_Migration_Admin_Backups_Controller::DOWNLOAD_NONCE ),
				'adminEmail'   => get_option( 'admin_email' ),
				'i18n'    => array(
					'confirmDelete'  => __( 'Type DELETE to confirm this cannot be undone.', 'avix-migration' ),
					'confirmWord'    => 'DELETE',
					'genericError'   => __( 'Something went wrong. Check the log for details.', 'avix-migration' ),
					'cancelled'      => __( 'Cancelled.', 'avix-migration' ),
					'connectionLost' => __( 'Lost connection to the server — retrying…', 'avix-migration' ),
				),
			)
		);

		// Shared on every screen that embeds the destinations picker/manager
		// partial (Backup, Content Export, Schedules) — harmless no-op
		// elsewhere since its DOMContentLoaded handler bails out immediately
		// when no .avix-destination-select is present on the page.
		$has_destination_picker = false !== strpos( (string) $hook_suffix, 'avix-migration-backup' )
			|| false !== strpos( (string) $hook_suffix, 'avix-migration-content' )
			|| false !== strpos( (string) $hook_suffix, 'avix-migration-schedules' );

		if ( $has_destination_picker ) {
			wp_enqueue_script(
				'avix-migration-destinations',
				AVIX_MIGRATION_URL . 'assets/js/avix-destinations.js',
				array( 'avix-migration-admin' ),
				AVIX_MIGRATION_VERSION,
				true
			);
		}

		// Per-screen scripts, only where they're actually used.
		if ( false !== strpos( (string) $hook_suffix, 'avix-migration-backup' ) ) {
			wp_enqueue_script(
				'avix-migration-backup',
				AVIX_MIGRATION_URL . 'assets/js/avix-backup.js',
				array( 'avix-migration-admin', 'avix-migration-destinations' ),
				AVIX_MIGRATION_VERSION,
				true
			);
		}

		if ( false !== strpos( (string) $hook_suffix, 'avix-migration-import' ) ) {
			wp_enqueue_script(
				'avix-migration-import',
				AVIX_MIGRATION_URL . 'assets/js/avix-import.js',
				array( 'avix-migration-admin' ),
				AVIX_MIGRATION_VERSION,
				true
			);
		}

		if ( false !== strpos( (string) $hook_suffix, 'avix-migration-content' ) ) {
			wp_enqueue_script(
				'avix-migration-content',
				AVIX_MIGRATION_URL . 'assets/js/avix-content.js',
				array( 'avix-migration-admin', 'avix-migration-destinations' ),
				AVIX_MIGRATION_VERSION,
				true
			);
		}

		if ( false !== strpos( (string) $hook_suffix, 'avix-migration-tools' ) ) {
			wp_enqueue_script(
				'avix-migration-tools',
				AVIX_MIGRATION_URL . 'assets/js/avix-tools.js',
				array( 'avix-migration-admin' ),
				AVIX_MIGRATION_VERSION,
				true
			);
		}

		if ( false !== strpos( (string) $hook_suffix, 'avix-migration-remote' ) ) {
			wp_enqueue_script(
				'avix-migration-remote',
				AVIX_MIGRATION_URL . 'assets/js/avix-remote.js',
				array( 'avix-migration-admin' ),
				AVIX_MIGRATION_VERSION,
				true
			);
		}

		if ( false !== strpos( (string) $hook_suffix, 'avix-migration-schedules' ) ) {
			wp_enqueue_script(
				'avix-migration-schedules',
				AVIX_MIGRATION_URL . 'assets/js/avix-schedules.js',
				array( 'avix-migration-admin', 'avix-migration-destinations' ),
				AVIX_MIGRATION_VERSION,
				true
			);
		}
	}
}
