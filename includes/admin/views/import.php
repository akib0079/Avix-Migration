<?php
/**
 * Import wizard: pick a source (upload or an existing local backup) ->
 * pre-flight report with an explicit confirmation -> progress -> success
 * (with a Rollback option) or failure (with a prominent Rollback option).
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$existing = Avix_Migration_Archive_Store::list_all();
$preselect = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
?>

<div id="avix-import-app">

	<div id="avix-import-source" class="avix-card">
		<h2 class="avix-section-title avix-mt-0"><?php esc_html_e( 'Choose a backup to restore', 'avix-migration' ); ?></h2>

		<div id="avix-dropzone" style="border:2px dashed var(--avix-border); border-radius:var(--avix-radius); padding:32px; text-align:center; cursor:pointer;">
			<span class="dashicons dashicons-upload" style="font-size:28px;width:28px;height:28px;"></span>
			<p><?php esc_html_e( 'Drag an .avix file here, or click to choose one', 'avix-migration' ); ?></p>
			<input type="file" id="avix-file-input" accept=".avix" style="display:none;">
		</div>
		<div id="avix-upload-progress" hidden style="margin-top:12px;">
			<div class="avix-progress"><div class="avix-progress__fill" data-upload-fill></div></div>
			<div class="avix-progress-meta"><span data-upload-percent>0%</span></div>
		</div>

		<?php if ( ! empty( $existing ) ) : ?>
			<h3 class="avix-section-title" style="margin-top:24px;"><?php esc_html_e( 'Or restore an existing backup', 'avix-migration' ); ?></h3>
			<table class="avix-table">
				<tbody>
					<?php foreach ( $existing as $item ) : ?>
						<tr>
							<td><?php echo esc_html( $item['filename'] ); ?></td>
							<td class="avix-text-muted"><?php echo esc_html( Avix_Migration_Util_Filesystem::human_size( $item['bytes'] ) ); ?></td>
							<td><button type="button" class="avix-btn avix-btn-sm" data-pick-existing="<?php echo esc_attr( $item['filename'] ); ?>"><?php esc_html_e( 'Restore this', 'avix-migration' ); ?></button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<div id="avix-import-preflight" class="avix-card" hidden>
		<h2 class="avix-section-title avix-mt-0"><?php esc_html_e( 'Before you restore', 'avix-migration' ); ?></h2>

		<table class="avix-table">
			<tbody>
				<tr><td><?php esc_html_e( 'Archive', 'avix-migration' ); ?></td><td data-pf-filename></td></tr>
				<tr><td><?php esc_html_e( 'Source site', 'avix-migration' ); ?></td><td data-pf-source-url></td></tr>
				<tr><td><?php esc_html_e( 'This site', 'avix-migration' ); ?></td><td><?php echo esc_html( site_url() ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Source WordPress / PHP', 'avix-migration' ); ?></td><td data-pf-versions></td></tr>
				<tr><td><?php esc_html_e( 'Type', 'avix-migration' ); ?></td><td data-pf-type></td></tr>
			</tbody>
		</table>

		<div data-pf-warnings style="margin-top:16px;"></div>

		<div id="avix-pf-full-options" style="margin-top:20px;">
			<div class="avix-toggle-row">
				<div class="avix-toggle-row__label"><?php esc_html_e( 'Restore database', 'avix-migration' ); ?></div>
				<label class="avix-switch"><input type="checkbox" id="avix-restore-database" checked><span class="avix-switch__track"></span></label>
			</div>
			<div class="avix-toggle-row">
				<div class="avix-toggle-row__label"><?php esc_html_e( 'Restore files', 'avix-migration' ); ?></div>
				<label class="avix-switch"><input type="checkbox" id="avix-restore-files" checked><span class="avix-switch__track"></span></label>
			</div>
			<div class="avix-toggle-row">
				<div>
					<div class="avix-toggle-row__label"><?php esc_html_e( 'Keep me logged in as the current admin', 'avix-migration' ); ?></div>
					<div class="avix-toggle-row__hint"><?php esc_html_e( 'Your current username and password will keep working on the restored site', 'avix-migration' ); ?></div>
				</div>
				<label class="avix-switch"><input type="checkbox" id="avix-keep-admin" checked><span class="avix-switch__track"></span></label>
			</div>
		</div>

		<div id="avix-pf-content-options" style="margin-top:20px;" hidden>
			<div class="avix-field">
				<label class="avix-field__label" for="avix-conflict-mode"><?php esc_html_e( 'If a post from this export already exists here', 'avix-migration' ); ?></label>
				<select id="avix-conflict-mode" class="avix-select">
					<option value="skip"><?php esc_html_e( 'Skip it', 'avix-migration' ); ?></option>
					<option value="overwrite"><?php esc_html_e( 'Overwrite it', 'avix-migration' ); ?></option>
					<option value="duplicate"><?php esc_html_e( 'Import as a new duplicate', 'avix-migration' ); ?></option>
				</select>
				<span class="avix-field__hint"><?php esc_html_e( 'Matched by an internal marker left on posts this plugin previously imported.', 'avix-migration' ); ?></span>
			</div>
		</div>

		<div class="avix-field" style="margin-top:16px;">
			<label class="avix-field__label" for="avix-confirm-restore"><?php esc_html_e( 'Type RESTORE to confirm — this will overwrite this site\'s current content', 'avix-migration' ); ?></label>
			<input type="text" id="avix-confirm-restore" class="avix-input" autocomplete="off">
		</div>

		<div class="avix-flex-between" style="margin-top:12px;">
			<button type="button" class="avix-btn" id="avix-preflight-cancel"><?php esc_html_e( 'Choose a different backup', 'avix-migration' ); ?></button>
			<button type="button" class="avix-btn avix-btn-danger" id="avix-start-import" disabled><?php esc_html_e( 'Restore now', 'avix-migration' ); ?></button>
		</div>
	</div>

	<div id="avix-import-progress" class="avix-card" hidden>
		<div class="avix-progress-row">
			<span class="avix-progress-row__stage" data-progress-stage><?php esc_html_e( 'Starting…', 'avix-migration' ); ?></span>
			<span><span class="avix-heartbeat" data-progress-heartbeat></span></span>
		</div>
		<div class="avix-progress"><div class="avix-progress__fill" data-progress-fill></div></div>
		<div class="avix-progress-meta">
			<span data-progress-percent>0%</span>
			<span data-progress-detail></span>
		</div>
		<div style="margin-top:16px;">
			<button type="button" class="avix-btn avix-btn-sm" data-toggle-log><?php esc_html_e( 'Show log', 'avix-migration' ); ?></button>
		</div>
		<div class="avix-log" data-progress-log style="margin-top:12px;" hidden></div>
	</div>

	<div id="avix-import-done" class="avix-card" hidden>
		<div class="avix-empty">
			<span class="dashicons dashicons-yes-alt" style="font-size:32px;width:32px;height:32px;color:var(--avix-success);"></span>
			<p class="avix-empty__title"><?php esc_html_e( 'Restore complete', 'avix-migration' ); ?></p>
			<p data-done-message class="avix-text-muted"></p>
			<div style="display:flex; gap:8px; margin-top:8px;">
				<a class="avix-btn avix-btn-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank"><?php esc_html_e( 'View site', 'avix-migration' ); ?></a>
				<button type="button" class="avix-btn" id="avix-discard-rollback"><?php esc_html_e( 'Looks good — clean up safety copy', 'avix-migration' ); ?></button>
				<button
					type="button"
					class="avix-btn avix-btn-danger"
					id="avix-rollback-done"
					data-avix-confirm-title="<?php esc_attr_e( 'Undo this restore?', 'avix-migration' ); ?>"
					data-avix-confirm-body="<?php esc_attr_e( 'This puts back exactly what was here before the restore.', 'avix-migration' ); ?>"
				><?php esc_html_e( 'Undo (rollback)', 'avix-migration' ); ?></button>
			</div>
		</div>
	</div>

	<div id="avix-import-failed" class="avix-card" hidden>
		<div class="avix-empty">
			<span class="dashicons dashicons-warning" style="font-size:32px;width:32px;height:32px;color:var(--avix-danger);"></span>
			<p class="avix-empty__title"><?php esc_html_e( 'Restore failed', 'avix-migration' ); ?></p>
			<p data-failed-message class="avix-text-muted"></p>
			<button type="button" class="avix-btn avix-btn-danger" id="avix-rollback-failed"><?php esc_html_e( 'Undo (rollback to before the restore)', 'avix-migration' ); ?></button>
		</div>
	</div>

</div>
