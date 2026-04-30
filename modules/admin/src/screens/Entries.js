/**
 * Entries inbox screen - 1.1.0 reimplementation.
 *
 * Layout:
 *   header        title + Export CSV + sort + search
 *   sidebar       system views (Inbox / Unread / Needs Reply / Starred /
 *                 Spam / Trash) + Smart Filters (High Priority)
 *   main          list of entries OR empty state OR detail panel
 *
 * REST contract used:
 *   GET /entries?view=...&search=...&sort_order=...&per_page=20
 *     The endpoint REQUIRES at least one of (form_id, view, search), so a
 *     view is always sent; defaults to 'inbox'. The server returns
 *     entry rows with form_name (LEFT JOIN) plus a 3-key 'preview' map.
 *   GET /entries/{id}      detail with the full data blob
 *   GET /entries/export?...  CSV download (apiFetch parse:false to get the
 *                            blob and trigger a save dialog)
 *
 * Smart Filters (Lite-only): only High Priority is exposed today; the
 * server view 'high_priority' is the same one Stats uses. Spam-suspected
 * stays off the menu until Pro unlocks it.
 *
 * No bulk actions, no AI carousel, no reply composer in this pass - we
 * are restoring the inbox shell, not the full 1.0.0 feature surface.
 */

import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import api from '../api';

const SYSTEM_VIEWS = [
	{ id: 'inbox',       label: 'Inbox'        },
	{ id: 'unread',      label: 'Unread'       },
	{ id: 'needs_reply', label: 'Needs Reply'  },
	{ id: 'starred',     label: 'Starred'      },
	{ id: 'spam',        label: 'Spam'         },
	{ id: 'trash',       label: 'Trash'        },
];

const SMART_FILTERS = [
	{ id: 'high_priority', label: 'High Priority' },
];

function formatDate( s ) {
	if ( ! s ) return '';
	const d = new Date( s.replace( ' ', 'T' ) + 'Z' );
	if ( Number.isNaN( d.getTime() ) ) return s;
	return d.toLocaleString();
}

function previewSummary( preview ) {
	if ( ! preview || typeof preview !== 'object' ) return '';
	const parts = [];
	for ( const v of Object.values( preview ) ) {
		if ( v == null ) continue;
		if ( typeof v === 'string' ) parts.push( v );
		else if ( typeof v === 'number' || typeof v === 'boolean' ) parts.push( String( v ) );
		else if ( Array.isArray( v ) ) parts.push( v.join( ', ' ) );
		if ( parts.length >= 2 ) break;
	}
	return parts.join( ' · ' ).slice( 0, 200 );
}

function pickSubmitterEmail( preview, data ) {
	const candidates = [ preview, data ];
	for ( const obj of candidates ) {
		if ( ! obj || typeof obj !== 'object' ) continue;
		for ( const v of Object.values( obj ) ) {
			if ( typeof v === 'string' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( v ) ) {
				return v;
			}
		}
	}
	return '';
}

function StatusPill( { entry } ) {
	if ( entry.status === 'spam' )  return <span className="nf-pill nf-pill--spam">{ __( 'Spam', 'neural-forms-lite' ) }</span>;
	if ( entry.status === 'trash' ) return <span className="nf-pill nf-pill--trash">{ __( 'Trash', 'neural-forms-lite' ) }</span>;
	if ( entry.status === 'new'   ) return <span className="nf-pill nf-pill--new">{ __( 'Unread', 'neural-forms-lite' ) }</span>;
	return <span className="nf-pill nf-pill--read">{ __( 'Read', 'neural-forms-lite' ) }</span>;
}

function InboxIcon() {
	// Inline SVG so we don't pull in an icon dependency.
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
		<div className="nf-inbox__empty">
			<div className="nf-inbox__empty-icon"><InboxIcon /></div>
			<p className="nf-inbox__empty-title">{ __( 'Your inbox is quiet', 'neural-forms-lite' ) }</p>
			<p className="nf-inbox__empty-hint">
				{ __( 'New submissions will land here the moment they come in.', 'neural-forms-lite' ) }
			</p>
		</div>
	);
}

