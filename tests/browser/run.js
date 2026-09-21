/* Automated real-Chrome workflows and project-owned visual baselines. */

import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import net from 'node:net';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';
import pixelmatch from 'pixelmatch';
import { PNG } from 'pngjs';

const root = fileURLToPath( new URL( '../../', import.meta.url ) );
const baselines = fileURLToPath( new URL( './baselines/', import.meta.url ) );
const update = process.env.UPDATE_SNAPSHOTS === '1';
const chrome = process.env.CHROME_PATH || '/opt/google/chrome/chrome';
const maxDiffRatio = 0.01;
const pixelThreshold = 0.12;
let pass = 0;
let fail = 0;

const check = ( name, condition, detail = '' ) => {
	if ( condition ) {
		pass++;
		console.log( `ok  ${ name }` );
	} else {
		fail++;
		console.log( `FAIL ${ name }${ detail ? ` — ${ detail }` : '' }` );
	}
};

const freePort = () => new Promise( ( resolve, reject ) => {
	const server = net.createServer();
	server.once( 'error', reject );
	server.listen( 0, '127.0.0.1', () => {
		const address = server.address();
		server.close( () => resolve( address.port ) );
	} );
} );

const waitForServer = async ( url ) => {
	for ( let attempt = 0; attempt < 50; attempt++ ) {
		try {
			const response = await fetch( url );
			if ( response.ok ) return;
		} catch {}
		await new Promise( ( resolve ) => setTimeout( resolve, 100 ) );
	}
	throw new Error( `PHP harness did not start at ${ url }` );
};

const compareScreenshot = async ( locator, name ) => {
	const actualBuffer = await locator.screenshot( { animations: 'disabled', caret: 'hide' } );
	const baselinePath = `${ baselines }${ name }.png`;
	if ( update ) {
		mkdirSync( baselines, { recursive: true } );
		writeFileSync( baselinePath, actualBuffer );
		check( `visual baseline updated: ${ name }`, true );
		return;
	}
	if ( ! existsSync( baselinePath ) ) {
		check( `visual baseline exists: ${ name }`, false, 'run UPDATE_SNAPSHOTS=1 npm run test:browser after visual approval' );
		return;
	}
	const actual = PNG.sync.read( actualBuffer );
	const expected = PNG.sync.read( readFileSync( baselinePath ) );
	if ( actual.width !== expected.width || actual.height !== expected.height ) {
		check( `visual snapshot: ${ name }`, false, `${ actual.width }×${ actual.height } != ${ expected.width }×${ expected.height }` );
		return;
	}
	const changed = pixelmatch( expected.data, actual.data, null, actual.width, actual.height, { threshold: pixelThreshold } );
	const ratio = changed / ( actual.width * actual.height );
	check( `visual snapshot: ${ name }`, ratio <= maxDiffRatio, `${ ( ratio * 100 ).toFixed( 3 ) }% changed; allowed ${( maxDiffRatio * 100 ).toFixed( 1 )}%` );
};

const completeGuide = async ( page ) => {
	await page.click( '#ss-start' );
	for ( const value of [ 'music', 'balanced', 'wireless', 'any' ] ) {
		await page.click( `[data-answer="${ value }"]` );
		await page.click( '#ss-next' );
	}
	await page.click( '#ss-next' );
};

if ( ! existsSync( chrome ) ) {
	throw new Error( `Chrome executable not found at ${ chrome }; set CHROME_PATH` );
}

const port = await freePort();
const origin = `http://127.0.0.1:${ port }`;
const server = spawn( 'php', [ '-S', `127.0.0.1:${ port }`, '-t', '.' ], { cwd: root, stdio: [ 'ignore', 'ignore', 'pipe' ] } );
let serverError = '';
server.stderr.on( 'data', ( chunk ) => { serverError += String( chunk ); } );

