/**
 * Entries-only React entry (Phase 5 of refactor/remove-protected-
 * admin-bundle).
 *
 * Mounted by AdminPanel::render_page() at #neural-forms-app when the
 * admin is on ?page=neural-forms-entries AND the
 * `NEURAL_FORMS_USE_ENTRIES_SOURCE_UI` constant is true. Default is
 * false: Lite continues to ship the rich `build/index.js` Entries UI
 * for everyone until QA confirms parity.
 *
 * Self-contained styles in src/entries.css (the protected
 * `build/index.css` is NOT enqueued on this code path). Slot registry
 * is shared with every other split bundle via slot-registry.js so
 * Pro's `EntryAiActions` and `EntryReplyActions` fills land here.
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
