/**
 * Forms screen - list + tab filter + new-form trigger.
 *
 * Columns:
 *   Name      - form.name with form.slug below
 *   Status    - pill (Active / Draft / Archived)
 *   Actions   - Edit / Archive (or Restore for archived)
 *   Shortcode - [neural-forms id="N"] + Copy button (0.2.0: id-based)
 *
 * The Shortcode column is the embed helper the classic-editor / non-block
 * users need. As of 0.2.0 the shortcode is built from form.id, not slug —
 * slugs can change (renames) or duplicate, while ids are immutable.
 * ShortcodeHandler.php still accepts the slug attribute for back-compat
 * with any page that already uses [neural-forms slug="..."].
 */

import { useEffect, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import api from '../api';

const TABS = [
	{ id: 'active',   label: 'Active',   statuses: [ 'active'   ] },
	{ id: 'drafts',   label: 'Drafts',   statuses: [ 'draft'    ] },
	{ id: 'archived', label: 'Archived', statuses: [ 'archived' ] },
];

export default function Forms() {
	const [ tab,   setTab   ] = useState( 'active' );
	const [ items, setItems ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error,   setError   ] = useState( '' );

	const activeTab = TABS.find( ( t ) => t.id === tab ) || TABS[ 0 ];

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
				// Surface a friendly message; keep the technical detail in
				// the console so a developer can still debug REST issues.
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
		<div className="neural-forms-screen neural-forms-screen--forms">
			<header className="neural-forms-screen__header">
				<h1 className="neural-forms-screen__title">{ __( 'Forms', 'neural-forms-lite' ) }</h1>
				<a className="neural-forms-btn neural-forms-btn--primary" href="?page=neural-forms&new=1">
					+ { __( 'New Form', 'neural-forms-lite' ) }
				</a>
			</header>

			<nav className="neural-forms-tabs" role="tablist">
				{ TABS.map( ( t ) => (
					<button
						key={ t.id }
						role="tab"
						aria-selected={ t.id === tab }
						className={
							'neural-forms-tabs__tab' +
							( t.id === tab ? ' neural-forms-tabs__tab--active' : '' )
						}
						onClick={ () => setTab( t.id ) }
					>
						{ t.label }
					</button>
				) ) }
			</nav>

			<div className="neural-forms-card">
				<table className="neural-forms-table">
					<thead>
						<tr>
							<th className="neural-forms-table__col-name">{ __( 'Name', 'neural-forms-lite' ) }</th>
							<th className="neural-forms-table__col-status">{ __( 'Status', 'neural-forms-lite' ) }</th>
							<th className="neural-forms-table__col-actions">{ __( 'Actions', 'neural-forms-lite' ) }</th>
							<th className="neural-forms-table__col-shortcode">{ __( 'Shortcode', 'neural-forms-lite' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ loading && (
							<tr><td colSpan={ 4 } className="neural-forms-table__empty">{ __( 'Loading...', 'neural-forms-lite' ) }</td></tr>
						) }
						{ ! loading && error && (
							<tr><td colSpan={ 4 } className="neural-forms-table__empty neural-forms-table__empty--error">{ error }</td></tr>
						) }
						{ ! loading && ! error && items.length === 0 && (
							<tr><td colSpan={ 4 } className="neural-forms-table__empty">{ __( 'No forms here yet.', 'neural-forms-lite' ) }</td></tr>
						) }
						{ ! loading && ! error && items.map( ( form ) => (
							<FormRow key={ form.id } form={ form } />
						) ) }
					</tbody>
				</table>
			</div>
		</div>
	);
}

function FormRow( { form } ) {
	const slug = String( form.slug || '' );
	// 0.2.0: ID-based shortcodes are the canonical embed format.
	// Slugs can change (rename) and duplicate (rare but possible
	// across statuses), while ids are immutable. Slug remains
	// supported by ShortcodeHandler.php for back-compat with any
	// page that already uses [neural-forms slug="..."]; this admin
	// UI just stops MINTING them. Falls back to slug only if a row
	// somehow has no id (shouldn't happen for saved forms).
	const formId = Number( form.id ) || 0;
	const shortcode = formId > 0
		? `[neural-forms id="${ formId }"]`
		: ( slug !== '' ? `[neural-forms slug="${ slug }"]` : '' );

	return (
		<tr>
			<td className="neural-forms-table__col-name">
				<div className="neural-forms-table__form-name">{ form.name || `#${ form.id }` }</div>
				<code className="neural-forms-table__form-slug">{ slug }</code>
			</td>

			<td className="neural-forms-table__col-status">
				<StatusPill status={ form.status } />
			</td>

			<td className="neural-forms-table__col-actions">
				<a
					className="neural-forms-btn neural-forms-btn--secondary"
					href={ `?page=neural-forms&edit=${ form.id }` }
				>
					{ __( 'Edit', 'neural-forms-lite' ) }
				</a>
				{ form.status !== 'archived' ? (
					<Button isSecondary onClick={ () => archiveForm( form.id ) }>
						{ __( 'Archive', 'neural-forms-lite' ) }
					</Button>
				) : (
					<Button isSecondary onClick={ () => restoreForm( form.id ) }>
						{ __( 'Restore', 'neural-forms-lite' ) }
					</Button>
				) }
			</td>

			<td className="neural-forms-table__col-shortcode">
				{ shortcode === '' ? (
					<span className="neural-forms-table__empty">-</span>
				) : (
					<ShortcodeCell shortcode={ shortcode } />
				) }
			</td>
		</tr>
	);
}

function ShortcodeCell( { shortcode } ) {
	const [ copied, setCopied ] = useState( false );

	const onCopy = async () => {
		try {
			await navigator.clipboard.writeText( shortcode );
			setCopied( true );
			setTimeout( () => setCopied( false ), 1500 );
		} catch ( e ) {
			// Clipboard API blocked (insecure context, permissions). Fall back
			// to the legacy execCommand path so the click still produces a copy.
			const ta = document.createElement( 'textarea' );
			ta.value = shortcode;
			ta.setAttribute( 'readonly', '' );
			ta.style.position = 'absolute';
			ta.style.left = '-9999px';
			document.body.appendChild( ta );
			ta.select();
			try { document.execCommand( 'copy' ); setCopied( true ); setTimeout( () => setCopied( false ), 1500 ); }
			catch ( _ ) { /* give up silently */ }
			document.body.removeChild( ta );
		}
	};

	return (
		<div className="neural-forms-shortcode">
			<code
				className="neural-forms-shortcode__code"
				title={ shortcode }
				onClick={ ( e ) => {
					// Convenience: click the code itself to select it for users
					// who prefer keyboard copy.
					const range = document.createRange();
					range.selectNodeContents( e.currentTarget );
					const sel = window.getSelection();
					sel.removeAllRanges();
					sel.addRange( range );
				} }
			>
				{ shortcode }
			</code>
			<button
				type="button"
				className={
					'neural-forms-shortcode__copy' +
					( copied ? ' neural-forms-shortcode__copy--done' : '' )
				}
				onClick={ onCopy }
				aria-label={ __( 'Copy shortcode to clipboard', 'neural-forms-lite' ) }
			>
				{ copied ? __( 'Copied', 'neural-forms-lite' ) : __( 'Copy', 'neural-forms-lite' ) }
			</button>
		</div>
	);
}

function StatusPill( { status } ) {
	const label = status === 'active'   ? __( 'Active',   'neural-forms-lite' )
	            : status === 'draft'    ? __( 'Draft',    'neural-forms-lite' )
	            : status === 'archived' ? __( 'Archived', 'neural-forms-lite' )
	            : status;

	return (
		<span className={ `neural-forms-pill neural-forms-pill--${ status || 'unknown' }` }>
			{ label }
		</span>
	);
}

function archiveForm( id ) {
	if ( ! window.confirm( __( 'Archive this form? Its entries are preserved.', 'neural-forms-lite' ) ) ) {
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