let browser;
try {
	await waitForServer( `${ origin }/tests/browser/index.php` );
	browser = await chromium.launch( { executablePath: chrome, headless: true, args: [ '--no-sandbox' ] } );

	const cases = [
		[ 'desktop', { width: 1440, height: 1000 } ],
		[ 'mobile', { width: 390, height: 844 } ],
	];
	for ( const [ viewportName, viewport ] of cases ) {
		for ( const theme of [ 'light', 'dark' ] ) {
			const page = await browser.newPage( { viewport } );
			const errors = [];
			page.on( 'pageerror', ( error ) => errors.push( error.message ) );
			page.on( 'console', ( message ) => { if ( message.type() === 'error' ) errors.push( message.text() ); } );
			await page.goto( `${ origin }/tests/browser/index.php?scenario=success&theme=${ theme }`, { waitUntil: 'networkidle' } );
			await page.addStyleTag( { content: '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}' } );
			const background = await page.locator( '#ts-sound' ).evaluate( ( node ) => getComputedStyle( node ).backgroundColor );
			check( `${ viewportName } ${ theme } computed theme`, theme === 'dark' ? background === 'rgb(7, 18, 29)' : background === 'rgb(255, 255, 255)', background );
			const noOverflow = await page.evaluate( () => document.documentElement.scrollWidth <= document.documentElement.clientWidth );
			check( `${ viewportName } ${ theme } has no horizontal overflow`, noOverflow );
			await compareScreenshot( page.locator( '.ss-hero' ), `hero-${ viewportName }-${ theme }` );
			await completeGuide( page );
			await page.waitForSelector( '.ss-product' );
			check( `${ viewportName } ${ theme } success workflow renders recommendations`, await page.locator( '.ss-product' ).count() === 2 );
			check( `${ viewportName } ${ theme } has no console/page errors`, errors.length === 0, errors.join( '; ' ) );
			await page.close();
		}
	}

	/* Theme integration: custom inherited tokens win, and either supported dark
	 * signal independently switches the guide's scoped fallback. */
	{
		const page = await browser.newPage( { viewport: { width: 1000, height: 800 } } );
		await page.goto( `${ origin }/tests/browser/index.php?scenario=success&theme=light`, { waitUntil: 'networkidle' } );
		await page.evaluate( () => document.documentElement.style.setProperty( '--surface-page', 'rgb(1, 2, 3)' ) );
		check( 'guide consumes inherited theme token without shadowing it', await page.locator( '#ts-sound' ).evaluate( ( node ) => getComputedStyle( node ).backgroundColor ) === 'rgb(1, 2, 3)' );
		await page.goto( `${ origin }/tests/browser/index.php?scenario=success&theme=dark`, { waitUntil: 'networkidle' } );
		await page.evaluate( () => document.body.classList.remove( 'dark' ) );
		check( 'data-theme dark signal works independently', await page.locator( '#ts-sound' ).evaluate( ( node ) => getComputedStyle( node ).backgroundColor ) === 'rgb(7, 18, 29)' );
		await page.evaluate( () => document.documentElement.style.setProperty( '--surface-page', 'rgb(4, 5, 6)' ) );
		check( 'data-theme dark consumes a custom inherited theme token', await page.locator( '#ts-sound' ).evaluate( ( node ) => getComputedStyle( node ).backgroundColor ) === 'rgb(4, 5, 6)' );
		await page.evaluate( () => document.documentElement.style.removeProperty( '--surface-page' ) );
		await page.evaluate( () => { document.body.classList.add( 'dark' ); document.body.removeAttribute( 'data-theme' ); document.documentElement.removeAttribute( 'data-theme' ); } );
		check( 'body.dark signal works independently', await page.locator( '#ts-sound' ).evaluate( ( node ) => getComputedStyle( node ).backgroundColor ) === 'rgb(7, 18, 29)' );
		await page.close();
	}

	/* Stable state-specific visual baselines and workflows. */
	{
		const page = await browser.newPage( { viewport: { width: 1440, height: 1000 } } );
		await page.goto( `${ origin }/tests/browser/index.php?scenario=success&theme=light`, { waitUntil: 'networkidle' } );
		await page.addStyleTag( { content: '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}' } );
		await page.click( '#ss-start' );
		await compareScreenshot( page.locator( '#ss-quiz' ), 'quiz-desktop-light' );
		await page.click( '[data-answer="music"]' ); await page.click( '#ss-next' );
		for ( const value of [ 'balanced', 'wireless', 'any' ] ) { await page.click( `[data-answer="${ value }"]` ); await page.click( '#ss-next' ); }
		await page.click( '#ss-next' );
		await page.waitForSelector( '.ss-product' );
		await compareScreenshot( page.locator( '#ss-results' ), 'results-desktop-light' );
		await page.click( '#ss-compare' );
		check( 'comparison workflow renders a visible table', await page.locator( '#ss-compare-table table' ).isVisible() );
		await compareScreenshot( page.locator( '#ss-compare-table' ), 'comparison-desktop-light' );
		await page.close();
	}

	for ( const scenario of [ 'empty', 'error', 'changed' ] ) {
		const page = await browser.newPage( { viewport: { width: 1440, height: 1000 } } );
		await page.goto( `${ origin }/tests/browser/index.php?scenario=${ scenario }&theme=light`, { waitUntil: 'networkidle' } );
		await page.addStyleTag( { content: '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}' } );
		await completeGuide( page );
		if ( scenario === 'empty' ) {
			await page.waitForFunction( () => ! document.querySelector( '#ss-empty' ).hidden );
			check( 'empty workflow explains that there are no matches', await page.locator( '#ss-empty' ).isVisible() );
			await compareScreenshot( page.locator( '#ss-results' ), 'empty-desktop-light' );
		} else if ( scenario === 'error' ) {
			await page.waitForFunction( () => document.querySelector( '#ss-stock-status' ).dataset.state === 'error' );
			check( 'network failure stays on guide and exposes retry', await page.locator( '#ss-retry' ).isVisible() && await page.locator( '#ss-results' ).isVisible() );
		} else {
			await page.waitForSelector( '.ss-buy' );
			await page.click( '.ss-buy' );
			await page.waitForFunction( () => document.querySelector( '#ss-stock-status' ).textContent.includes( 'تغییر کرده' ) );
			check( 'changed inventory refreshes results with a notice', true );
		}
		await page.close();
	}

	/* Final validation success navigates only after the validate response. */
	{
		const page = await browser.newPage( { viewport: { width: 1000, height: 800 } } );
		await page.goto( `${ origin }/tests/browser/index.php?scenario=success&theme=light`, { waitUntil: 'networkidle' } );
		await completeGuide( page );
		await page.waitForSelector( '.ss-buy' );
		await Promise.all( [ page.waitForURL( '**/product/10/**' ), page.click( '.ss-buy' ) ] );
		check( 'final validation navigates to the verified purchase URL', page.url().includes( '/product/10/?ts_sound_ref=' ) );
		await page.close();
	}

	/* Minimal critical accessibility invariants without a second browser stack. */
	{
		const page = await browser.newPage();
		await page.goto( `${ origin }/tests/browser/index.php`, { waitUntil: 'networkidle' } );
		const issues = await page.evaluate( () => {
			const ids = [ ...document.querySelectorAll( '[id]' ) ].map( ( node ) => node.id );
			return {
				duplicateIds: ids.filter( ( id, index ) => ids.indexOf( id ) !== index ),
				imagesWithoutAlt: document.querySelectorAll( 'img:not([alt])' ).length,
				buttonsWithoutName: [ ...document.querySelectorAll( 'button' ) ].filter( ( button ) => ! button.textContent.trim() && ! button.getAttribute( 'aria-label' ) ).length,
			};
		} );
		check( 'no accessibility-critical duplicate IDs', issues.duplicateIds.length === 0, issues.duplicateIds.join( ', ' ) );
		check( 'all images expose alt text', issues.imagesWithoutAlt === 0 );
		check( 'all buttons expose an accessible name', issues.buttonsWithoutName === 0 );
		await page.close();
	}
} finally {
	if ( browser ) await browser.close();
	server.kill( 'SIGTERM' );
}

if ( serverError && /Fatal error|Warning:/i.test( serverError ) ) {
	check( 'browser harness emits no PHP warnings/fatals', false, serverError.trim() );
} else {
	check( 'browser harness emits no PHP warnings/fatals', true );
}

console.log( `\n${ pass } passed, ${ fail } failed` );
process.exit( fail ? 1 : 0 );
