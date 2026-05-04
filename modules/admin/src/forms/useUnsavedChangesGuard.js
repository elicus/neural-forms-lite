/**
 * Unsaved-changes guard with custom-modal support.
 *
 * Two layers, both fire only while `isDirty === true`:
 *
 * Layer 1 — beforeunload (browser-owned):
 *   Catches tab close / reload / URL bar nav / forward / back. Browser
 *   shows its native confirmation; we cannot replace this with a
 *   custom modal because the browser kills any UI before the unload
 *   handler runs.
 *
 * Layer 2 — same-document <a href> click capture:
 *   Catches WordPress sidebar / admin bar / header Analytics link
 *   navigation. When intercepted, we DO NOT call window.confirm —
 *   instead the hook stores the intended URL in `pendingHref` and
 *   the host component (Builder) renders a Neural-Forms-styled
 *   modal whose three actions (Save as Draft / Discard / Stay)
 *   resolve via the methods returned from this hook.
 *
 * Returned API:
 *
 *   pendingHref          : string | null — intercepted URL, host renders modal off this
 *   requestNavigation(h) : programmatically open the modal for a back-button
 *                          or other non-anchor navigation
 *   confirmNavigate()    : detach BOTH listeners imperatively, then go to pendingHref.
 *                          Used by the modal's Save-as-Draft (after save) and
 *                          Discard buttons. Bypassing through state alone is
 *                          racy — the navigation can fire before React detaches
 *                          the listener — so we tear down imperatively.
 *   cancelNavigate()     : Stay here. Clear pendingHref and the "modal already
 *                          showing" ref. Listeners stay armed.
 *
 * Click-handler skips (never intercepts):
 *   - non-primary mouse button
 *   - meta/ctrl/shift/alt — opens new tab/window
 *   - target="_blank" or download attr
 *   - empty href / href starts with "#"
 *   - same origin + pathname + search (in-page hash flips)
 *   - while a modal is already in-flight (pendingRef ensures one-modal-at-a-time)
 */

import { useEffect, useRef, useState } from '@wordpress/element';

export default function useUnsavedChangesGuard( { isDirty } ) {
	const [ pendingHref, setPendingHref ] = useState( null );

	// Refs let the click handler synchronously check "is a modal already
	// open?" without depending on `pendingHref` state (which would be a
	// stale closure unless re-bound on every change). They also let us
	// imperatively remove listeners — needed because navigating via
	// window.location runs BEFORE React's effect cleanup.
	const pendingRef        = useRef( null );
	const beforeUnloadRef   = useRef( null );
	const clickHandlerRef   = useRef( null );

	useEffect( () => {
		if ( ! isDirty ) return undefined;
		if ( typeof window === 'undefined' ) return undefined;

		const onBeforeUnload = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
			return '';
		};

		const onClickCapture = ( event ) => {
			if ( event.button !== 0 ) return;
			if ( event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
				return;
			}

			const anchor =
				event.target && event.target.closest
					? event.target.closest( 'a[href]' )
					: null;
			if ( ! anchor ) return;

			const target = anchor.getAttribute( 'target' );
			if ( target && target.toLowerCase() === '_blank' ) return;
			if ( anchor.hasAttribute( 'download' ) ) return;

			const href = anchor.getAttribute( 'href' );
			if ( ! href ) return;
			if ( href.startsWith( '#' ) ) return;

			// Same-page (origin + path + search) → in-page hash or no-op.
			try {
				const resolved = new URL( anchor.href, window.location.href );
				const current  = new URL( window.location.href );
				if (
					resolved.origin === current.origin &&
					resolved.pathname === current.pathname &&
					resolved.search === current.search
				) {
					return;
				}
			} catch ( _e ) {
				// fall through to interception if URL parsing fails
			}

			// Already showing a modal — drop the click. Latest pending
			// href is preserved; user finishes the existing flow before
			// new ones can queue.
			if ( pendingRef.current ) {
				event.preventDefault();
				event.stopPropagation();
				return;
			}

			event.preventDefault();
			event.stopPropagation();
			pendingRef.current = anchor.href;
			setPendingHref( anchor.href );
		};

		beforeUnloadRef.current = onBeforeUnload;
		clickHandlerRef.current = onClickCapture;

		window.addEventListener( 'beforeunload', onBeforeUnload );
		document.addEventListener( 'click', onClickCapture, true );

		return () => {
			window.removeEventListener( 'beforeunload', onBeforeUnload );
			document.removeEventListener( 'click', onClickCapture, true );
			beforeUnloadRef.current = null;
			clickHandlerRef.current = null;
		};
	}, [ isDirty ] );

	/**
	 * Rip both listeners off the DOM imperatively. Used right before
	 * navigating, so beforeunload doesn't fire a second native prompt
	 * on Discard / Save-as-Draft-then-leave. The useEffect cleanup
	 * runs only on unmount or isDirty flip — neither happens fast
	 * enough when we're about to set window.location.href.
	 */
	const disarm = () => {
		if ( typeof window === 'undefined' ) return;
		if ( beforeUnloadRef.current ) {
			window.removeEventListener( 'beforeunload', beforeUnloadRef.current );
			beforeUnloadRef.current = null;
		}
		if ( clickHandlerRef.current ) {
			document.removeEventListener( 'click', clickHandlerRef.current, true );
			clickHandlerRef.current = null;
		}
	};

	const requestNavigation = ( href ) => {
		if ( ! href ) return;
		if ( pendingRef.current ) return; // one modal at a time
		pendingRef.current = href;
		setPendingHref( href );
	};

	const confirmNavigate = () => {
		const href = pendingRef.current;
		if ( ! href ) return;
		disarm();
		// Synchronous navigation. The page tears down; React state
		// resets on the next mount. No need to clear pendingRef.
		window.location.href = href;
	};

	const cancelNavigate = () => {
		pendingRef.current = null;
		setPendingHref( null );
	};

	return { pendingHref, requestNavigation, confirmNavigate, cancelNavigate };
}
