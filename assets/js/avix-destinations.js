/**
 * Shared destination picker + connection manager — included on Backup,
 * Content Export, and Schedules screens. Populates every
 * .avix-destination-select on the page and wires the manager panel
 * (list/add/edit/test/delete + OAuth connect for Drive/Dropbox).
 */
( function () {
	'use strict';

	var qs = window.AvixAdmin.qs;
	var qsa = window.AvixAdmin.qsa;

	var PROVIDERS = [ 's3', 'ftp', 'sftp', 'drive', 'dropbox' ];

	function escapeHtml( s ) {
		var div = document.createElement( 'div' );
		div.textContent = String( s == null ? '' : s );
		return div.innerHTML;
	}

	function populateSelects( destinations ) {
		qsa( '.avix-destination-select' ).forEach( function ( select ) {
			var current = select.value;
			qsa( 'option:not([value="local"])', select ).forEach( function ( opt ) { opt.remove(); } );
			Object.keys( destinations ).forEach( function ( id ) {
				var opt = document.createElement( 'option' );
				opt.value = id;
				opt.textContent = destinations[ id ].name + ' (' + destinations[ id ].provider + ')';
				select.appendChild( opt );
			} );
			if ( current && ( 'local' === current || destinations[ current ] ) ) {
				select.value = current;
			}
		} );
	}

	function loadDestinations() {
		return window.AvixAdmin.ajax( 'avix_storage_list', {} ).then( function ( data ) {
			populateSelects( data.destinations );
			renderList( data.destinations );
			return data.destinations;
		} );
	}

	function renderList( destinations ) {
		qsa( '.avix-destinations-list tbody' ).forEach( function ( body ) {
			var ids = Object.keys( destinations );
			if ( ! ids.length ) {
				body.innerHTML = '<tr><td colspan="3" class="avix-text-muted">No destinations yet.</td></tr>';
				return;
			}
			body.innerHTML = '';
			ids.forEach( function ( id ) {
				var d = destinations[ id ];
				var tr = document.createElement( 'tr' );
				tr.innerHTML =
					'<td>' + escapeHtml( d.name ) + '</td>' +
					'<td><span class="avix-badge avix-badge-neutral">' + escapeHtml( d.provider ) + '</span></td>' +
					'<td style="text-align:right;"><button type="button" class="avix-btn avix-btn-sm" data-edit-dest="' + id + '">Edit</button> ' +
					'<button type="button" class="avix-btn avix-btn-sm avix-btn-danger" data-delete-dest="' + id + '">Delete</button></td>';
				body.appendChild( tr );
			} );

			qsa( '[data-edit-dest]', body ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () { openForm( destinations[ btn.getAttribute( 'data-edit-dest' ) ], btn.getAttribute( 'data-edit-dest' ) ); } );
			} );
			qsa( '[data-delete-dest]', body ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					if ( ! window.confirm( 'Delete this destination?' ) ) { return; }
					window.AvixAdmin.ajax( 'avix_storage_delete', { destination_id: btn.getAttribute( 'data-delete-dest' ) } ).then( loadDestinations );
				} );
			} );
		} );
	}

	function forEachManager( fn ) {
		qsa( '.avix-destinations-manager' ).forEach( fn );
	}

	function updateFieldVisibility( manager, provider ) {
		// Test each block against the selected provider directly, rather than
		// looping providers and assigning `hidden` per pass. Two blocks are
		// deliberately shared by two providers each (ftp+sftp, drive+dropbox
		// have near-identical fields), so a per-provider loop is last-write-
		// wins: the `sftp` pass would re-hide the shared block that the `ftp`
		// pass had just shown, leaving FTP and Drive with no fields at all.
		var selector = PROVIDERS.map( function ( p ) { return '.avix-dest-fields-' + p; } ).join( ',' );
		qsa( selector, manager ).forEach( function ( el ) {
			el.hidden = ! el.classList.contains( 'avix-dest-fields-' + provider );
		} );
		qsa( '.avix-dest-fields-sftp-only', manager ).forEach( function ( el ) { el.hidden = 'sftp' !== provider; } );
		qsa( '.avix-dest-fields-ftp-only', manager ).forEach( function ( el ) { el.hidden = 'ftp' !== provider; } );
		qsa( '.avix-dest-fields-drive-only', manager ).forEach( function ( el ) { el.hidden = 'drive' !== provider; } );
		qsa( '.avix-dest-fields-dropbox-only', manager ).forEach( function ( el ) { el.hidden = 'dropbox' !== provider; } );
	}

	function openForm( destination, id ) {
		forEachManager( function ( manager ) {
			var form = qs( '.avix-destination-form', manager );
			form.hidden = false;
			qs( '.avix-dest-id', form ).value = id || '';
			qs( '.avix-dest-name', form ).value = destination ? destination.name : '';
			var providerSelect = qs( '.avix-dest-provider', form );
			providerSelect.value = destination ? destination.provider : 's3';
			providerSelect.disabled = !! destination;
			updateFieldVisibility( manager, providerSelect.value );

			qsa( '[data-field]', form ).forEach( function ( input ) {
				var field = input.getAttribute( 'data-field' );
				if ( 'checkbox' === input.type ) {
					input.checked = destination ? !! destination[ field ] : false;
				} else {
					input.value = destination && destination[ field ] ? destination[ field ] : '';
				}
			} );

			// Secrets are stripped from all_public() — a Drive/Dropbox
			// destination that already exists implies it's connected,
			// since it can't have been saved any other way.
			qs( '.avix-dest-oauth-connected', form ).hidden = ! ( destination && ( 'drive' === destination.provider || 'dropbox' === destination.provider ) );
			qs( '.avix-dest-oauth-connect', form ).hidden = !! ( destination && ( 'drive' === destination.provider || 'dropbox' === destination.provider ) );
		} );
	}

	function closeForm() {
		forEachManager( function ( manager ) {
			qs( '.avix-destination-form', manager ).hidden = true;
		} );
	}

	function collectFields( form ) {
		var data = { provider: qs( '.avix-dest-provider', form ).value, name: qs( '.avix-dest-name', form ).value, destination_id: qs( '.avix-dest-id', form ).value };
		qsa( '[data-field]', form ).forEach( function ( input ) {
			var field = input.getAttribute( 'data-field' );
			data[ field ] = 'checkbox' === input.type ? ( input.checked ? '1' : '' ) : input.value;
		} );
		return data;
	}

	function wireManager( manager ) {
		var form = qs( '.avix-destination-form', manager );
		if ( ! form ) {
			// Don't let one missing container throw out of here: this runs
			// before wireToggles() and loadDestinations() in the same
			// DOMContentLoaded handler, so a throw took the whole panel down
			// with it rather than degrading just this piece.
			return;
		}

		var addBtn = qs( '.avix-destination-add', manager );
		if ( addBtn ) {
			addBtn.addEventListener( 'click', function () { openForm( null, null ); } );
		}

		qs( '.avix-dest-provider', form ).addEventListener( 'change', function ( e ) {
			updateFieldVisibility( manager, e.target.value );
		} );

		qs( '.avix-dest-cancel', form ).addEventListener( 'click', closeForm );

		qs( '.avix-dest-test', form ).addEventListener( 'click', function () {
			var payload = collectFields( form );
			window.AvixAdmin.ajax( 'avix_storage_test', payload )
				.then( function ( data ) {
					window.AvixAdmin.toast( data.message || ( data.success ? 'Connected.' : 'Failed.' ), data.success ? 'success' : 'error' );
				} )
				.catch( function ( err ) { window.AvixAdmin.toast( err.message || 'Test failed.', 'error' ); } );
		} );

		qs( '.avix-dest-oauth-connect', form ).addEventListener( 'click', function () {
			var provider = qs( '.avix-dest-provider', form ).value;
			var clientId = qs( '[data-field="client_id"]', form ).value;
			var clientSecret = qs( '[data-field="client_secret"]', form ).value;
			if ( ! clientId || ! clientSecret ) {
				window.AvixAdmin.toast( 'Enter the client ID and secret first.', 'error' );
				return;
			}
			window.AvixAdmin.ajax( 'avix_storage_oauth_url', { provider: provider, client_id: clientId, client_secret: clientSecret } )
				.then( function ( data ) { window.location.href = data.url; } )
				.catch( function ( err ) { window.AvixAdmin.toast( err.message || 'Could not start authorization.', 'error' ); } );
		} );

		// Click, not submit: the container is a div (see the partial), so
		// there is no submit event to listen for.
		qs( '.avix-dest-save', form ).addEventListener( 'click', function () {
			window.AvixAdmin.ajax( 'avix_storage_save', collectFields( form ) )
				.then( function () {
					window.AvixAdmin.toast( 'Destination saved.', 'success' );
					closeForm();
					loadDestinations();
				} )
				.catch( function ( err ) { window.AvixAdmin.toast( err.message || 'Could not save destination.', 'error' ); } );
		} );
	}

	function wireToggles() {
		qsa( '.avix-destinations-toggle' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				forEachManager( function ( manager ) { manager.hidden = ! manager.hidden; } );
			} );
		} );
	}

	function showOauthReturnNotice() {
		var params = new URLSearchParams( window.location.search );
		var notice = params.get( 'avix_oauth_notice' );
		var message = params.get( 'avix_oauth_message' );
		if ( notice && message ) {
			window.AvixAdmin.toast( message, 'error' === notice ? 'error' : 'success' );
			forEachManager( function ( manager ) { manager.hidden = false; } );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( ! document.querySelector( '.avix-destination-select' ) ) {
			return;
		}
		forEachManager( wireManager );
		wireToggles();
		loadDestinations();
		showOauthReturnNotice();
	} );
} )();
