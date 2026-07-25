<?php
/**
 * Schedules: list of saved recurring backups, plus an add/edit form. A
 * schedule that's due fires from the plugin's hourly housekeeping tick
 * (see Schedule_Scheduler); this screen just manages the rules.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$schedules = Avix_Migration_Schedule_Store::all();
?>

<div id="avix-schedules-app">

	<div class="avix-card" id="avix-schedules-list-card">
		<div class="avix-flex-between">
			<h2 class="avix-section-title avix-mt-0"><?php esc_html_e( 'Scheduled backups', 'avix-migration' ); ?></h2>
			<button type="button" class="avix-btn avix-btn-primary" id="avix-add-schedule"><?php esc_html_e( 'Add schedule', 'avix-migration' ); ?></button>
		</div>

		<div class="avix-badge avix-badge-neutral" style="display:block; padding:10px 12px; margin-bottom:12px;">
			<?php esc_html_e( 'Scheduled tasks only run when something visits this site (standard WordPress behavior). For reliable timing, ask your host to set up a real system cron hitting wp-cron.php every few minutes.', 'avix-migration' ); ?>
		</div>

		<?php if ( empty( $schedules ) ) : ?>
			<div class="avix-empty">
				<span class="dashicons dashicons-clock" style="font-size:32px;width:32px;height:32px;"></span>
				<p class="avix-empty__title"><?php esc_html_e( 'No schedules yet', 'avix-migration' ); ?></p>
			</div>
		<?php else : ?>
			<table class="avix-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'avix-migration' ); ?></th>
						<th><?php esc_html_e( 'Frequency', 'avix-migration' ); ?></th>
						<th><?php esc_html_e( 'Last run', 'avix-migration' ); ?></th>
						<th><?php esc_html_e( 'Status', 'avix-migration' ); ?></th>
						<th><?php esc_html_e( 'Enabled', 'avix-migration' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $schedules as $id => $s ) : ?>
						<tr data-schedule-row="<?php echo esc_attr( $id ); ?>">
							<td><?php echo esc_html( $s['name'] ); ?></td>
							<td>
								<?php echo esc_html( ucfirst( str_replace( array( 'avix_', '_' ), array( '', ' ' ), $s['frequency'] ) ) ); ?>
								<?php if ( ! in_array( $s['frequency'], array( 'hourly', 'avix_six_hourly' ), true ) ) : ?>
									<span class="avix-text-muted"> @ <?php echo esc_html( $s['time_of_day'] ); ?></span>
								<?php endif; ?>
							</td>
							<td class="avix-text-muted"><?php echo $s['last_run_at'] ? esc_html( human_time_diff( $s['last_run_at'] ) . ' ago' ) : esc_html__( 'Never', 'avix-migration' ); ?></td>
							<td>
								<?php if ( 'done' === $s['last_status'] ) : ?>
									<span class="avix-badge avix-badge-success"><?php esc_html_e( 'Success', 'avix-migration' ); ?></span>
								<?php elseif ( 'failed' === $s['last_status'] ) : ?>
									<span class="avix-badge avix-badge-danger"><?php esc_html_e( 'Failed', 'avix-migration' ); ?></span>
								<?php elseif ( 'running' === $s['last_status'] ) : ?>
									<span class="avix-badge avix-badge-warning"><?php esc_html_e( 'Running', 'avix-migration' ); ?></span>
								<?php else : ?>
									<span class="avix-badge avix-badge-neutral">—</span>
								<?php endif; ?>
							</td>
							<td>
								<label class="avix-switch">
									<input type="checkbox" data-toggle-schedule="<?php echo esc_attr( $id ); ?>" <?php checked( ! empty( $s['enabled'] ) ); ?>>
									<span class="avix-switch__track"></span>
								</label>
							</td>
							<td>
								<div style="display:flex; gap:6px;">
									<button type="button" class="avix-btn avix-btn-sm" data-run-now="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Run now', 'avix-migration' ); ?></button>
									<button type="button" class="avix-btn avix-btn-sm" data-edit-schedule="<?php echo esc_attr( wp_json_encode( $s ) ); ?>"><?php esc_html_e( 'Edit', 'avix-migration' ); ?></button>
									<button
										type="button"
										class="avix-btn avix-btn-sm avix-btn-danger"
										data-avix-confirm-action="avix_schedule_delete"
										data-avix-confirm-title="<?php esc_attr_e( 'Delete this schedule?', 'avix-migration' ); ?>"
										data-avix-confirm-body="<?php esc_attr_e( 'This only removes the schedule — existing backups it created are kept.', 'avix-migration' ); ?>"
										data-avix-confirm-payload='<?php echo esc_attr( wp_json_encode( array( 'schedule_id' => $id ) ) ); ?>'
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

	<div class="avix-card" id="avix-schedule-form-card" hidden>
		<h2 class="avix-section-title avix-mt-0" id="avix-schedule-form-title"><?php esc_html_e( 'New schedule', 'avix-migration' ); ?></h2>

		<form id="avix-schedule-form">
			<input type="hidden" id="avix-sched-id" value="">

			<div class="avix-field">
				<label class="avix-field__label" for="avix-sched-name"><?php esc_html_e( 'Name', 'avix-migration' ); ?></label>
				<input type="text" id="avix-sched-name" class="avix-input" placeholder="<?php esc_attr_e( 'Nightly backup', 'avix-migration' ); ?>">
			</div>

			<div class="avix-field">
				<label class="avix-field__label" for="avix-sched-frequency"><?php esc_html_e( 'Frequency', 'avix-migration' ); ?></label>
				<select id="avix-sched-frequency" class="avix-select">
					<option value="hourly"><?php esc_html_e( 'Hourly', 'avix-migration' ); ?></option>
					<option value="avix_six_hourly"><?php esc_html_e( 'Every 6 hours', 'avix-migration' ); ?></option>
					<option value="daily" selected><?php esc_html_e( 'Daily', 'avix-migration' ); ?></option>
					<option value="avix_weekly"><?php esc_html_e( 'Weekly', 'avix-migration' ); ?></option>
					<option value="avix_monthly"><?php esc_html_e( 'Monthly', 'avix-migration' ); ?></option>
				</select>
			</div>

			<div class="avix-field" id="avix-sched-time-field">
				<label class="avix-field__label" for="avix-sched-time"><?php esc_html_e( 'Time of day', 'avix-migration' ); ?></label>
				<input type="time" id="avix-sched-time" class="avix-input" value="02:00">
			</div>

			<div class="avix-toggle-row">
				<div class="avix-toggle-row__label"><?php esc_html_e( 'Enabled', 'avix-migration' ); ?></div>
				<label class="avix-switch"><input type="checkbox" id="avix-sched-enabled" checked><span class="avix-switch__track"></span></label>
			</div>
			<div class="avix-toggle-row">
				<div class="avix-toggle-row__label"><?php esc_html_e( 'Include database', 'avix-migration' ); ?></div>
				<label class="avix-switch"><input type="checkbox" id="avix-sched-include-database" checked><span class="avix-switch__track"></span></label>
			</div>
			<div class="avix-toggle-row">
				<div class="avix-toggle-row__label"><?php esc_html_e( 'Include files', 'avix-migration' ); ?></div>
				<label class="avix-switch"><input type="checkbox" id="avix-sched-include-files" checked><span class="avix-switch__track"></span></label>
			</div>
			<?php
			$sub = array(
				'uploads'    => __( 'Uploads', 'avix-migration' ),
				'plugins'    => __( 'Plugins', 'avix-migration' ),
				'themes'     => __( 'Themes', 'avix-migration' ),
				'mu-plugins' => __( 'Must-use plugins', 'avix-migration' ),
			);
			foreach ( $sub as $key => $label ) :
				$field = 'avix-sched-include-' . $key;
				?>
				<div class="avix-toggle-row" style="padding-left:16px;">
					<div class="avix-toggle-row__label avix-text-muted"><?php echo esc_html( $label ); ?></div>
					<label class="avix-switch"><input type="checkbox" id="<?php echo esc_attr( $field ); ?>" data-content-toggle="<?php echo esc_attr( $key ); ?>" checked><span class="avix-switch__track"></span></label>
				</div>
			<?php endforeach; ?>

			<div style="margin-top:12px;">
				<?php $instance_id = 'schedule'; include __DIR__ . '/partials/destinations-panel.php'; ?>
			</div>

			<div class="avix-field" style="margin-top:12px;">
				<label class="avix-field__label"><?php esc_html_e( 'Retention', 'avix-migration' ); ?></label>
				<div style="display:flex; gap:12px; align-items:center;">
					<span class="avix-text-muted"><?php esc_html_e( 'Keep last', 'avix-migration' ); ?></span>
					<input type="number" id="avix-sched-keep-last" class="avix-input" style="max-width:80px;" min="0" value="5">
					<span class="avix-text-muted"><?php esc_html_e( 'Delete after (days, 0 = never)', 'avix-migration' ); ?></span>
					<input type="number" id="avix-sched-older-days" class="avix-input" style="max-width:80px;" min="0" value="0">
				</div>
			</div>

			<div class="avix-field">
				<label class="avix-field__label" for="avix-sched-email"><?php esc_html_e( 'Notification email', 'avix-migration' ); ?></label>
				<input type="email" id="avix-sched-email" class="avix-input" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
			</div>
			<div class="avix-toggle-row">
				<div class="avix-toggle-row__label"><?php esc_html_e( 'Notify on success', 'avix-migration' ); ?></div>
				<label class="avix-switch"><input type="checkbox" id="avix-sched-notify-success"><span class="avix-switch__track"></span></label>
			</div>
			<div class="avix-toggle-row">
				<div class="avix-toggle-row__label"><?php esc_html_e( 'Notify on failure', 'avix-migration' ); ?></div>
				<label class="avix-switch"><input type="checkbox" id="avix-sched-notify-failure" checked><span class="avix-switch__track"></span></label>
			</div>

			<div class="avix-flex-between" style="margin-top:16px;">
				<button type="button" class="avix-btn" id="avix-schedule-cancel"><?php esc_html_e( 'Cancel', 'avix-migration' ); ?></button>
				<button type="submit" class="avix-btn avix-btn-primary"><?php esc_html_e( 'Save schedule', 'avix-migration' ); ?></button>
			</div>
		</form>
	</div>

	<div class="avix-card" id="avix-schedule-progress-card" hidden>
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
