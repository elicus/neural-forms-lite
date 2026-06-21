/**
 * Cross-bundle slot registry — shared by every per-screen split bundle
 * (settings, entries, …) so Pro's `window.neuralForms.slots.registerFill`
 * calls land in a single coherent registry on the page.
 *
 * Two consumers:
 *   - Pro's admin bundle calls `registerFill(slot, id, render)` to inject
 *     React elements into Lite-owned slot regions.
 *   - Lite's per-screen bundles render those fills via the <ProSlot>
 *     component, which subscribes to the registry and re-renders when
 *     fills register or unregister.
 *
 * Whitelist must match the rich `build/index.js` `We` map so a fill that
 * works on the rich bundle's screens also works under any split bundle.
 *
 * Public API exposed on window.neuralForms.slots (Pro contract):
 *   registerFill( slotName, fillId, renderFn )  → unregister fn
 *   unregisterFill( slotName, fillId )
 *   SLOT_NAMES (read-only array)
 *
 * Private API only used by <ProSlot>:
 *   __getFills( slotName ) → array of renderFn
 *   __subscribe( fn ) → unsubscribe fn
 */

import { Fragment, useEffect, useState } from '@wordpress/element';

const SLOTS = {
	EntryAiActions:      true,
	EntryReplyActions:   true,
	SettingsSpamPanel:   true,
	SettingsSiteProfile: true,
	SettingsLicense:     true,
};

/**
 * Idempotently install the cross-bundle registry on `window.neuralForms.slots`.
 *
 * Safe to call from every per-screen entry point; the first call wins
 * (rich bundle, or whichever split bundle loaded first), subsequent
 * calls bail out so we don't clobber an already-populated registry.
 */
export function initSlotRegistry() {
	if ( typeof window === 'undefined' ) return;
	window.neuralForms = window.neuralForms || {};
	if (
		window.neuralForms.slots
		&& typeof window.neuralForms.slots.registerFill === 'function'
	) {
		return;
	}

	const fills       = new Map();   // slotName → Map<fillId, renderFn>
	const subscribers = new Set();

	function notify() {
		subscribers.forEach( function ( fn ) { fn(); } );
	}

	function unregister( slot, id ) {
		const m = fills.get( slot );
		if ( ! m ) return;
		m.delete( id );
		if ( m.size === 0 ) fills.delete( slot );
		notify();
	}

	window.neuralForms.slots = {
		registerFill: function ( slot, id, render ) {
			if ( ! ( slot in SLOTS ) ) {
				if ( typeof console !== 'undefined' && console.warn ) {
					console.warn( '[NeuralForms] Unknown slot name: ' + slot );
				}
				return function () {};
			}
			if ( ! fills.has( slot ) ) fills.set( slot, new Map() );
			fills.get( slot ).set( id, render );
			notify();
			return function () { unregister( slot, id ); };
		},
		unregisterFill: unregister,
		SLOT_NAMES: Object.keys( SLOTS ),
		__getFills: function ( slot ) {
			const m = fills.get( slot );
			return m ? Array.from( m.values() ) : [];
		},
		__subscribe: function ( fn ) {
			subscribers.add( fn );
			return function () { subscribers.delete( fn ); };
		},
	};
}

/**
 * Render every fill registered for a slot, passing fillProps through.
 *
 * - Subscribes to the registry so a late-registering Pro fill triggers
 *   a re-render of this slot.
 * - Returns null when no fill is registered (caller decides whether to
 *   show an upsell, fallback UI, or nothing).
 */
export function ProSlot( { name, fillProps } ) {
	const slots = ( typeof window !== 'undefined' && window.neuralForms?.slots ) || null;
	const [ , bump ] = useState( 0 );

	useEffect( () => {
		if ( ! slots || typeof slots.__subscribe !== 'function' ) return undefined;
		return slots.__subscribe( () => bump( ( v ) => v + 1 ) );
	}, [ slots ] );

	if ( ! slots || typeof slots.__getFills !== 'function' ) return null;
	const renders = slots.__getFills( name );
	if ( renders.length === 0 ) return null;

	const props = fillProps || {};
	return (
		<>
			{ renders.map( ( render, i ) => (
				<Fragment key={ i }>
					{ typeof render === 'function' ? render( props ) : render }
				</Fragment>
			) ) }
		</>
	);
}
