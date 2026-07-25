<?php
/**
 * Tools — system diagnostics, the live log, and housekeeping actions.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$site   = Avix_Migration_Util_Sysinfo::snapshot();
$jobs   = Avix_Migration_Job_Store::all_ids();
$log    = Avix_Migration_Util_Logger::tail( 'plugin', 100 );

$required_extensions = array( 'zip', 'zlib', 'mysqli', 'pdo_mysql', 'openssl', 'curl', 'mbstring', 'json' );
$missing_extensions  = array_filter(
	$required_extensions,
	function ( $ext ) {
		return ! extension_loaded( $ext );
	}
);

$stuck_count = 0;
foreach ( $jobs as $id ) {
	$job = Avix_Migration_Job_Store::load( $id );
	if ( $job && ! $job->is_terminal() ) {
		$stuck_count++;
	}
}
?>

<div class="avix-card">
	<h2 class="avix-section-title avix-mt-0"><?php esc_html_e( 'System info', 'avix-migration' ); ?></h2>
	<?php if ( ! empty( $missing_extensions ) ) : ?>
		<p class="avix-badge avix-badge-danger" style="margin-bottom:12px;">
			<?php
			printf(
				/* translators: %s: comma-separated PHP extension names */
				esc_html__( 'Missing PHP extensions: %s', 'avix-migration' ),
				esc_html( implode( ', ', $missing_extensions ) )
			);
			?>
		</p>
	<?php endif; ?>
	<table class="avix-table">
		<tbody>
			<tr><td><?php esc_html_e( 'WordPress version', 'avix-migration' ); ?></td><td><?php echo esc_html( $site['wp_version'] ); ?></td></tr>
			<tr><td><?php esc_html_e( 'PHP version', 'avix-migration' ); ?></td><td><?php echo esc_html( $site['php_version'] ); ?></td></tr>
			<tr><td><?php esc_html_e( 'MySQL version', 'avix-migration' ); ?></td><td><?php echo esc_html( $site['mysql_version'] ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Table prefix', 'avix-migration' ); ?></td><td><?php echo esc_html( $site['table_prefix'] ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Memory limit', 'avix-migration' ); ?></td><td><?php echo esc_html( $site['memory_limit'] ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Max execution time', 'avix-migration' ); ?></td><td><?php echo esc_html( $site['max_execution_time'] ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Disk free', 'avix-migration' ); ?></td><td><?php echo null === $site['disk_free'] ? '—' : esc_html( Avix_Migration_Util_Filesystem::human_size( $site['disk_free'] ) ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Active plugins', 'avix-migration' ); ?></td><td><?php echo esc_html( $site['plugin_count'] ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Active theme', 'avix-migration' ); ?></td><td><?php echo esc_html( $site['active_theme']['name'] ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Multisite', 'avix-migration' ); ?></td><td><?php echo $site['is_multisite'] ? esc_html__( 'Yes', 'avix-migration' ) : esc_html__( 'No', 'avix-migration' ); ?></td></tr>
		</tbody>
	</table>
</div>

<div class="avix-card">
	<div class="avix-flex-between">
		<h2 class="avix-section-title avix-mt-0"><?php esc_html_e( 'Housekeeping', 'avix-migration' ); ?></h2>
	</div>
	<div class="avix-toggle-row">
		<div>
			<div class="avix-toggle-row__label"><?php esc_html_e( 'Jobs currently running or queued', 'avix-migration' ); ?></div>
			<div class="avix-toggle-row__hint"><?php echo esc_html( sprintf( _n( '%d job', '%d jobs', $stuck_count, 'avix-migration' ), $stuck_count ) ); ?></div>
		</div>
		<button
			type="button"
			class="avix-btn"
			data-avix-confirm-action="avix_reset_stuck_jobs"
			data-avix-confirm-title="<?php esc_attr_e( 'Reset all in-progress jobs?', 'avix-migration' ); ?>"
			data-avix-confirm-body="<?php esc_attr_e( 'Any backup or import currently running will be marked failed. Only do this if a job is genuinely stuck.', 'avix-migration' ); ?>"
			data-avix-reload-on-success="1"
		><?php esc_html_e( 'Reset stuck jobs', 'avix-migration' ); ?></button>
	</div>
	<div class="avix-toggle-row">
		<div>
			<div class="avix-toggle-row__label"><?php esc_html_e( 'Delete all plugin data', 'avix-migration' ); ?></div>
			<div class="avix-toggle-row__hint"><?php esc_html_e( 'Removes every stored backup, job, log, and setting. Does not affect your site content.', 'avix-migration' ); ?></div>
		</div>
		<button
			type="button"
			class="avix-btn avix-btn-danger"
			data-avix-confirm-action="avix_delete_all_data"
			data-avix-confirm-title="<?php esc_attr_e( 'Delete all Avix Migration data?', 'avix-migration' ); ?>"
			data-avix-confirm-body="<?php esc_attr_e( 'This permanently deletes every stored backup archive, job, and log file. This cannot be undone.', 'avix-migration' ); ?>"
			data-avix-confirm-word="DELETE"
			data-avix-reload-on-success="1"
		><?php esc_html_e( 'Delete everything', 'avix-migration' ); ?></button>
	</div>
</div>

<div class="avix-card">
	<h2 class="avix-section-title avix-mt-0"><?php esc_html_e( 'Database check', 'avix-migration' ); ?></h2>
	<p class="avix-section-desc"><?php esc_html_e( 'Runs the same insert WordPress performs when saving a media upload and reports the database\'s actual error, instead of the generic message wp-admin shows. Use this if uploads, posts, or pages are failing. The test row is removed immediately.', 'avix-migration' ); ?></p>

	<button type="button" class="avix-btn avix-btn-primary" id="avix-run-db-probe"><?php esc_html_e( 'Run check', 'avix-migration' ); ?></button>

	<div id="avix-db-probe-result" hidden style="margin-top:14px;">
		<div id="avix-db-probe-verdict" style="margin-bottom:10px;"></div>
		<textarea id="avix-db-probe-raw" class="avix-input" rows="12" readonly style="font-family:var(--avix-mono); font-size:12px;"></textarea>
		<p class="avix-field__hint"><?php esc_html_e( 'Copy the box above if you need to send this on for support.', 'avix-migration' ); ?></p>
	</div>
</div>

<div class="avix-card">
	<h2 class="avix-section-title avix-mt-0"><?php esc_html_e( 'Rollback snapshots', 'avix-migration' ); ?></h2>
	<p class="avix-section-desc">
		<?php esc_html_e( 'Copies of this site\'s tables taken before an import, kept so a restore can be undone.', 'avix-migration' ); ?>
		<?php esc_html_e( 'If several imports failed in a row, the OLDEST snapshot is the one holding your original content — each later attempt only snapshotted the partial results of the one before it. Restore the oldest, confirm the site is right, then drop the rest.', 'avix-migration' ); ?>
	</p>

	<?php
	$snapshots = Avix_Migration_Rollback_Manager::list_snapshots();

	// Health check on the LIVE tables. If a restore died after the snapshot
	// was taken, the site's real content is sitting in the avix_rb_ tables
	// and the live ones are missing or half-imported — which looks to the
	// operator like "my media library is empty" rather than anything to do
	// with this plugin. Surface it plainly, and make it obvious that
	// dropping the snapshot in that state would destroy the only good copy.
	global $wpdb;
	$core_tables = array( $wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->users );
	$missing_core = array();
	foreach ( $core_tables as $t ) {
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) ) {
			$missing_core[] = $t;
		}
	}
	$post_count = empty( $missing_core ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->posts}`" ) : 0;
	$site_looks_broken = ! empty( $missing_core ) || ( 0 === $post_count && ! empty( $snapshots ) );
	?>

	<?php if ( $site_looks_broken ) : ?>
		<div class="avix-badge avix-badge-danger" style="display:block; padding:12px; margin-bottom:14px;">
			<strong><?php esc_html_e( 'This site\'s live tables look incomplete.', 'avix-migration' ); ?></strong><br>
			<?php if ( ! empty( $missing_core ) ) : ?>
				<?php
				printf(
					/* translators: %s: comma-separated table names */
					esc_html__( 'Missing core tables: %s.', 'avix-migration' ),
					esc_html( implode( ', ', $missing_core ) )
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'The posts table is empty.', 'avix-migration' ); ?>
			<?php endif; ?>
			<br>
			<?php esc_html_e( 'An import most likely failed partway. Your previous content is probably held in a snapshot below — use Restore, and do NOT drop these snapshots until the site looks right again.', 'avix-migration' ); ?>
		</div>
	<?php endif; ?>

	<?php if ( empty( $snapshots ) ) : ?>
		<p class="avix-text-muted"><?php esc_html_e( 'No snapshots stored.', 'avix-migration' ); ?></p>
	<?php else : ?>
		<table class="avix-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Taken', 'avix-migration' ); ?></th>
					<th><?php esc_html_e( 'Tables', 'avix-migration' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$oldest_ts = min( array_keys( $snapshots ) );
				foreach ( $snapshots as $ts => $count ) :
					?>
					<tr>
						<td>
							<?php echo esc_html( date_i18n( 'Y-m-d H:i:s', $ts ) ); ?>
							<?php if ( $ts === $oldest_ts && count( $snapshots ) > 1 ) : ?>
								<span class="avix-badge avix-badge-success"><?php esc_html_e( 'oldest — likely your original content', 'avix-migration' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
						<td style="text-align:right; white-space:nowrap;">
							<button
								type="button"
								class="avix-btn avix-btn-sm avix-btn-primary"
								data-avix-confirm-action="avix_restore_snapshot"
								data-avix-confirm-title="<?php esc_attr_e( 'Restore this snapshot?', 'avix-migration' ); ?>"
								data-avix-confirm-body="<?php esc_attr_e( 'Puts these tables back as the live site, replacing whatever is there now. Use this if an import left the site broken or empty.', 'avix-migration' ); ?>"
								data-avix-confirm-payload='<?php echo esc_attr( wp_json_encode( array( 'timestamp' => $ts ) ) ); ?>'
								data-avix-reload-on-success="1"
							><?php esc_html_e( 'Restore', 'avix-migration' ); ?></button>
							<button
								type="button"
								class="avix-btn avix-btn-sm avix-btn-danger"
								data-avix-confirm-action="avix_purge_snapshot"
								data-avix-confirm-title="<?php esc_attr_e( 'Permanently drop this snapshot?', 'avix-migration' ); ?>"
								data-avix-confirm-body="<?php esc_attr_e( 'If the import that created this snapshot failed, these tables hold the ONLY copy of your previous site content — dropping them destroys it. Only do this once you have confirmed the live site is correct.', 'avix-migration' ); ?>"
								data-avix-confirm-word="DROP"
								data-avix-confirm-payload='<?php echo esc_attr( wp_json_encode( array( 'timestamp' => $ts ) ) ); ?>'
								data-avix-reload-on-success="1"
							><?php esc_html_e( 'Drop', 'avix-migration' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<div class="avix-card">
	<h2 class="avix-section-title avix-mt-0"><?php esc_html_e( 'Log', 'avix-migration' ); ?></h2>
	<?php if ( empty( $log ) ) : ?>
		<p class="avix-text-muted"><?php esc_html_e( 'Nothing logged yet.', 'avix-migration' ); ?></p>
	<?php else : ?>
		<div class="avix-log">
			<?php foreach ( $log as $entry ) : ?>
				<div class="avix-log__line is-<?php echo esc_attr( $entry['level'] ); ?>">
					[<?php echo esc_html( date_i18n( 'Y-m-d H:i:s', $entry['time'] ) ); ?>] <?php echo esc_html( strtoupper( $entry['level'] ) ); ?>: <?php echo esc_html( $entry['message'] ); ?>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
