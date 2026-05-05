/**
 * Entries-only React entry.
 *
 * Mounts JUST the Entries inbox into #neural-forms-app. Sibling to
 * builder-entry.js: AdminPanel.php enqueues build/entries.js INSTEAD
 * of build/index.js when the current screen is the entries page, so
 * the rich 1.0.0 index.js bundle keeps owning Forms / Settings /
 * Stats while Entries comes from this rebuildable bundle.
 *
 * Why split?
 *   The 1.0.0 build/index.js is byte-protected — full source parity
 *   has not been restored. Carving Entries off into its own per-screen
 *   bundle lets Lite ship Entries UI changes (Polls/Quizzes folders,
 *   reanalyze button, AI carousel, bulk toolbar) without rebuilding
 *   the rich bundle.
 */

import { createRoot } from '@wordpress/element';
import EntriesScreen from './entries/EntriesScreen';
import './index.css';

const mount = document.getElementById( 'neural-forms-app' );

if ( mount ) {
	const root = createRoot( mount );
	root.render( <EntriesScreen /> );
}
