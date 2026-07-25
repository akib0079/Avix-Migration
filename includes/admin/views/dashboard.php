<?php
/**
 * Dashboard — site snapshot + the three primary actions. Sizes are
 * approximate and cached for an hour (see Util_Sysinfo::wp_content_size()/
 * db_size()) so this page stays fast even on a multi-GB site; the actual
 * backup step computes exact sizes fresh.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fs_size   = Avix_Migration_Util_Sysinfo::wp_content_size();
$db_size   = Avix_Migration_Util_Sysinfo::db_size();
$disk_free = Avix_Migration_Util_Filesystem::disk_free();
$latest    = Avix_Migration_Archive_Store::latest();

$estimated_archive = $fs_size['bytes'] + $db_size['bytes'];
?>

<div class="avix-card">
	<h2 class="avix-section-title avix-mt-0"><?php esc_html_e( 'Site snapshot', 'avix-migration' ); ?></h2>
	<p class="avix-section-desc"><?php esc_html_e( 'Approximate — refreshed hourly. The backup wizard measures exact sizes when it runs.', 'avix-migration' ); ?></p>

	<div class="avix-card-grid">
		<div class="avix-stat">
			<span class="avix-stat__label"><?php esc_html_e( 'Database', 'avix-migration' ); ?></span>
			<span class="avix-stat__value"><?php echo esc_html( Avix_Migration_Util_Filesystem::human_size( $db_size['bytes'] ) ); ?></span>
		</div>
		<div class="avix-stat">
			<span class="avix-stat__label"><?php esc_html_e( 'Files (wp-content)', 'avix-migration' ); ?></span>
			<span class="avix-stat__value"><?php echo esc_html( Avix_Migration_Util_Filesystem::human_size( $fs_size['bytes'] ) ); ?></span>
		</div>
		<div class="avix-stat">
			<span class="avix-stat__label"><?php esc_html_e( 'Estimated backup size', 'avix-migration' ); ?></span>
			<span class="avix-stat__value"><?php echo esc_html( Avix_Migration_Util_Filesystem::human_size( $estimated_archive ) ); ?></span>
			<span class="avix-stat__hint"><?php esc_html_e( 'Before exclusions — the wizard usually shrinks this.', 'avix-migration' ); ?></span>
		</div>
		<div class="avix-stat">
			<span class="avix-stat__label"><?php esc_html_e( 'Disk free', 'avix-migration' ); ?></span>
			<span class="avix-stat__value"><?php echo null === $disk_free ? '—' : esc_html( Avix_Migration_Util_Filesystem::human_size( $disk_free ) ); ?></span>
		</div>
		<div class="avix-stat">
			<span class="avix-stat__label"><?php esc_html_e( 'Last backup', 'avix-migration' ); ?></span>
			<span class="avix-stat__value">
				<?php
				if ( $latest ) {
					echo esc_html( human_time_diff( $latest['created_at'] ) . ' ' . __( 'ago', 'avix-migration' ) );
				} else {
					esc_html_e( 'Never', 'avix-migration' );
				}
				?>
			</span>
			<?php if ( $latest ) : ?>
				<span class="avix-stat__hint"><?php echo esc_html( $latest['filename'] ); ?></span>
			<?php endif; ?>
		</div>
		<div class="avix-stat">
			<span class="avix-stat__label"><?php esc_html_e( 'Scheduled backups', 'avix-migration' ); ?></span>
			<span class="avix-stat__value">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=avix-migration-schedules' ) ); ?>"><?php esc_html_e( 'Set up', 'avix-migration' ); ?></a>
			</span>
		</div>
	</div>
</div>

<div class="avix-card">
	<h2 class="avix-section-title avix-mt-0"><?php esc_html_e( 'Get started', 'avix-migration' ); ?></h2>
	<div class="avix-actions-row">
		<a class="avix-action-card" href="<?php echo esc_url( admin_url( 'admin.php?page=avix-migration-backup' ) ); ?>">
			<div class="avix-action-card__icon" aria-hidden="true"><span class="dashicons dashicons-database-export"></span></div>
			<div class="avix-action-card__title"><?php esc_html_e( 'Create a backup', 'avix-migration' ); ?></div>
			<div class="avix-action-card__desc"><?php esc_html_e( 'Full site, or just the database and specific folders.', 'avix-migration' ); ?></div>
		</a>
		<a class="avix-action-card" href="<?php echo esc_url( admin_url( 'admin.php?page=avix-migration-import' ) ); ?>">
			<div class="avix-action-card__icon" aria-hidden="true"><span class="dashicons dashicons-database-import"></span></div>
			<div class="avix-action-card__title"><?php esc_html_e( 'Import a backup', 'avix-migration' ); ?></div>
			<div class="avix-action-card__desc"><?php esc_html_e( 'Restore an .avix file exported from this or another site.', 'avix-migration' ); ?></div>
		</a>
		<a class="avix-action-card" href="<?php echo esc_url( admin_url( 'admin.php?page=avix-migration-remote' ) ); ?>">
			<div class="avix-action-card__icon" aria-hidden="true"><span class="dashicons dashicons-migrate"></span></div>
			<div class="avix-action-card__title"><?php esc_html_e( 'Migrate to another site', 'avix-migration' ); ?></div>
			<div class="avix-action-card__desc"><?php esc_html_e( 'Send this site directly to another install running Avix Migration.', 'avix-migration' ); ?></div>
		</a>
	</div>
</div>

<?php if ( ! empty( $latest ) ) : ?>
<div class="avix-card">
	<div class="avix-flex-between">
		<h2 class="avix-section-title avix-mt-0"><?php esc_html_e( 'Recent backups', 'avix-migration' ); ?></h2>
		<a class="avix-btn avix-btn-sm" href="<?php echo esc_url( admin_url( 'admin.php?page=avix-migration-backups' ) ); ?>"><?php esc_html_e( 'View all', 'avix-migration' ); ?></a>
	</div>
	<?php
	$recent = array_slice( Avix_Migration_Archive_Store::list_all(), 0, 5 );
	?>
	<table class="avix-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'File', 'avix-migration' ); ?></th>
				<th><?php esc_html_e( 'Type', 'avix-migration' ); ?></th>
				<th><?php esc_html_e( 'Size', 'avix-migration' ); ?></th>
				<th><?php esc_html_e( 'Created', 'avix-migration' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $recent as $item ) : ?>
				<tr>
					<td><?php echo esc_html( $item['filename'] ); ?></td>
					<td><span class="avix-badge avix-badge-neutral"><?php echo esc_html( ucfirst( $item['type'] ) ); ?></span></td>
					<td><?php echo esc_html( Avix_Migration_Util_Filesystem::human_size( $item['bytes'] ) ); ?></td>
					<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['created_at'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
<?php endif; ?>
