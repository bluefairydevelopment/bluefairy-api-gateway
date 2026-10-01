/**
 * BlueFairy API Gateway — admin screen behaviour. No jQuery.
 *
 * Reads window.bfagAdmin = { ajaxUrl, nonces: { purge, refresh, retry, toggle }, i18n: {...} }.
 * Every control degrades: links keep their nonce URLs, buttons are no-ops without the object.
 */
( function () {
	'use strict';

	var cfg = window.bfagAdmin;
	if ( ! cfg || ! cfg.ajaxUrl ) {
		return;
	}
	var i18n = cfg.i18n || {};

	/**
	 * POST an admin-ajax action. Resolves with the parsed JSON envelope.
	 *
	 * @param {string} action admin-ajax action name.
	 * @param {string} nonce  The nonce for that action.
	 * @param {Object} fields Extra fields; array values become key[].
	 * @return {Promise<Object>} The JSON envelope { success, data }.
	 */
	function post( action, nonce, fields ) {
		var body = new window.FormData();
		body.append( 'action', action );
		body.append( 'nonce', nonce );
		Object.keys( fields || {} ).forEach( function ( key ) {
			var value = fields[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( function ( item ) {
					body.append( key + '[]', item );
				} );
			} else {
				body.append( key, value );
			}
		} );
		return window.fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {
			return response.json();
		} );
	}

	/**
	 * Show a transient message in the nearest status slot.
	 *
	 * @param {Element} near    An element inside the section whose status slot to use.
	 * @param {string}  text    Message.
	 * @param {boolean} isError Style as an error.
	 */
	function status( near, text, isError ) {
		var slot = null;
		var scope = near;
		while ( scope && ! slot ) {
			slot = scope.querySelector ? scope.querySelector( '[data-bfag-status]' ) : null;
			scope = scope.parentElement;
		}
		if ( ! slot ) {
			slot = document.querySelector( '[data-bfag-status]' );
		}
		if ( ! slot ) {
			return;
		}
		slot.textContent = text;
		slot.classList.toggle( 'is-error', !! isError );
		if ( ! isError ) {
			window.clearTimeout( slot.bfagTimer );
			slot.bfagTimer = window.setTimeout( function () {
				slot.textContent = '';
			}, 4000 );
		}
	}

	/**
	 * Format a KPI value for its card.
	 *
	 * @param {*}      value  Raw value from the server.
	 * @param {string} format 'rate' for a 0–1 fraction, anything else for an integer.
	 * @return {string} Display text.
	 */
	function format( value, format ) {
		if ( value === null || value === undefined ) {
			return i18n.cold || '—';
		}
		if ( format === 'rate' ) {
			return ( Number( value ) * 100 ).toFixed( 1 ) + '%';
		}
		return Number( value ).toLocaleString();
	}

	// Confirm before destructive links.
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest( '[data-bfag-confirm]' );
		if ( link && ! window.confirm( i18n.confirmDelete || 'Delete?' ) ) {
			event.preventDefault();
		}
	} );

	// Purge all (AJAX; the href is the non-JS fallback).
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-bfag-purge]' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		if ( ! window.confirm( i18n.confirmPurge || 'Purge?' ) ) {
			return;
		}
		var tags = ( button.getAttribute( 'data-bfag-purge' ) || '' ).split( ',' ).map( function ( t ) {
			return t.trim();
		} ).filter( Boolean );
		status( button, i18n.working || '…', false );
		post( 'bfag_ajax_purge_cache', cfg.nonces.purge, tags.length ? { tags: tags } : {} ).then( function ( json ) {
			status( button, json.success ? ( i18n.purged || 'Purged.' ) : ( i18n.error || 'Error' ), ! json.success );
		} ).catch( function () {
			status( button, i18n.error || 'Error', true );
		} );
	} );

	// Refresh KPIs.
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-bfag-refresh]' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		button.disabled = true;
		status( button, i18n.working || '…', false );
		post( 'bfag_ajax_refresh_kpis', cfg.nonces.refresh, {} ).then( function ( json ) {
			button.disabled = false;
			if ( ! json.success || ! json.data ) {
				status( button, i18n.error || 'Error', true );
				return;
			}
			document.querySelectorAll( '[data-bfag-kpi]' ).forEach( function ( el ) {
				var key = el.getAttribute( 'data-bfag-kpi' );
				if ( Object.prototype.hasOwnProperty.call( json.data, key ) ) {
					el.textContent = format( json.data[ key ], el.getAttribute( 'data-bfag-format' ) );
				}
			} );
			status( button, i18n.refreshed || 'Updated.', false );
		} ).catch( function () {
			button.disabled = false;
			status( button, i18n.error || 'Error', true );
		} );
	} );

	// Retry a delivery.
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-bfag-retry]' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		button.disabled = true;
		status( button, i18n.working || '…', false );
		post( 'bfag_ajax_retry_webhook', cfg.nonces.retry, { delivery_id: button.getAttribute( 'data-bfag-retry' ) } ).then( function ( json ) {
			button.disabled = false;
			var row = button.closest( 'tr' );
			var badge = row ? row.querySelector( '[data-bfag-delivery-status]' ) : null;
			if ( badge ) {
				badge.textContent = json.success ? 'success' : 'failed';
				badge.className = 'bfag-badge bfag-badge--' + ( json.success ? 'success' : 'failed' );
			}
			status( button, json.success ? ( i18n.retried || 'Delivered.' ) : ( i18n.retryFailed || 'Failed.' ), ! json.success );
		} ).catch( function () {
			button.disabled = false;
			status( button, i18n.error || 'Error', true );
		} );
	} );

	// Toggle a rule / webhook (the badge link's href is the non-JS fallback).
	document.addEventListener( 'click', function ( event ) {
		var badge = event.target.closest( '[data-bfag-toggle]' );
		if ( ! badge ) {
			return;
		}
		event.preventDefault();
		var enabled = badge.getAttribute( 'data-bfag-enabled' ) === '1' ? '0' : '1';
		badge.classList.add( 'is-busy' );
		post( 'bfag_ajax_toggle_rule', cfg.nonces.toggle, {
			kind: badge.getAttribute( 'data-bfag-toggle' ),
			id: badge.getAttribute( 'data-bfag-id' ),
			enabled: enabled
		} ).then( function ( json ) {
			badge.classList.remove( 'is-busy' );
			if ( ! json.success ) {
				status( badge, i18n.error || 'Error', true );
				return;
			}
			badge.setAttribute( 'data-bfag-enabled', enabled );
			badge.textContent = enabled === '1' ? ( i18n.enabled || 'Enabled' ) : ( i18n.disabled || 'Disabled' );
			badge.className = 'bfag-badge ' + ( enabled === '1' ? 'bfag-badge--success' : 'bfag-badge--muted' );
			// Keep the fallback href pointing at the opposite state.
			badge.href = badge.href.replace( /([?&])enabled=\d/, '$1enabled=' + ( enabled === '1' ? '0' : '1' ) );
		} ).catch( function () {
			badge.classList.remove( 'is-busy' );
			status( badge, i18n.error || 'Error', true );
		} );
	} );

	// Copy to clipboard.
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-bfag-copy]' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		var text = button.getAttribute( 'data-bfag-copy' ) || '';
		var done = function () {
			status( button, i18n.copied || 'Copied.', false );
		};
		if ( window.navigator.clipboard && window.navigator.clipboard.writeText ) {
			window.navigator.clipboard.writeText( text ).then( done, function () {
				status( button, i18n.error || 'Error', true );
			} );
			return;
		}
		var target = button.parentElement ? button.parentElement.querySelector( '[data-bfag-copy-target]' ) : null;
		if ( target && window.getSelection ) {
			var range = document.createRange();
			range.selectNodeContents( target );
			var selection = window.getSelection();
			selection.removeAllRanges();
			selection.addRange( range );
			try {
				document.execCommand( 'copy' );
				done();
			} catch ( e ) {
				status( button, i18n.error || 'Error', true );
			}
		}
	} );

	// Show the preset-config block for the selected preset.
	function syncPreset( select ) {
		var slug = select.value;
		document.querySelectorAll( '[data-bfag-preset-config]' ).forEach( function ( block ) {
			block.classList.toggle( 'is-active', block.getAttribute( 'data-bfag-preset-config' ) === slug );
		} );
	}
	document.querySelectorAll( '[data-bfag-preset-select]' ).forEach( function ( select ) {
		syncPreset( select );
		select.addEventListener( 'change', function () {
			syncPreset( select );
		} );
	} );

	// Remember a dismissed notice ([data-bfag-dismiss]) for this user. WordPress
	// adds the × button after DOMContentLoaded, so the listener is delegated.
	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '.notice-dismiss' ) : null;
		var notice = button ? button.closest( '[data-bfag-dismiss]' ) : null;
		if ( ! notice || ! cfg.nonces || ! cfg.nonces.dismiss ) {
			return;
		}
		post( 'bfag_ajax_dismiss_notice', cfg.nonces.dismiss, { notice: notice.getAttribute( 'data-bfag-dismiss' ) } ).catch( function () {} );
	} );
}() );
