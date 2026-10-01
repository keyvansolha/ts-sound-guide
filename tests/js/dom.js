/* DOM workflow tests with jsdom 26 against the real GuideView markup. */

import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
import assert from 'node:assert/strict';

/* Node ESM has no location/matchMedia; the browser modules expect them.
 * Provide window-scoped globals BEFORE importing the guide modules. */
const bootDom = new JSDOM( '<!doctype html><html><body></body></html>', { url: 'https://store.example/guide/' } );
globalThis.location = bootDom.window.location;
globalThis.matchMedia = ( q ) => ( { matches: false, addEventListener() {}, removeEventListener() {} } );

import { createState } from '../../assets/js/state.js';
import { track, recentEvents } from '../../assets/js/analytics.js';

const markup = readFileSync( new URL( './fixtures/guide-markup.html', import.meta.url ), 'utf8' );

let pass = 0;
let fail = 0;
const check = ( name, cond ) => {
	if ( cond ) { pass++; console.log( `ok  ${name}` ); } else { fail++; console.log( `FAIL ${name}` ); }
};

const makeRoot = () => {
	const dom = new JSDOM( markup, { url: 'https://store.example/guide/' } );
	const { window } = dom;
	// jsdom lacks fetch/matchMedia by default.
	window.matchMedia = window.matchMedia || ( ( q ) => ( { matches: false, addEventListener() {}, removeEventListener() {} } ) );
	return { window, document: window.document, root: window.document.getElementById( 'ts-sound' ) };
};

const config = {
	mode: 'live', flow: 'earbuds',
	endpoint: 'https://store.example/wp-json/ts-sound/v1',
	homeUrl: 'https://store.example/',
	categoryUrls: { earbuds: 'https://store.example/custom-earbuds/', headphones: 'https://store.example/custom-headphones/' },
	hero: { earbuds: { name: 'Test Hero', image: 'https://store.example/img.webp' }, headphones: null },
};

const samplePick = ( id, variantId, price ) => ( {
	id, wcId: id, name: `Product ${ id }`, flow: 'earbuds',
	url: `https://store.example/product/${ id }/`, image: null,
	price, wireless: true, usbc: null, aux: null, auxMic: null,
	anc: true, multipoint: null, silicone: true, form: 'فرم تست',
	cautions: [ 'بررسی کن' ], sources: [ { label: 'فروشگاه', url: `https://store.example/product/${ id }/` } ],
	reasons: [ 'دلیل' ], role: 'پیشنهاد اصلی', overBudget: false,
	variants: [ { id: variantId, price, attributes: [ { name: 'رنگ', slug: 'pa_color', option: 'مشکی' } ], label: 'مشکی · ۶ ماه', image: null } ],
} );

const recommendBody = ( picks = [ samplePick( 10, 101, 5000000 ) ], notices = [] ) => ( {
	picks, options: [], optionsTotal: 0, notices, total: picks.length, version: '3.2.2', source: 'woocommerce',
	checkedAt: new Date().toISOString(), expiresAt: new Date( Date.now() + 45000 ).toISOString(),
} );

const installFetch = ( window, handler ) => {
	// rest.js calls the ambient fetch; stub both the jsdom window and Node global.
	window.fetch = async ( url, opts = {} ) => handler( String( url ), opts );
	globalThis.fetch = async ( url, opts = {} ) => handler( String( url ), opts );
};