function EntryDetail( { entry, loading, error } ) {
	if ( loading ) return <div className="nf-inbox__detail nf-inbox__detail--status">{ __( 'Loading entry...', 'neural-forms-lite' ) }</div>;
	if ( error )   return <div className="nf-inbox__detail nf-inbox__detail--status nf-inbox__detail--error">{ error }</div>;
	if ( ! entry ) return null;

	const data = entry.data && typeof entry.data === 'object' ? entry.data : {};
	const rows = Object.entries( data );
	const email = pickSubmitterEmail( null, data );

	return (
		<div className="nf-inbox__detail">
			<header className="nf-inbox__detail-header">
				<div>
					<h3 className="nf-inbox__detail-title">{ entry.form_name || `#${ entry.form_id }` }</h3>
					<p className="nf-inbox__detail-meta">
						{ email && <span>{ email }</span> }
						{ email && ' · ' }
						<span>{ formatDate( entry.submitted_at || entry.created_at ) }</span>
						<span> · </span>
						<StatusPill entry={ entry } />
					</p>
				</div>
			</header>

			{ rows.length === 0 && (
				<p className="nf-inbox__detail-empty">{ __( 'No data captured for this entry.', 'neural-forms-lite' ) }</p>
			) }

			{ rows.length > 0 && (
				<dl className="nf-inbox__detail-grid">
					{ rows.map( ( [ k, v ] ) => (
						<div className="nf-inbox__detail-row" key={ k }>
							<dt>{ k }</dt>
							<dd>{ typeof v === 'string' ? v : ( v == null ? '' : JSON.stringify( v ) ) }</dd>
						</div>
					) ) }
				</dl>
			) }
		</div>
	);
}

