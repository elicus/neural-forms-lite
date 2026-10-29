/** Entry point for the Neural Forms Payments page (?page=neural-forms-payments). */
import { createRoot } from '@wordpress/element';
import Payments from './screens/Payments';
import { initSlotRegistry } from './slot-registry';
import './settings.css';
import './payments.css';

initSlotRegistry();

document.addEventListener( 'DOMContentLoaded', function () {
	const mount = document.getElementById( 'neural-forms-app' );
	if ( ! mount ) return;
	createRoot( mount ).render( <Payments /> );
} );