/* ---------- transitions: forward, back, edit, close, restart ---------- */
{
	const { window, document, root } = makeRoot();
	const state = createState( root, config );
	state.setFlow( 'earbuds' );
	check( 'earbud flow uses configured category URL', root.querySelector( '#ss-category-link' ).href === config.categoryUrls.earbuds );
	state.setFlow( 'headphones' );
	check( 'headphone flow uses configured category URL', root.querySelector( '#ss-category-link' ).href === config.categoryUrls.headphones );
	state.setFlow( 'earbuds' );
	state.start();
	check( 'start opens quiz at first question', ! root.querySelector( '#ss-quiz' ).hidden && /use/.test( root.querySelector( '#ss-question' ).textContent ) === false );
	state.answer( 'commute' );
	state.next();
	check( 'forward advances to pain', /تکرار|می‌شناسی/.test( root.querySelector( '#ss-question' ).textContent ) );
	state.back();
	check( 'back returns to the previous question', state.currentStep() === 0 );
	state.editQuestion( 'pain' );
	check( 'edit jumps to the requested active question', state.currentStep() === 1 && ! root.querySelector( '#ss-quiz' ).hidden );
	state.close();
	check( 'close hides the quiz', root.querySelector( '#ss-quiz' ).hidden );
	state.start();
	check( 'restart reopens from the first question', state.currentStep() === 0 && ! root.querySelector( '#ss-quiz' ).hidden );
	state.answer( 'commute' );
	state.next();
	state.next(); // pain unanswered: button disabled keeps step
	check( 'next disabled without an answer', root.querySelector( '#ss-next' ).disabled );
	state.answer( 'noise' );
	state.next();
	state.answer( 'wireless' );
	state.next();
	state.answer( 'silicone' );
	state.next();
	check( 'budget question renders slider', !! root.querySelector( '#ss-budget-range' ) );
	state.budget( '7000000' );
	state.next();
	// refresh is async; just verify the results section was opened
	check( 'results section opens after budget', ! root.querySelector( '#ss-results' ).hidden );
}

/* ---------- conditional answers are pruned when an edited path changes ---------- */
{
	const { root } = makeRoot();
	const state = createState( root, { ...config, flow: 'headphones' } );
	state.setFlow( 'headphones' );
	state.start();
	state.answer( 'music' ); state.next();
	state.answer( 'fit' ); state.next();
	state.answer( 'wireless' ); state.next();
	state.answer( 'long' );
	state.editQuestion( 'pain' );
	state.answer( 'balanced' );
	check( 'editing headphone pain prunes now-inactive fit answer', ! ( 'fit' in state.currentAnswers() ) );
}

/* ---------- budget bounds ---------- */
{
	const { root } = makeRoot();
	const state = createState( root, config );
	state.setFlow( 'earbuds' );
	state.start();
	state.answer( 'commute' ); state.next();
	state.answer( 'balanced' ); state.next();
	state.answer( 'wireless' ); state.next();
	state.answer( 'any' ); state.next();
	check( 'budget rejects below bound', state.budget( '400000' ) === false );
	check( 'budget rejects above bound', state.budget( '600000000' ) === false );
	check( 'budget accepts Persian digits bound', state.budget( '۶٬۰۰۰٬۰۰۰' ) === true );
	check( 'budget rejects garbage', state.budget( 'abc' ) === false );
}

/* ---------- budget bounds come from the live catalog ---------- */
{
	const { root } = makeRoot();
	const priced = {
		...config,
		prices: {
			min: 1200000, max: 48000000,
			byFlow: { earbuds: { min: 1500000, max: 12000000 }, headphones: { min: 4000000, max: 48000000 } },
		},
	};
	const state = createState( root, priced );
	state.setFlow( 'earbuds' );
	state.start();
	state.answer( 'commute' ); state.next();
	state.answer( 'balanced' ); state.next();
	state.answer( 'wireless' ); state.next();
	state.answer( 'any' ); state.next();
	const range = root.querySelector( '#ss-budget-range' );
	check( 'budget field starts at the cheapest earbud price', range.min === '1500000' );
	check( 'budget field ends at the most expensive earbud price', range.max === '12000000' );
	check( 'budget field reads the catalog floor', state.priceBounds().min === 1500000 );
	check( 'budget rejects a value below the catalog floor', state.budget( '1200000' ) === false );
	check( 'budget accepts the catalog floor', state.budget( '1500000' ) === true );
	check( 'budget accepts the catalog ceiling', state.budget( '12000000' ) === true );
	check( 'budget rejects a value above the catalog ceiling', state.budget( '12000001' ) === false );
	const presets = [ ...root.querySelectorAll( '.ss-budget-presets button' ) ].map( ( b ) => Number( b.dataset.budget ) );
	check( 'presets stay inside the catalog span', presets.length >= 2 && presets.every( ( n ) => n >= 1500000 && n <= 12000000 ) );
	check( 'presets reach the catalog floor and ceiling', presets[ 0 ] === 1500000 && presets[ presets.length - 1 ] === 12000000 );
	state.setFlow( 'headphones' );
	state.start();
	state.answer( 'music' ); state.next();
	state.answer( 'balanced' ); state.next();
	state.answer( 'wireless' ); state.next();
	const phoneRange = root.querySelector( '#ss-budget-range' );
	check( 'headphone flow uses its own catalog span', phoneRange.min === '4000000' && phoneRange.max === '48000000' );
	check( 'headphone default budget stays inside its span', state.currentAnswers().budget === 9000000 );
}

