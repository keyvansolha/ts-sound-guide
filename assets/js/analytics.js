/* Matomo analytics adapter. Coarse answer enums and commerce events only. */

let events = [];

/** Track a coarse event when Matomo is present; degrade silently when not. */
const track = ( root, name, data = {} ) => {
	const entry = { name, ...data };
	events.push( entry );
	events = events.slice( -60 );
	const view = root?.ownerDocument?.defaultView;
	if ( view?._paq && typeof view._paq.push === 'function' ) {
		view._paq.push( [ 'trackEvent', 'TS Sound Guide', name, JSON.stringify( data ) ] );
	}
	root?.dispatchEvent?.( new ( view ?? globalThis ).CustomEvent( 'ts:sound:event', { detail: entry } ) );
};

/** Recently captured events (for tests). */
const recentEvents = () => events;

export { track, recentEvents };
