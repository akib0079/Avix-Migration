<?php
/**
 * "Keep me logged in as the current admin" — without this, restoring a
 * full-site backup replaces wp_users/wp_usermeta wholesale with the
 * source site's accounts, and the operator is immediately logged out with
 * no way back in except the source site's credentials (which they may not
 * have, e.g. restoring a client's backup). This re-applies the operator's
 * own login (captured from the pre-import snapshot, which the rollback
 * rename kept fully intact) onto the freshly-imported user tables.
 *
 * Two cases:
 *  - The operator's user_login already exists in the imported data (common:
 *    restoring the same site onto itself) — just overwrite that imported
 *    user's password hash with the operator's own, so their existing
 *    password keeps working, and make sure their capabilities grant admin.
 *  - It doesn't exist (restoring a different site's backup) — insert it as
 *    a new user at a safe, non-colliding ID.
 *
 * Reads the "before" data from the rollback snapshot's renamed-aside
 * tables (untouched by the replay), not from any separately captured copy
 * — one less thing to keep in sync.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Job_Steps_Import_Keep_Admin extends Avix_Migration_Job_Step {

	public function label() {
		return __( 'Restoring your login', 'avix-migration' );
	}

	public function execute( Avix_Migration_Job $job ) {
		if ( empty( $job->meta['keep_current_admin'] ) || empty( $job->meta['keep_admin_user_id'] ) || empty( $job->meta['has_database'] ) ) {
			return Avix_Migration_Job_Step_Result::step_complete( __( 'Login preservation not requested.', 'avix-migration' ) );
		}

		global $wpdb;
		$map = (array) ( $job->meta['rollback_map'] ?? array() );

		$backup_users_table    = $map[ $wpdb->users ] ?? null;
		$backup_usermeta_table = $map[ $wpdb->usermeta ] ?? null;

		if ( ! $backup_users_table || ! $backup_usermeta_table ) {
			return Avix_Migration_Job_Step_Result::step_complete( __( 'No pre-import user snapshot available — skipped.', 'avix-migration' ) );
		}

		$user_id = (int) $job->meta['keep_admin_user_id'];

		$captured_user = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$backup_users_table}` WHERE ID = %d", $user_id ),
			ARRAY_A
		);
		if ( ! $captured_user ) {
			return Avix_Migration_Job_Step_Result::step_complete( __( 'Could not find your account in the pre-import snapshot — skipped.', 'avix-migration' ) );
		}

		$captured_meta = $wpdb->get_results(
			$wpdb->prepare( "SELECT meta_key, meta_value FROM `{$backup_usermeta_table}` WHERE user_id = %d", $user_id ),
			ARRAY_A
		);

		$existing_id = $wpdb->get_var(
			$wpdb->prepare( "SELECT ID FROM `{$wpdb->users}` WHERE user_login = %s", $captured_user['user_login'] )
		);

		if ( $existing_id ) {
			$target_id = (int) $existing_id;
			$wpdb->update(
				$wpdb->users,
				array(
					'user_pass'  => $captured_user['user_pass'],
					'user_email' => $captured_user['user_email'],
				),
				array( 'ID' => $target_id )
			);
		} else {
			$max_id    = (int) $wpdb->get_var( "SELECT MAX(ID) FROM `{$wpdb->users}`" );
			$target_id = $max_id + 1000; // Comfortable gap from any imported ID.

			$row            = $captured_user;
			$row['ID']      = $target_id;
			$wpdb->insert( $wpdb->users, $row );
		}

		foreach ( (array) $captured_meta as $meta ) {
			$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $target_id, 'meta_key' => $meta['meta_key'] ) );
			$wpdb->insert(
				$wpdb->usermeta,
				array(
					'user_id'    => $target_id,
					'meta_key'   => $meta['meta_key'],
					'meta_value' => $meta['meta_value'],
				)
			);
		}

		Avix_Migration_Util_Logger::info(
			$job->id,
			'Operator login restored on imported site.',
			array( 'user_login' => $captured_user['user_login'], 'reused_existing' => (bool) $existing_id )
		);

		return Avix_Migration_Job_Step_Result::step_complete(
			sprintf(
				/* translators: %s: WordPress username */
				__( 'Your login (%s) will work on the imported site.', 'avix-migration' ),
				$captured_user['user_login']
			)
		);
	}
}
