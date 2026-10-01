/**
 * Runs assets/js/admin.js against a hand-rolled fake DOM. No packages.
 *
 *   node tests/js/admin.test.js
 *
 * Covers the dashboard Refresh (N4: the "Not computed yet" line is replaced
 * once the server answers), the dismissible-notice handler, and the
 * rate-limit Penalty row that follows the "Count by" select (U-08).
 *
 * @package BlueFairy\ApiGateway
 */

'use strict';

const assert = require( 'assert' );
const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

const SCRIPT = path.join( __dirname, '..', '..', 'assets', 'js', 'admin.js' );

// ------------------------------------------------------------------
// Fake DOM: a tree of elements with attributes, classes, parents and
// descendants; selectors cover tag, .class and [attr="value"] parts.
// ------------------------------------------------------------------

function element( tag, attrs, children ) {
	const listeners = {};
	const classes = new Set( ( ( attrs || {} ).class || '' ).split( ' ' ).filter( Boolean ) );
	const el = {
		tag: tag,
		attrs: Object.assign( {}, attrs ),
		hidden: false,
		disabled: false,
		value: '',
		textContent: '',
		parentElement: null,
		children: [],
		classList: {
			add: ( c ) => { classes.add( c ); },
			remove: ( c ) => { classes.delete( c ); },
			contains: ( c ) => classes.has( c ),
			toggle: ( c, force ) => {
				const on = force === undefined ? ! classes.has( c ) : Boolean( force );
				if ( on ) {
					classes.add( c );
				} else {
					classes.delete( c );
				}
				return on;
			},
		},
		getAttribute: ( name ) => ( Object.prototype.hasOwnProperty.call( el.attrs, name ) ? el.attrs[ name ] : null ),
		setAttribute: ( name, value ) => { el.attrs[ name ] = String( value ); },
		addEventListener: ( type, fn ) => { ( listeners[ type ] = listeners[ type ] || [] ).push( fn ); },
		dispatch: ( type ) => { ( listeners[ type ] || [] ).forEach( ( fn ) => fn( { preventDefault() {} } ) ); },
		closest: ( selector ) => {
			let node = el;
			while ( node ) {
				if ( matches( node, selector ) ) {
					return node;
				}
				node = node.parentElement;
			}
			return null;
		},
		querySelector: ( selector ) => descendants( el ).find( ( d ) => matches( d, selector ) ) || null,
		querySelectorAll: ( selector ) => descendants( el ).filter( ( d ) => matches( d, selector ) ),
	};
	( children || [] ).forEach( ( child ) => {
		child.parentElement = el;
		el.children.push( child );
	} );
	return el;
}

function descendants( el ) {
	const out = [];
	el.children.forEach( ( child ) => {
		out.push( child );
		out.push( ...descendants( child ) );
	} );
	return out;
}

