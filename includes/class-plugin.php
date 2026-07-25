<?php
/**
 * Composition root. Everything the plugin does on a normal WordPress
 * request is wired up from boot() — the admin UI, the generic job-progress
 * AJAX endpoints every wizard polls, and the scheduler.
 *
 * Deliberately a thin singleton: it exists to give hooks one obvious place
 * to attach from, not to hold business logic itself.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Plugin {

	/** @var self|null */
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function boot() {
		load_plugin_textdomain( 'avix-migration', false, dirname( AVIX_MIGRATION_BASENAME ) . '/languages' );

		Avix_Migration_Util_Filesystem::create_storage_dirs();

		Avix_Migration_Schedule_Scheduler::boot();

		// NOT inside the is_admin() block below: is_admin() is false during
		// a REST API request (wp-json/* is front-end context, not
		// wp-admin), so a remote site's incoming avix/v1/* request would
		// never see these routes registered if this were gated the same
		// way the wp-admin-only controllers are.
		Avix_Migration_Remote_Rest_Controller::boot();
		Avix_Migration_Cli_Commands::boot(); // Self-guards on class_exists( 'WP_CLI' ).

		if ( is_admin() ) {
			Avix_Migration_Admin_Menu::boot();
			Avix_Migration_Admin_Assets::boot();
			Avix_Migration_Admin_Backups_Controller::boot();
			Avix_Migration_Admin_Backup_Controller::boot();
			Avix_Migration_Admin_Import_Controller::boot();
			Avix_Migration_Admin_Content_Controller::boot();
			Avix_Migration_Admin_Schedule_Controller::boot();
			Avix_Migration_Admin_Storage_Controller::boot();
			Avix_Migration_Admin_Remote_Controller::boot();
			Avix_Migration_Admin_Tools_Controller::boot();
			// Admin_Ajax::boot() must run last — it reads the handler map,
			// which other controllers populate via the
			// avix_migration_ajax_handlers filter during their own boot().
			Avix_Migration_Admin_Ajax::boot();
		}
	}
}