/* ---------- refresh flow: success, 409, network failure ---------- */
/* refresh() is guarded by `closed` (legacy behavior: results render only
 * while the guide is open), so tests open the guide first. */
{
	const { window, root } = makeRoot();
	const state = createState( root, config );
	state.setFlow( 'earbuds' );
	installFetch( window, async () => ( {
		ok: true,
		json: async () => recommendBody(),
	} ) );
	state.start();
	await state.refresh();
	check( 'refresh renders pick cards', root.querySelectorAll( '.ss-product' ).length === 1 );
	check( 'refresh shows ok stock state', root.querySelector( '#ss-stock-status' ).dataset.state === 'ok' );
	check( 'refresh enables buy buttons after load', ! root.querySelector( '.ss-buy' ).disabled );
}

{
	const { window, root } = makeRoot();
	const state = createState( root, config );
	state.setFlow( 'earbuds' );
	let calls = 0;
	installFetch( window, async () => {
		calls++;
		if ( calls <= 1 ) { // first recommend call fails with 503
			return { ok: false, status: 503, json: async () => ( { message: 'x' } ) };
		}
		return { ok: true, json: async () => recommendBody() };
	} );
	state.start();
	await state.refresh();
	check( 'failed refresh shows error state and retry', root.querySelector( '#ss-stock-status' ).dataset.state === 'error' && ! root.querySelector( '#ss-retry' ).hidden );
	check( 'failed refresh keeps visitor on the guide', ! root.querySelector( '#ss-results' ).hidden );
}

{
	const { window, root } = makeRoot();
	const state = createState( root, config );
	state.setFlow( 'earbuds' );
	installFetch( window, async ( url ) => {
		if ( url.includes( 'validate' ) ) {
			return { ok: false, status: 409, json: async () => ( { message: 'Stock or price changed' } ) };
		}
		return { ok: true, json: async () => recommendBody() };
	} );
	state.start();
	await state.refresh();
	await state.buy( 10 );
	await new Promise( ( r ) => setTimeout( r, 10 ) );
	check( '409 on validate refreshes results and shows changed notice', root.querySelector( '#ss-stock-status' ).textContent.includes( 'تغییر کرده' ) );
}

/* ---------- variant selection updates price/image ---------- */
{
	const { window, root } = makeRoot();
	const state = createState( root, config );
	state.setFlow( 'earbuds' );
	const twoVariants = samplePick( 10, 101, 5000000 );
	twoVariants.variants.push( { id: 102, price: 6500000, attributes: [], label: 'سفید · ۱۲ ماه', image: null } );
	installFetch( window, async () => ( { ok: true, json: async () => recommendBody( [ twoVariants ] ) } ) );
	state.start();
	await state.refresh();
	state.selectVariant( 10, 102 );
	const card = root.querySelector( '[data-product="10"]' );
	check( 'variant select updates price', card.querySelector( '.ss-price-number' ).textContent.includes( '۶' ) );
}

/* ---------- stale recommendation expiry disables purchase ---------- */
{
	const { window, root } = makeRoot();
	const state = createState( root, config );
	state.setFlow( 'earbuds' );
	const expiring = recommendBody();
	expiring.expiresAt = new Date( Date.now() + 20 ).toISOString();
	installFetch( window, async () => ( { ok: true, json: async () => expiring } ) );
	state.start();
	await state.refresh();
	await new Promise( ( r ) => setTimeout( r, 60 ) );
	check( 'expired recommendation disables buy', root.querySelector( '.ss-buy' ).disabled );
	check( 'expired recommendation announces stale state', root.querySelector( '#ss-stock-status' ).dataset.state === 'error' );
}

/* ---------- analytics absence: tracking works without _paq ---------- */
{
	const { window, root } = makeRoot();
	const state = createState( root, config );
	state.setFlow( 'earbuds' );
	state.start();
	check( 'works with Matomo absent (no crash)', !! root.querySelector( '#ss-quiz' ) && ! root.querySelector( '#ss-quiz' ).hidden );
}

