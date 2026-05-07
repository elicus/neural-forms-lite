/**
 * Entries screen entry — mounted by AdminPanel::render_page() at
 * #neural-forms-app on ?page=neural-forms-entries. Self-contained
 * styles in src/entries.css. Slot registry is shared with every other
 * per-screen bundle via slot-registry.js so Pro's `EntryAiActions`
 * and `EntryReplyActions` fills land here.
 */

import { createRoot } from '@wordpress/element';
import EntriesScreen from './entries/EntriesScreen';
import { initSlotRegistry } from './slot-registry';
import './entries.css';

initSlotRegistry();

document.addEventListener( 'DOMContentLoaded', function () {
	const mount = document.getElementById( 'neural-forms-app' );
	if ( ! mount ) return;
	createRoot( mount ).render( <EntriesScreen /> );
} );
