/**
 * Import wizard: chunked upload, pre-flight report, start/poll/rollback.
 * Depends on window.AvixAdmin (avix-admin.js).
 */
( function () {
	'use strict';

	var qs = window.AvixAdmin.qs;
	var cfg = window.AvixMigration || {};
	var CHUNK_SIZE = 2 * 1024 * 1024; // 2 MB

	var state = { filename: null, archiveType: 'full' };

	function showPanel( id ) {
		[ 'avix-import-source', 'avix-import-preflight', 'avix-import-progress', 'avix-import-done', 'avix-import-failed' ].forEach( function ( panelId ) {
			var el = document.getElementById( panelId );
			if ( el ) {
				el.hidden = panelId !== id;
			}
		} );
	}

	/**
	 * Uploads a File in fixed-size chunks via FormData (binary-safe,
	 * unlike the generic AvixAdmin.ajax() helper which URL-encodes
	 * everything and can't carry a Blob).
	 */
	function uploadFile( file, onProgress ) {
		var uploadId = 'up' + Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2 );
		var totalChunks = Math.ceil( file.size / CHUNK_SIZE );
		var index = 0;

		function sendNext() {
			if ( index >= totalChunks ) {
				return Promise.reject( new Error( 'No more chunks.' ) );
			}
			var start = index * CHUNK_SIZE;
			var blob = file.slice( start, start + CHUNK_SIZE );

			var form = new FormData();
			form.append( 'action', 'avix_upload_chunk' );
			form.append( 'nonce', cfg.nonce );
			form.append( 'upload_id', uploadId );
			form.append( 'chunk_index', String( index ) );
			form.append( 'total_chunks', String( totalChunks ) );
			form.append( 'filename', file.name );
			form.append( 'chunk', blob );

			return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form } )
				.then( function ( res ) { return res.json(); } )
				.then( function ( json ) {
					if ( ! json || ! json.success ) {
						throw new Error( ( json && json.data && json.data.message ) || 'Upload failed.' );
					}
					index++;
					onProgress( Math.round( ( index / totalChunks ) * 100 ) );
					if ( json.data.done ) {
						return json.data.final_path;
					}
					return sendNext();
				} );
		}

		return sendNext();
	}

	function handleFile( file ) {
		document.getElementById( 'avix-upload-progress' ).hidden = false;
		var fill = document.querySelector( '[data-upload-fill]' );
		var pct  = document.querySelector( '[data-upload-percent]' );

		uploadFile( file, function ( percent ) {
			fill.style.width = percent + '%';
			pct.textContent = percent + '%';
		} )
			.then( function ( finalPath ) {
				// Server returns an absolute path for logging purposes only;
				// we only ever refer to archives by filename from here on.
				var filename = finalPath.split( '/' ).pop();
				loadPreflight( filename );
			} )
			.catch( function ( err ) {
				window.AvixAdmin.toast( err.message || 'Upload failed.', 'error' );
			} );
	}

	function wireDropzone() {
		var zone = document.getElementById( 'avix-dropzone' );
		var input = document.getElementById( 'avix-file-input' );
		if ( ! zone ) {
			return;
		}

		zone.addEventListener( 'click', function () { input.click(); } );
		input.addEventListener( 'change', function () {
			if ( input.files[0] ) {
				handleFile( input.files[0] );
			}
		} );
		zone.addEventListener( 'dragover', function ( e ) { e.preventDefault(); zone.style.opacity = '0.7'; } );
		zone.addEventListener( 'dragleave', function () { zone.style.opacity = '1'; } );
		zone.addEventListener( 'drop', function ( e ) {
			e.preventDefault();
			zone.style.opacity = '1';
			if ( e.dataTransfer.files[0] ) {
				handleFile( e.dataTransfer.files[0] );
			}
		} );

		window.AvixAdmin.qsa( '[data-pick-existing]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				loadPreflight( btn.getAttribute( 'data-pick-existing' ) );
			} );
		} );
	}

	function loadPreflight( filename ) {
		state.filename = filename;
		window.AvixAdmin.ajax( 'avix_import_preflight', { filename: filename } )
			.then( function ( data ) {
				renderPreflight( filename, data );
				showPanel( 'avix-import-preflight' );
			} )
			.catch( function ( err ) {
				window.AvixAdmin.toast( err.message || 'Could not read that archive.', 'error' );
			} );
	}

	function renderPreflight( filename, data ) {
		var m = data.manifest || {};
		var site = m.site || {};
		state.archiveType = m.archive_type === 'content' ? 'content' : 'full';

		document.getElementById( 'avix-pf-full-options' ).hidden = state.archiveType !== 'full';
		document.getElementById( 'avix-pf-content-options' ).hidden = state.archiveType !== 'content';

		qs( '[data-pf-filename]' ).textContent = filename;
		qs( '[data-pf-source-url]' ).textContent = site.site_url || '—';
		qs( '[data-pf-versions]' ).textContent = ( site.wp_version || '?' ) + ' / PHP ' + ( site.php_version || '?' );
		qs( '[data-pf-type]' ).textContent = m.archive_type === 'content' ? 'Selected content' : 'Full site';

		var warningsEl = qs( '[data-pf-warnings]' );
		warningsEl.innerHTML = '';
		( data.warnings || [] ).forEach( function ( w ) {
			var div = document.createElement( 'div' );
			div.className = 'avix-badge avix-badge-warning';
			div.style.display = 'block';
			div.style.marginBottom = '6px';
			div.textContent = w;
			warningsEl.appendChild( div );
		} );
	}

	function wirePreflight() {
		var confirmInput = document.getElementById( 'avix-confirm-restore' );
		var startBtn = document.getElementById( 'avix-start-import' );
		if ( ! confirmInput ) {
			return;
		}

		confirmInput.addEventListener( 'input', function () {
			startBtn.disabled = confirmInput.value.trim().toUpperCase() !== 'RESTORE';
		} );

		document.getElementById( 'avix-preflight-cancel' ).addEventListener( 'click', function () {
			showPanel( 'avix-import-source' );
		} );

		startBtn.addEventListener( 'click', function () {
			startBtn.disabled = true;

			var payload = { filename: state.filename };
			if ( 'content' === state.archiveType ) {
				payload.conflict_mode = document.getElementById( 'avix-conflict-mode' ).value;
			} else {
				payload.restore_database = document.getElementById( 'avix-restore-database' ).checked ? '1' : '';
				payload.restore_files = document.getElementById( 'avix-restore-files' ).checked ? '1' : '';
				payload.keep_current_admin = document.getElementById( 'avix-keep-admin' ).checked ? '1' : '';
			}

			window.AvixAdmin.ajax( 'avix_start_import', payload )
				.then( function ( data ) {
					startPolling( data.job_id );
				} )
				.catch( function ( err ) {
					startBtn.disabled = false;
					window.AvixAdmin.toast( err.message || 'Could not start the import.', 'error' );
				} );
		} );
	}

	var lastLogCount = 0;
	function refreshLog( jobId, logEl ) {
		window.AvixAdmin.ajax( 'avix_job_log', { job_id: jobId } ).then( function ( data ) {
			var entries = data.entries || [];
			if ( entries.length === lastLogCount ) {
				return;
			}
			lastLogCount = entries.length;
			logEl.innerHTML = entries
				.map( function ( e ) {
					var cls = 'error' === e.level ? 'is-error' : ( 'warning' === e.level ? 'is-warning' : '' );
					var div = document.createElement( 'div' );
					div.className = 'avix-log__line ' + cls;
					div.textContent = e.message;
					return div.outerHTML;
				} )
				.join( '' );
		} );
	}

	function startPolling( jobId ) {
		showPanel( 'avix-import-progress' );

		var fill      = document.querySelector( '[data-progress-fill]' );
		var percentEl = document.querySelector( '[data-progress-percent]' );
		var stageEl   = document.querySelector( '[data-progress-stage]' );
		var detailEl  = document.querySelector( '[data-progress-detail]' );
		var heartbeat = document.querySelector( '[data-progress-heartbeat]' );
		var logEl     = document.querySelector( '[data-progress-log]' );

		document.querySelector( '[data-toggle-log]' ).addEventListener( 'click', function ( e ) {
			logEl.hidden = ! logEl.hidden;
			e.target.textContent = logEl.hidden ? 'Show log' : 'Hide log';
		} );

		window.AvixAdmin.pollJob( jobId, {
			onProgress: function ( snapshot ) {
				heartbeat.classList.remove( 'is-stalled' );
				fill.style.width = snapshot.percent + '%';
				percentEl.textContent = snapshot.percent + '%';
				stageEl.textContent = snapshot.stage_label || 'Working…';
				detailEl.textContent = snapshot.stage_message || '';
				refreshLog( jobId, logEl );
			},
			onDone: function ( snapshot ) {
				showPanel( 'avix-import-done' );
				qs( '[data-done-message]' ).textContent = 'content' === state.archiveType
					? ( snapshot.stage_message || 'Your content has been imported.' )
					: 'Your site has been restored.';

				// Rollback/discard only apply to a full-site database
				// restore — a content import never takes a database
				// snapshot, so those actions would just dead-end in a
				// "no rollback snapshot" error.
				if ( 'content' === state.archiveType ) {
					document.getElementById( 'avix-discard-rollback' ).hidden = true;
					document.getElementById( 'avix-rollback-done' ).hidden = true;
				} else {
					wireDoneActions( jobId );
				}
			},
			onFailed: function ( snapshot ) {
				showPanel( 'avix-import-failed' );
				qs( '[data-failed-message]' ).textContent = snapshot.error || 'Unknown error.';
				var rollbackBtn = document.getElementById( 'avix-rollback-failed' );
				if ( 'content' === state.archiveType ) {
					rollbackBtn.hidden = true;
				} else {
					rollbackBtn.addEventListener( 'click', function () {
						rollback( jobId, true );
					} );
				}
			},
			onNetworkIssue: function () {
				heartbeat.classList.add( 'is-stalled' );
			},
		} );
	}

	function wireDoneActions( jobId ) {
		document.getElementById( 'avix-discard-rollback' ).addEventListener( 'click', function () {
			window.AvixAdmin.ajax( 'avix_discard_rollback', { job_id: jobId } ).then( function () {
				window.AvixAdmin.toast( 'Safety copy removed.', 'success' );
			} );
		} );
		document.getElementById( 'avix-rollback-done' ).addEventListener( 'click', function () {
			window.AvixAdmin.confirmDestructive( {
				title: 'Undo this restore?',
				body: 'This puts back exactly what was here before the restore.',
				onConfirm: function () { rollback( jobId, false ); },
			} );
		} );
	}

	function rollback( jobId, reloadAfter ) {
		window.AvixAdmin.ajax( 'avix_rollback_import', { job_id: jobId } )
			.then( function () {
				window.AvixAdmin.toast( 'Rolled back.', 'success' );
				if ( reloadAfter ) {
					window.location.reload();
				}
			} )
			.catch( function ( err ) {
				window.AvixAdmin.toast( err.message || 'Rollback failed.', 'error' );
			} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( ! document.getElementById( 'avix-import-app' ) ) {
			return;
		}
		wireDropzone();
		wirePreflight();

		var params = new URLSearchParams( window.location.search );
		var preselect = params.get( 'file' );
		if ( preselect ) {
			loadPreflight( preselect );
		}
	} );
} )();