export default function Entries() {
	const [ view,       setView       ] = useState( 'inbox' );
	const [ search,     setSearch     ] = useState( '' );
	const [ sortOrder,  setSortOrder  ] = useState( 'desc' );

	const [ entries,        setEntries        ] = useState( [] );
	const [ entriesLoading, setEntriesLoading ] = useState( false );
	const [ entriesError,   setEntriesError   ] = useState( '' );

	const [ openEntryId, setOpenEntryId   ] = useState( 0 );
	const [ openEntry,   setOpenEntry     ] = useState( null );
	const [ openLoading, setOpenLoading   ] = useState( false );
	const [ openError,   setOpenError     ] = useState( '' );

	const [ exporting,    setExporting    ] = useState( false );
	const [ exportError,  setExportError  ] = useState( '' );

	// Debounce the search input so we don't fire a request on every keystroke.
	const [ debouncedSearch, setDebouncedSearch ] = useState( '' );
	useEffect( () => {
		const t = setTimeout( () => setDebouncedSearch( search.trim() ), 250 );
		return () => clearTimeout( t );
	}, [ search ] );

	// Reset detail when filters change so a stale "open entry" doesn't
	// linger after the visible list moved on.
	const filterKey = view + '|' + debouncedSearch + '|' + sortOrder;
	const lastFilterKey = useRef( filterKey );
	useEffect( () => {
		if ( lastFilterKey.current !== filterKey ) {
			lastFilterKey.current = filterKey;
			setOpenEntryId( 0 );
			setOpenEntry( null );
		}
	}, [ filterKey ] );

	useEffect( () => {
		let cancelled = false;
		setEntriesLoading( true );
		setEntriesError( '' );

		const params = new URLSearchParams();
		params.set( 'view',       view );
		params.set( 'sort_order', sortOrder );
		params.set( 'per_page',   '20' );
		if ( debouncedSearch ) params.set( 'search', debouncedSearch );

		api( '/entries?' + params.toString() )
			.then( ( res ) => {
				if ( cancelled ) return;
				setEntries( Array.isArray( res ) ? res : [] );
				setEntriesLoading( false );
			} )
			.catch( ( err ) => {
				if ( cancelled ) return;
				// eslint-disable-next-line no-console
				if ( err ) console.error( 'Neural Forms /entries failed:', err );
				setEntries( [] );
				setEntriesError( __( 'Could not load entries. Please check REST API availability.', 'neural-forms-lite' ) );
				setEntriesLoading( false );
			} );

		return () => { cancelled = true; };
	}, [ view, debouncedSearch, sortOrder ] );

	const openEntryDetail = ( id ) => {
		if ( openEntryId === id ) {
			setOpenEntryId( 0 );
			setOpenEntry( null );
			return;
		}
		setOpenEntryId( id );
		setOpenEntry( null );
		setOpenError( '' );
		setOpenLoading( true );
		api( `/entries/${ id }` )
			.then( ( res ) => {
				setOpenEntry( res );
				setOpenLoading( false );
			} )
			.catch( ( err ) => {
				// eslint-disable-next-line no-console
				if ( err ) console.error( 'Neural Forms /entries/{id} failed:', err );
				setOpenError( __( 'Could not load entry.', 'neural-forms-lite' ) );
				setOpenLoading( false );
			} );
	};

	const exportCsv = () => {
		setExporting( true );
		setExportError( '' );

		const params = new URLSearchParams();
		params.set( 'view',       view );
		params.set( 'sort_order', sortOrder );
		if ( debouncedSearch ) params.set( 'search', debouncedSearch );

		// parse:false returns the raw Response so we can grab the CSV blob
		// (apiFetch tries to JSON.parse the body by default).
		apiFetch( {
			path:  '/neural-forms/v1/entries/export?' + params.toString(),
			parse: false,
		} )
			.then( ( resp ) => resp.blob() )
			.then( ( blob ) => {
				const url  = URL.createObjectURL( blob );
				const a    = document.createElement( 'a' );
				const stamp = new Date().toISOString().slice( 0, 10 );
				a.href     = url;
				a.download = `neural-forms-${ view }-${ stamp }.csv`;
				document.body.appendChild( a );
				a.click();
				document.body.removeChild( a );
				URL.revokeObjectURL( url );
				setExporting( false );
			} )
			.catch( ( err ) => {
				// eslint-disable-next-line no-console
				if ( err ) console.error( 'Neural Forms /entries/export failed:', err );
				setExportError( __( 'Export failed. Please try again.', 'neural-forms-lite' ) );
				setExporting( false );
			} );
	};

	const filteredEntries = useMemo( () => entries, [ entries ] );

	return (
		<div className="neural-forms-screen nf-inbox">
			<header className="nf-inbox__header">
				<h1 className="nf-inbox__title">{ __( 'Inbox', 'neural-forms-lite' ) }</h1>

				<div className="nf-inbox__controls">
					<button
						type="button"
						className="neural-forms-btn nf-inbox__export"
						onClick={ exportCsv }
						disabled={ exporting }
						title={ __( 'Export entries matching the current filter as CSV', 'neural-forms-lite' ) }
					>
						{ exporting ? __( 'Exporting...', 'neural-forms-lite' ) : __( 'Export CSV', 'neural-forms-lite' ) }
						<span aria-hidden="true" className="nf-inbox__caret">▾</span>
					</button>

					<select
						className="nf-inbox__sort"
						value={ sortOrder }
						onChange={ ( e ) => setSortOrder( e.target.value ) }
						aria-label={ __( 'Sort order', 'neural-forms-lite' ) }
					>
						<option value="desc">{ __( 'Newest first', 'neural-forms-lite' ) }</option>
						<option value="asc">{ __( 'Oldest first', 'neural-forms-lite' ) }</option>
					</select>

					<input
						type="search"
						className="nf-inbox__search"
						value={ search }
						onChange={ ( e ) => setSearch( e.target.value ) }
						placeholder={ __( 'Search entries...', 'neural-forms-lite' ) }
						aria-label={ __( 'Search entries', 'neural-forms-lite' ) }
					/>
				</div>
			</header>

			{ !! exportError && <p className="nf-inbox__inline-error">{ exportError }</p> }

			<div className="nf-inbox__body">
				<aside className="nf-inbox__sidebar">
					<div className="nf-inbox__sidebar-card">
						<div className="nf-inbox__sidebar-section">
							<h3 className="nf-inbox__sidebar-title">{ __( 'All Forms', 'neural-forms-lite' ) }</h3>
							<ul className="nf-inbox__sidebar-list">
								{ SYSTEM_VIEWS.map( ( v ) => (
									<li key={ v.id }>
										<button
											type="button"
											className={ 'nf-inbox__sidebar-item' + ( view === v.id ? ' is-active' : '' ) }
											onClick={ () => setView( v.id ) }
										>{ v.label }</button>
									</li>
								) ) }
							</ul>
							<p className="nf-inbox__sidebar-hint">
								{ __( 'Build a form to start collecting entries.', 'neural-forms-lite' ) }
							</p>
						</div>

						<div className="nf-inbox__sidebar-section">
							<h3 className="nf-inbox__sidebar-title">{ __( 'Smart Filters', 'neural-forms-lite' ) }</h3>
							<ul className="nf-inbox__sidebar-list">
								{ SMART_FILTERS.map( ( v ) => (
									<li key={ v.id }>
										<button
											type="button"
											className={ 'nf-inbox__sidebar-item' + ( view === v.id ? ' is-active' : '' ) }
											onClick={ () => setView( v.id ) }
										>{ v.label }</button>
									</li>
								) ) }
							</ul>
						</div>
					</div>
				</aside>

				<main className="nf-inbox__main">
					<div className="nf-inbox__card">
						{ entriesLoading && <p className="nf-inbox__status">{ __( 'Loading entries...', 'neural-forms-lite' ) }</p> }
						{ !! entriesError && <p className="nf-inbox__status nf-inbox__status--error">{ entriesError }</p> }
						{ ! entriesLoading && ! entriesError && filteredEntries.length === 0 && <EmptyState /> }

						{ filteredEntries.length > 0 && (
							<table className="nf-inbox__table">
								<thead>
									<tr>
										<th>{ __( 'Form', 'neural-forms-lite' ) }</th>
										<th>{ __( 'Submitter', 'neural-forms-lite' ) }</th>
										<th>{ __( 'Preview', 'neural-forms-lite' ) }</th>
										<th>{ __( 'Status', 'neural-forms-lite' ) }</th>
										<th>{ __( 'Date', 'neural-forms-lite' ) }</th>
									</tr>
								</thead>
								<tbody>
									{ filteredEntries.map( ( e ) => {
										const submitter = pickSubmitterEmail( e.preview, null ) || '-';
										return (
											<tr
												key={ e.id }
												className={
													'nf-inbox__row'
													+ ( e.status === 'new' ? ' is-unread' : '' )
													+ ( openEntryId === e.id ? ' is-open' : '' )
												}
												onClick={ () => openEntryDetail( e.id ) }
											>
												<td className="nf-inbox__row-form">{ e.form_name || `#${ e.form_id }` }</td>
												<td className="nf-inbox__row-submitter">{ submitter }</td>
												<td className="nf-inbox__row-preview">{ previewSummary( e.preview ) }</td>
												<td><StatusPill entry={ e } /></td>
												<td className="nf-inbox__row-date">{ formatDate( e.submitted_at || e.created_at ) }</td>
											</tr>
										);
									} ) }
								</tbody>
							</table>
						) }

						{ ( openEntryId !== 0 ) && (
							<EntryDetail entry={ openEntry } loading={ openLoading } error={ openError } />
						) }
					</div>
				</main>
			</div>
		</div>
	);
}
