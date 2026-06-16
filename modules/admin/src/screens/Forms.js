/**
 * Forms screen — list + tab filter + new-form trigger.
 *
 * Layout (matches the canonical rich UI):
 *
 *   ┌──────────────────────────────────────────────────┐
 *   │ Forms                              [ + New Form ]│
 *   └──────────────────────────────────────────────────┘
 *   [Active] [Drafts] [Archived]
 *   ┌──────────────────────────────────────────────────┐
 *   │ NAME │ STATUS │ ACTIONS │ SHORTCODE              │
 *   ├──────────────────────────────────────────────────┤
 *   │ ...rows...                                       │
 *   └──────────────────────────────────────────────────┘
 *
 * Columns:
 *   Name       — form.name with form.slug below in monospace
 *   Status     — pill (Active / Draft / Archived)
 *   Actions    — Edit + (Archive | Restore | Delete)
 *   Shortcode  — [neural-forms id="N"] + Copy button
 *
 * As of 0.2.0 the shortcode is built from form.id, not slug — slugs
 * can change (renames) or duplicate, while ids are immutable.
 * ShortcodeHandler.php still accepts the slug attribute for back-compat
 * with any page that already uses [neural-forms slug="…"].
 *
 * Class naming: every element uses BEM under `.neural-forms-forms-list`
 * so the existing rich CSS tokens (table chrome, status pills, action
 * buttons, hover states) apply cleanly. Authored CSS lives in
 * src/forms.css (self-contained per-screen bundle).
 */

import { useEffect, useState, useCallback } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import api from '../api';
import TemplateLibraryModal from '../templates/TemplateLibraryModal';

const TABS = [
	{ id: 'active',   label: __( 'Active',   'neural-forms-lite' ), statuses: [ 'active'   ] },
	{ id: 'drafts',   label: __( 'Drafts',   'neural-forms-lite' ), statuses: [ 'draft'    ] },
	{ id: 'archived', label: __( 'Archived', 'neural-forms-lite' ), statuses: [ 'archived' ] },
];

