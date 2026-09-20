/* REST client: same-origin POST, timeout, abort, and response validation. */

const REQUEST_TIMEOUT_MS = 10000;

/** Parse a budget-like numeric string with Persian/Arabic digits. */
const parseDigits = ( value ) => Number(
	String( value )
		.replace( /[۰-۹]/g, ( d ) => '۰۱۲۳۴۵۶۷۸۹'.indexOf( d ) )
		.replace( /[٠-٩]/g, ( d ) => '٠١٢٣٤٥٦٧٨٩'.indexOf( d ) )
		.replace( /[,٬\s]/g, '' )
);

/**
 * POST one guide action with timeout and outer abort support.
 * Resolves with the parsed response; rejects with Error (status set when HTTP).
 */
const request = async ( config, action, extra = {}, outerController = null ) => {
	const endpoint = new URL( config.endpoint, location.href );
	if ( endpoint.origin !== location.origin ) {
		throw new Error( 'origin' );
	}
	const local = new AbortController();
	let timedOut = false;
	const abort = () => local.abort();
	outerController?.signal?.addEventListener( 'abort', abort, { once: true } );
	const timeout = setTimeout( () => {
		timedOut = true;
		local.abort();
	}, REQUEST_TIMEOUT_MS );
	try {
		const response = await fetch( endpoint.href.replace( /\/$/, '' ) + '/' + action, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			cache: 'no-store',
			signal: local.signal,
			body: JSON.stringify( { ...extra } ),
		} );
		if ( ! response.ok ) {
			const e = new Error( 'request' );
			e.status = response.status;
			throw e;
		}
		const data = await response.json();
		if ( action === 'recommend'
			&& ( ! Array.isArray( data.picks )
				|| ! Number.isFinite( Date.parse( data.expiresAt ) )
				|| Date.parse( data.expiresAt ) <= Date.now() ) ) {
			throw new Error( 'expired' );
		}
		return data;
	} catch ( e ) {
		if ( timedOut ) {
			throw new Error( 'timeout' );
		}
		throw e;
	} finally {
		clearTimeout( timeout );
		outerController?.signal?.removeEventListener( 'abort', abort );
	}
};

export { request, parseDigits, REQUEST_TIMEOUT_MS };
