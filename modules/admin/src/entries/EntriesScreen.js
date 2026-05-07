/**
 * Entries inbox — the main screen controller.
 *
 * Owns:
 *   - Sidebar selection state ({ type, formId, view, smartFilter })
 *   - Search + sort + page state
 *   - List fetch (/entries) and counts fetch (/entries/counts)
 *   - Per-form sidebar list (/forms with embedded counts when available)
 *   - Detail-pane state (open id, fetched body, AI carousel busy slot)
 *   - REST glue for: PATCH /entries/{id}, POST /entries/bulk, POST /reply,
 *     POST /reanalyze, POST /regenerate-reply, POST /polish-reply,
 *     POST /ai-responses/{id}/finalize
 *
 * Composition tree:
 *   EntriesScreen
 *     ├─ EntriesSidebar       sidebar (All Forms / Forms / Smart Filters)
 *     ├─ BulkToolbar          shown when selection > 0
 *     ├─ EntriesList          rows         (list mode)
 *     └─ EntryDetailPanel     full-width   (detail mode, URL has entry_id)
 *
 * Selection model — three shapes, all four keys always present:
 *
 *   { type: 'all'  , formId: null, view: '<system view>', smartFilter: null }
 *   { type: 'form' , formId: N   , view: '<system view>', smartFilter: null }
 *   { type: 'smart', formId: null, view: 'inbox',         smartFilter: '<id>' }
 *
 * REST mapping:
 *   all   → ?view=<view>
 *   form  → ?form_id=<formId>&view=<view>
 *   smart → ?view=<smartFilter>     (smart filter ids are first-class views server-side)
 *
 * URL contract (replaceState for selection, pushState for entry detail):
 *   form_id=N    → form selection
 *   view=<id>    → child view under form selection, or All-Forms / Smart view
 *   entry_id=N   → detail mode (overlays the list pane)
 *
 * Phase 5 of refactor/remove-protected-admin-bundle. Default All-Forms
 * selection uses bare ?view=inbox (no folder= alias). Polls/Quizzes
 * smart folders intentionally absent.
 */