export default function Forms() {
	const [ tab,     setTab     ] = useState( 'active' );
	const [ items,   setItems   ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error,   setError   ] = useState( '' );
	const [ isTemplateModalOpen, setTemplateModalOpen ] = useState( false );
	// Per-tab counts surfaced as pill badges on each tab + summed for
	// the header subtitle. Three parallel per_page=1 requests on mount
	// (we only need each response's `total` field); reused on archive /
	// restore / delete operations to keep the badges in sync.
	const [ counts, setCounts ] = useState( { active: 0, drafts: 0, archived: 0 } );

	const activeTab = TABS.find( ( t ) => t.id === tab ) || TABS[ 0 ];

	const refreshCounts = useCallback( () => {
		Promise.all(
			TABS.map( ( t ) =>
				api( `/forms?status=${ encodeURIComponent( t.statuses.join( ',' ) ) }&per_page=1` )
					.then( ( res ) => {
						if ( typeof res?.total === 'number' ) return res.total;
						// Fallback if endpoint shape ever drifts — items
						// length will at least round-trip a sensible
						// number for the small-install case where this
						// is most likely to matter.
						if ( Array.isArray( res?.items ) ) return res.items.length;
						if ( Array.isArray( res ) ) return res.length;
						return 0;
					} )
					.catch( () => 0 )
			)
		).then( ( [ active, drafts, archived ] ) => {
			setCounts( { active, drafts, archived } );
		} );
	}, [] );

	useEffect( () => {
		refreshCounts();
	}, [ refreshCounts ] );

	const totalCount = counts.active + counts.drafts + counts.archived;

	// The "+ New Form → Use Template" item is rendered by an inline
	// JS injector in AdminPanel.php (outside the React tree). It
	// dispatches `neural-forms:open-template-library` on the document
	// instead of navigating to the Templates admin page so the form-
	// builder flow stays in-context.
	useEffect( () => {
		function openTemplateModal() {
			setTemplateModalOpen( true );
		}
		document.addEventListener( 'neural-forms:open-template-library', openTemplateModal );
		// Advertise that an in-page modal listener exists so the
		// injector knows it can dispatch instead of falling back to a
		// page redirect.
		window.neuralFormsTemplateModalReady = true;
		return () => {
			document.removeEventListener( 'neural-forms:open-template-library', openTemplateModal );
			window.neuralFormsTemplateModalReady = false;
		};
	}, [] );

	useEffect( () => {
		let cancelled = false;

		setLoading( true );
		setError( '' );

		const status = activeTab.statuses.join( ',' );

		api( `/forms?status=${ encodeURIComponent( status ) }&per_page=100` )
			.then( ( res ) => {
				if ( cancelled ) return;
				const list = Array.isArray( res?.items ) ? res.items : ( Array.isArray( res ) ? res : [] );
				setItems( list );
				setLoading( false );
			} )
			.catch( ( err ) => {
				if ( cancelled ) return;
				if ( err ) {
					// eslint-disable-next-line no-console
					console.error( 'Neural Forms /forms request failed:', err );
				}
				setError( __( 'Could not load forms. Please check REST API availability.', 'neural-forms-lite' ) );
				setLoading( false );
			} );

		return () => { cancelled = true; };
	}, [ tab ] );

	return (
		<div className="neural-forms-forms-list">
			<header className="neural-forms-forms-list__header">
				<div className="neural-forms-forms-list__copy">
					<h1 className="neural-forms-forms-list__title">
						{ __( 'Forms', 'neural-forms-lite' ) }
					</h1>
					<p className="neural-forms-forms-list__subtitle">
						{ __( 'Create, manage, and publish your forms.', 'neural-forms-lite' ) }
						{ totalCount > 0 && (
							<>
								{ ' · ' }
								<span className="neural-forms-forms-list__total">
									{ sprintf(
										/* translators: %d: total number of forms across all statuses. */
										_n( '%d form', '%d forms', totalCount, 'neural-forms-lite' ),
										totalCount
									) }
								</span>
							</>
						) }
					</p>
				</div>
				<a
					className="neural-forms-forms-list__new-btn"
					href="?page=neural-forms&new=1"
				>
					{ __( '+ New Form', 'neural-forms-lite' ) }
				</a>
			</header>

			<nav className="neural-forms-forms-list__tabs" role="tablist">
				{ TABS.map( ( t ) => {
					const c = counts[ t.id ] ?? 0;
					return (
						<button
							key={ t.id }
							type="button"
							role="tab"
							aria-selected={ t.id === tab }
							className={
								'neural-forms-forms-list__tab' +
								( t.id === tab ? ' neural-forms-forms-list__tab--active' : '' )
							}
							onClick={ () => setTab( t.id ) }
						>
							<span className="neural-forms-forms-list__tab-label">{ t.label }</span>
							<span className="neural-forms-forms-list__tab-count" aria-label={
								/* translators: %d: number of forms in this status bucket. */
								sprintf( __( '%d items', 'neural-forms-lite' ), c )
							}>{ c }</span>
						</button>
					);
				} ) }
			</nav>

			{ loading && (
				<div className="neural-forms-forms-list__loading">
					{ __( 'Loading…', 'neural-forms-lite' ) }
				</div>
			) }

			{ ! loading && error && (
				<div className="neural-forms-forms-list__error">{ error }</div>
			) }

			{ ! loading && ! error && items.length === 0 && (
				<div className="neural-forms-forms-list__empty">
					{ tab === 'active' ? (
						<>
							{ __( 'No active forms yet.', 'neural-forms-lite' ) }{ ' ' }
							<a
								className="neural-forms-forms-list__empty-cta"
								href="?page=neural-forms&new=1"
							>
								{ __( 'Create your first form', 'neural-forms-lite' ) }
							</a>
							{ '.' }
							<div className="neural-forms-forms-list__empty-import">
								{ __( 'Or migrate forms from Contact Form 7 in Migration.', 'neural-forms-lite' ) }
							</div>
						</>
					) : (
						__( 'Nothing here.', 'neural-forms-lite' )
					) }
				</div>
			) }

			{ ! loading && ! error && items.length > 0 && (
				<table className="neural-forms-forms-list__table">
					<thead>
						<tr>
							<th className="neural-forms-forms-list__th neural-forms-forms-list__th--name">
								{ __( 'Name', 'neural-forms-lite' ) }
							</th>
							<th className="neural-forms-forms-list__th neural-forms-forms-list__th--status">
								{ __( 'Status', 'neural-forms-lite' ) }
							</th>
							<th className="neural-forms-forms-list__th neural-forms-forms-list__th--actions">
								{ __( 'Actions', 'neural-forms-lite' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ items.map( ( form ) => (
							<FormRow
								key={ form.id }
								form={ form }
								onRemove={ ( id ) => {
									setItems( ( prev ) => prev.filter( ( f ) => f.id !== id ) );
									// Status change moves the row between
									// tabs — refresh badges so each bucket
									// count reflects reality.
									refreshCounts();
								} }
							/>
						) ) }
					</tbody>
				</table>
			) }

			{ /* Template library popup. Opened by the "+ New Form →
			     Use Template" dropdown item (inline JS injector in
			     AdminPanel.php dispatches `neural-forms:open-template-
			     library` which the effect above subscribes to). The
			     modal short-circuits to null when isOpen=false, so this
			     line is free when the popup isn't visible. */ }
			<TemplateLibraryModal
				isOpen={ isTemplateModalOpen }
				onClose={ () => setTemplateModalOpen( false ) }
			/>
		</div>
	);
}

function FormRow( { form, onRemove } ) {
	const status = String( form.status || '' );

	return (
		<tr className="neural-forms-forms-list__row">
			<td className="neural-forms-forms-list__cell neural-forms-forms-list__cell--name">
				<a
					className="neural-forms-forms-list__form-name"
					href={ `?page=neural-forms&edit=${ form.id }` }
				>
					{ form.name || `#${ form.id }` }
				</a>
			</td>

			<td className="neural-forms-forms-list__cell neural-forms-forms-list__cell--status">
				<StatusPill status={ status } />
			</td>

			<td className="neural-forms-forms-list__cell neural-forms-forms-list__cell--actions">
				<a
					className="neural-forms-forms-list__action-btn neural-forms-forms-list__action-btn--edit"
					href={ `?page=neural-forms&edit=${ form.id }` }
				>
					{ __( 'Edit', 'neural-forms-lite' ) }
				</a>
				{ status === 'draft' && (
					<button
						type="button"
						className="neural-forms-forms-list__action-btn neural-forms-forms-list__action-btn--restore"
						onClick={ () => activateForm( form.id, onRemove ) }
					>
						{ __( 'Activate', 'neural-forms-lite' ) }
					</button>
				) }
				{ status !== 'archived' ? (
					<button
						type="button"
						className="neural-forms-forms-list__action-btn neural-forms-forms-list__action-btn--delete"
						onClick={ () => archiveForm( form.id, onRemove ) }
					>
						{ __( 'Archive', 'neural-forms-lite' ) }
					</button>
				) : (
					<>
						<button
							type="button"
							className="neural-forms-forms-list__action-btn neural-forms-forms-list__action-btn--restore"
							onClick={ () => restoreForm( form.id, onRemove ) }
						>
							{ __( 'Restore', 'neural-forms-lite' ) }
						</button>
						<button
							type="button"
							className="neural-forms-forms-list__action-btn neural-forms-forms-list__action-btn--delete"
							onClick={ () => deleteForm( form.id, onRemove ) }
						>
							{ __( 'Delete', 'neural-forms-lite' ) }
						</button>
					</>
				) }
			</td>
		</tr>
	);
}

function StatusPill( { status } ) {
	const label = status === 'active'   ? __( 'Active',   'neural-forms-lite' )
	            : status === 'draft'    ? __( 'Draft',    'neural-forms-lite' )
	            : status === 'archived' ? __( 'Archived', 'neural-forms-lite' )
	            : status;

	return (
		<span className={ `neural-forms-forms-list__status neural-forms-forms-list__status--${ status || 'unknown' }` }>
			{ label }
		</span>
	);
}

/**
 * Three row actions share the same shape: confirm (optional) → REST
 * call → optimistic-remove the row via the parent's onRemove. Avoiding
 * `window.location.reload()` keeps the user on the current tab (the
 * old behaviour bounced Archived → Active on every reload because
 * "Active" is the default tab).
 *
 * Archive (Active/Drafts tab): row vanishes from the current tab
 *   because the form's status flipped to 'archived'.
 * Restore (Archived tab): row vanishes because the form is no longer
 *   archived.
 * Delete (Archived tab): row vanishes because the row itself is gone.
 */
function archiveForm( id, onRemove ) {
	if ( ! window.confirm(
		__( 'Archive this form? Its entries are preserved.', 'neural-forms-lite' )
	) ) {
		return;
	}
	api( `/forms/${ id }`, { method: 'DELETE' } )
		.then( () => { if ( typeof onRemove === 'function' ) onRemove( id ); } )
		.catch( ( err ) => window.alert( err?.message || 'Failed to archive form.' ) );
}

function restoreForm( id, onRemove ) {
	api( `/forms/${ id }/restore`, { method: 'POST' } )
		.then( () => { if ( typeof onRemove === 'function' ) onRemove( id ); } )
		.catch( ( err ) => window.alert( err?.message || 'Failed to restore form.' ) );
}

/**
 * Flip a draft form to active via PUT /forms/{id} with status=active.
 * Only surfaced on the Drafts tab — the action does nothing meaningful
 * on Active rows (already active) or Archived rows (Restore already
 * publishes back to active state). Same optimistic-remove pattern as
 * archive/restore/delete: the row vanishes from the current tab on
 * success because it no longer matches the tab filter.
 */
function activateForm( id, onRemove ) {
	api( `/forms/${ id }`, { method: 'PUT', data: { status: 'active' } } )
		.then( () => { if ( typeof onRemove === 'function' ) onRemove( id ); } )
		.catch( ( err ) => window.alert( err?.message || 'Failed to activate form.' ) );
}

/**
 * Permanently delete a previously-archived form via DELETE /forms/{id}?force=true.
 * Only callable from the Archived tab — the Active/Drafts tabs offer
 * Archive (soft delete) instead so live forms can never disappear in
 * a single click.
 *
 * The endpoint's `force=true` branch drops the row and cascades
 * referenced entries; this is irreversible, hence the destructive
 * confirm prompt below.
 */
function deleteForm( id, onRemove ) {
	if ( ! window.confirm(
		__( 'Permanently delete this form and all its entries? This cannot be undone.', 'neural-forms-lite' )
	) ) {
		return;
	}
	api( `/forms/${ id }?force=true`, { method: 'DELETE' } )
		.then( () => { if ( typeof onRemove === 'function' ) onRemove( id ); } )
		.catch( ( err ) => window.alert( err?.message || 'Failed to delete form.' ) );
}
