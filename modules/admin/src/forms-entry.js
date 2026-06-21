/**
 * Entry point for the Neural Forms Forms-list screen.
 *
 * Mounted by AdminPanel::render_page() at #neural-forms-app when the
 * admin is on ?page=neural-forms (the default Forms list view) AND
 * AdminPanel chooses to enqueue this per-screen bundle instead of the
 * rich `build/index.js`. Phase 1 of the
 * `refactor/remove-protected-admin-bundle` work — see CLAUDE.md.
 *
 * Independent split bundle — never shares state with the rich admin
 * bundle (build/index.js) or the builder bundle (build/builder.js).
 * The shortcode-column injector and the new-form-dropdown injector
 * (both in AdminPanel.php) attach to the DOM this bundle emits via
 * the same `.neural-forms-forms-list__*` BEM hooks the rich bundle
 * used, so those PHP-side enhancements continue to work unchanged.
 */

import { createRoot } from '@wordpress/element';
import Forms from './screens/Forms';
import './forms.css';

document.addEventListener( 'DOMContentLoaded', function () {
	const mount = document.getElementById( 'neural-forms-app' );
	if ( ! mount ) return;
	createRoot( mount ).render( <Forms /> );
} );
