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

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
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

	const activeTab = TABS.find( ( t ) => t.id === tab ) || TABS[ 0 ];

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
				{ TABS.map( ( t ) => (
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
						{ t.label }
					</button>
				) ) }
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
							<FormRow key={ form.id } form={ form } />
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

function FormRow( { form } ) {
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
				{ status !== 'archived' ? (
					<button
						type="button"
						className="neural-forms-forms-list__action-btn neural-forms-forms-list__action-btn--delete"
						onClick={ () => archiveForm( form.id ) }
					>
						{ __( 'Archive', 'neural-forms-lite' ) }
					</button>
				) : (
					<button
						type="button"
						className="neural-forms-forms-list__action-btn neural-forms-forms-list__action-btn--restore"
						onClick={ () => restoreForm( form.id ) }
					>
						{ __( 'Restore', 'neural-forms-lite' ) }
					</button>
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

function archiveForm( id ) {
	if ( ! window.confirm(
		__( 'Archive this form? Its entries are preserved.', 'neural-forms-lite' )
	) ) {
		return;
	}
	api( `/forms/${ id }`, { method: 'DELETE' } )
		.then( () => window.location.reload() )
		.catch( ( err ) => window.alert( err?.message || 'Failed to archive form.' ) );
}

function restoreForm( id ) {
	api( `/forms/${ id }/restore`, { method: 'POST' } )
		.then( () => window.location.reload() )
		.catch( ( err ) => window.alert( err?.message || 'Failed to restore form.' ) );
}
