<?php
/**
 * Shared destination picker + connection manager, included on the Backup,
 * Content Export, and Schedules screens — one panel, one JS module
 * (avix-destinations.js), so adding/testing/deleting a destination behaves
 * identically everywhere it appears.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="avix-field">
	<label class="avix-field__label" for="avix-destination-select-<?php echo esc_attr( $instance_id ?? 'default' ); ?>"><?php esc_html_e( 'Destination', 'avix-migration' ); ?></label>
	<div class="avix-flex-between avix-gap-sm">
		<select class="avix-select avix-destination-select" id="avix-destination-select-<?php echo esc_attr( $instance_id ?? 'default' ); ?>" style="max-width:280px;">
			<option value="local"><?php esc_html_e( 'Local storage (this site)', 'avix-migration' ); ?></option>
		</select>
		<button type="button" class="avix-btn avix-btn-sm avix-destinations-toggle"><?php esc_html_e( 'Manage destinations', 'avix-migration' ); ?></button>
	</div>
</div>

<div class="avix-card avix-destinations-manager" hidden style="margin-top:12px;">
	<div class="avix-flex-between">
		<h3 class="avix-section-title avix-mt-0"><?php esc_html_e( 'Storage destinations', 'avix-migration' ); ?></h3>
		<button type="button" class="avix-btn avix-btn-sm avix-destination-add"><?php esc_html_e( 'Add destination', 'avix-migration' ); ?></button>
	</div>

	<table class="avix-table avix-destinations-list">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'avix-migration' ); ?></th>
				<th><?php esc_html_e( 'Provider', 'avix-migration' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody><tr><td colspan="3" class="avix-text-muted"><?php esc_html_e( 'Loading…', 'avix-migration' ); ?></td></tr></tbody>
	</table>

	<!--
		A <div>, deliberately NOT a <form>: this partial is embedded inside the
		Backup and Schedules forms, and HTML forbids nested forms. The parser
		drops the inner <form> start tag but honours its </form>, which closed
		the OUTER form early — leaving the host page's own submit button
		associated with no form at all, so clicking it fired no submit event.
		Saving here is driven by a click handler instead.
	-->
	<div class="avix-destination-form" hidden>
		<input type="hidden" class="avix-dest-id" value="">

		<div class="avix-field">
			<label class="avix-field__label"><?php esc_html_e( 'Provider', 'avix-migration' ); ?></label>
			<select class="avix-select avix-dest-provider">
				<?php foreach ( Avix_Migration_Storage_Manager::labels() as $pid => $plabel ) : ?>
					<option value="<?php echo esc_attr( $pid ); ?>"><?php echo esc_html( $plabel ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="avix-field">
			<label class="avix-field__label"><?php esc_html_e( 'Name', 'avix-migration' ); ?></label>
			<input type="text" class="avix-input avix-dest-name" placeholder="<?php esc_attr_e( 'e.g. Offsite S3 backup', 'avix-migration' ); ?>">
		</div>

		<div class="avix-dest-fields-s3" hidden>
			<div class="avix-field"><label class="avix-field__label"><?php esc_html_e( 'Endpoint', 'avix-migration' ); ?></label><input type="text" class="avix-input" data-field="endpoint" placeholder="s3.amazonaws.com"></div>
			<div class="avix-field"><label class="avix-field__label"><?php esc_html_e( 'Bucket', 'avix-migration' ); ?></label><input type="text" class="avix-input" data-field="bucket"></div>
			<div class="avix-field"><label class="avix-field__label"><?php esc_html_e( 'Region', 'avix-migration' ); ?></label><input type="text" class="avix-input" data-field="region" placeholder="us-east-1"></div>
			<div class="avix-field"><label class="avix-field__label"><?php esc_html_e( 'Access key', 'avix-migration' ); ?></label><input type="text" class="avix-input" data-field="access_key"></div>
			<div class="avix-field"><label class="avix-field__label"><?php esc_html_e( 'Secret key', 'avix-migration' ); ?></label><input type="password" class="avix-input" data-field="secret_key"></div>
			<div class="avix-toggle-row"><div class="avix-toggle-row__label"><?php esc_html_e( 'Path-style addressing', 'avix-migration' ); ?></div><label class="avix-switch"><input type="checkbox" data-field="path_style"><span class="avix-switch__track"></span></label></div>
		</div>

		<div class="avix-dest-fields-ftp avix-dest-fields-sftp" hidden>
			<div class="avix-field"><label class="avix-field__label"><?php esc_html_e( 'Host', 'avix-migration' ); ?></label><input type="text" class="avix-input" data-field="host"></div>
			<div class="avix-field"><label class="avix-field__label"><?php esc_html_e( 'Port', 'avix-migration' ); ?></label><input type="number" class="avix-input" data-field="port"></div>
			<div class="avix-field"><label class="avix-field__label"><?php esc_html_e( 'Username', 'avix-migration' ); ?></label><input type="text" class="avix-input" data-field="username"></div>
			<div class="avix-field"><label class="avix-field__label"><?php esc_html_e( 'Password', 'avix-migration' ); ?></label><input type="password" class="avix-input" data-field="password"></div>
			<div class="avix-field avix-dest-fields-sftp-only"><label class="avix-field__label"><?php esc_html_e( 'Private key (optional, PEM)', 'avix-migration' ); ?></label><textarea class="avix-input" rows="3" data-field="private_key"></textarea></div>
			<div class="avix-field avix-dest-fields-sftp-only"><label class="avix-field__label"><?php esc_html_e( 'Key passphrase', 'avix-migration' ); ?></label><input type="password" class="avix-input" data-field="passphrase"></div>
			<div class="avix-field"><label class="avix-field__label"><?php esc_html_e( 'Remote directory', 'avix-migration' ); ?></label><input type="text" class="avix-input" data-field="remote_dir" placeholder="/backups"></div>
			<div class="avix-toggle-row avix-dest-fields-ftp-only"><div class="avix-toggle-row__label"><?php esc_html_e( 'Use FTPS (explicit TLS)', 'avix-migration' ); ?></div><label class="avix-switch"><input type="checkbox" data-field="use_ftps"><span class="avix-switch__track"></span></label></div>
		</div>

		<div class="avix-dest-fields-drive avix-dest-fields-dropbox" hidden>
			<p class="avix-text-muted"><?php esc_html_e( 'Requires your own OAuth app (internal use avoids the provider\'s public-app review). Enter your client credentials, then connect.', 'avix-migration' ); ?></p>
			<div class="avix-field"><label class="avix-field__label"><?php esc_html_e( 'Client ID', 'avix-migration' ); ?></label><input type="text" class="avix-input" data-field="client_id"></div>
			<div class="avix-field"><label class="avix-field__label"><?php esc_html_e( 'Client secret', 'avix-migration' ); ?></label><input type="password" class="avix-input" data-field="client_secret"></div>
			<div class="avix-field avix-dest-fields-drive-only"><label class="avix-field__label"><?php esc_html_e( 'Folder ID (optional)', 'avix-migration' ); ?></label><input type="text" class="avix-input" data-field="folder_id"></div>
			<div class="avix-field avix-dest-fields-dropbox-only"><label class="avix-field__label"><?php esc_html_e( 'Folder path (optional)', 'avix-migration' ); ?></label><input type="text" class="avix-input" data-field="remote_dir" placeholder="/backups"></div>
			<button type="button" class="avix-btn avix-dest-oauth-connect"><?php esc_html_e( 'Connect…', 'avix-migration' ); ?></button>
			<span class="avix-badge avix-badge-success avix-dest-oauth-connected" hidden><?php esc_html_e( 'Connected', 'avix-migration' ); ?></span>
		</div>

		<div class="avix-flex-between" style="margin-top:14px;">
			<button type="button" class="avix-btn avix-dest-cancel"><?php esc_html_e( 'Cancel', 'avix-migration' ); ?></button>
			<div style="display:flex; gap:8px;">
				<button type="button" class="avix-btn avix-dest-test"><?php esc_html_e( 'Test connection', 'avix-migration' ); ?></button>
				<button type="button" class="avix-btn avix-btn-primary avix-dest-save"><?php esc_html_e( 'Save', 'avix-migration' ); ?></button>
			</div>
		</div>
	</div>
</div>
