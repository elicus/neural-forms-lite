/**
 * Gmail-ish keyboard navigation hook for the entries inbox.
 *
 * Bindings (active only when no input/textarea/contenteditable holds focus
 * and the user isn't holding a modifier — we don't want to fight the
 * browser's own shortcuts):
 *
 *   j / ↓     next row
 *   k / ↑     previous row
 *   Enter     open / collapse the highlighted row
 *   Esc       → onClose (the screen decides: clear selection on the list;
 *             the detail panel handles its own Escape to close)
 *   r         mark read     (POST /entries/{id} status=read)
 *   u         mark unread   (POST /entries/{id} status=new)
 *   s / *     toggle star
 *   #         move to trash (POST /entries/{id} status=trash)
 *   !         move to spam  (POST /entries/{id} status=spam)
 *
 * The hook is decoupled from the screen so a future inline-detail
 * tray could swap in a different binding set without touching the
 * list rendering.
 *
 * Args:
 *   ids        ordered array of entry ids on the current page
 *   focusedId  id the shortcuts act on: the highlighted list row, or the
 *              open entry while a detail is showing (or null)
 *   actions    object of { onMove, onOpen, onClose, onMarkRead, onMarkUnread,
 *                          onToggleStar, onTrash, onSpam }
 */

import { useEffect } from '@wordpress/element';

function isEditing( target ) {
	if ( ! target ) return false;
	const tag = ( target.tagName || '' ).toLowerCase();
	if ( tag === 'input' || tag === 'textarea' || tag === 'select' ) return true;
	if ( target.isContentEditable ) return true;
	return false;
}

export default function useInboxKeyboard( { ids, focusedId, actions } ) {
	useEffect( () => {
		const handler = ( e ) => {
			if ( e.metaKey || e.ctrlKey || e.altKey ) return;
			if ( isEditing( e.target ) ) return;

			const list = Array.isArray( ids ) ? ids : [];
			const idx = focusedId != null ? list.indexOf( focusedId ) : -1;
			const id  = focusedId;

			switch ( e.key ) {
				case 'j':
				case 'ArrowDown': {
					if ( list.length === 0 ) return;
					const next = idx < 0 ? list[ 0 ] : list[ Math.min( list.length - 1, idx + 1 ) ];
					actions.onMove?.( next );
					e.preventDefault();
					return;
				}
				case 'k':
				case 'ArrowUp': {
					if ( list.length === 0 ) return;
					const prev = idx <= 0 ? list[ 0 ] : list[ idx - 1 ];
					actions.onMove?.( prev );
					e.preventDefault();
					return;
				}
				case 'Enter':
					if ( id != null ) { actions.onOpen?.( id ); e.preventDefault(); }
					return;
				case 'Escape':
					actions.onClose?.();
					return;
				case 'r':
					if ( id != null ) { actions.onMarkRead?.( id ); }
					return;
				case 'u':
					if ( id != null ) { actions.onMarkUnread?.( id ); }
					return;
				case 's':
				case '*':
					if ( id != null ) { actions.onToggleStar?.( id ); }
					return;
				case '#':
					if ( id != null ) { actions.onTrash?.( id ); }
					return;
				case '!':
					if ( id != null ) { actions.onSpam?.( id ); }
					return;
				default:
					return;
			}
		};

		window.addEventListener( 'keydown', handler );
		return () => window.removeEventListener( 'keydown', handler );
	}, [ ids, focusedId, actions ] );
}
