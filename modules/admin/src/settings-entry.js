/**
 * Entry point for the Neural Forms Settings screen.
 *
 * Mounted by AdminPanel::render_page() at #neural-forms-app when the
 * admin is on ?page=neural-forms-settings AND AdminPanel chooses to
 * enqueue this per-screen bundle instead of the rich `build/index.js`.
 * Phase 3 of the `refactor/remove-protected-admin-bundle` work.
 *
 * Independent split bundle — never shares state with the rich admin
 * bundle (build/index.js) or the builder bundle (build/builder.js).
 * Self-contained styles in src/settings.css.
 *
 * Section order matches the live rich UI:
 *   1. Neural Forms Cloud AI
 *   2. Email Settings
 *   3. Notifications
 *   4. CAPTCHA
 *   5. General & Privacy
 *
 * Pro features (business-aware analysis, spam detection) render as
 * upgrade lock cards in Lite when their plugin capabilities are off,
 * and as Pro-filled slot regions when on. Pro registers its fills
 * via `window.neuralForms.slots.registerFill( slot, id, render )`.
 * The rich `build/index.js` initialises the registry on every other
 * screen; this split bundle initialises a runtime-compatible stub
 * below so Pro's fills land in the same place on the Settings page.
 */

import { createRoot } from '@wordpress/element';
import Settings from './screens/Settings';
import './settings.css';

/**
 * Cross-bundle slot registry. Mirrors the runtime contract that the
 * rich admin bundle exposes: registerFill / unregisterFill / SLOT_NAMES.
 * Pro consumes only those three; the `__getFills` / `__subscribe`
 * accessors are private to this bundle's <ProSlot> component.
 *
 * The whitelist keys MUST match the rich bundle's `We` map so a Pro
 * fill that works on Entries also works here.
 */
( function ensureSlotRegistry() {
	if ( typeof window === 'undefined' ) return;
	window.neuralForms = window.neuralForms || {};
	if (
		window.neuralForms.slots
		&& typeof window.neuralForms.slots.registerFill === 'function'
	) {
		return; // Already initialised by another bundle on this page.
	}

	const SLOTS = {
		EntryAiActions:      true,
		EntryReplyActions:   true,
		SettingsSpamPanel:   true,
		SettingsSiteProfile: true,
		SettingsLicense:     true,
	};

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
} )();

document.addEventListener( 'DOMContentLoaded', function () {
	const mount = document.getElementById( 'neural-forms-app' );
	if ( ! mount ) return;
	createRoot( mount ).render( <Settings /> );
} );
