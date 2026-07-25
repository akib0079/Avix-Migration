<?php
/**
 * Backup wizard: Include -> Exclusions -> Advanced -> Destination, then a
 * live progress screen. All client-side panel switching (avix-backup.js);
 * the actual work is the Export_* job step pipeline.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fs_size = Avix_Migration_Util_Sysinfo::wp_content_size();
$db_size = Avix_Migration_Util_Sysinfo::db_size();
?>

<div id="avix-backup-app" data-ajax-nonce="<?php echo esc_attr( wp_create_nonce( Avix_Migration_Admin_Ajax::NONCE_ACTION ) ); ?>">

	<div id="avix-backup-wizard">
		<div class="avix-wizard-steps">
			<span class="avix-wizard-step is-active" data-step-chip="include"><?php esc_html_e( '1. Include', 'avix-migration' ); ?></span>
			<span class="avix-wizard-step" data-step-chip="exclusions"><?php esc_html_e( '2. Exclusions', 'avix-migration' ); ?></span>
			<span class="avix-wizard-step" data-step-chip="advanced"><?php esc_html_e( '3. Advanced', 'avix-migration' ); ?></span>
			<span class="avix-wizard-step" data-step-chip="destination"><?php esc_html_e( '4. Destination', 'avix-migration' ); ?></span>
		</div>

		<form id="avix-backup-form">

			<div class="avix-card" data-step-panel="include">
				<h2 class="avix-section-title avix-mt-0"><?php esc_html_e( 'What should this backup include?', 'avix-migration' ); ?></h2>
				<p class="avix-section-desc"><?php esc_html_e( 'A full backup is your database plus wp-content. Turn off anything you don\'t need.', 'avix-migration' ); ?></p>

				<div class="avix-toggle-row">
					<div>
						<div class="avix-toggle-row__label"><?php esc_html_e( 'Database', 'avix-migration' ); ?></div>
						<div class="avix-toggle-row__hint"><?php echo esc_html( Avix_Migration_Util_Filesystem::human_size( $db_size['bytes'] ) ); ?></div>
					</div>
					<label class="avix-switch"><input type="checkbox" name="include_database" checked><span class="avix-switch__track"></span></label>
				</div>

				<div class="avix-toggle-row">
					<div>
						<div class="avix-toggle-row__label"><?php esc_html_e( 'Files (wp-content)', 'avix-migration' ); ?></div>
						<div class="avix-toggle-row__hint"><?php echo esc_html( Avix_Migration_Util_Filesystem::human_size( $fs_size['bytes'] ) ); ?></div>
					</div>
					<label class="avix-switch"><input type="checkbox" name="include_files" id="avix-include-files" checked><span class="avix-switch__track"></span></label>
				</div>

				<div id="avix-content-subtoggles">
					<?php
					$sub = array(
						'uploads'    => __( 'Uploads (media library)', 'avix-migration' ),
						'plugins'    => __( 'Plugins', 'avix-migration' ),
						'themes'     => __( 'Themes', 'avix-migration' ),
						'mu-plugins' => __( 'Must-use plugins', 'avix-migration' ),
					);
					foreach ( $sub as $key => $label ) :
						$field = 'include_' . str_replace( '-', '_', $key );
						?>
						<div class="avix-toggle-row" style="padding-left:16px;">
							<div class="avix-toggle-row__label avix-text-muted"><?php echo esc_html( $label ); ?></div>
							<label class="avix-switch"><input type="checkbox" name="<?php echo esc_attr( $field ); ?>" checked><span class="avix-switch__track"></span></label>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="avix-flex-between" style="margin-top:20px;">
					<span></span>
					<button type="button" class="avix-btn avix-btn-primary" data-wizard-next><?php esc_html_e( 'Next: Exclusions', 'avix-migration' ); ?></button>
				</div>
			</div>

			<div class="avix-card" data-step-panel="exclusions" hidden>
				<h2 class="avix-section-title avix-mt-0"><?php esc_html_e( 'Exclusions', 'avix-migration' ); ?></h2>
				<p class="avix-section-desc"><?php esc_html_e( 'Detected on this site — pre-checked because they\'re either another backup tool\'s own storage or a regenerable cache.', 'avix-migration' ); ?></p>

				<div id="avix-detected-exclusions">
					<p class="avix-text-muted"><?php esc_html_e( 'Scanning…', 'avix-migration' ); ?></p>
				</div>

				<div class="avix-field" style="margin-top:16px;">
					<label class="avix-field__label" for="avix-exclude-custom"><?php esc_html_e( 'Additional patterns (one per line)', 'avix-migration' ); ?></label>
					<textarea id="avix-exclude-custom" name="exclude_custom" class="avix-input" style="max-width:100%; min-height:80px;" placeholder="uploads/2019/*&#10;*.log"></textarea>
					<span class="avix-field__hint"><?php esc_html_e( 'A bare name matches a top-level wp-content folder; use * for glob patterns anywhere else.', 'avix-migration' ); ?></span>
				</div>

				<div class="avix-flex-between" style="margin-top:20px;">
					<button type="button" class="avix-btn" data-wizard-back><?php esc_html_e( 'Back', 'avix-migration' ); ?></button>
					<button type="button" class="avix-btn avix-btn-primary" data-wizard-next><?php esc_html_e( 'Next: Advanced', 'avix-migration' ); ?></button>
				</div>
			</div>

			<div class="avix-card" data-step-panel="advanced" hidden>
				<h2 class="avix-section-title avix-mt-0"><?php esc_html_e( 'Advanced', 'avix-migration' ); ?></h2>

				<div class="avix-toggle-row">
					<div>
						<div class="avix-toggle-row__label"><?php esc_html_e( 'Skip transients', 'avix-migration' ); ?></div>
						<div class="avix-toggle-row__hint"><?php esc_html_e( 'Cached values that regenerate on their own', 'avix-migration' ); ?></div>
					</div>
					<label class="avix-switch"><input type="checkbox" name="skip_transients" checked><span class="avix-switch__track"></span></label>
				</div>
				<div class="avix-toggle-row">
					<div>
						<div class="avix-toggle-row__label"><?php esc_html_e( 'Skip post revisions', 'avix-migration' ); ?></div>
						<div class="avix-toggle-row__hint"><?php esc_html_e( 'Keeps only the current version of each post/page', 'avix-migration' ); ?></div>
					</div>
					<label class="avix-switch"><input type="checkbox" name="skip_revisions"><span class="avix-switch__track"></span></label>
				</div>
				<div class="avix-toggle-row">
					<div>
						<div class="avix-toggle-row__label"><?php esc_html_e( 'Skip spam &amp; trashed comments', 'avix-migration' ); ?></div>
					</div>
					<label class="avix-switch"><input type="checkbox" name="skip_spam_trash_comments" checked><span class="avix-switch__track"></span></label>
				</div>
				<div class="avix-toggle-row">
					<div>
						<div class="avix-toggle-row__label"><?php esc_html_e( 'Export every table in this database', 'avix-migration' ); ?></div>
						<div class="avix-toggle-row__hint"><?php esc_html_e( 'Only if another install shares this database with a different prefix', 'avix-migration' ); ?></div>
					</div>
					<label class="avix-switch"><input type="checkbox" name="all_tables"><span class="avix-switch__track"></span></label>
				</div>

				<div class="avix-flex-between" style="margin-top:20px;">
					<button type="button" class="avix-btn" data-wizard-back><?php esc_html_e( 'Back', 'avix-migration' ); ?></button>
					<button type="button" class="avix-btn avix-btn-primary" data-wizard-next><?php esc_html_e( 'Next: Destination', 'avix-migration' ); ?></button>
				</div>
			</div>

			<div class="avix-card" data-step-panel="destination" hidden>
				<h2 class="avix-section-title avix-mt-0"><?php esc_html_e( 'Destination', 'avix-migration' ); ?></h2>
				<p class="avix-section-desc"><?php esc_html_e( 'Always saved locally first; a cloud destination uploads the finished archive there too.', 'avix-migration' ); ?></p>

				<?php $instance_id = 'backup'; include __DIR__ . '/partials/destinations-panel.php'; ?>

				<div class="avix-flex-between" style="margin-top:20px;">
					<button type="button" class="avix-btn" data-wizard-back><?php esc_html_e( 'Back', 'avix-migration' ); ?></button>
					<button type="submit" class="avix-btn avix-btn-primary" id="avix-start-backup"><?php esc_html_e( 'Start backup', 'avix-migration' ); ?></button>
				</div>
			</div>

		</form>
	</div>

	<div id="avix-backup-progress" class="avix-card" hidden>
		<div class="avix-progress-row">
			<span class="avix-progress-row__stage" data-progress-stage><?php esc_html_e( 'Starting…', 'avix-migration' ); ?></span>
			<span><span class="avix-heartbeat" data-progress-heartbeat></span></span>
		</div>
		<div class="avix-progress"><div class="avix-progress__fill" data-progress-fill role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div></div>
		<div class="avix-progress-meta">
			<span data-progress-percent>0%</span>
			<span data-progress-detail></span>
		</div>

		<div style="margin-top:16px;">
			<button type="button" class="avix-btn avix-btn-danger avix-btn-sm" data-cancel-job><?php esc_html_e( 'Cancel', 'avix-migration' ); ?></button>
			<button type="button" class="avix-btn avix-btn-sm" data-toggle-log style="margin-left:6px;"><?php esc_html_e( 'Show log', 'avix-migration' ); ?></button>
		</div>
		<div class="avix-log" data-progress-log style="margin-top:12px;" hidden></div>
	</div>

	<div id="avix-backup-done" class="avix-card" hidden>
		<div class="avix-empty">
			<span class="dashicons dashicons-yes-alt" style="font-size:32px;width:32px;height:32px;color:var(--avix-success);"></span>
			<p class="avix-empty__title"><?php esc_html_e( 'Backup complete', 'avix-migration' ); ?></p>
			<p data-done-summary></p>
			<div style="display:flex; gap:8px; margin-top:8px;">
				<a class="avix-btn avix-btn-primary" data-done-download href="#"><?php esc_html_e( 'Download', 'avix-migration' ); ?></a>
				<a class="avix-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=avix-migration-backups' ) ); ?>"><?php esc_html_e( 'View all backups', 'avix-migration' ); ?></a>
			</div>
		</div>
	</div>

	<div id="avix-backup-failed" class="avix-card" hidden>
		<div class="avix-empty">
			<span class="dashicons dashicons-warning" style="font-size:32px;width:32px;height:32px;color:var(--avix-danger);"></span>
			<p class="avix-empty__title"><?php esc_html_e( 'Backup failed', 'avix-migration' ); ?></p>
			<p data-failed-message class="avix-text-muted"></p>
			<button type="button" class="avix-btn" onclick="window.location.reload()"><?php esc_html_e( 'Try again', 'avix-migration' ); ?></button>
		</div>
	</div>

</div>
