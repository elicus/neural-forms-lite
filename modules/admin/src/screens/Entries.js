/**
 * Entries screen - Gmail-style inbox.
 *
 * NOT YET PORTED from the original compiled bundle. This stub renders a
 * placeholder so the menu link still resolves to a sensible page rather
 * than a blank mount. Re-implementation needs:
 *
 *   - sidebar (forms + system views: inbox/unread/starred/needs_reply/spam/trash)
 *   - list view with bulk actions, search, date-range filter
 *   - entry detail with reply composer + AI carousel + message thread
 *   - keyboard shortcuts (j/k navigation, e=archive, r=reply, etc.)
 *
 * Reference: docs/STATE.md and CLAUDE.md "Entries (Inbox System)".
 */

import { __ } from '@wordpress/i18n';

export default function Entries() {
	return (
		<div className="neural-forms-screen neural-forms-screen--placeholder">
			<h1>{ __( 'Entries', 'neural-forms-lite' ) }</h1>
			<p>{ __( 'The entries inbox is being rebuilt. The data is safe in the database - it just needs a UI.', 'neural-forms-lite' ) }</p>
		</div>
	);
}