const ATTR_PART = /\[[^=\]]+(?:="[^"]*")?\]/g;
function matches( el, selector ) {
	return selector.split( ',' ).some( ( part ) => {
		const m = /^\s*([a-z]*)((?:\.[A-Za-z0-9_-]+)*)((?:\[[^=\]]+(?:="[^"]*")?\])*)\s*$/.exec( part );
		if ( ! m ) {
			throw new Error( 'fake DOM cannot parse selector: ' + part );
		}
		if ( m[ 1 ] && m[ 1 ] !== el.tag ) {
			return false;
		}
		const classes = m[ 2 ].split( '.' ).filter( Boolean );
		if ( ! classes.every( ( c ) => el.classList.contains( c ) ) ) {
			return false;
		}
		const attrs = m[ 3 ].match( ATTR_PART ) || [];
		return attrs.every( ( a ) => {
			const am = /^\[([^=\]]+)(?:="([^"]*)")?\]$/.exec( a );
			const have = el.getAttribute( am[ 1 ] );
			return am[ 2 ] === undefined ? have !== null : have === am[ 2 ];
		} );
	} );
}

function fakeDocument( root ) {
	const listeners = {};
	return {
		readyState: 'complete',
		querySelector: ( sel ) => root.querySelector( sel ),
		querySelectorAll: ( sel ) => root.querySelectorAll( sel ),
		addEventListener: ( type, fn ) => { ( listeners[ type ] = listeners[ type ] || [] ).push( fn ); },
		click: ( target ) => {
			( listeners.click || [] ).forEach( ( fn ) => fn( { target: target, preventDefault() {} } ) );
		},
	};
}

/**
 * A fetch() that records every POST and answers with the given envelope.
 *
 * @param {Object} envelope The JSON the server answers with.
 * @return {Function} The fetch stand-in, with a `calls` array.
 */
function fakeFetch( envelope ) {
	const calls = [];
	const fetch = ( url, init ) => {
		calls.push( { url: url, fields: init.body.fields } );
		return Promise.resolve( { json: () => Promise.resolve( envelope ) } );
	};
	fetch.calls = calls;
	return fetch;
}

function FakeFormData() {
	this.fields = {};
}
FakeFormData.prototype.append = function ( key, value ) {
	this.fields[ key ] = value;
};

function load( root, options ) {
	const doc = fakeDocument( root );
	globalThis.window = globalThis;
	globalThis.document = doc;
	globalThis.FormData = FakeFormData;
	globalThis.fetch = ( options && options.fetch ) || fakeFetch( { success: true, data: {} } );
	globalThis.bfagAdmin = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		nonces: Object.assign( { purge: 'n-purge', refresh: 'n-refresh', retry: 'n-retry', toggle: 'n-toggle', dismiss: 'n-dismiss' }, ( options && options.nonces ) || {} ),
		i18n: { updatedNow: 'Updated just now', refreshed: 'Updated.', working: 'Working…' },
	};
	vm.runInThisContext( fs.readFileSync( SCRIPT, 'utf8' ), { filename: SCRIPT } );
	return doc;
}

function flush() {
	return new Promise( ( resolve ) => setImmediate( resolve ) );
}

// ------------------------------------------------------------------
// Cases.
// ------------------------------------------------------------------

const cases = [];
function it( name, fn ) {
	cases.push( { name, fn } );
}

function dashboard() {
	const value = element( 'span', { 'data-bfag-kpi': 'cache_hit_rate', 'data-bfag-format': 'rate' } );
	value.textContent = '—';
	const hits = element( 'span', { 'data-bfag-kpi': 'cache_hits' } );
	hits.textContent = '—';
	const updated = element( 'span', { class: 'bfag-kpi-updated' } );
	updated.textContent = 'Not computed yet — the figures fill in after the first five-minute cron run, or press Refresh.';
	const status = element( 'span', { 'data-bfag-status': '' } );
	const button = element( 'button', { 'data-bfag-refresh': '' } );
	const root = element( 'div', {}, [
		element( 'div', { 'data-bfag-kpis': '' }, [ value, hits ] ),
		element( 'p', { class: 'bfag-kpi-actions' }, [ button, updated, status ] ),
	] );
	return { root, value, hits, updated, status, button };
}

it( 'Refresh replaces the "Not computed yet" line and fills the cards (N4)', async () => {
	const d = dashboard();
	const fetch = fakeFetch( { success: true, data: { cache_hit_rate: 0.756, cache_hits: 1234, computed_at: 1790812800 } } );
	const doc = load( d.root, { fetch: fetch } );

	doc.click( d.button );
	await flush();

	assert.strictEqual( fetch.calls.length, 1 );
	assert.strictEqual( fetch.calls[ 0 ].fields.action, 'bfag_ajax_refresh_kpis' );
	assert.strictEqual( fetch.calls[ 0 ].fields.nonce, 'n-refresh' );
	assert.strictEqual( d.updated.textContent, 'Updated just now' );
	assert.strictEqual( d.value.textContent, '75.6%' );
	assert.strictEqual( d.hits.textContent, '1,234' );
	assert.strictEqual( d.button.disabled, false );
	assert.strictEqual( d.status.textContent, 'Updated.' );
} );

it( 'without computed_at in the answer the updated line is left alone', async () => {
	const d = dashboard();
	const doc = load( d.root, { fetch: fakeFetch( { success: true, data: { cache_hits: 5 } } ) } );
	doc.click( d.button );
	await flush();
	assert.strictEqual( d.hits.textContent, '5' );
	assert.ok( d.updated.textContent.startsWith( 'Not computed yet' ), 'unchanged: ' + d.updated.textContent );
} );

