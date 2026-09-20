/* Matomo analytics adapter. Coarse answer enums and commerce events only. */

let events = [];

/** Track a coarse event when Matomo is present; degrade silently when not. */
const track = ( root, name, data = {} ) => {
	const entry = { name, ...data };
	events.push( entry );
	events = events.slice( -60 );
	if ( window._paq && typeof window._paq.push === 'function' ) {
		window._paq.push( [ 'trackEvent', 'TS Sound Guide', name, JSON.stringify( data ) ] );
	}
	root.dispatchEvent( new CustomEvent( 'ts:sound:event', { detail: entry } ) );
};

/** Recently captured events (for tests). */
const recentEvents = () => events;

export { track, recentEvents };