import { useEffect, useMemo, useRef, useState, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import api from '../api';

import EntriesSidebar  from './EntriesSidebar';
import EntriesList     from './EntriesList';
import EntryDetail     from './EntryDetail';
import BulkToolbar     from './BulkToolbar';
import useInboxKeyboard from './useInboxKeyboard';
import { SYSTEM_VIEWS, SMART_FILTERS } from './constants';
import { buildFieldLabels, errorMessage, planErrorPayload, safetyErrorPayload } from './helpers';

// Static lookup sets so URL → selection restoration is O(1) and
// stays in sync with the sidebar's authoritative lists in constants.js.
const SYSTEM_VIEW_IDS  = new Set( SYSTEM_VIEWS.map( ( v ) => v.id ) );
const SMART_FILTER_IDS = new Set( SMART_FILTERS.map( ( v ) => v.id ) );

const DEFAULT_SELECTION = {
	type:        'all',
	formId:      null,
	view:        'inbox',
	smartFilter: null,
};

/**
 * Map a /reanalyze, /regenerate-reply, or /polish-reply error to the
 * inline message we show. SafetyGate errors get their own copy so the
 * user understands no credits were deducted and no provider call ran;
 * plan-locked errors fall through to the upgrade-modal contract;
 * everything else uses errorMessage's fallback.
 */
function aiErrorMessage( err, fallback ) {
	const safety = safetyErrorPayload( err );
	if ( safety ) {
		if ( safety.kind === 'unavailable' ) {
			return __( 'AI safety check is temporarily unavailable. Please try again in a few minutes.', 'neural-forms-lite' );
		}
		const noCharge = safety.usage && safety.usage.credits_deducted === false
			? ' ' + __( 'No credits were deducted.', 'neural-forms-lite' )
			: '';
		return __( 'AI skipped this content because it appears unsafe.', 'neural-forms-lite' ) + noCharge;
	}
	const plan = planErrorPayload( err );
	if ( plan ) return plan.message || errorMessage( err, fallback );
	return errorMessage( err, fallback );
}

function InboxIcon() {
	return (
		<svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor"
			strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
			<path d="M22 12h-6l-2 3h-4l-2-3H2" />
			<path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" />
		</svg>
	);
}

function EmptyState() {
	return (
		<div className="neural-forms-entries-empty">
			<div className="neural-forms-entries-empty__icon"><InboxIcon /></div>
			<p className="neural-forms-entries-empty__title">
				{ __( 'Your inbox is quiet', 'neural-forms-lite' ) }
			</p>
			<p className="neural-forms-entries-empty__hint">
				{ __( 'New submissions will land here the moment they come in.', 'neural-forms-lite' ) }
			</p>
		</div>
	);
}

// Convert a sidebar selection into the URLSearchParams shape the
// /entries endpoint expects. Bare `view` for the All-Forms default —
// no folder= alias.
function selectionToQuery( sel ) {
	const p = new URLSearchParams();
	if ( ! sel ) {
		p.set( 'view', 'inbox' );
		return p;
	}
	switch ( sel.type ) {
		case 'form':
			p.set( 'form_id', String( sel.formId ) );
			p.set( 'view',    sel.view || 'inbox' );
			return p;
		case 'smart':
			p.set( 'view', sel.smartFilter || 'high_priority' );
			return p;
		case 'all':
		default:
			p.set( 'view', sel.view || 'inbox' );
			return p;
	}
}

function readEntryIdFromUrl() {
	if ( typeof window === 'undefined' ) return 0;
	try {
		const raw = new URL( window.location.href ).searchParams.get( 'entry_id' );
		const n = Number( raw );
		return Number.isFinite( n ) && n > 0 ? n : 0;
	} catch ( _err ) {
		return 0;
	}
}

function readSelectionFromUrl() {
	if ( typeof window === 'undefined' ) return { ...DEFAULT_SELECTION };
	try {
		const params  = new URL( window.location.href ).searchParams;
		const formIdN = Number( params.get( 'form_id' ) );
		const view    = params.get( 'view' ) || '';
		if ( Number.isFinite( formIdN ) && formIdN > 0 ) {
			return {
				type:        'form',
				formId:      formIdN,
				view:        SYSTEM_VIEW_IDS.has( view ) ? view : 'inbox',
				smartFilter: null,
			};
		}
		if ( SMART_FILTER_IDS.has( view ) ) {
			return { type: 'smart', formId: null, view: 'inbox', smartFilter: view };
		}
		if ( SYSTEM_VIEW_IDS.has( view ) ) {
			return { type: 'all', formId: null, view, smartFilter: null };
		}
	} catch ( _err ) { /* fall through */ }
	return { ...DEFAULT_SELECTION };
}

const VIEW_LABELS = {
	inbox: 'Inbox', unread: 'Unread', needs_reply: 'Needs Reply',
	starred: 'Starred', spam: 'Spam', trash: 'Trash',
};
const SMART_LABELS = { high_priority: 'High Priority', spam_suspected: 'Spam Suspected' };

function selectionTitle( sel, forms ) {
	if ( ! sel ) return __( 'Inbox', 'neural-forms-lite' );
	if ( sel.type === 'smart' ) {
		return __( SMART_LABELS[ sel.smartFilter ] || 'Inbox', 'neural-forms-lite' );
	}
	if ( sel.type === 'form' ) {
		const f = ( forms || [] ).find( ( x ) => x.id === sel.formId );
		return f?.name || `#${ sel.formId }`;
	}
	return __( VIEW_LABELS[ sel.view ] || 'Inbox', 'neural-forms-lite' );
}

export default function EntriesScreen() {
	const adminData = window.neuralFormsAdmin || {};
	const capabilities = adminData.plugin?.capabilities || {};

	const [ selection, setSelection ] = useState( readSelectionFromUrl );
	const [ search,    setSearch    ] = useState( '' );
	const [ sortOrder, setSortOrder ] = useState( 'desc' );

	const [ entries,        setEntries        ] = useState( [] );
	const [ entriesLoading, setEntriesLoading ] = useState( false );
	const [ entriesError,   setEntriesError   ] = useState( '' );

	const [ counts, setCounts ] = useState( {} );

	const [ forms,        setForms        ] = useState( [] );
	const [ formsLoading, setFormsLoading ] = useState( false );

	const [ openEntryId, setOpenEntryId ] = useState( readEntryIdFromUrl );
	const [ openEntry,   setOpenEntry   ] = useState( null );
	const [ openLoading, setOpenLoading ] = useState( false );
	const [ openError,   setOpenError   ] = useState( '' );

	const [ focusedId, setFocusedId ] = useState( null );

	const [ selectedIds,    setSelectedIds    ] = useState( () => new Set() );
	const [ bulkBusy,       setBulkBusy       ] = useState( false );
	const [ bulkError,      setBulkError      ] = useState( '' );

	const [ exporting,    setExporting    ] = useState( false );
	const [ exportError,  setExportError  ] = useState( '' );

	const [ debouncedSearch, setDebouncedSearch ] = useState( '' );

	const [ reanalyzing,    setReanalyzing    ] = useState( false );
	const [ reanalyzeError, setReanalyzeError ] = useState( '' );
	const [ composerBusy,   setComposerBusy   ] = useState( null );
	const [ composerError,  setComposerError  ] = useState( '' );
	const [ carouselBusy,   setCarouselBusy   ] = useState( null );

	useEffect( () => {
		const t = setTimeout( () => setDebouncedSearch( search.trim() ), 250 );
		return () => clearTimeout( t );
	}, [ search ] );

	const filterKey = useMemo(
		() => JSON.stringify( selection ) + '|' + debouncedSearch + '|' + sortOrder,
		[ selection, debouncedSearch, sortOrder ]
	);
	const lastFilterKey = useRef( filterKey );
	useEffect( () => {
		if ( lastFilterKey.current !== filterKey ) {
			lastFilterKey.current = filterKey;
			setOpenEntryId( 0 );
			setOpenEntry( null );
			setSelectedIds( new Set() );
		}
	}, [ filterKey ] );

	// Forms list for the per-form sidebar. FormsEndpoint returns
	// { items: [...], total: N } — NOT a bare array — because
	// FormRepository::list() shapes the response that way. Walk both
	// shapes defensively in case a future caller normalises the wire
	// format. (Same pattern the rich bundle uses.)
	useEffect( () => {
		let cancelled = false;
		setFormsLoading( true );
		api( '/forms?per_page=200&status=any' ).then( ( res ) => {
			if ( cancelled ) return;
			const list = Array.isArray( res?.items )
				? res.items
				: ( Array.isArray( res ) ? res : [] );
			setForms( list );
			setFormsLoading( false );
		} ).catch( () => {
			if ( cancelled ) return;
			setForms( [] );
			setFormsLoading( false );
		} );
		return () => { cancelled = true; };
	}, [] );

	// Counts — refetched whenever the filter scope changes so badges
	// update after a bulk action or status flip. When a form is the
	// selected scope, the response is form-scoped (so the expanded
	// form's child rows show the right inbox/unread/etc numbers);
	// otherwise it's site-wide.
	const refetchCounts = useCallback( () => {
		const params = new URLSearchParams();
		if ( selection?.type === 'form' ) {
			params.set( 'form_id', String( selection.formId ) );
		}
		const path = '/entries/counts' + ( params.toString() ? '?' + params.toString() : '' );
		api( path ).then( ( res ) => {
			if ( ! res || typeof res !== 'object' ) return;
			setCounts( res );
		} ).catch( () => { /* counts are non-critical */ } );
	}, [ selection ] );

	useEffect( () => { refetchCounts(); }, [ refetchCounts ] );

	// Main list fetch.
	useEffect( () => {
		let cancelled = false;
		setEntriesLoading( true );
		setEntriesError( '' );

		const params = selectionToQuery( selection );
		params.set( 'sort_order', sortOrder );
		params.set( 'per_page',   '50' );
		if ( debouncedSearch ) params.set( 'search', debouncedSearch );

		api( '/entries?' + params.toString() ).then( ( res ) => {
			if ( cancelled ) return;
			const list = Array.isArray( res ) ? res : [];
			setEntries( list );
			setEntriesLoading( false );
			if ( list.length > 0 && ( focusedId == null || ! list.find( ( e ) => e.id === focusedId ) ) ) {
				setFocusedId( list[ 0 ].id );
			} else if ( list.length === 0 ) {
				setFocusedId( null );
			}
		} ).catch( ( err ) => {
			if ( cancelled ) return;
			setEntries( [] );
			setEntriesError( errorMessage( err, __( 'Could not load entries.', 'neural-forms-lite' ) ) );
			setEntriesLoading( false );
		} );

		return () => { cancelled = true; };
	}, [ selection, debouncedSearch, sortOrder ] );

	// URL-driven detail routing + selection restoration. The presence
	// of `entry_id` switches the right pane to detail; `form_id` /
	// `view` restore the sidebar selection on browser back/forward
	// and on direct-URL load. Selection changes use replaceState (no
	// history pollution per click); detail open uses pushState so
	// browser back returns to the list.
	useEffect( () => {
		const sync = () => {
			setOpenEntryId( readEntryIdFromUrl() );
			setSelection( readSelectionFromUrl() );
		};
		window.addEventListener( 'popstate', sync );
		return () => window.removeEventListener( 'popstate', sync );
	}, [] );

	// Wrap setSelection so every sidebar click also writes the new
	// scope to the URL — direct page reloads / shared bookmarks land
	// the user in the same folder. We use replaceState so a chain of
	// sidebar clicks doesn't bloat history.
	const updateSelection = useCallback( ( next ) => {
		setSelection( next );
		try {
			const url = new URL( window.location.href );
			url.searchParams.delete( 'form_id' );
			url.searchParams.delete( 'view' );
			if ( next?.type === 'form' ) {
				url.searchParams.set( 'form_id', String( next.formId ) );
				url.searchParams.set( 'view',    next.view || 'inbox' );
			} else if ( next?.type === 'smart' ) {
				url.searchParams.set( 'view', next.smartFilter );
			} else {
				url.searchParams.set( 'view', next?.view || 'inbox' );
			}
			window.history.replaceState( {}, '', url.toString() );
		} catch ( _err ) { /* URL writes are best-effort */ }
	}, [] );

	// Fetch detail whenever the URL-driven entry id changes.
	useEffect( () => {
		if ( openEntryId === 0 ) {
			setOpenEntry( null );
			setOpenError( '' );
			setOpenLoading( false );
			return undefined;
		}
		let cancelled = false;
		setOpenEntry( null );
		setOpenError( '' );
		setOpenLoading( true );
		api( `/entries/${ openEntryId }` ).then( ( res ) => {
			if ( cancelled ) return;
			setOpenEntry( res );
			setOpenLoading( false );
		} ).catch( ( err ) => {
			if ( cancelled ) return;
			setOpenError( errorMessage( err, __( 'Could not load entry.', 'neural-forms-lite' ) ) );
			setOpenLoading( false );
		} );
		return () => { cancelled = true; };
	}, [ openEntryId ] );

	// Push entry_id onto the URL (preserving current scope's form_id /
	// view for a shareable bookmark) and let the popstate-driven sync
	// re-render the right pane as the detail view.
	const openEntryDetail = useCallback( ( id ) => {
		try {
			const url = new URL( window.location.href );
			url.searchParams.set( 'entry_id', String( id ) );
			url.searchParams.delete( 'form_id' );
			url.searchParams.delete( 'view' );
			if ( selection?.type === 'form' ) {
				url.searchParams.set( 'form_id', String( selection.formId ) );
				url.searchParams.set( 'view',    selection.view || 'inbox' );
			} else if ( selection?.type === 'smart' ) {
				url.searchParams.set( 'view', selection.smartFilter );
			} else {
				url.searchParams.set( 'view', selection?.view || 'inbox' );
			}
			window.history.pushState( { nfDetail: id }, '', url.toString() );
		} catch ( _err ) { /* best-effort URL write */ }
		setOpenEntryId( id );
	}, [ selection ] );

	// Close the detail. If we pushed the detail onto history (normal
	// click flow), step back so the previous list URL is restored
	// without bloating history. If the user direct-loaded a URL with
	// entry_id (no nfDetail marker on history.state), replaceState
	// removes entry_id in place so browser Back doesn't take them
	// back into the same detail.
	const closeDetail = useCallback( () => {
		const state = ( window.history && window.history.state ) || null;
		if ( state && state.nfDetail ) {
			window.history.back();
			// popstate-driven sync will reset openEntryId.
			return;
		}
		try {
			const url = new URL( window.location.href );
			url.searchParams.delete( 'entry_id' );
			window.history.replaceState( {}, '', url.toString() );
		} catch ( _err ) { /* best-effort URL write */ }
		setOpenEntryId( 0 );
	}, [] );

	const patchEntry = useCallback( ( id, fields ) => {
		return api( `/entries/${ id }`, { method: 'PATCH', data: fields } ).then( ( res ) => {
			setEntries( ( prev ) => prev.map( ( e ) => e.id === id ? { ...e, ...res } : e ) );
			if ( openEntryId === id ) {
				setOpenEntry( ( prev ) => prev ? { ...prev, ...res } : prev );
			}
			refetchCounts();
			return res;
		} );
	}, [ openEntryId, refetchCounts ] );

	const toggleStar = useCallback( ( entry ) => {
		const next = ( entry.is_starred === 1 || entry.is_starred === true ) ? 0 : 1;
		patchEntry( entry.id, { is_starred: next } ).catch( () => { /* surfaced via inline error */ } );
	}, [ patchEntry ] );

	const dispatchBulk = useCallback( ( action ) => {
		if ( selectedIds.size === 0 ) return Promise.resolve();
		setBulkBusy( true );
		setBulkError( '' );
		return api( '/entries/bulk', {
			method: 'POST',
			data: { ids: Array.from( selectedIds ), action },
		} ).then( () => {
			setSelectedIds( new Set() );
			setBulkBusy( false );
			refetchCounts();
			const params = selectionToQuery( selection );
			params.set( 'sort_order', sortOrder );
			params.set( 'per_page',   '50' );
			if ( debouncedSearch ) params.set( 'search', debouncedSearch );
			return api( '/entries?' + params.toString() ).then( ( res ) => {
				setEntries( Array.isArray( res ) ? res : [] );
			} );
		} ).catch( ( err ) => {
			setBulkBusy( false );
			setBulkError( errorMessage( err, __( 'Bulk action failed.', 'neural-forms-lite' ) ) );
		} );
	}, [ selectedIds, selection, debouncedSearch, sortOrder, refetchCounts ] );

	const reanalyze = useCallback( () => {
		if ( ! openEntry ) return Promise.resolve();
		setReanalyzing( true );
		setReanalyzeError( '' );
		return api( `/entries/${ openEntry.id }/reanalyze`, { method: 'POST' } ).then( () => {
			setReanalyzing( false );
			return api( `/entries/${ openEntry.id }` ).then( ( fresh ) => {
				setOpenEntry( fresh );
				setEntries( ( prev ) => prev.map( ( e ) => e.id === openEntry.id ? { ...e, ...fresh } : e ) );
			} );
		} ).catch( ( err ) => {
			setReanalyzing( false );
			setReanalyzeError( aiErrorMessage( err, __( 'Reanalyze failed.', 'neural-forms-lite' ) ) );
		} );
	}, [ openEntry ] );

	const regenerateReply = useCallback( () => {
		if ( ! openEntry ) return Promise.reject();
		setComposerBusy( 'regenerate' );
		setComposerError( '' );
		return api( `/entries/${ openEntry.id }/regenerate-reply`, { method: 'POST' } ).then( ( res ) => {
			setComposerBusy( null );
			return api( `/entries/${ openEntry.id }` ).then( ( fresh ) => {
				setOpenEntry( fresh );
				return res;
			} );
		} ).catch( ( err ) => {
			setComposerBusy( null );
			setComposerError( aiErrorMessage( err, __( 'Regenerate failed.', 'neural-forms-lite' ) ) );
			throw err;
		} );
	}, [ openEntry ] );

	const polishReply = useCallback( ( text ) => {
		if ( ! openEntry ) return Promise.reject();
		setComposerBusy( 'polish' );
		setComposerError( '' );
		return api( `/entries/${ openEntry.id }/polish-reply`, { method: 'POST', data: { text } } ).then( ( res ) => {
			setComposerBusy( null );
			api( `/entries/${ openEntry.id }` ).then( ( fresh ) => setOpenEntry( fresh ) ).catch( () => {} );
			return res;
		} ).catch( ( err ) => {
			setComposerBusy( null );
			setComposerError( aiErrorMessage( err, __( 'Polish failed.', 'neural-forms-lite' ) ) );
			throw err;
		} );
	}, [ openEntry ] );

	const sendReply = useCallback( ( payload ) => {
		if ( ! openEntry ) return Promise.reject();
		setComposerBusy( 'send' );
		setComposerError( '' );
		return api( `/entries/${ openEntry.id }/reply`, { method: 'POST', data: payload } ).then( () => {
			setComposerBusy( null );
			return api( `/entries/${ openEntry.id }` ).then( ( fresh ) => {
				setOpenEntry( fresh );
				setEntries( ( prev ) => prev.map( ( e ) => e.id === openEntry.id ? { ...e, status: fresh.status, needs_reply: fresh.needs_reply } : e ) );
				refetchCounts();
			} );
		} ).catch( ( err ) => {
			setComposerBusy( null );
			setComposerError( errorMessage( err, __( 'Send failed.', 'neural-forms-lite' ) ) );
		} );
	}, [ openEntry, refetchCounts ] );

	const finalizeDraft = useCallback( ( draftId ) => {
		if ( ! openEntry ) return Promise.resolve();
		setCarouselBusy( draftId );
		return api( `/entries/${ openEntry.id }/ai-responses/${ draftId }/finalize`, { method: 'POST' } ).then( () => {
			setCarouselBusy( null );
			return api( `/entries/${ openEntry.id }` ).then( ( fresh ) => setOpenEntry( fresh ) );
		} ).catch( () => {
			setCarouselBusy( null );
		} );
	}, [ openEntry ] );

	const exportCsv = () => {
		setExporting( true );
		setExportError( '' );

		const params = selectionToQuery( selection );
		params.set( 'sort_order', sortOrder );
		if ( debouncedSearch ) params.set( 'search', debouncedSearch );

		apiFetch( {
			path:  '/neural-forms/v1/entries/export?' + params.toString(),
			parse: false,
		} ).then( ( resp ) => resp.blob() ).then( ( blob ) => {
			const url  = URL.createObjectURL( blob );
			const a    = document.createElement( 'a' );
			const stamp = new Date().toISOString().slice( 0, 10 );
			a.href     = url;
			a.download = `neural-forms-${ selection?.id || 'all' }-${ stamp }.csv`;
			document.body.appendChild( a );
			a.click();
			document.body.removeChild( a );
			URL.revokeObjectURL( url );
			setExporting( false );
		} ).catch( ( err ) => {
			setExportError( errorMessage( err, __( 'Export failed.', 'neural-forms-lite' ) ) );
			setExporting( false );
		} );
	};

	const ids = useMemo( () => entries.map( ( e ) => e.id ), [ entries ] );

	useInboxKeyboard( {
		ids,
		focusedId,
		actions: {
			onMove:        setFocusedId,
			onOpen:        ( id ) => openEntryDetail( id ),
			onClose:       closeDetail,
			onMarkRead:    ( id ) => patchEntry( id, { status: 'read' } ).catch( () => {} ),
			onMarkUnread:  ( id ) => patchEntry( id, { status: 'new'  } ).catch( () => {} ),
			onToggleStar:  ( id ) => {
				const e = entries.find( ( x ) => x.id === id );
				if ( e ) toggleStar( e );
			},
			onTrash: ( id ) => patchEntry( id, { status: 'trash' } ).catch( () => {} ),
			onSpam:  ( id ) => patchEntry( id, { status: 'spam'  } ).catch( () => {} ),
		},
	} );

	const toggleSelect = useCallback( ( id, checked ) => {
		setSelectedIds( ( prev ) => {
			const next = new Set( prev );
			if ( checked ) next.add( id ); else next.delete( id );
			return next;
		} );
	}, [] );
	const selectAll = useCallback( ( checked ) => {
		setSelectedIds( () => checked ? new Set( ids ) : new Set() );
	}, [ ids ] );
	const clearSelection = useCallback( () => setSelectedIds( new Set() ), [] );

	const title = selectionTitle( selection, forms );

	return (
		<div className="neural-forms-entries-layout">
			<header className="neural-forms-entries-header">
				<h1 className="neural-forms-entries-title">{ title }</h1>

				<div className="neural-forms-entries-controls">
					<button
						type="button"
						className="neural-forms-entries-btn"
						onClick={ exportCsv }
						disabled={ exporting }
						title={ __( 'Export entries matching the current filter as CSV', 'neural-forms-lite' ) }
					>
						{ exporting ? __( 'Exporting...', 'neural-forms-lite' ) : __( 'Export CSV', 'neural-forms-lite' ) }
					</button>

					<select
						className="neural-forms-entries-sort"
						value={ sortOrder }
						onChange={ ( e ) => setSortOrder( e.target.value ) }
						aria-label={ __( 'Sort order', 'neural-forms-lite' ) }
					>
						<option value="desc">{ __( 'Newest first', 'neural-forms-lite' ) }</option>
						<option value="asc">{ __( 'Oldest first', 'neural-forms-lite' ) }</option>
					</select>

					<input
						type="search"
						className="neural-forms-entries-search"
						value={ search }
						onChange={ ( e ) => setSearch( e.target.value ) }
						placeholder={ __( 'Search entries...', 'neural-forms-lite' ) }
						aria-label={ __( 'Search entries', 'neural-forms-lite' ) }
					/>
				</div>
			</header>

			{ !! exportError && <p className="neural-forms-entries-error">{ exportError }</p> }
			{ !! bulkError   && <p className="neural-forms-entries-error">{ bulkError }</p> }

			<div className="neural-forms-entries-body">
				<EntriesSidebar
					selection={ selection }
					onSelect={ ( sel ) => {
						// Clicking a sidebar folder while on the detail
						// page should return the right pane to the list
						// scoped to the new selection.
						updateSelection( sel );
						if ( openEntryId !== 0 ) closeDetail();
					} }
					counts={ counts }
					forms={ forms }
					formsLoading={ formsLoading }
				/>

				<main className="neural-forms-entries-content">
					{ openEntryId !== 0 ? (
						<EntryDetailPanel
							entryId={ openEntryId }
							onBack={ closeDetail }
							entry={ openEntry }
							loading={ openLoading }
							error={ openError }
							capabilities={ capabilities }
							fieldLabels={
								// `forms` is already in memory from /forms?per_page=200,
								// and each row carries its `form_schema`. Rebuild the
								// label map only when the open entry's form changes.
								openEntry
									? buildFieldLabels(
										forms.find( ( f ) => f.id === openEntry.form_id )
									)
									: {}
							}
							onReanalyze={ reanalyze }
							reanalyzing={ reanalyzing }
							reanalyzeError={ reanalyzeError }
							onPolish={ polishReply }
							onRegenerate={ regenerateReply }
							onSendReply={ sendReply }
							onFinalize={ finalizeDraft }
							composerBusy={ composerBusy }
							composerError={ composerError }
							carouselBusy={ carouselBusy }
							onToggleStar={ () => openEntry && toggleStar( openEntry ) }
						/>
					) : (
						<>
							<BulkToolbar
								selection={ selectedIds }
								onClear={ clearSelection }
								onAction={ dispatchBulk }
								busy={ bulkBusy }
								view={ ( selection?.view && SYSTEM_VIEW_IDS.has( selection.view ) ) ? selection.view : 'inbox' }
							/>

							{ entriesLoading && <p className="neural-forms-entries-status">{ __( 'Loading entries...', 'neural-forms-lite' ) }</p> }
							{ !! entriesError && <p className="neural-forms-entries-status neural-forms-entries-status--error">{ entriesError }</p> }
							{ ! entriesLoading && ! entriesError && entries.length === 0 && <EmptyState /> }

							{ entries.length > 0 && (
								<EntriesList
									entries={ entries }
									selection={ selectedIds }
									onToggleSelect={ toggleSelect }
									onSelectAll={ selectAll }
									onOpen={ openEntryDetail }
									onToggleStar={ toggleStar }
									openId={ 0 }
								/>
							) }
						</>
					) }
				</main>
			</div>
		</div>
	);
}

/**
 * Right-pane wrapper for the detail view: a back button + the detail
 * card stack underneath. Pressing Escape (anywhere outside an input)
 * fires onBack so keyboard users can leave the detail without reaching
 * for the mouse.
 */
function EntryDetailPanel( { entryId, onBack, ...detailProps } ) {
	useEffect( () => {
		const handler = ( e ) => {
			if ( e.key !== 'Escape' ) return;
			const t = e.target;
			const tag = t && t.tagName ? t.tagName.toLowerCase() : '';
			if ( tag === 'input' || tag === 'textarea' || tag === 'select' || ( t && t.isContentEditable ) ) return;
			onBack();
		};
		window.addEventListener( 'keydown', handler );
		return () => window.removeEventListener( 'keydown', handler );
	}, [ onBack ] );

	return (
		<div className="neural-forms-entries-detail-panel">
			<div className="neural-forms-entries-detail-panel__header">
				<button
					type="button"
					className="neural-forms-entries-btn neural-forms-entries-detail-panel__back"
					onClick={ onBack }
				>
					<span aria-hidden="true">&larr;&nbsp;</span>
					{ __( 'Back to entries', 'neural-forms-lite' ) }
				</button>
				<span className="neural-forms-entries-detail-panel__title">
					{ __( 'Entry', 'neural-forms-lite' ) } #{ entryId }
				</span>
			</div>
			<EntryDetail { ...detailProps } />
		</div>
	);
}
