/**
 * Entries inbox — the main screen controller.
 *
 * Owns:
 *   - Sidebar selection state (system view, smart filter, per-form)
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
 *     ├─ EntriesSidebar       sidebar
 *     ├─ BulkToolbar          shown when selection > 0
 *     ├─ EntriesList          rows
 *     └─ EntryDetail          right pane (when openId !== 0)
 *           ├─ AiAnalysisCard
 *           ├─ AiCarousel
 *           └─ ReplyComposer
 *
 * The sidebar selection is a tagged union so the active highlight,
 * page title, and request shape stay coherent:
 *
 *   { kind: 'view',   id: 'inbox' }            → ?view=inbox
 *   { kind: 'filter', id: 'high_priority' }    → ?view=high_priority
 *   { kind: 'form',   formId: 12 }             → ?form_id=12&view=inbox
 *
 * Phase 5 of refactor/remove-protected-admin-bundle drops the smart
 * folders (Polls/Quizzes/normal_inbox) so the source UI matches the
 * rich UI's taxonomy. Default All-Forms selection uses bare ?view=inbox.
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
import { errorMessage, planErrorPayload, safetyErrorPayload } from './helpers';

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
function selectionToQuery( selection ) {
	const p = new URLSearchParams();
	if ( ! selection ) {
		p.set( 'view', 'inbox' );
		return p;
	}
	switch ( selection.kind ) {
		case 'form':
			p.set( 'form_id', String( selection.formId ) );
			p.set( 'view', 'inbox' );
			return p;
		case 'filter':
			p.set( 'view', selection.id );
			return p;
		case 'view':
		default:
			p.set( 'view', selection.id || 'inbox' );
			return p;
	}
}

function selectionTitle( selection, forms ) {
	if ( ! selection ) return __( 'Inbox', 'neural-forms-lite' );
	if ( selection.kind === 'form' ) {
		const f = ( forms || [] ).find( ( x ) => x.id === selection.formId );
		return f?.name || `#${ selection.formId }`;
	}
	const labels = {
		inbox: 'Inbox', unread: 'Unread', needs_reply: 'Needs Reply',
		starred: 'Starred', spam: 'Spam', trash: 'Trash',
		high_priority: 'High Priority', spam_suspected: 'Spam Suspected',
	};
	return __( labels[ selection.id ] || 'Inbox', 'neural-forms-lite' );
}

export default function EntriesScreen() {
	const adminData = window.neuralFormsAdmin || {};
	const capabilities = adminData.plugin?.capabilities || {};

	const [ selection, setSelection ] = useState( { kind: 'view', id: 'inbox' } );
	const [ search,    setSearch    ] = useState( '' );
	const [ sortOrder, setSortOrder ] = useState( 'desc' );

	const [ entries,        setEntries        ] = useState( [] );
	const [ entriesLoading, setEntriesLoading ] = useState( false );
	const [ entriesError,   setEntriesError   ] = useState( '' );

	const [ counts, setCounts ] = useState( {} );

	const [ forms,        setForms        ] = useState( [] );
	const [ formsLoading, setFormsLoading ] = useState( false );

	const [ openEntryId, setOpenEntryId ] = useState( 0 );
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

	// Forms list for the per-form sidebar.
	useEffect( () => {
		let cancelled = false;
		setFormsLoading( true );
		api( '/forms?per_page=200' ).then( ( res ) => {
			if ( cancelled ) return;
			setForms( Array.isArray( res ) ? res : [] );
			setFormsLoading( false );
		} ).catch( () => {
			if ( cancelled ) return;
			setForms( [] );
			setFormsLoading( false );
		} );
		return () => { cancelled = true; };
	}, [] );

	// Counts — refetched whenever the filter scope changes so badges
	// update after a bulk action or status flip.
	const refetchCounts = useCallback( () => {
		const params = new URLSearchParams();
		if ( selection?.kind === 'form' ) {
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

	// Detail fetch.
	const openEntryDetail = useCallback( ( id ) => {
		if ( openEntryId === id ) {
			setOpenEntryId( 0 );
			setOpenEntry( null );
			return;
		}
		setOpenEntryId( id );
		setOpenEntry( null );
		setOpenError( '' );
		setOpenLoading( true );
		api( `/entries/${ id }` ).then( ( res ) => {
			setOpenEntry( res );
			setOpenLoading( false );
		} ).catch( ( err ) => {
			setOpenError( errorMessage( err, __( 'Could not load entry.', 'neural-forms-lite' ) ) );
			setOpenLoading( false );
		} );
	}, [ openEntryId ] );

	const closeDetail = useCallback( () => {
		setOpenEntryId( 0 );
		setOpenEntry( null );
		setOpenError( '' );
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
					onSelect={ setSelection }
					counts={ counts }
					forms={ forms }
					formsLoading={ formsLoading }
				/>

				<main className="neural-forms-entries-content">
					<BulkToolbar
						selection={ selectedIds }
						onClear={ clearSelection }
						onAction={ dispatchBulk }
						busy={ bulkBusy }
						view={ selection?.kind === 'view' || selection?.kind === 'filter' ? selection.id : 'inbox' }
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
							openId={ openEntryId }
						/>
					) }

					{ openEntryId !== 0 && (
						<EntryDetail
							entry={ openEntry }
							loading={ openLoading }
							error={ openError }
							capabilities={ capabilities }
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
					) }
				</main>
			</div>
		</div>
	);
}
