/**
 * Schedules screen: add/edit/delete/toggle/run-now for saved recurring
 * backups. Depends on window.AvixAdmin.
 */
( function () {
	'use strict';

	var qs = window.AvixAdmin.qs;
	var qsa = window.AvixAdmin.qsa;

	var CONTENT_KEYS = [ 'uploads', 'plugins', 'themes', 'mu-plugins' ];

	function showForm( schedule ) {
		document.getElementById( 'avix-schedules-list-card' ).hidden = true;
		document.getElementById( 'avix-schedule-form-card' ).hidden = false;
		document.getElementById( 'avix-schedule-progress-card' ).hidden = true;

		var isEdit = !! schedule;
		document.getElementById( 'avix-schedule-form-title' ).textContent = isEdit ? 'Edit schedule' : 'New schedule';
		document.getElementById( 'avix-sched-id' ).value = isEdit ? schedule.id : '';
		document.getElementById( 'avix-sched-name' ).value = isEdit ? schedule.name : '';
		document.getElementById( 'avix-sched-frequency' ).value = isEdit ? schedule.frequency : 'daily';
		document.getElementById( 'avix-sched-time' ).value = isEdit ? schedule.time_of_day : '02:00';
		document.getElementById( 'avix-sched-enabled' ).checked = isEdit ? !! schedule.enabled : true;
		document.getElementById( 'avix-sched-include-database' ).checked = isEdit ? !! schedule.include_database : true;
		document.getElementById( 'avix-sched-include-files' ).checked = isEdit ? !! schedule.include_files : true;

		CONTENT_KEYS.forEach( function ( key ) {
			var excluded = isEdit && schedule.excluded_top_dirs && schedule.excluded_top_dirs[ key ];
			document.querySelector( '[data-content-toggle="' + key + '"]' ).checked = ! excluded;
		} );

		var destSelect = document.getElementById( 'avix-destination-select-schedule' );
		if ( destSelect ) {
			destSelect.value = isEdit && schedule.destination_id ? schedule.destination_id : 'local';
		}

		document.getElementById( 'avix-sched-keep-last' ).value = isEdit ? schedule.retention_keep_last : 5;
		document.getElementById( 'avix-sched-older-days' ).value = isEdit ? schedule.retention_older_than_days : 0;
		document.getElementById( 'avix-sched-email' ).value = isEdit ? schedule.notify_email : ( window.AvixMigration.adminEmail || '' );
		document.getElementById( 'avix-sched-notify-success' ).checked = isEdit ? !! schedule.notify_on_success : false;
		document.getElementById( 'avix-sched-notify-failure' ).checked = isEdit ? !! schedule.notify_on_failure : true;
		toggleTimeField();
	}

	function hideForm() {
		document.getElementById( 'avix-schedules-list-card' ).hidden = false;
		document.getElementById( 'avix-schedule-form-card' ).hidden = true;
	}

	function toggleTimeField() {
		var freq = document.getElementById( 'avix-sched-frequency' ).value;
		document.getElementById( 'avix-sched-time-field' ).hidden = ( 'hourly' === freq || 'avix_six_hourly' === freq );
	}

	function wireForm() {
		document.getElementById( 'avix-add-schedule' ).addEventListener( 'click', function () { showForm( null ); } );
		document.getElementById( 'avix-schedule-cancel' ).addEventListener( 'click', hideForm );
		document.getElementById( 'avix-sched-frequency' ).addEventListener( 'change', toggleTimeField );

		qsa( '[data-edit-schedule]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var schedule = JSON.parse( btn.getAttribute( 'data-edit-schedule' ) );
				showForm( schedule );
			} );
		} );

		document.getElementById( 'avix-schedule-form' ).addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var payload = {
				schedule_id: document.getElementById( 'avix-sched-id' ).value,
				name: document.getElementById( 'avix-sched-name' ).value,
				frequency: document.getElementById( 'avix-sched-frequency' ).value,
				time_of_day: document.getElementById( 'avix-sched-time' ).value,
				enabled: document.getElementById( 'avix-sched-enabled' ).checked ? '1' : '',
				include_database: document.getElementById( 'avix-sched-include-database' ).checked ? '1' : '',
				include_files: document.getElementById( 'avix-sched-include-files' ).checked ? '1' : '',
				destination_id: ( document.getElementById( 'avix-destination-select-schedule' ) || {} ).value || 'local',
				retention_keep_last: document.getElementById( 'avix-sched-keep-last' ).value,
				retention_older_than_days: document.getElementById( 'avix-sched-older-days' ).value,
				notify_email: document.getElementById( 'avix-sched-email' ).value,
				notify_on_success: document.getElementById( 'avix-sched-notify-success' ).checked ? '1' : '',
				notify_on_failure: document.getElementById( 'avix-sched-notify-failure' ).checked ? '1' : '',
			};

			CONTENT_KEYS.forEach( function ( key ) {
				// The element id uses the raw key ("mu-plugins", as the view
				// renders it); only the POST key is underscored, to match the
				// server's str_replace( '-', '_' ). Underscoring the id too
				// looked up an element that doesn't exist, so `mu-plugins`
				// silently posted as unchecked and was excluded from every
				// scheduled backup. Uploads/plugins/themes have no dash, so
				// they worked — which is what hid this.
				var el = document.getElementById( 'avix-sched-include-' + key );
				payload[ 'include_' + key.split( '-' ).join( '_' ) ] = el && el.checked ? '1' : '';
			} );

			window.AvixAdmin.ajax( 'avix_schedule_save', payload )
				.then( function () {
					window.AvixAdmin.toast( 'Schedule saved.', 'success' );
					window.location.reload();
				} )
				.catch( function ( err ) {
					window.AvixAdmin.toast( err.message || 'Could not save schedule.', 'error' );
				} );
		} );
	}

	function wireListActions() {
		qsa( '[data-toggle-schedule]' ).forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				window.AvixAdmin.ajax( 'avix_schedule_toggle', { schedule_id: input.getAttribute( 'data-toggle-schedule' ) } )
					.catch( function ( err ) {
						input.checked = ! input.checked; // Revert on failure.
						window.AvixAdmin.toast( err.message || 'Could not update schedule.', 'error' );
					} );
			} );
		} );

		qsa( '[data-run-now]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				btn.disabled = true;
				window.AvixAdmin.ajax( 'avix_schedule_run_now', { schedule_id: btn.getAttribute( 'data-run-now' ) } )
					.then( function ( data ) {
						startPolling( data.job_id );
					} )
					.catch( function ( err ) {
						btn.disabled = false;
						window.AvixAdmin.toast( err.message || 'Could not start backup.', 'error' );
					} );
			} );
		} );
	}

	function startPolling( jobId ) {
		document.getElementById( 'avix-schedules-list-card' ).hidden = true;
		document.getElementById( 'avix-schedule-form-card' ).hidden = true;
		document.getElementById( 'avix-schedule-progress-card' ).hidden = false;

		var fill      = document.querySelector( '[data-progress-fill]' );
		var percentEl = document.querySelector( '[data-progress-percent]' );
		var stageEl   = document.querySelector( '[data-progress-stage]' );
		var detailEl  = document.querySelector( '[data-progress-detail]' );
		var heartbeat = document.querySelector( '[data-progress-heartbeat]' );

		window.AvixAdmin.pollJob( jobId, {
			onProgress: function ( snapshot ) {
				heartbeat.classList.remove( 'is-stalled' );
				fill.style.width = snapshot.percent + '%';
				percentEl.textContent = snapshot.percent + '%';
				stageEl.textContent = snapshot.stage_label || 'Working…';
				detailEl.textContent = snapshot.stage_message || '';
			},
			onDone: function () {
				window.AvixAdmin.toast( 'Backup complete.', 'success' );
				window.location.reload();
			},
			onFailed: function ( snapshot ) {
				window.AvixAdmin.toast( snapshot.error || 'Backup failed.', 'error' );
				window.location.reload();
			},
			onNetworkIssue: function () {
				heartbeat.classList.add( 'is-stalled' );
			},
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( ! document.getElementById( 'avix-schedules-app' ) ) {
			return;
		}
		wireForm();
		wireListActions();
	} );
} )();
