/**
 * Entry point for the Neural Forms Results screen.
 *
 * Mounted by AdminPanel::render_results_page() at #neural-forms-results-app.
 * Independent split bundle — never shares state with the rich admin
 * bundle (build/index.js) or the builder bundle (build/builder.js).
 *
 * The Results screen is intentionally screen-shaped and extensible:
 * v1 ships only Poll Results, but the layout (Header + Overview cards
 * + Sections) is positioned so future Quiz / Rating / Drop-off / AI
 * insights can land as additional Section components without re-doing
 * the page chrome.
 */

import { createRoot } from '@wordpress/element';
import ResultsApp from './results/ResultsApp';
import './results.css';

document.addEventListener( 'DOMContentLoaded', function () {
	const mount = document.getElementById( 'neural-forms-results-app' );
	if ( ! mount ) return;
	createRoot( mount ).render( <ResultsApp /> );
} );
