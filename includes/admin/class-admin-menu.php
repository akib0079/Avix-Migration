<?php
/**
 * Registers the wp-admin menu and renders each screen inside a shared
 * branded layout (header + tab nav). Individual screens are plain PHP view
 * templates under includes/admin/views/ — no framework, no build step.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Admin_Menu {

	const CAPABILITY = 'manage_options';

	/**
	 * Screen definitions: slug => [ title, view file, menu label ]. Order
	 * here is the order they appear in the submenu, which is also the
	 * logical task flow: see what you have -> back it up -> export content
	 * -> bring something in -> manage what's stored -> automate it ->
	 * connect sites -> diagnostics.
	 */
	public static function screens() {
		return array(
			'avix-migration'          => array(
				'title' => __( 'Dashboard', 'avix-migration' ),
				'view'  => 'dashboard',
			),
			'avix-migration-backup'   => array(
				'title' => __( 'Backup', 'avix-migration' ),
				'view'  => 'backup',
			),
			'avix-migration-content'  => array(
				'title' => __( 'Content Export', 'avix-migration' ),
				'view'  => 'content-export',
			),
			'avix-migration-import'   => array(
				'title' => __( 'Import', 'avix-migration' ),
				'view'  => 'import',
			),
			'avix-migration-backups'  => array(
				'title' => __( 'Backups', 'avix-migration' ),
				'view'  => 'backups',
			),
			'avix-migration-schedules' => array(
				'title' => __( 'Schedules', 'avix-migration' ),
				'view'  => 'schedules',
			),
			'avix-migration-remote'   => array(
				'title' => __( 'Remote Sites', 'avix-migration' ),
				'view'  => 'remote-sites',
			),
			'avix-migration-tools'    => array(
				'title' => __( 'Tools', 'avix-migration' ),
				'view'  => 'tools',
			),
		);
	}

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	/**
	 * URL of the brand mark shown in the admin header.
	 *
	 * Prefers a real logo file dropped in at assets/img/avix-logo.(svg|png|jpg|webp)
	 * over the bundled vector approximation, so the agency's own asset can be
	 * used verbatim without editing any code — drop the file in and it's
	 * picked up. Falls back to assets/img/avix-mark.svg when none is present.
	 *
	 * @return string
	 */
	public static function brand_mark_url() {
		foreach ( array( 'avix-logo.svg', 'avix-logo.png', 'avix-logo.jpg', 'avix-logo.webp' ) as $candidate ) {
			if ( is_readable( AVIX_MIGRATION_DIR . 'assets/img/' . $candidate ) ) {
				return AVIX_MIGRATION_URL . 'assets/img/' . $candidate;
			}
		}
		return apply_filters( 'avix_migration_brand_mark_url', AVIX_MIGRATION_URL . 'assets/img/avix-mark.svg' );
	}

	public static function register_menu() {
		$screens = self::screens();
		$first   = array_key_first( $screens );

		add_menu_page(
			__( 'Avix Migration', 'avix-migration' ),
			__( 'Avix Migration', 'avix-migration' ),
			self::CAPABILITY,
			$first,
			array( __CLASS__, 'render' ),
			'dashicons-migrate',
			80
		);

		foreach ( $screens as $slug => $screen ) {
			add_submenu_page(
				$first,
				$screen['title'] . ' — ' . __( 'Avix Migration', 'avix-migration' ),
				$screen['title'],
				self::CAPABILITY,
				$slug,
				array( __CLASS__, 'render' )
			);
		}
	}

	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'avix-migration' ) );
		}

		$current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'avix-migration';
		$screens = self::screens();
		if ( ! isset( $screens[ $current ] ) ) {
			$current = 'avix-migration';
		}

		self::render_view( 'layout-header', array( 'current' => $current, 'screens' => $screens ) );
		self::render_view( $screens[ $current ]['view'] );
		self::render_view( 'layout-footer' );
	}

	/**
	 * Includes a view template with $args extracted into local scope. Views
	 * are trusted plugin code, not user input, so extract() here is safe.
	 */
	public static function render_view( $name, array $args = array() ) {
		$file = AVIX_MIGRATION_DIR . 'includes/admin/views/' . $name . '.php';
		if ( ! is_readable( $file ) ) {
			return;
		}
		extract( $args ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		include $file;
	}
}
