/**
 * Entry point for the Neural Forms CF7 Import screen.
 *
 * Mounted by AdminPanel::render_page() at #neural-forms-app when the
 * admin is on ?page=neural-forms-migration AND AdminPanel chooses to
 * enqueue this per-screen bundle instead of the rich `build/index.js`.
 * Phase 4 of the `refactor/remove-protected-admin-bundle` work.
 *
 * Three-state UI:
 *   1. List   — GET /migration/cf7/forms        → table of CF7 forms
 *   2. Preview — GET /migration/cf7/preview/{id} → mapped fields + warnings
 *   3. Result — POST /migration/cf7/import/{id} → success / failure / duplicate
 *
 * No Pro coupling: the migration feature is Lite-only.
 */

import { createRoot } from '@wordpress/element';
import Import from './screens/Import';
import './import.css';

document.addEventListener( 'DOMContentLoaded', function () {
	const mount = document.getElementById( 'neural-forms-app' );
	if ( ! mount ) return;
	createRoot( mount ).render( <Import /> );
} );
