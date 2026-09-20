/* DOM controller: reads public bootstrap config, wires events to state. */
import { hydrateIcons } from './render.js';
import { createState } from './state.js';
import { track } from './analytics.js';

const readConfig = () => {
	const node = document.getElementById( 'ts-sound-config' );
	if ( node ) {
		try {
			return JSON.parse( node.textContent || '{}' );
		} catch ( e ) {
			return {};
		}
	}
	return window.TSSoundConfig || {};
};

const boot = () => {
	const root = document.getElementById( 'ts-sound' );
	if ( ! root ) {
		return;
	}
	const config = readConfig();
	hydrateIcons( root );
	const state = createState( root, config );
	const $ = ( id ) => root.querySelector( '#' + id );

	root.addEventListener( 'click', ( e ) => {
		const b = e.target.closest( 'button' );
		if ( ! b ) {
			return;
		}
		if ( b.dataset.flow ) {
			state.setFlow( b.dataset.flow );
			track( root, 'flow_select', { flow: b.dataset.flow } );
		} else if ( b.dataset.preset ) {
			state.setPreset( b.dataset.preset );
			state.start( b.dataset.preset );
		} else if ( b.dataset.answer ) {
			state.answer( b.dataset.answer );
		} else if ( b.dataset.budget ) {
			state.budget( b.dataset.budget );
			root.querySelector( '#ss-budget-exact' ).value = b.dataset.budget;
			root.querySelector( '#ss-budget-range' ).value = Math.min( Number( b.dataset.budget ), 20000000 );
		} else if ( b.dataset.edit ) {
			state.editQuestion( b.dataset.edit );
		} else if ( b.dataset.buy ) {
			state.buy( Number( b.dataset.buy ) );
		}
	} );

	root.addEventListener( 'input', ( e ) => {
		if ( [ 'ss-budget-range', 'ss-budget-exact' ].includes( e.target.id ) ) {
			state.budget( e.target.value );
			if ( e.target.id === 'ss-budget-range' ) {
				$( 'ss-budget-exact' ).value = e.target.value;
			} else if ( ! $( 'ss-next' ).disabled ) {
				$( 'ss-budget-range' ).value = Math.min( state.currentAnswers().budget, 20000000 );
			}
		}
	} );

	root.addEventListener( 'change', ( e ) => {
		if ( e.target.id === 'ss-flex' ) {
			state.setFlex( e.target.checked );
			state.budget( state.currentAnswers().budget );
		}
		if ( e.target.dataset.variant ) {
			state.selectVariant( Number( e.target.dataset.variant ), Number( e.target.value ) );
		}
	} );

	$( 'ss-start' ).addEventListener( 'click', () => state.start() );
	$( 'ss-next' ).addEventListener( 'click', () => state.next() );
	$( 'ss-back' ).addEventListener( 'click', () => state.back() );
	$( 'ss-close' ).addEventListener( 'click', () => state.close() );
	$( 'ss-edit' ).addEventListener( 'click', () => state.start() );
	$( 'ss-compare' ).addEventListener( 'click', () => state.compare() );
	$( 'ss-retry' ).addEventListener( 'click', () => state.refresh() );
	$( 'ss-motion' ).addEventListener( 'click', () => state.toggleMotion() );

	if ( matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
		root.dataset.motion = 'off';
		$( 'ss-motion' ).hidden = true;
	}

	// Refresh stale results when the visitor returns.
	document.addEventListener( 'visibilitychange', () => {
		if ( ! document.hidden && state.hasResult() && state.isVisible() ) {
			state.refresh();
		}
	} );
	window.addEventListener( 'focus', () => {
		if ( state.hasResult() && state.isVisible() ) {
			state.refresh();
		}
	} );
	setInterval( () => {
		if ( state.hasResult() && state.isVisible() && ! document.hidden ) {
			state.refresh();
		}
	}, 30000 );

	state.setFlow( config.flow || 'earbuds' );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