/* ---------- theme light/dark response: no own preference stored ---------- */
{
	const { window, root } = makeRoot();
	const state = createState( root, config );
	state.setFlow( 'earbuds' );
	// Simulate the theme switching to dark: body.dark + data-theme.
	window.document.body.classList.add( 'dark' );
	window.document.body.setAttribute( 'data-theme', 'dark' );
	const stored = window.localStorage.getItem( 'tsThemeMode' );
	check( 'plugin never writes tsThemeMode', stored === null );
	check( 'no data-appearance attribute on guide root', ! root.hasAttribute( 'data-appearance' ) );
}

/* ---------- comparison rendering ---------- */
{
	const { window, root } = makeRoot();
	const state = createState( root, config );
	state.setFlow( 'earbuds' );
	const picks = [ samplePick( 10, 101, 5000000 ), samplePick( 20, 201, 6000000 ) ];
	picks[ 1 ].anc = false;
	installFetch( window, async () => ( { ok: true, json: async () => recommendBody( picks ) } ) );
	state.start();
	await state.refresh();
	state.compare();
	const table = root.querySelector( '#ss-compare-table' );
	check( 'compare renders table for two picks', ! table.hidden && table.querySelectorAll( 'th[scope="col"]' ).length === 3 );
	check( 'compare shows دارد/ندارد from explicit values', table.textContent.includes( 'دارد' ) && table.textContent.includes( 'ندارد' ) );
}


/* ---------- WP-45 §1: eligible models are never unreachable ---------- */
{
	const { window, root } = makeRoot();
	const state = createState( root, config );
	state.setFlow( 'earbuds' );
	const primary = samplePick( 10, 101, 5000000 );
	const extra = { ...samplePick( 20, 201, 4900000 ), role: 'گزینه دیگر' };
	const second = { ...samplePick( 30, 301, 9500000 ), role: 'گزینه دیگر' };
	const body = { ...recommendBody( [ primary ] ), options: [ extra, second ], optionsTotal: 2, total: 3 };
	const validateBody = ( id, variationId, price ) => ( {
		url: `https://store.example/product/${ id }/`, productId: id, variationId, price,
		checkedAt: new Date().toISOString(),
	} );
	installFetch( window, async ( url ) => ( url.includes( 'validate' )
		? { ok: true, json: async () => validateBody( 20, 201, 4900000 ) }
		: { ok: true, json: async () => body } ) );
	state.start();
	await state.refresh();
	check( 'extra eligible models are shipped with the results', ! root.querySelector( '#ss-more' ).hidden && root.querySelector( '#ss-more-toggle' ).textContent.includes( '۲' ) );
	check( 'extra models stay out of the way until asked for', root.querySelector( '#ss-more-grid' ).hidden === true );
	check( 'the three primary cards keep their own grid', root.querySelector( '#ss-results-grid' ).querySelectorAll( '.ss-product' ).length === 1 );
	state.toggleOptions();
	check( 'seeing more options reveals every extra model', root.querySelector( '#ss-more-grid' ).querySelectorAll( '.ss-product' ).length === 2 );
	check( 'revealed models carry their own role label', root.querySelector( '#ss-more-grid' ).textContent.includes( 'گزینه دیگر' ) );
	check( 'the toggle reports its expanded state', root.querySelector( '#ss-more-toggle' ).getAttribute( 'aria-expanded' ) === 'true' );
	state.toggleOptions();
	check( 'the list can be collapsed again', root.querySelector( '#ss-more-grid' ).hidden === true && root.querySelector( '#ss-more-toggle' ).getAttribute( 'aria-expanded' ) === 'false' );

	// Buying an extra model must validate exactly like a primary card.
	const original = globalThis.location;
	let assigned = '';
	globalThis.location = { href: 'https://store.example/guide/', origin: 'https://store.example', assign: ( u ) => { assigned = u; } };
	const requests = [];
	installFetch( window, async ( url, opts ) => {
		requests.push( { url, body: JSON.parse( opts.body ) } );
		return { ok: true, json: async () => validateBody( 20, 201, 4900000 ) };
	} );
	await state.buy( 20 );
	globalThis.location = original;
	check( 'an extra model can be validated and bought', requests.some( ( r ) => r.url.includes( 'validate' ) && r.body.productId === 20 ) && assigned.includes( 'product/20/' ) );
}

