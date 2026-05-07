/**
 * Bulk-action toolbar for the entries list.
 *
 * Renders a row above the table with the current selection count + a
 * set of action buttons. Action buttons are derived from the current
 * view/scope so destructive options stay context-aware:
 *
 *   - In Trash:  "Restore", "Delete forever"
 *   - In Spam:   "Not spam" (restore), "Delete forever"
 *   - Else:      "Mark read/unread", "Star/Unstar", "Spam", "Trash"
 *
 * Selection is owned by the parent. The toolbar only emits intent;
 * the parent dispatches POST /entries/bulk and refetches counts.
 *
 * Props:
 *   selection      Set<number> of selected entry IDs
 *   onClear        () => void
 *   onAction       (action: string) => Promise<void>
 *   busy           boolean — disables buttons while a request is in flight
 *   view           current sidebar view id (for context-aware actions)
 */

import { __ } from '@wordpress/i18n';

function buttons( view ) {
	if ( view === 'trash' ) {
		return [
			{ id: 'restore', label: 'Restore' },
			{ id: 'delete',  label: 'Delete forever', destructive: true },
		];
	}
	if ( view === 'spam' ) {
		return [
			{ id: 'restore', label: 'Not spam' },
			{ id: 'delete',  label: 'Delete forever', destructive: true },
		];
	}
	return [
		{ id: 'mark_read',   label: 'Mark read'   },
		{ id: 'mark_unread', label: 'Mark unread' },
		{ id: 'star',        label: 'Star'        },
		{ id: 'unstar',      label: 'Unstar'      },
		{ id: 'spam',        label: 'Spam'        },
		{ id: 'trash',       label: 'Trash'       },
	];
}

export default function BulkToolbar( { selection, onClear, onAction, busy, view } ) {
	const count = selection?.size || 0;
	if ( count === 0 ) return null;

	const acts = buttons( view );

	return (
		<div className="neural-forms-entries-bulk-toolbar">
			<div className="neural-forms-entries-bulk-toolbar__count">
				{ count === 1
					? __( '1 selected', 'neural-forms-lite' )
					: `${ count } ${ __( 'selected', 'neural-forms-lite' ) }` }
			</div>
			<div className="neural-forms-entries-bulk-toolbar__actions">
				{ acts.map( ( a ) => (
					<button
						key={ a.id }
						type="button"
						className={
							'neural-forms-entries-btn neural-forms-entries-bulk-toolbar__btn'
							+ ( a.destructive ? ' neural-forms-entries-bulk-toolbar__btn--destructive' : '' )
						}
						disabled={ busy }
						onClick={ () => {
							if ( a.id === 'delete' ) {
								// eslint-disable-next-line no-alert
								if ( ! window.confirm( __( 'Permanently delete these entries? This cannot be undone.', 'neural-forms-lite' ) ) ) return;
							}
							onAction( a.id );
						} }
					>
						{ __( a.label, 'neural-forms-lite' ) }
					</button>
				) ) }
				<button
					type="button"
					className="neural-forms-entries-btn neural-forms-entries-bulk-toolbar__btn neural-forms-entries-bulk-toolbar__btn--ghost"
					onClick={ onClear }
					disabled={ busy }
				>
					{ __( 'Clear', 'neural-forms-lite' ) }
				</button>
			</div>
		</div>
	);
}
