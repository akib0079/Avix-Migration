<?php
/**
 * Plugin Name:       Avix Migration
 * Plugin URI:        https://avixdigitalagency.com/avix-migration
 * Description:       Backup, migrate, and transfer WordPress sites — full-site or selected content — between installs.
 * Version:           1.0.10
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Avix Digital Agency
 * Author URI:        https://avixdigitalagency.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       avix-migration
 * Domain Path:       /languages
 *
 * @package Avix_Migration
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------
// Core constants.
// ---------------------------------------------------------------------

define( 'AVIX_MIGRATION_VERSION', '1.0.10' );
define( 'AVIX_MIGRATION_FORMAT_VERSION', 1 );
define( 'AVIX_MIGRATION_FILE', __FILE__ );
define( 'AVIX_MIGRATION_DIR', plugin_dir_path( __FILE__ ) );
define( 'AVIX_MIGRATION_URL', plugin_dir_url( __FILE__ ) );
define( 'AVIX_MIGRATION_BASENAME', plugin_basename( __FILE__ ) );

// Minimum requirements. Checked before anything else in this file runs.
define( 'AVIX_MIGRATION_MIN_PHP', '7.4' );
define( 'AVIX_MIGRATION_MIN_WP', '5.6' );

/**
 * Storage layout, all relative to wp-content/ so it survives WP core updates
 * and stays outside any theme/plugin directory a bulk "delete inactive
 * plugins" cleanup might target.
 */
define( 'AVIX_MIGRATION_STORAGE_DIRNAME', 'avix-backups' );

/**
 * Bail out (with an admin notice) instead of fataling on old PHP/WP.
 * A fatal error on activation is the single worst first impression a
 * plugin can make — this check must never itself require a PHP 7.4 feature.
 */
function avix_migration_requirements_met() {
	if ( version_compare( PHP_VERSION, AVIX_MIGRATION_MIN_PHP, '<' ) ) {
		return false;
	}
	if ( version_compare( get_bloginfo( 'version' ), AVIX_MIGRATION_MIN_WP, '<' ) ) {
		return false;
	}
	return true;
}

function avix_migration_requirements_notice() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: required PHP version, 2: required WP version */
				__( 'Avix Migration requires PHP %1$s+ and WordPress %2$s+. It has been deactivated.', 'avix-migration' ),
				AVIX_MIGRATION_MIN_PHP,
				AVIX_MIGRATION_MIN_WP
			)
		)
	);
}

/**
 * Autoloader — maps Avix_Migration_Foo_Bar => includes/foo/class-foo-bar.php.
 */
require_once AVIX_MIGRATION_DIR . 'includes/class-autoloader.php';

/**
 * phpseclib (SFTP support) — guarded because another plugin bundling its
 * own copy of phpseclib may have already loaded the same classes; a second
 * unconditional require would fatal with "cannot redeclare class".
 */
if ( ! class_exists( '\phpseclib3\Net\SFTP' ) && is_readable( AVIX_MIGRATION_DIR . 'vendor/autoload.php' ) ) {
	require_once AVIX_MIGRATION_DIR . 'vendor/autoload.php';
}

/**
 * Boots the plugin once requirements are confirmed. Everything else in the
 * plugin is reachable from Avix_Migration_Plugin::instance().
 */
function avix_migration_init() {
	if ( ! avix_migration_requirements_met() ) {
		add_action( 'admin_notices', 'avix_migration_requirements_notice' );
		return;
	}

	Avix_Migration_Autoloader::register();
	Avix_Migration_Plugin::instance()->boot();
}
add_action( 'plugins_loaded', 'avix_migration_init' );

/**
 * Activation: create storage directories, harden them, schedule the
 * housekeeping cron. Deliberately does NOT create DB tables — job/schedule
 * state lives in files and options, not custom tables, so there is nothing
 * to migrate across plugin versions.
 */
function avix_migration_activate() {
	require_once AVIX_MIGRATION_DIR . 'includes/class-autoloader.php';
	Avix_Migration_Autoloader::register();

	Avix_Migration_Util_Filesystem::create_storage_dirs();
	Avix_Migration_Schedule_Scheduler::activate();

	update_option( 'avix_migration_activated_at', time(), false );
}
register_activation_hook( __FILE__, 'avix_migration_activate' );

/**
 * Deactivation: clear scheduled cron events only. Backups, settings, and
 * connection keys are left untouched — deactivating is not "delete my
 * backups." Full removal only happens via uninstall.php, and only when the
 * user has opted in via Tools > Delete all plugin data.
 */
function avix_migration_deactivate() {
	require_once AVIX_MIGRATION_DIR . 'includes/class-autoloader.php';
	Avix_Migration_Autoloader::register();

	Avix_Migration_Schedule_Scheduler::deactivate();
}
register_deactivation_hook( __FILE__, 'avix_migration_deactivate' );