it( 'a failed refresh leaves the line and shows the error', async () => {
	const d = dashboard();
	const doc = load( d.root, { fetch: fakeFetch( { success: false } ) } );
	doc.click( d.button );
	await flush();
	assert.ok( d.updated.textContent.startsWith( 'Not computed yet' ) );
	assert.ok( d.status.classList.contains( 'is-error' ) );
} );

it( 'dismissing a [data-bfag-dismiss] notice posts the dismissal with its nonce', async () => {
	const x = element( 'button', { class: 'notice-dismiss' } );
	const notice = element( 'div', { class: 'notice bfag-proxy-notice', 'data-bfag-dismiss': 'proxy' }, [ x ] );
	const other = element( 'button', { class: 'notice-dismiss' } );
	const plain = element( 'div', { class: 'notice' }, [ other ] );
	const fetch = fakeFetch( { success: true, data: { dismissed: 'proxy' } } );
	const doc = load( element( 'div', {}, [ notice, plain ] ), { fetch: fetch } );

	doc.click( x );
	await flush();
	assert.strictEqual( fetch.calls.length, 1 );
	assert.deepStrictEqual( fetch.calls[ 0 ].fields, { action: 'bfag_ajax_dismiss_notice', nonce: 'n-dismiss', notice: 'proxy' } );

	doc.click( other );
	await flush();
	assert.strictEqual( fetch.calls.length, 1, 'a notice that is not ours posts nothing' );
} );

it( 'without a dismiss nonce nothing is posted', async () => {
	const x = element( 'button', { class: 'notice-dismiss' } );
	const notice = element( 'div', { 'data-bfag-dismiss': 'proxy' }, [ x ] );
	const fetch = fakeFetch( { success: true } );
	const doc = load( element( 'div', {}, [ notice ] ), { fetch: fetch, nonces: { dismiss: undefined } } );
	doc.click( x );
	await flush();
	assert.strictEqual( fetch.calls.length, 0 );
} );

it( 'the Penalty row follows the "Count by" select: IP shows and enables it, anything else hides and disables it (U-08)', () => {
	const select = element( 'select', { name: 'bfag[subject_type]' } );
	select.value = 'user';
	const input = element( 'input', { name: 'bfag[penalty_seconds]' } );
	const row = element( 'tr', { 'data-bfag-penalty-row': '' }, [ element( 'td', {}, [ input ] ) ] );
	load( element( 'div', {}, [ select, row ] ) );

	assert.strictEqual( row.hidden, true, 'on load, a user rule hides the row' );
	assert.strictEqual( input.disabled, true, 'and disables the field so it is not posted' );

	select.value = 'ip';
	select.dispatch( 'change' );
	assert.strictEqual( row.hidden, false );
	assert.strictEqual( input.disabled, false );

	select.value = 'apikey';
	select.dispatch( 'change' );
	assert.strictEqual( row.hidden, true );
	assert.strictEqual( input.disabled, true );
} );

it( 'the fake DOM selector matcher can tell elements apart (self-check)', () => {
	const a = element( 'span', { class: 'bfag-kpi-updated' } );
	const b = element( 'button', { class: 'notice-dismiss' } );
	const c = element( 'select', { name: 'bfag[subject_type]' } );
	assert.strictEqual( matches( a, '.bfag-kpi-updated' ), true );
	assert.strictEqual( matches( b, '.bfag-kpi-updated' ), false );
	assert.strictEqual( matches( b, '.notice-dismiss' ), true );
	assert.strictEqual( matches( c, 'select[name="bfag[subject_type]"]' ), true );
	assert.strictEqual( matches( a, 'select[name="bfag[subject_type]"]' ), false );
	const inner = element( 'i', {} );
	const outer = element( 'div', { 'data-bfag-dismiss': 'x' }, [ inner ] );
	assert.strictEqual( inner.closest( '[data-bfag-dismiss]' ), outer );
	assert.strictEqual( inner.closest( '[data-bfag-refresh]' ), null );
} );

( async () => {
	let failed = 0;
	for ( const c of cases ) {
		try {
			await c.fn();
			process.stdout.write( 'ok   - ' + c.name + '\n' );
		} catch ( error ) {
			failed += 1;
			process.stdout.write( 'FAIL - ' + c.name + '\n' + ( error && error.stack ? error.stack : String( error ) ) + '\n' );
		}
	}
	process.stdout.write( ( cases.length - failed ) + '/' + cases.length + ' passed\n' );
	process.exit( failed === 0 ? 0 : 1 );
} )();
