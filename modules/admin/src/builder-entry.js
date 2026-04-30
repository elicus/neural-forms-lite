/**
 * Builder-only React entry.
 *
 * Mounts JUST the Form Builder. Imports nothing from screens/ or
 * Router.js so this bundle is independent of the Forms / Entries /
 * Settings / Stats source - which is intentional during the 1.1.0
 * split-bundle migration: those screens still ship from the rich
 * 1.0.0 build/index.js bundle while the new builder ships separately
 * here.
 *
 * Mount target:
 *   This bundle uses the SAME #neural-forms-app element the rich
 *   admin uses, but PHP swaps the enqueue: when the URL is the form
 *   editor (?page=neural-forms&edit=N or &new=1), AdminPanel.php
 *   skips index.js entirely and enqueues this bundle instead. So the
 *   two bundles are never both alive on the same page.
 */

import { createRoot } from '@wordpress/element';
import Builder from './forms/Builder';
import './builder.css';

const mount = document.getElementById( 'neural-forms-app' );

if ( mount ) {
	const params  = new URLSearchParams( window.location.search );
	const editId  = parseInt( params.get( 'edit' ) || '0', 10 );
	const isNew   = params.get( 'new' ) === '1';

	if ( editId > 0 || isNew ) {
		const root = createRoot( mount );
		root.render(
			<Builder formId={ editId > 0 ? editId : 0 } isNew={ ! ( editId > 0 ) } />
		);
	}
}
