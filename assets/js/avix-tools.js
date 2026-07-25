/**
 * Tools screen: the database check. Renders a plain-language verdict plus
 * the raw report, so the underlying MySQL error is visible rather than
 * hidden behind wp-admin's generic "Could not insert..." message.
 */
( function () {
	'use strict';

	function verdictFor( report ) {
		var missing = [];
		var t = report.tables || {};

		Object.keys( t ).forEach( function ( key ) {
			if ( ! t[ key ].exists ) {
				missing.push( t[ key ].table );
			}
		} );

		if ( missing.length ) {
			return {
				tone: 'danger',
				text: 'Missing tables: ' + missing.join( ', ' ) +
					'. An import did not finish. Restore the oldest rollback snapshot below.',
			};
		}

		if ( ! report.insert_ok ) {
			return {
				tone: 'danger',
				text: 'The test insert failed. Database said: ' + ( report.insert_error || '(no error reported)' ),
			};
		}

		// AUTO_INCREMENT at or below the current max id means the next real
		// insert collides on the primary key.
		var posts = t.posts || {};
		if ( posts.auto_increment !== null && posts.max_id !== null && posts.auto_increment <= posts.max_id ) {
			return {
				tone: 'warning',
				text: 'wp_posts AUTO_INCREMENT (' + posts.auto_increment + ') is not above its largest id (' +
					posts.max_id + '). New posts and uploads will collide on the primary key.',
			};
		}

		return { tone: 'success', text: 'Insert succeeded — the database accepts new posts and attachments normally.' };
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var btn = document.getElementById( 'avix-run-db-probe' );
		if ( ! btn ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			btn.textContent = 'Running…';

			window.AvixAdmin.ajax( 'avix_db_probe', {} )
				.then( function ( report ) {
					var wrap = document.getElementById( 'avix-db-probe-result' );
					var verdictEl = document.getElementById( 'avix-db-probe-verdict' );
					var v = verdictFor( report );

					wrap.hidden = false;
					verdictEl.className = 'avix-badge avix-badge-' + v.tone;
					verdictEl.style.display = 'block';
					verdictEl.style.padding = '10px 12px';
					verdictEl.textContent = v.text;

					document.getElementById( 'avix-db-probe-raw' ).value = JSON.stringify( report, null, 2 );
				} )
				.catch( function ( err ) {
					window.AvixAdmin.toast( err.message || 'Could not run the check.', 'error' );
				} )
				.then( function () {
					btn.disabled = false;
					btn.textContent = 'Run check';
				} );
		} );
	} );
} )();
