/**
 * Content Export: paginated/filterable post picker with selection state
 * that survives across pages and filter changes, dependency preview, then
 * the export start/poll flow. Depends on window.AvixAdmin.
 */
( function () {
	'use strict';

	var qs = window.AvixAdmin.qs;
	var qsa = window.AvixAdmin.qsa;
	var cfg = window.AvixMigration || {};

	var selected = new Set();
	var currentPage = 1;

	function escapeHtml( s ) {
		var div = document.createElement( 'div' );
		div.textContent = String( s == null ? '' : s );
		return div.innerHTML;
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

	function showPanel( id ) {
		[ 'avix-content-picker', 'avix-content-preview', 'avix-content-progress', 'avix-content-done', 'avix-content-failed' ].forEach( function ( panelId ) {
			var el = document.getElementById( panelId );
			if ( el ) {
				el.hidden = panelId !== id;
			}
		} );
	}

	function updateSelectedCount() {
		document.getElementById( 'avix-content-selected-count' ).textContent = selected.size
			? selected.size + ' selected'
			: '';
		document.getElementById( 'avix-content-preview-btn' ).disabled = selected.size === 0;
	}

	function loadPosts( page ) {
		currentPage = page || 1;
		var body = document.getElementById( 'avix-content-rows' );
		body.innerHTML = '<tr><td colspan="5" class="avix-text-muted">Loading…</td></tr>';

		window.AvixAdmin.ajax( 'avix_content_list_posts', {
			search: document.getElementById( 'avix-content-search' ).value,
			post_type: document.getElementById( 'avix-content-type-filter' ).value,
			post_status: document.getElementById( 'avix-content-status-filter' ).value,
			page: currentPage,
		} )
			.then( function ( data ) {
				renderRows( data.items );
				renderPagination( data.total_pages );
			} )
			.catch( function ( err ) {
				body.innerHTML = '<tr><td colspan="5">' + escapeHtml( err.message || 'Could not load posts.' ) + '</td></tr>';
			} );
	}

	function renderRows( items ) {
		var body = document.getElementById( 'avix-content-rows' );
		if ( ! items.length ) {
			body.innerHTML = '<tr><td colspan="5" class="avix-text-muted">No posts match.</td></tr>';
			return;
		}
		body.innerHTML = '';
		items.forEach( function ( item ) {
			var tr = document.createElement( 'tr' );
			var checked = selected.has( item.ID ) ? 'checked' : '';
			tr.innerHTML =
				'<td><input type="checkbox" data-row-check value="' + item.ID + '" ' + checked + '></td>' +
				'<td>' + escapeHtml( item.title ) + '</td>' +
				'<td><span class="avix-badge avix-badge-neutral">' + escapeHtml( item.post_type ) + '</span></td>' +
				'<td>' + escapeHtml( item.status ) + '</td>' +
				'<td class="avix-text-muted">' + escapeHtml( item.date ) + '</td>';
			body.appendChild( tr );
		} );

		qsa( '[data-row-check]', body ).forEach( function ( cb ) {
			cb.addEventListener( 'change', function () {
				var id = parseInt( cb.value, 10 );
				if ( cb.checked ) {
					selected.add( id );
				} else {
					selected.delete( id );
				}
				updateSelectedCount();
			} );
		} );
	}

	function renderPagination( totalPages ) {
		var el = document.getElementById( 'avix-content-pagination' );
		if ( totalPages <= 1 ) {
			el.innerHTML = '';
			return;
		}
		el.innerHTML = '';
		for ( var p = 1; p <= totalPages; p++ ) {
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'avix-btn avix-btn-sm' + ( p === currentPage ? ' avix-btn-primary' : '' );
			btn.style.marginRight = '4px';
			btn.textContent = String( p );
			btn.addEventListener( 'click', function ( pageNum ) {
				return function () { loadPosts( pageNum ); };
			}( p ) );
			el.appendChild( btn );
		}
	}

	function wireFilters() {
		var debounce;
		document.getElementById( 'avix-content-search' ).addEventListener( 'input', function () {
			clearTimeout( debounce );
			debounce = setTimeout( function () { loadPosts( 1 ); }, 350 );
		} );
		document.getElementById( 'avix-content-type-filter' ).addEventListener( 'change', function () { loadPosts( 1 ); } );
		document.getElementById( 'avix-content-status-filter' ).addEventListener( 'change', function () { loadPosts( 1 ); } );

		document.getElementById( 'avix-content-select-all' ).addEventListener( 'change', function ( e ) {
			qsa( '[data-row-check]' ).forEach( function ( cb ) {
				cb.checked = e.target.checked;
				var id = parseInt( cb.value, 10 );
				if ( e.target.checked ) {
					selected.add( id );
				} else {
					selected.delete( id );
				}
			} );
			updateSelectedCount();
		} );
	}

	function wirePreview() {
		document.getElementById( 'avix-content-preview-btn' ).addEventListener( 'click', function () {
			window.AvixAdmin.ajax( 'avix_content_preview', { post_ids: Array.from( selected ).join( ',' ) } )
				.then( function ( data ) {
					qs( '[data-preview-summary]' ).textContent =
						data.post_count + ' posts, ' + data.attachment_count + ' media files' +
						( data.template_count ? ', ' + data.template_count + ' referenced templates' : '' ) +
						' — approximately ' + humanSize( data.estimated_bytes );

					var warningsEl = qs( '[data-preview-warnings]' );
					warningsEl.innerHTML = '';
					( data.warnings || [] ).forEach( function ( w ) {
						var div = document.createElement( 'div' );
						div.className = 'avix-badge avix-badge-warning';
						div.style.display = 'block';
						div.style.marginBottom = '6px';
						div.style.marginTop = '8px';
						div.textContent = w;
						warningsEl.appendChild( div );
					} );

					showPanel( 'avix-content-preview' );
				} )
				.catch( function ( err ) {
					window.AvixAdmin.toast( err.message || 'Could not build a preview.', 'error' );
				} );
		} );

		document.getElementById( 'avix-preview-back' ).addEventListener( 'click', function () {
			showPanel( 'avix-content-picker' );
		} );

		document.getElementById( 'avix-content-start' ).addEventListener( 'click', function ( e ) {
			e.target.disabled = true;
			var destSelect = document.getElementById( 'avix-destination-select-content' );
			window.AvixAdmin.ajax( 'avix_content_start_export', {
				post_ids: Array.from( selected ).join( ',' ),
				destination_id: destSelect ? destSelect.value : 'local',
			} )
				.then( function ( data ) {
					startPolling( data.job_id );
				} )
				.catch( function ( err ) {
					e.target.disabled = false;
					window.AvixAdmin.toast( err.message || 'Could not start the export.', 'error' );
				} );
		} );
	}

	function downloadUrl( filename ) {
		return cfg.adminPostUrl + '?action=avix_download_archive&file=' + encodeURIComponent( filename ) + '&_wpnonce=' + encodeURIComponent( cfg.downloadNonce );
	}

	function startPolling( jobId ) {
		showPanel( 'avix-content-progress' );

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
			onDone: function ( snapshot ) {
				showPanel( 'avix-content-done' );
				qs( '[data-done-summary]' ).textContent = snapshot.archive_filename || 'Export complete.';
				var link = document.querySelector( '[data-done-download]' );
				if ( snapshot.archive_filename ) {
					link.href = downloadUrl( snapshot.archive_filename );
				}
			},
			onFailed: function ( snapshot ) {
				showPanel( 'avix-content-failed' );
				qs( '[data-failed-message]' ).textContent = snapshot.error || 'Unknown error.';
			},
			onNetworkIssue: function () {
				heartbeat.classList.add( 'is-stalled' );
			},
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( ! document.getElementById( 'avix-content-app' ) ) {
			return;
		}
		wireFilters();
		wirePreview();
		updateSelectedCount();
		loadPosts( 1 );
	} );
} )();
