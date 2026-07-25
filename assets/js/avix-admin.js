/**
 * Avix Migration — admin JS. Vanilla, no build step, no framework
 * dependency. Loaded only on the plugin's own wp-admin screens.
 *
 * Public surface hangs off window.AvixAdmin so per-screen inline scripts
 * (backup wizard, import wizard, etc., added in later milestones) can reuse
 * ajax(), toast(), confirmDestructive(), and pollJob() without redefining
 * them.
 */
( function () {
	'use strict';

	var cfg = window.AvixMigration || {};

	/**
	 * POSTs an action to admin-ajax.php with the plugin's nonce attached.
	 * Resolves with the `data` payload on success; rejects with an Error
	 * carrying the server's message on failure, or a distinguishable
	 * network-error on fetch failure so callers can retry instead of
	 * treating a dropped connection as a permanent failure.
	 *
	 * @param {string} action
	 * @param {Object} [data]
	 * @returns {Promise<any>}
	 */
	function ajax( action, data ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce || '' );
		Object.keys( data || {} ).forEach( function ( key ) {
			var value = data[ key ];
			body.set( key, typeof value === 'object' ? JSON.stringify( value ) : value );
		} );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
			.then( function ( res ) {
				return res.json().catch( function () {
					throw new Error( 'network' );
				} );
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					var message = json && json.data && json.data.message ? json.data.message : ( cfg.i18n && cfg.i18n.genericError );
					throw new Error( message || 'Request failed.' );
				}
				return json.data;
			} )
			.catch( function ( err ) {
				if ( err instanceof TypeError ) {
					// fetch() itself threw — genuine network failure, not an
					// application error the server reported.
					throw new Error( 'network' );
				}
				throw err;
			} );
	}

	// ---------------------------------------------------------------
	// Toasts
	// ---------------------------------------------------------------

	function toastContainer() {
		var el = document.querySelector( '.avix-toast-container' );
		if ( ! el ) {
			el = document.createElement( 'div' );
			el.className = 'avix-toast-container';
			el.setAttribute( 'aria-live', 'polite' );
			document.body.appendChild( el );
		}
		return el;
	}

	/**
	 * @param {string} message
	 * @param {'info'|'success'|'warning'|'error'} [type]
	 * @param {number} [duration] ms; 0 = sticky.
	 */
	function toast( message, type, duration ) {
		type = type || 'info';
		duration = duration === undefined ? 4500 : duration;

		var container = toastContainer();
		var node = document.createElement( 'div' );
		node.className = 'avix-toast is-' + type;
		node.textContent = message;
		container.appendChild( node );

		if ( duration > 0 ) {
			setTimeout( function () {
				node.remove();
			}, duration );
		}
		return node;
	}

	// ---------------------------------------------------------------
	// Destructive-action confirmation modal (typed confirmation)
	// ---------------------------------------------------------------

	/**
	 * @param {Object} opts
	 * @param {string} opts.title
	 * @param {string} opts.body
	 * @param {string} [opts.requiredWord] If set, the confirm button stays
	 *        disabled until the user types this word exactly.
	 * @param {Function} opts.onConfirm
	 */
	function confirmDestructive( opts ) {
		var overlay = document.createElement( 'div' );
		overlay.className = 'avix-modal-overlay';

		var requiredWord = opts.requiredWord || '';

		overlay.innerHTML =
			'<div class="avix-modal" role="dialog" aria-modal="true" aria-labelledby="avix-modal-title">' +
				'<h3 class="avix-modal__title" id="avix-modal-title"></h3>' +
				'<p class="avix-modal__body"></p>' +
				( requiredWord
					? '<div class="avix-field"><input type="text" class="avix-input" data-avix-confirm-input autocomplete="off" /></div>'
					: '' ) +
				'<div class="avix-modal__actions">' +
					'<button type="button" class="avix-btn" data-avix-cancel></button>' +
					'<button type="button" class="avix-btn avix-btn-danger" data-avix-confirm-btn></button>' +
				'</div>' +
			'</div>';

		overlay.querySelector( '.avix-modal__title' ).textContent = opts.title || '';
		overlay.querySelector( '.avix-modal__body' ).textContent = opts.body || '';
		overlay.querySelector( '[data-avix-cancel]' ).textContent = 'Cancel';
		var confirmBtn = overlay.querySelector( '[data-avix-confirm-btn]' );
		confirmBtn.textContent = 'Confirm';

		if ( requiredWord ) {
			confirmBtn.disabled = true;
			var input = overlay.querySelector( '[data-avix-confirm-input]' );
			input.placeholder = requiredWord;
			input.addEventListener( 'input', function () {
				confirmBtn.disabled = input.value !== requiredWord;
			} );
		}

		function close() {
			overlay.remove();
			document.removeEventListener( 'keydown', onKeydown );
		}
		function onKeydown( e ) {
			if ( e.key === 'Escape' ) {
				close();
			}
		}

		overlay.querySelector( '[data-avix-cancel]' ).addEventListener( 'click', close );
		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay ) {
				close();
			}
		} );
		confirmBtn.addEventListener( 'click', function () {
			close();
			if ( typeof opts.onConfirm === 'function' ) {
				opts.onConfirm();
			}
		} );

		document.addEventListener( 'keydown', onKeydown );
		document.body.appendChild( overlay );

		if ( requiredWord ) {
			overlay.querySelector( '[data-avix-confirm-input]' ).focus();
		} else {
			confirmBtn.focus();
		}
	}

	// ---------------------------------------------------------------
	// Job polling — drives Job_Runner one tick at a time via
	// avix_job_run_step until the job reaches a terminal status.
	// ---------------------------------------------------------------

	/**
	 * @param {string} jobId
	 * @param {Object} handlers
	 * @param {function(Object)} [handlers.onProgress] Called after every tick.
	 * @param {function(Object)} [handlers.onDone]      Called once, status === 'done'.
	 * @param {function(Object)} [handlers.onFailed]    Called once, status === 'failed'.
	 * @param {function(Object)} [handlers.onCancelled] Called once, status === 'cancelled'.
	 * @param {function()}       [handlers.onNetworkIssue] Called each time a tick fails to reach the server.
	 * @returns {{ stop: function() }} Call .stop() to end polling early (e.g. user navigated away).
	 */
	function pollJob( jobId, handlers ) {
		handlers = handlers || {};
		var stopped = false;
		var consecutiveFailures = 0;

		function tick() {
			if ( stopped ) {
				return;
			}
			ajax( 'avix_job_run_step', { job_id: jobId } )
				.then( function ( snapshot ) {
					consecutiveFailures = 0;
					if ( typeof handlers.onProgress === 'function' ) {
						handlers.onProgress( snapshot );
					}
					if ( snapshot.status === 'done' ) {
						handlers.onDone && handlers.onDone( snapshot );
						return;
					}
					if ( snapshot.status === 'failed' ) {
						handlers.onFailed && handlers.onFailed( snapshot );
						return;
					}
					if ( snapshot.status === 'cancelled' ) {
						handlers.onCancelled && handlers.onCancelled( snapshot );
						return;
					}
					schedule( 400 );
				} )
				.catch( function ( err ) {
					consecutiveFailures++;
					if ( typeof handlers.onNetworkIssue === 'function' ) {
						handlers.onNetworkIssue( err, consecutiveFailures );
					}
					// Back off up to 10s rather than hammering a server
					// that's temporarily unreachable (e.g. mid-deploy).
					schedule( Math.min( 10000, 1000 * Math.pow( 1.6, consecutiveFailures ) ) );
				} );
		}

		function schedule( delay ) {
			if ( ! stopped ) {
				setTimeout( tick, delay );
			}
		}

		tick();

		return {
			stop: function () {
				stopped = true;
			},
		};
	}

	// ---------------------------------------------------------------
	// Small DOM helpers
	// ---------------------------------------------------------------

	function qs( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
	}
	function qsa( sel, ctx ) {
		return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( sel ) );
	}

	// ---------------------------------------------------------------
	// Generic wire-up: any element with data-avix-confirm="Title|Body"
	// and optional data-avix-confirm-word opens the confirmation modal
	// before firing its own data-avix-confirm-action AJAX call.
	// ---------------------------------------------------------------

	document.addEventListener( 'DOMContentLoaded', function () {
		qsa( '[data-avix-confirm-action]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var action = btn.getAttribute( 'data-avix-confirm-action' );
				var word = btn.getAttribute( 'data-avix-confirm-word' ) || '';
				confirmDestructive( {
					title: btn.getAttribute( 'data-avix-confirm-title' ) || 'Are you sure?',
					body: btn.getAttribute( 'data-avix-confirm-body' ) || 'This cannot be undone.',
					requiredWord: word,
					onConfirm: function () {
						var extra = {};
						try {
							extra = JSON.parse( btn.getAttribute( 'data-avix-confirm-payload' ) || '{}' );
						} catch ( e2 ) {
							extra = {};
						}
						ajax( action, extra )
							.then( function () {
								toast( 'Done.', 'success' );
								if ( btn.getAttribute( 'data-avix-reload-on-success' ) ) {
									window.location.reload();
								}
							} )
							.catch( function ( err ) {
								toast( err.message || ( cfg.i18n && cfg.i18n.genericError ), 'error' );
							} );
					},
				} );
			} );
		} );
	} );

	window.AvixAdmin = {
		ajax: ajax,
		toast: toast,
		confirmDestructive: confirmDestructive,
		pollJob: pollJob,
		qs: qs,
		qsa: qsa,
	};
} )();
