/**
 * Remote Sites screen: issue/revoke connection keys, add/remove remotes,
 * and push/pull. Push polls a normal local job then, once it finishes,
 * switches to polling the remote's import job; pull polls the remote's
 * export job then switches to a normal local job (which downloads the
 * archive as its first step). Depends on window.AvixAdmin.
 */
( function () {
	'use strict';

	var qs = window.AvixAdmin.qs;
	var qsa = window.AvixAdmin.qsa;

	function escapeHtml( s ) {
		var div = document.createElement( 'div' );
		div.textContent = String( s == null ? '' : s );
		return div.innerHTML;
	}

	function loadAll() {
		window.AvixAdmin.ajax( 'avix_remote_list', {} ).then( function ( data ) {
			renderIssuedKeys( data.issued_keys );
			renderRemotes( data.remotes );
		} );
	}

	function renderIssuedKeys( keys ) {
		var body = document.getElementById( 'avix-issued-keys-rows' );
		var ids = Object.keys( keys );
		if ( ! ids.length ) {
			body.innerHTML = '<tr><td colspan="3" class="avix-text-muted">No keys issued yet.</td></tr>';
			return;
		}
		body.innerHTML = '';
		ids.forEach( function ( id ) {
			var k = keys[ id ];
			var expires = k.expires_at ? new Date( k.expires_at * 1000 ).toLocaleString() : 'Never';
			var tr = document.createElement( 'tr' );
			tr.innerHTML = '<td>' + escapeHtml( k.label ) + '</td><td class="avix-text-muted">' + escapeHtml( expires ) + '</td>' +
				'<td style="text-align:right;"><button type="button" class="avix-btn avix-btn-sm avix-btn-danger" data-revoke-key="' + id + '">Revoke</button></td>';
			body.appendChild( tr );
		} );
		qsa( '[data-revoke-key]', body ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				window.AvixAdmin.ajax( 'avix_remote_revoke_key', { key_id: btn.getAttribute( 'data-revoke-key' ) } ).then( loadAll );
			} );
		} );
	}

	function renderRemotes( remotes ) {
		var body = document.getElementById( 'avix-remotes-rows' );
		var ids = Object.keys( remotes );
		if ( ! ids.length ) {
			body.innerHTML = '<tr><td colspan="4" class="avix-text-muted">No remote sites yet.</td></tr>';
			return;
		}
		body.innerHTML = '';
		ids.forEach( function ( id ) {
			var r = remotes[ id ];
			var tr = document.createElement( 'tr' );
			tr.innerHTML =
				'<td>' + escapeHtml( r.label ) + '</td>' +
				'<td class="avix-text-muted">' + escapeHtml( r.site_url ) + '</td>' +
				'<td data-status-cell><span class="avix-badge avix-badge-neutral">Checking…</span></td>' +
				'<td style="text-align:right; white-space:nowrap;">' +
				'<button type="button" class="avix-btn avix-btn-sm" data-push="' + id + '">Push</button> ' +
				'<button type="button" class="avix-btn avix-btn-sm" data-pull="' + id + '">Pull</button> ' +
				'<button type="button" class="avix-btn avix-btn-sm avix-btn-danger" data-delete-remote="' + id + '">Delete</button>' +
				'</td>';
			body.appendChild( tr );

			window.AvixAdmin.ajax( 'avix_remote_check', { remote_id: id } ).then( function ( status ) {
				var cell = qs( '[data-status-cell]', tr );
				cell.innerHTML = status.reachable
					? '<span class="avix-badge avix-badge-success">Reachable</span>'
					: '<span class="avix-badge avix-badge-danger" title="' + escapeHtml( status.message ) + '">Unreachable</span>';
			} );

			qs( '[data-push]', tr ).addEventListener( 'click', function () { startPush( id ); } );
			qs( '[data-pull]', tr ).addEventListener( 'click', function () { startPull( id ); } );
			qs( '[data-delete-remote]', tr ).addEventListener( 'click', function () {
				if ( ! window.confirm( 'Remove this remote site?' ) ) { return; }
				window.AvixAdmin.ajax( 'avix_remote_delete', { remote_id: id } ).then( loadAll );
			} );
		} );
	}

	function wireKeyForm() {
		document.getElementById( 'avix-issue-key-btn' ).addEventListener( 'click', function () {
			document.getElementById( 'avix-new-key-form' ).hidden = false;
			document.getElementById( 'avix-new-key-result' ).style.display = 'none';
		} );
		document.getElementById( 'avix-key-cancel' ).addEventListener( 'click', function () {
			document.getElementById( 'avix-new-key-form' ).hidden = true;
		} );
		document.getElementById( 'avix-key-generate' ).addEventListener( 'click', function () {
			window.AvixAdmin.ajax( 'avix_remote_issue_key', {
				label: document.getElementById( 'avix-key-label' ).value,
				expires_hours: document.getElementById( 'avix-key-expires' ).value,
			} ).then( function ( data ) {
				document.getElementById( 'avix-new-key-form' ).hidden = true;
				var result = document.getElementById( 'avix-new-key-result' );
				result.style.display = 'block';
				result.textContent = data.connection_string;
				window.AvixAdmin.toast( 'Key generated — copy it now, it will not be shown again.', 'success' );
				loadAll();
			} ).catch( function ( err ) {
				window.AvixAdmin.toast( err.message || 'Could not generate key.', 'error' );
			} );
		} );
	}

	function wireRemoteForm() {
		document.getElementById( 'avix-add-remote-btn' ).addEventListener( 'click', function () {
			document.getElementById( 'avix-add-remote-form' ).hidden = false;
		} );
		document.getElementById( 'avix-remote-cancel' ).addEventListener( 'click', function () {
			document.getElementById( 'avix-add-remote-form' ).hidden = true;
		} );
		document.getElementById( 'avix-remote-save' ).addEventListener( 'click', function () {
			window.AvixAdmin.ajax( 'avix_remote_add', {
				label: document.getElementById( 'avix-remote-label' ).value,
				connection_string: document.getElementById( 'avix-remote-connstring' ).value,
			} ).then( function () {
				document.getElementById( 'avix-add-remote-form' ).hidden = true;
				window.AvixAdmin.toast( 'Remote added.', 'success' );
				loadAll();
			} ).catch( function ( err ) {
				window.AvixAdmin.toast( err.message || 'Could not add remote.', 'error' );
			} );
		} );
	}

	// ---------------------------------------------------------------
	// Progress UI (shared by push and pull).
	// ---------------------------------------------------------------

	var progressEls;

	function showProgress() {
		document.getElementById( 'avix-remote-progress-card' ).hidden = false;
		progressEls = {
			fill: document.querySelector( '[data-progress-fill]' ),
			percent: document.querySelector( '[data-progress-percent]' ),
			stage: document.querySelector( '[data-progress-stage]' ),
			detail: document.querySelector( '[data-progress-detail]' ),
			heartbeat: document.querySelector( '[data-progress-heartbeat]' ),
		};
	}

	function renderProgress( snapshot ) {
		progressEls.heartbeat.classList.remove( 'is-stalled' );
		progressEls.fill.style.width = snapshot.percent + '%';
		progressEls.percent.textContent = snapshot.percent + '%';
		progressEls.stage.textContent = snapshot.stage_label || 'Working…';
		progressEls.detail.textContent = snapshot.stage_message || '';
	}

	/**
	 * Polls a job running on a REMOTE site via this site's proxy AJAX
	 * action (the browser never talks to the remote directly — it has no
	 * way to sign a request, by design). Mirrors AvixAdmin.pollJob's
	 * shape so the same snapshot-rendering code works for both.
	 */
	function pollRemoteJob( remoteId, remoteJobId, kind, handlers ) {
		var failures = 0;

		function tick() {
			window.AvixAdmin.ajax( 'avix_remote_poll_remote_job', { remote_id: remoteId, remote_job_id: remoteJobId, kind: kind } )
				.then( function ( snapshot ) {
					failures = 0;
					handlers.onProgress && handlers.onProgress( snapshot );
					if ( 'done' === snapshot.status ) {
						handlers.onDone && handlers.onDone( snapshot );
						return;
					}
					if ( 'failed' === snapshot.status ) {
						handlers.onFailed && handlers.onFailed( snapshot );
						return;
					}
					setTimeout( tick, 800 );
				} )
				.catch( function ( err ) {
					failures++;
					handlers.onNetworkIssue && handlers.onNetworkIssue( err, failures );
					setTimeout( tick, Math.min( 10000, 1000 * Math.pow( 1.6, failures ) ) );
				} );
		}

		tick();
	}

	function startPush( remoteId ) {
		showProgress();
		window.AvixAdmin.ajax( 'avix_remote_push', { remote_id: remoteId } )
			.then( function ( data ) {
				window.AvixAdmin.pollJob( data.job_id, {
					onProgress: renderProgress,
					onDone: function ( snapshot ) {
						if ( snapshot.remote_import_job_id ) {
							progressEls.stage.textContent = 'Waiting for the remote site to import…';
							pollRemoteJob( remoteId, snapshot.remote_import_job_id, 'import', {
								onProgress: renderProgress,
								onDone: function () {
									window.AvixAdmin.toast( 'Push complete — remote site updated.', 'success' );
									loadAll();
								},
								onFailed: function ( s ) {
									window.AvixAdmin.toast( 'Remote import failed: ' + ( s.error || 'unknown error' ), 'error' );
								},
								onNetworkIssue: function () { progressEls.heartbeat.classList.add( 'is-stalled' ); },
							} );
						} else {
							window.AvixAdmin.toast( 'Uploaded — remote import did not report a job id.', 'error' );
						}
					},
					onFailed: function ( snapshot ) {
						window.AvixAdmin.toast( snapshot.error || 'Push failed.', 'error' );
					},
					onNetworkIssue: function () { progressEls.heartbeat.classList.add( 'is-stalled' ); },
				} );
			} )
			.catch( function ( err ) {
				window.AvixAdmin.toast( err.message || 'Could not start push.', 'error' );
			} );
	}

	function startPull( remoteId ) {
		showProgress();
		progressEls.stage.textContent = 'Asking remote site to export…';

		window.AvixAdmin.ajax( 'avix_remote_pull_start', { remote_id: remoteId } )
			.then( function ( data ) {
				pollRemoteJob( remoteId, data.remote_job_id, 'export', {
					onProgress: renderProgress,
					onDone: function ( snapshot ) {
						progressEls.stage.textContent = 'Downloading…';
						window.AvixAdmin.ajax( 'avix_remote_pull_begin_import', {
							remote_id: remoteId,
							remote_job_id: data.remote_job_id,
							archive_filename: snapshot.archive_filename,
							archive_type: snapshot.archive_type || 'full',
						} ).then( function ( importData ) {
							window.AvixAdmin.pollJob( importData.job_id, {
								onProgress: renderProgress,
								onDone: function () {
									window.AvixAdmin.toast( 'Pull complete — this site has been updated.', 'success' );
									loadAll();
								},
								onFailed: function ( s ) {
									window.AvixAdmin.toast( s.error || 'Import failed.', 'error' );
								},
								onNetworkIssue: function () { progressEls.heartbeat.classList.add( 'is-stalled' ); },
							} );
						} ).catch( function ( err ) {
							window.AvixAdmin.toast( err.message || 'Could not start the import.', 'error' );
						} );
					},
					onFailed: function ( snapshot ) {
						window.AvixAdmin.toast( 'Remote export failed: ' + ( snapshot.error || 'unknown error' ), 'error' );
					},
					onNetworkIssue: function () { progressEls.heartbeat.classList.add( 'is-stalled' ); },
				} );
			} )
			.catch( function ( err ) {
				window.AvixAdmin.toast( err.message || 'Could not start pull.', 'error' );
			} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( ! document.getElementById( 'avix-remote-app' ) ) {
			return;
		}
		wireKeyForm();
		wireRemoteForm();
		loadAll();
	} );
} )();
