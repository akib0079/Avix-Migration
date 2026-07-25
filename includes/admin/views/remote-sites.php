<?php
/**
 * Remote Sites: generate a connection key for another site to use when
 * connecting in, add a remote by pasting one, and push/pull directly.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div id="avix-remote-app">

	<div class="avix-card">
		<div class="avix-flex-between">
			<h2 class="avix-section-title avix-mt-0"><?php esc_html_e( 'Connection keys issued by this site', 'avix-migration' ); ?></h2>
			<button type="button" class="avix-btn avix-btn-primary" id="avix-issue-key-btn"><?php esc_html_e( 'Generate key', 'avix-migration' ); ?></button>
		</div>
		<p class="avix-section-desc"><?php esc_html_e( 'Paste this into another site\'s "Add remote" form there so it can push to or pull from this site.', 'avix-migration' ); ?></p>

		<table class="avix-table">
			<thead><tr><th><?php esc_html_e( 'Label', 'avix-migration' ); ?></th><th><?php esc_html_e( 'Expires', 'avix-migration' ); ?></th><th></th></tr></thead>
			<tbody id="avix-issued-keys-rows"><tr><td colspan="3" class="avix-text-muted"><?php esc_html_e( 'Loading…', 'avix-migration' ); ?></td></tr></tbody>
		</table>

		<div id="avix-new-key-form" class="avix-field" hidden style="margin-top:12px;">
			<label class="avix-field__label"><?php esc_html_e( 'Label', 'avix-migration' ); ?></label>
			<input type="text" id="avix-key-label" class="avix-input" placeholder="<?php esc_attr_e( 'e.g. Staging site', 'avix-migration' ); ?>">
			<label class="avix-field__label" style="margin-top:8px;"><?php esc_html_e( 'Expires in (hours, 0 = never)', 'avix-migration' ); ?></label>
			<input type="number" id="avix-key-expires" class="avix-input" value="24" min="0" style="max-width:120px;">
			<div style="margin-top:10px; display:flex; gap:8px;">
				<button type="button" class="avix-btn" id="avix-key-cancel"><?php esc_html_e( 'Cancel', 'avix-migration' ); ?></button>
				<button type="button" class="avix-btn avix-btn-primary" id="avix-key-generate"><?php esc_html_e( 'Generate', 'avix-migration' ); ?></button>
			</div>
		</div>

		<div id="avix-new-key-result" class="avix-badge avix-badge-success" style="display:none; padding:12px; word-break:break-all; margin-top:12px;"></div>
	</div>

	<div class="avix-card">
		<div class="avix-flex-between">
			<h2 class="avix-section-title avix-mt-0"><?php esc_html_e( 'Remote sites', 'avix-migration' ); ?></h2>
			<button type="button" class="avix-btn avix-btn-primary" id="avix-add-remote-btn"><?php esc_html_e( 'Add remote', 'avix-migration' ); ?></button>
		</div>

		<table class="avix-table">
			<thead><tr><th><?php esc_html_e( 'Label', 'avix-migration' ); ?></th><th><?php esc_html_e( 'Site', 'avix-migration' ); ?></th><th><?php esc_html_e( 'Status', 'avix-migration' ); ?></th><th></th></tr></thead>
			<tbody id="avix-remotes-rows"><tr><td colspan="4" class="avix-text-muted"><?php esc_html_e( 'Loading…', 'avix-migration' ); ?></td></tr></tbody>
		</table>

		<div id="avix-add-remote-form" hidden style="margin-top:12px;">
			<div class="avix-field">
				<label class="avix-field__label"><?php esc_html_e( 'Label', 'avix-migration' ); ?></label>
				<input type="text" id="avix-remote-label" class="avix-input" placeholder="<?php esc_attr_e( 'e.g. Production site', 'avix-migration' ); ?>">
			</div>
			<div class="avix-field">
				<label class="avix-field__label"><?php esc_html_e( 'Connection key', 'avix-migration' ); ?></label>
				<textarea id="avix-remote-connstring" class="avix-input" rows="3" placeholder="<?php esc_attr_e( 'Paste the key generated on the other site', 'avix-migration' ); ?>"></textarea>
			</div>
			<div style="display:flex; gap:8px;">
				<button type="button" class="avix-btn" id="avix-remote-cancel"><?php esc_html_e( 'Cancel', 'avix-migration' ); ?></button>
				<button type="button" class="avix-btn avix-btn-primary" id="avix-remote-save"><?php esc_html_e( 'Add', 'avix-migration' ); ?></button>
			</div>
		</div>
	</div>

	<div class="avix-card" id="avix-remote-progress-card" hidden>
		<div class="avix-progress-row">
			<span class="avix-progress-row__stage" data-progress-stage><?php esc_html_e( 'Starting…', 'avix-migration' ); ?></span>
			<span><span class="avix-heartbeat" data-progress-heartbeat></span></span>
		</div>
		<div class="avix-progress"><div class="avix-progress__fill" data-progress-fill></div></div>
		<div class="avix-progress-meta">
			<span data-progress-percent>0%</span>
			<span data-progress-detail></span>
		</div>
	</div>

</div>
