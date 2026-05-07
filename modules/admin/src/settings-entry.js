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
 * and as Pro-filled slot regions when on. Pro registers its fills via
 * `window.neuralForms.slots.registerFill( slot, id, render )`. The
 * registry is shared with every other split bundle via slot-registry.js.
 */

import { createRoot } from '@wordpress/element';
import Settings from './screens/Settings';
import { initSlotRegistry } from './slot-registry';
import './settings.css';

initSlotRegistry();

document.addEventListener( 'DOMContentLoaded', function () {
	const mount = document.getElementById( 'neural-forms-app' );
	if ( ! mount ) return;
	createRoot( mount ).render( <Settings /> );
} );
