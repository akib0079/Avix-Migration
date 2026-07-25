<?php
/**
 * Content Export: post/page/CPT picker with search + filters, a dependency
 * preview, then the same progress/done/failed pattern as the other wizards.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_types = Avix_Migration_Admin_Content_Controller::exportable_post_types();
?>

<div id="avix-content-app">

	<div id="avix-content-picker" class="avix-card">
		<h2 class="avix-section-title avix-mt-0"><?php esc_html_e( 'Choose what to export', 'avix-migration' ); ?></h2>
		<p class="avix-section-desc"><?php esc_html_e( 'Media, referenced Elementor templates, and taxonomy terms are included automatically for whatever you select.', 'avix-migration' ); ?></p>

		<div class="avix-flex-between avix-gap-sm" style="margin-bottom:12px;">
			<input type="text" id="avix-content-search" class="avix-input" placeholder="<?php esc_attr_e( 'Search…', 'avix-migration' ); ?>" style="max-width:260px;">
			<select id="avix-content-type-filter" class="avix-select" style="max-width:180px;">
				<option value=""><?php esc_html_e( 'All types', 'avix-migration' ); ?></option>
				<?php foreach ( $post_types as $pt ) : ?>
					<option value="<?php echo esc_attr( $pt ); ?>"><?php echo esc_html( $pt ); ?></option>
				<?php endforeach; ?>
			</select>
			<select id="avix-content-status-filter" class="avix-select" style="max-width:160px;">
				<option value=""><?php esc_html_e( 'Any status', 'avix-migration' ); ?></option>
				<option value="publish"><?php esc_html_e( 'Published', 'avix-migration' ); ?></option>
				<option value="draft"><?php esc_html_e( 'Draft', 'avix-migration' ); ?></option>
				<option value="private"><?php esc_html_e( 'Private', 'avix-migration' ); ?></option>
			</select>
			<span class="avix-text-muted" id="avix-content-selected-count" style="margin-left:auto;"></span>
		</div>

		<table class="avix-table">
			<thead>
				<tr>
					<th style="width:32px;"><input type="checkbox" id="avix-content-select-all"></th>
					<th><?php esc_html_e( 'Title', 'avix-migration' ); ?></th>
					<th><?php esc_html_e( 'Type', 'avix-migration' ); ?></th>
					<th><?php esc_html_e( 'Status', 'avix-migration' ); ?></th>
					<th><?php esc_html_e( 'Date', 'avix-migration' ); ?></th>
				</tr>
			</thead>
			<tbody id="avix-content-rows">
				<tr><td colspan="5" class="avix-text-muted"><?php esc_html_e( 'Loading…', 'avix-migration' ); ?></td></tr>
			</tbody>
		</table>

		<div class="avix-flex-between" style="margin-top:12px;">
			<div id="avix-content-pagination"></div>
			<button type="button" class="avix-btn avix-btn-primary" id="avix-content-preview-btn" disabled><?php esc_html_e( 'Preview export', 'avix-migration' ); ?></button>
		</div>
	</div>

	<div id="avix-content-preview" class="avix-card" hidden>
		<h2 class="avix-section-title avix-mt-0"><?php esc_html_e( 'Export preview', 'avix-migration' ); ?></h2>
		<p data-preview-summary style="font-size:15px;"></p>
		<div data-preview-warnings></div>

		<div style="margin-top:16px;">
			<?php $instance_id = 'content'; include __DIR__ . '/partials/destinations-panel.php'; ?>
		</div>

		<div class="avix-flex-between" style="margin-top:16px;">
			<button type="button" class="avix-btn" id="avix-preview-back"><?php esc_html_e( 'Back to selection', 'avix-migration' ); ?></button>
			<button type="button" class="avix-btn avix-btn-primary" id="avix-content-start"><?php esc_html_e( 'Export now', 'avix-migration' ); ?></button>
		</div>
	</div>

	<div id="avix-content-progress" class="avix-card" hidden>
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

	<div id="avix-content-done" class="avix-card" hidden>
		<div class="avix-empty">
			<span class="dashicons dashicons-yes-alt" style="font-size:32px;width:32px;height:32px;color:var(--avix-success);"></span>
			<p class="avix-empty__title"><?php esc_html_e( 'Export complete', 'avix-migration' ); ?></p>
			<p data-done-summary></p>
			<div style="display:flex; gap:8px; margin-top:8px;">
				<a class="avix-btn avix-btn-primary" data-done-download href="#"><?php esc_html_e( 'Download', 'avix-migration' ); ?></a>
				<a class="avix-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=avix-migration-backups' ) ); ?>"><?php esc_html_e( 'View all backups', 'avix-migration' ); ?></a>
			</div>
		</div>
	</div>

	<div id="avix-content-failed" class="avix-card" hidden>
		<div class="avix-empty">
			<span class="dashicons dashicons-warning" style="font-size:32px;width:32px;height:32px;color:var(--avix-danger);"></span>
			<p class="avix-empty__title"><?php esc_html_e( 'Export failed', 'avix-migration' ); ?></p>
			<p data-failed-message class="avix-text-muted"></p>
		</div>
	</div>

</div>
