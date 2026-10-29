/**
 * Entry point for the Neural Forms Quotes page (?page=neural-forms-quotes).
 * Independent split bundle; shares settings.css with the Settings screen
 * so the two pages look the same.
 */

import { createRoot } from '@wordpress/element';
import Quotes from './screens/Quotes';
import { initSlotRegistry } from './slot-registry';
import './settings.css';

initSlotRegistry();

document.addEventListener( 'DOMContentLoaded', function () {
	const mount = document.getElementById( 'neural-forms-app' );
	if ( ! mount ) return;
	createRoot( mount ).render( <Quotes /> );
} );
