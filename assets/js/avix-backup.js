/**
 * Backup wizard: step navigation, exclusion auto-detect, and the
 * start-backup -> poll-job -> success/failure flow. Depends on
 * window.AvixAdmin (avix-admin.js) for ajax()/pollJob()/toast().
 */
( function () {
	'use strict';

	var qs = window.AvixAdmin.qs;
	var qsa = window.AvixAdmin.qsa;
	var cfg = window.AvixMigration || {};

	var STEPS = array_steps();
	function array_steps() {
		return [ 'include', 'exclusions', 'advanced', 'destination' ];
	}

	var currentStepIndex = 0;

	function showStep( index ) {
		currentStepIndex = index;
		STEPS.forEach( function ( key, i ) {
			var panel = document.querySelector( '[data-step-panel="' + key + '"]' );
			var chip  = document.querySelector( '[data-step-chip="' + key + '"]' );
			if ( panel ) {
				panel.hidden = i !== index;
			}
			if ( chip ) {
				chip.classList.toggle( 'is-active', i === index );
				chip.classList.toggle( 'is-done', i < index );
			}
		} );
	}

	function wireWizardNav() {
		qsa( '[data-wizard-next]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				if ( currentStepIndex < STEPS.length - 1 ) {
					showStep( currentStepIndex + 1 );
				}
			} );
		} );
		qsa( '[data-wizard-back]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				if ( currentStepIndex > 0 ) {
					showStep( currentStepIndex - 1 );
				}
			} );
		} );

		var includeFiles = document.getElementById( 'avix-include-files' );
		var subToggles    = document.getElementById( 'avix-content-subtoggles' );
		if ( includeFiles && subToggles ) {
			var sync = function () {
				subToggles.style.opacity = includeFiles.checked ? '1' : '0.4';
				qsa( 'input[type="checkbox"]', subToggles ).forEach( function ( cb ) {
					cb.disabled = ! includeFiles.checked;
				} );
			};
			includeFiles.addEventListener( 'change', sync );
			sync();
		}
	}

	function loadDetectedExclusions() {
		var container = document.getElementById( 'avix-detected-exclusions' );
		if ( ! container ) {
			return;
		}
		window.AvixAdmin.ajax( 'avix_detect_exclusions', {} )
			.then( function ( data ) {
				var detected = data.detected || [];
				if ( ! detected.length ) {
					container.innerHTML = '<p class="avix-text-muted">' + escapeHtml( 'Nothing suspicious found — nice and clean.' ) + '</p>';
					return;
				}
				container.innerHTML = '';
				detected.forEach( function ( item ) {
					var row = document.createElement( 'div' );
					row.className = 'avix-toggle-row';
					row.innerHTML =
						'<div>' +
							'<div class="avix-toggle-row__label">' + escapeHtml( item.dir ) + '</div>' +
							'<div class="avix-toggle-row__hint">' + escapeHtml( item.reason ) + ' — ' + escapeHtml( humanSize( item.bytes ) ) + '</div>' +
						'</div>' +
						'<label class="avix-switch"><input type="checkbox" name="exclude_auto[]" value="' + escapeHtml( item.dir ) + '" checked><span class="avix-switch__track"></span></label>';
					container.appendChild( row );
				} );
			} )
			.catch( function () {
				container.innerHTML = '<p class="avix-text-muted">Could not scan for exclusions — you can still add patterns manually below.</p>';
			} );
	}

	function humanSize( bytes ) {
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i = 0;
		bytes = Number( bytes ) || 0;
		while ( bytes >= 1024 && i < units.length - 1 ) {
			bytes /= 1024;
			i++;
		}
		return Math.round( bytes * 10 ) / 10 + ' ' + units[ i ];
	}

	function escapeHtml( s ) {
		var div = document.createElement( 'div' );
		div.textContent = String( s == null ? '' : s );
		return div.innerHTML;
	}

	function serializeForm( form ) {
		var data = {};
		qsa( 'input, textarea', form ).forEach( function ( field ) {
			if ( field.disabled ) {
				return;
			}
			if ( 'checkbox' === field.type ) {
				if ( field.name.endsWith( '[]' ) ) {
					var key = field.name.slice( 0, -2 );
					data[ key ] = data[ key ] || [];
					if ( field.checked ) {
						data[ key ].push( field.value );
					}
				} else {
					data[ field.name ] = field.checked ? '1' : '';
				}
			} else {
				data[ field.name ] = field.value;
			}
		} );
		return data;
	}

	function showPanel( id ) {
		[ 'avix-backup-wizard', 'avix-backup-progress', 'avix-backup-done', 'avix-backup-failed' ].forEach( function ( panelId ) {
			var el = document.getElementById( panelId );
			if ( el ) {
				el.hidden = panelId !== id;
			}
		} );
	}

	function downloadUrl( filename ) {
		return cfg.adminPostUrl + '?action=avix_download_archive&file=' + encodeURIComponent( filename ) + '&_wpnonce=' + encodeURIComponent( cfg.downloadNonce );
	}

	function startPolling( jobId ) {
		showPanel( 'avix-backup-progress' );

		var fill        = document.querySelector( '[data-progress-fill]' );
		var percentEl   = document.querySelector( '[data-progress-percent]' );
		var stageEl     = document.querySelector( '[data-progress-stage]' );
		var detailEl    = document.querySelector( '[data-progress-detail]' );
		var heartbeat   = document.querySelector( '[data-progress-heartbeat]' );
		var logEl       = document.querySelector( '[data-progress-log]' );

		var poller = window.AvixAdmin.pollJob( jobId, {
			onProgress: function ( snapshot ) {
				heartbeat.classList.remove( 'is-stalled' );
				fill.style.width = snapshot.percent + '%';
				fill.setAttribute( 'aria-valuenow', snapshot.percent );
				percentEl.textContent = snapshot.percent + '%';
				stageEl.textContent = snapshot.stage_label || 'Working…';
				detailEl.textContent = snapshot.stage_message || '';
				refreshLog( jobId, logEl );
			},
			onDone: function ( snapshot ) {
				showPanel( 'avix-backup-done' );
				var summary = document.querySelector( '[data-done-summary]' );
				var totalBytes = ( snapshot.totals && snapshot.totals.bytes_done ) || 0;
				summary.textContent = ( snapshot.archive_filename || 'Archive' ) + ' — ' + humanSize( totalBytes );
				var link = document.querySelector( '[data-done-download]' );
				if ( snapshot.archive_filename ) {
					link.href = downloadUrl( snapshot.archive_filename );
				}
			},
			onFailed: function ( snapshot ) {
				showPanel( 'avix-backup-failed' );
				document.querySelector( '[data-failed-message]' ).textContent = snapshot.error || 'Unknown error.';
			},
			onCancelled: function () {
				window.AvixAdmin.toast( cfg.i18n && cfg.i18n.cancelled, 'warning' );
				window.location.reload();
			},
			onNetworkIssue: function () {
				heartbeat.classList.add( 'is-stalled' );
			},
		} );

		document.querySelector( '[data-cancel-job]' ).addEventListener( 'click', function () {
			poller.stop();
			window.AvixAdmin.ajax( 'avix_job_cancel', { job_id: jobId } ).then( function () {
				window.location.reload();
			} );
		} );

		document.querySelector( '[data-toggle-log]' ).addEventListener( 'click', function ( e ) {
			logEl.hidden = ! logEl.hidden;
			e.target.textContent = logEl.hidden ? 'Show log' : 'Hide log';
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
					return '<div class="avix-log__line ' + cls + '">' + escapeHtml( e.message ) + '</div>';
				} )
				.join( '' );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.getElementById( 'avix-backup-form' );
		if ( ! form ) {
			return; // Not on the Backup screen.
		}

		wireWizardNav();
		loadDetectedExclusions();

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var btn = document.getElementById( 'avix-start-backup' );
			btn.disabled = true;

			var payload = serializeForm( form );
			var destSelect = document.getElementById( 'avix-destination-select-backup' );
			payload.destination_id = destSelect ? destSelect.value : 'local';

			window.AvixAdmin.ajax( 'avix_start_backup', payload )
				.then( function ( data ) {
					startPolling( data.job_id );
				} )
				.catch( function ( err ) {
					btn.disabled = false;
					window.AvixAdmin.toast( err.message || ( cfg.i18n && cfg.i18n.genericError ), 'error' );
				} );
		} );
	} );
} )();