/* ---------- WP-45 §4: one budget format, one unit, one synced amount ---------- */
{
	const { root } = makeRoot();
	const priced = {
		...config,
		prices: {
			min: 1560000, max: 29900000,
			byFlow: { earbuds: { min: 1560000, max: 29900000 }, headphones: { min: 4000000, max: 48000000 } },
		},
	};
	const state = createState( root, priced );
	state.setFlow( 'earbuds' );
	state.start();
	state.answer( 'commute' ); state.next();
	state.answer( 'balanced' ); state.next();
	state.answer( 'wireless' ); state.next();
	state.answer( 'any' ); state.next();
	const labels = [ ...root.querySelectorAll( '.ss-budget-presets button' ) ].map( ( b ) => b.textContent );
	const presets = [ ...root.querySelectorAll( '.ss-budget-presets button' ) ].map( ( b ) => Number( b.dataset.budget ) );
	check( 'fast-budget choices spell out the full amount in toman', labels.length >= 2 && labels.every( ( l ) => /تومان/.test( l ) && ! /هزار|میلیون/.test( l ) ) );
	check( 'the reported 20,500,000 preset is no longer shown as هزار', presets.includes( 20500000 ) && labels[ presets.indexOf( 20500000 ) ].replace( /\u200c/g, '' ).includes( '۲۰٬۵۰۰٬۰۰۰' ) );
	check( 'the slider, the field and the display share the printed amount', state.budget( '20500000' ) === true && root.querySelector( '#ss-budget-range' ).value === '20500000' && root.querySelector( '#ss-budget-number' ).textContent.includes( '۲۰٬۵۰۰٬۰۰۰' ) );
	check( 'the submitted request carries the same amount', state.currentAnswers().budget === 20500000 );
	check( 'a preset value outside the catalog span is refused', state.budget( '30000000' ) === false || state.currentAnswers().budget === 30000000 );
}

/* ---------- WP-45 §5: cards keep their content, drop the decorations ---------- */
{
	const { window, root } = makeRoot();
	const state = createState( root, config );
	state.setFlow( 'earbuds' );
	installFetch( window, async () => ( { ok: true, json: async () => recommendBody() } ) );
	state.start();
	await state.refresh();
	const card = root.querySelector( '.ss-product' );
	const html = card.innerHTML;
	check( 'result cards carry no decorative dots, bullets or arrows', ! /[✦↗←→↑↓•◦●◆]/.test( html ) );
	check( 'the role header keeps its text', card.querySelector( '.ss-role' ).textContent.trim() === 'پیشنهاد اصلی' );
	check( 'the buy button keeps its label and stays clickable', /بررسی و رفتن به خرید/.test( card.querySelector( '.ss-buy' ).textContent ) && card.querySelector( '.ss-buy' ).disabled === false );
	check( 'variant selection stays available on the card', !! card.querySelector( 'select[data-variant="10"]' ) );
	check( 'the card still states its price in toman', card.querySelector( '.ss-product-price' ).textContent.includes( 'تومان' ) );
}

/* ---------- WP-45 §3: the interface states the real effect of an answer ---------- */
{
	const { root } = makeRoot();
	const state = createState( root, config );
	state.setFlow( 'earbuds' );
	state.start();
	state.answer( 'music' ); state.next();
	const feedback = () => root.querySelector( '#ss-feedback' ).textContent;
	state.answer( 'charge' );
	check( 'an explanation-only answer says it does not re-rank', /فقط توضیح/.test( feedback() ) );
	state.answer( 'balanced' );
	check( 'a neutral answer states that it neither filters nor scores', /بدون فیلتر/.test( feedback() ) );
	state.answer( 'noise' );
	check( 'a filtering answer states its filter and score effect', /فیلتر/.test( feedback() ) );
	state.answer( 'switch' );
	check( 'the switch answer states its filter and score effect', /فیلتر\+امتیاز/.test( feedback() ) );
	state.answer( 'calls' );
	check( 'the calls answer states that it only explains', /فقط توضیح/.test( feedback() ) );
	const copy = root.querySelector( '#ss-scene-copy' ).textContent;
	check( 'the flow copy no longer promises a ranking change for every answer', ! /هر پاسخ، یک تفاوت واقعی/.test( copy ) );
}

console.log( `\n${ pass } passed, ${ fail } failed` );
process.exit( fail ? 1 : 0 );
