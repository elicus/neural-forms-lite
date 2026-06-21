/**
 * Entry point for the Neural Forms Integrations screen.
 *
 * Mounted by AdminPanel::render_page() at #neural-forms-app when the
 * admin is on ?page=neural-forms-integrations. Independent split
 * bundle — never shares state with the rich admin bundle, the
 * builder bundle, or any other per-screen bundle.
 *
 * The page reads `window.neuralFormsAdmin.plugin.capabilities.integrations`
 * to decide between two render modes:
 *   - `false` (Lite-only): renders a single Pro-upsell card.
 *   - `true`  (Pro active): renders the provider tab list (HubSpot /
 *     WhatsApp / Mailchimp / Google Forms), each showing a "Coming
 *     soon" card while the actual provider integrations are wired up.
 */

import { createRoot } from '@wordpress/element';
import Integrations from './screens/Integrations';
import { initSlotRegistry } from './slot-registry';
import './integrations.css';

initSlotRegistry();

document.addEventListener( 'DOMContentLoaded', function () {
	const mount = document.getElementById( 'neural-forms-app' );
	if ( ! mount ) return;
	createRoot( mount ).render( <Integrations /> );
} );
