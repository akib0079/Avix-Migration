<?php
/**
 * Backups — lists local archives with download/delete. Creating new ones
 * lives on the Backup screen (Milestone 2 wizard).
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$archives = Avix_Migration_Archive_Store::list_all();
?>

<div class="avix-card">
	<div class="avix-flex-between">
		<div>
			<h2 class="avix-section-title avix-mt-0"><?php esc_html_e( 'Stored backups', 'avix-migration' ); ?></h2>
			<p class="avix-section-desc"><?php esc_html_e( 'Archives kept locally in wp-content/avix-backups/. Cloud-stored copies appear here too once a storage destination is connected.', 'avix-migration' ); ?></p>
		</div>
		<a class="avix-btn avix-btn-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=avix-migration-backup' ) ); ?>"><?php esc_html_e( 'Create backup', 'avix-migration' ); ?></a>
	</div>

	<?php if ( empty( $archives ) ) : ?>
		<div class="avix-empty">
			<span class="dashicons dashicons-database" style="font-size:32px;width:32px;height:32px;"></span>
			<p class="avix-empty__title"><?php esc_html_e( 'No backups yet', 'avix-migration' ); ?></p>
			<p><?php esc_html_e( 'Create your first backup to see it listed here.', 'avix-migration' ); ?></p>
		</div>
	<?php else : ?>
		<table class="avix-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'File', 'avix-migration' ); ?></th>
					<th><?php esc_html_e( 'Type', 'avix-migration' ); ?></th>
					<th><?php esc_html_e( 'Site', 'avix-migration' ); ?></th>
					<th><?php esc_html_e( 'Size', 'avix-migration' ); ?></th>
					<th><?php esc_html_e( 'Created', 'avix-migration' ); ?></th>
					<th><?php esc_html_e( 'Integrity', 'avix-migration' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $archives as $item ) : ?>
					<tr>
						<td><?php echo esc_html( $item['filename'] ); ?></td>
						<td><span class="avix-badge avix-badge-neutral"><?php echo esc_html( ucfirst( $item['type'] ) ); ?></span></td>
						<td class="avix-text-muted"><?php echo esc_html( $item['site_url'] ); ?></td>
						<td><?php echo esc_html( Avix_Migration_Util_Filesystem::human_size( $item['bytes'] ) ); ?></td>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['created_at'] ) ); ?></td>
						<td>
							<?php if ( $item['verified'] ) : ?>
								<span class="avix-badge avix-badge-success"><?php esc_html_e( 'Checksum OK', 'avix-migration' ); ?></span>
							<?php else : ?>
								<span class="avix-badge avix-badge-warning"><?php esc_html_e( 'No checksum', 'avix-migration' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<div style="display:flex; gap:6px;">
								<a class="avix-btn avix-btn-sm" href="<?php echo esc_url( Avix_Migration_Admin_Backups_Controller::download_url( $item['filename'] ) ); ?>"><?php esc_html_e( 'Download', 'avix-migration' ); ?></a>
								<a class="avix-btn avix-btn-sm" href="<?php echo esc_url( admin_url( 'admin.php?page=avix-migration-import&file=' . rawurlencode( $item['filename'] ) ) ); ?>"><?php esc_html_e( 'Restore', 'avix-migration' ); ?></a>
								<button
									type="button"
									class="avix-btn avix-btn-sm avix-btn-danger"
									data-avix-confirm-action="avix_delete_archive"
									data-avix-confirm-title="<?php esc_attr_e( 'Delete this backup?', 'avix-migration' ); ?>"
									data-avix-confirm-body="<?php esc_attr_e( 'This permanently deletes the archive file. It cannot be undone.', 'avix-migration' ); ?>"
									data-avix-confirm-payload='<?php echo esc_attr( wp_json_encode( array( 'file' => $item['filename'] ) ) ); ?>'
									data-avix-reload-on-success="1"
								><?php esc_html_e( 'Delete', 'avix-migration' ); ?></button>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
