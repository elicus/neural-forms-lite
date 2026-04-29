/**
 * Neural Forms admin SPA - entry point.
 *
 * AdminPanel.php enqueues this bundle on every Neural Forms screen and
 * passes the current screen ID via window.neuralFormsAdmin.screen. We
 * mount a single root into #neural-forms-app and let Router.js render
 * the right screen.
 *
 * No client-side URL routing - screen identity is decided in PHP from
 * the WP admin's ?page= query param, so deep links / browser refresh
 * always land on the right screen without us re-parsing the URL here.
 */

import { createRoot } from '@wordpress/element';
import Router from './Router';
import './index.css';

const mount = document.getElementById( 'neural-forms-app' );

if ( mount ) {
	const root   = createRoot( mount );
	const data   = window.neuralFormsAdmin || {};
	const screen = data.screen || 'forms';

	root.render( <Router screen={ screen } data={ data } /> );
}
