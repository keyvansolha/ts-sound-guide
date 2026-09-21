/* JS unit tests: conditional questions, budget parsing, state transitions,
 * REST behavior, and analytics absence. Plain node, no framework. */

import { questions } from '../../assets/js/questions.js';
import { parseDigits, request } from '../../assets/js/rest.js';
import { readFileSync } from 'node:fs';

let pass = 0;
let fail = 0;
const check = ( name, cond ) => {
	if ( cond ) { pass++; console.log( `ok  ${name}` ); } else { fail++; console.log( `FAIL ${name}` ); }
};

/* conditional question construction */
const base = questions( { flow: 'earbuds' } );
check( 'earbud base sequence', [ 'use', 'pain', 'connection', 'fit', 'budget' ].every( ( k, i ) => base[ i ].key === k ) );
const usbc = questions( { flow: 'earbuds', connection: 'usbc' } );
check( 'usbc adds device question after connection', usbc[ 3 ].key === 'device' && usbc[ 2 ].key === 'connection' );
const work = questions( { flow: 'earbuds', use: 'work' } );
check( 'work adds calls question', work.some( ( q ) => q.key === 'calls' ) );
const callsPain = questions( { flow: 'earbuds', pain: 'calls' } );
check( 'calls pain adds calls question', callsPain.some( ( q ) => q.key === 'calls' ) );
const phones = questions( { flow: 'headphones', pain: 'fit' } );
check( 'headphone fit-pain adds comfort question', phones.some( ( q ) => q.key === 'fit' && q.options.some( ( o ) => o[ 0 ] === 'glasses' ) ) );
const phonesNoFit = questions( { flow: 'headphones' } );
check( 'headphones without fit pain skip fit', ! phonesNoFit.some( ( q ) => q.key === 'fit' ) );
check( 'headphone connection options are wireless/aux only', questions( { flow: 'headphones' } )[ 2 ].options.every( ( o ) => [ 'wireless', 'aux' ].includes( o[ 0 ] ) ) );

/* budget parsing (Persian and Arabic digits, separators) */
check( 'parse Persian digits', parseDigits( '۶۰۰۰۰۰۰' ) === 6000000 );
check( 'parse Arabic digits', parseDigits( '٦٠٠٠٠٠٠' ) === 6000000 );
check( 'parse Latin with commas', parseDigits( '6,000,000' ) === 6000000 );
check( 'parse Persian thousands separator', parseDigits( '۶٬۰۰۰٬۰۰۰' ) === 6000000 );
check( 'reject non-numeric', ! Number.isFinite( parseDigits( 'abc' ) ) );

/* The scoped bridge consumes theme variables without redeclaring them. */
const tokenBridge = readFileSync( new URL( '../../assets/token-bridge.css', import.meta.url ), 'utf8' );
const guideStyles = readFileSync( new URL( '../../assets/style.css', import.meta.url ), 'utf8' );
check( 'token bridge declares only guide-owned custom properties', ! /^\s*--(?!ss-)/m.test( tokenBridge ) );
check( 'guide stylesheet consumes only guide token aliases', ! /var\(--(?!ss-)/.test( guideStyles ) );
check( 'token bridge supports body.dark and data-theme dark signals', tokenBridge.includes( 'body.dark .ts-sound' ) && tokenBridge.includes( 'html[data-theme="dark"] .ts-sound' ) && tokenBridge.includes( 'body[data-theme="dark"] .ts-sound' ) );

/* REST client boundary behavior */
globalThis.location = { href: 'https://store.example/guide/', origin: 'https://store.example' };
const restConfig = { endpoint: '/wp-json/ts-sound/v1' };
const validRecommend = () => ( {
	picks: [],
	expiresAt: new Date( Date.now() + 45000 ).toISOString(),
} );

let sent = null;
globalThis.fetch = async ( url, options ) => {
	sent = { url: String( url ), options };
	return { ok: true, json: async () => validRecommend() };
};
await request( restConfig, 'recommend', { answers: { flow: 'earbuds' } } );
check( 'REST client posts same-origin JSON without caching', sent.url.endsWith( '/recommend' ) && sent.options.method === 'POST' && sent.options.cache === 'no-store' && sent.options.credentials === 'same-origin' );

let crossOriginRejected = false;
try {
	await request( { endpoint: 'https://attacker.example/api' }, 'recommend' );
} catch ( e ) {
	crossOriginRejected = e.message === 'origin';
}
check( 'REST client rejects cross-origin endpoints before fetch', crossOriginRejected );

for ( const status of [ 409, 503 ] ) {
	globalThis.fetch = async () => ( { ok: false, status, json: async () => ( {} ) } );
	let received = 0;
	try {
		await request( restConfig, 'validate', {} );
	} catch ( e ) {
		received = e.status;
	}
	check( `REST client preserves HTTP ${ status } status`, received === status );
}

globalThis.fetch = async () => ( {
	ok: true,
	json: async () => ( { picks: [], expiresAt: new Date( Date.now() - 1000 ).toISOString() } ),
} );
let expiredRejected = false;
try {
	await request( restConfig, 'recommend' );
} catch ( e ) {
	expiredRejected = e.message === 'expired';
}
check( 'REST client rejects stale recommendation responses', expiredRejected );

const abortingFetch = async ( url, options ) => new Promise( ( resolve, reject ) => {
	options.signal.addEventListener( 'abort', () => {
		const error = new Error( 'aborted' );
		error.name = 'AbortError';
		reject( error );
	}, { once: true } );
} );
globalThis.fetch = abortingFetch;
const outer = new AbortController();
const abortedRequest = request( restConfig, 'recommend', {}, outer );
outer.abort();
let abortForwarded = false;
try {
	await abortedRequest;
} catch ( e ) {
	abortForwarded = e.name === 'AbortError';
}
check( 'REST client forwards outer aborts', abortForwarded );

const realSetTimeout = globalThis.setTimeout;
const realClearTimeout = globalThis.clearTimeout;
globalThis.setTimeout = ( callback ) => {
	queueMicrotask( callback );
	return 1;
};
globalThis.clearTimeout = () => {};
globalThis.fetch = abortingFetch;
let timedOut = false;
try {
	await request( restConfig, 'recommend' );
} catch ( e ) {
	timedOut = e.message === 'timeout';
}
globalThis.setTimeout = realSetTimeout;
globalThis.clearTimeout = realClearTimeout;
check( 'REST client converts its own abort into timeout', timedOut );

console.log( `\n${ pass } passed, ${ fail } failed` );
process.exit( fail ? 1 : 0 );
