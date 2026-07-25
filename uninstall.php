<?php
/**
 * Fires only when the plugin is deleted from wp-admin (Plugins > Delete),
 * never on a plain deactivate. Removes every file and option the plugin
 * created — see Avix_Migration_Util_Uninstaller for the exact list.
 *
 * @package Avix_Migration
 */

// WP_UNINSTALL_PLUGIN is only defined when WordPress itself includes this
// file as part of the delete flow — refuse to run any other way.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-autoloader.php';
Avix_Migration_Autoloader::register();

Avix_Migration_Util_Uninstaller::wipe_all();
