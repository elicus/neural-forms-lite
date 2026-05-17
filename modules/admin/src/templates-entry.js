/**
 * Entry point for the Neural Forms Templates screen.
 *
 * Mounted by AdminPanel::render_page() at #neural-forms-app when the
 * admin is on ?page=neural-forms-templates. Independent split bundle
 * — never shares state with the rich admin bundle (build/index.js).
 *
 * Backed by Lite REST:
 *   GET  /neural-forms/v1/templates              → catalog list
 *   POST /neural-forms/v1/templates/import       → import a manifest
 */

import { createRoot } from '@wordpress/element';
import Templates from './screens/Templates';
import './templates.css';

document.addEventListener( 'DOMContentLoaded', function () {
	const mount = document.getElementById( 'neural-forms-app' );
	if ( ! mount ) return;
	createRoot( mount ).render( <Templates /> );
} );
