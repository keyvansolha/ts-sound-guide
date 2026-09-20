/* Persian number formatting shared by every module. */
const fa = ( n ) => new Intl.NumberFormat( 'fa-IR' ).format( n );

/** Escape dynamic text for safe interpolation into markup. */
const esc = ( s ) => String( s ?? '' ).replace( /[&<>"']/g, ( c ) => ( {
	'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[ c ] ) );

export { fa, esc };
