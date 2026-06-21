/**
 * Entry point for the Neural Forms Stats screen.
 *
 * Mounted by AdminPanel::render_page() at #neural-forms-app when the
 * admin is on ?page=neural-forms-stats AND AdminPanel chooses to
 * enqueue this per-screen bundle instead of the rich `build/index.js`.
 * Phase 2 of the `refactor/remove-protected-admin-bundle` work.
 *
 * Independent split bundle — never shares state with the rich admin
 * bundle (build/index.js) or the builder bundle (build/builder.js).
 * Self-contained styles in src/stats.css.
 */

import { createRoot } from '@wordpress/element';
import Stats from './screens/Stats';
import './stats.css';

document.addEventListener( 'DOMContentLoaded', function () {
	const mount = document.getElementById( 'neural-forms-app' );
	if ( ! mount ) return;
	createRoot( mount ).render( <Stats /> );
} );
