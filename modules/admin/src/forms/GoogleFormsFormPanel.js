/**
 * Per-form Google Forms panel.
 *
 * Lives inside the builder Settings → Integrations → Google Forms
 * subtab. Reads / writes a single slice of form.settings:
 *
 *   form.settings.integrations.google_forms = {
 *     enabled:           bool,
 *     mode:              'imported_source' | 'linked_source',
 *     google_form_id:    string,
 *     google_form_title: string,
 *     google_form_url:   'https://docs.google.com/forms/d/.../edit',
 *     imported_at:       'Y-m-d H:i:s' UTC,
 *     sync_responses:    bool,
 *   }
 *
 * Four render modes:
 *
 *   A. capability locked       → Pro upsell (consistent with
 *                                 the HubSpot panel)
 *   B. Google not connected    → "Connect first" card with link
 *                                 to the global Integrations page
 *   C. mode === 'imported_source' (or linked_source with
 *      pre-existing config) → linked-state card: title, ID,
 *      imported_at, "One-time import" mode, "Coming soon" for
 *      response sync and structure refresh
 *   D. neither mode set        → "Link to a Google Form" picker
 *                                 (does NOT overwrite the schema
 *                                 — just stamps metadata so the
 *                                 future response-sync milestone
 *                                 can address the right form)
 *
 * REST surface consumed (Pro, from earlier Google Forms commits):
 *   GET /neural-forms-pro/v1/integrations/google-forms/status
 *   GET /neural-forms-pro/v1/integrations/google-forms/forms
 *
 * Save behavior:
 *   This panel never POSTs to a dedicated endpoint. Mutations
 *   call the `onChange` prop which threads back up to the
 *   builder's normal Save flow — `form.settings` round-trips
 *   through the C4 sanitizer which now whitelists the
 *   integrations.google_forms shape.
 */

import { useEffect, useState, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../api';

function integrationsPageHref() {
	return ( typeof window !== 'undefined' && window.location )
		? window.location.origin + '/wp-admin/admin.php?page=neural-forms-integrations'
		: '/wp-admin/admin.php?page=neural-forms-integrations';
}

function errorMessage( err, fallback ) {
	if ( ! err ) return fallback;
	if ( typeof err === 'string' ) return err;
	if ( err.message ) return err.message;
	if ( err.data && err.data.message ) return err.data.message;
	return fallback;
}

export default function GoogleFormsFormPanel( { google, onChange, capUnlocked } ) {
	const [ statusLoading, setStatusLoading ] = useState( true );
	const [ status, setStatus ] = useState( null );
	const [ statusError, setStatusError ] = useState( '' );
	const [ formsLoading, setFormsLoading ] = useState( false );
	const [ formsList, setFormsList ] = useState( [] );
	const [ formsError, setFormsError ] = useState( '' );
	const [ pickedFormId, setPickedFormId ] = useState( '' );

	const setGooglePatch = useCallback( ( patch ) => {
		onChange( { ...google, ...patch } );
	}, [ google, onChange ] );

	const linked = !! ( google?.google_form_id && google?.mode );

	useEffect( () => {
		if ( ! capUnlocked ) { setStatusLoading( false ); return; }
		let cancelled = false;
		( async () => {
			try {
				const s = await api( '/neural-forms-pro/v1/integrations/google-forms/status' );
				if ( cancelled ) return;
				setStatus( s );
			} catch ( e ) {
				if ( cancelled ) return;
				setStatusError( errorMessage( e, __( 'Could not load Google Forms status.', 'neural-forms-lite' ) ) );
			} finally {
				if ( ! cancelled ) setStatusLoading( false );
			}
		} )();
		return () => { cancelled = true; };
	}, [ capUnlocked ] );

	const reloadForms = useCallback( async () => {
		setFormsLoading( true );
		setFormsError( '' );
		try {
			const res = await api( '/neural-forms-pro/v1/integrations/google-forms/forms' );
			setFormsList( Array.isArray( res?.forms ) ? res.forms : [] );
		} catch ( e ) {
			setFormsError( errorMessage( e, __( 'Could not load Google Forms.', 'neural-forms-lite' ) ) );
		} finally {
			setFormsLoading( false );
		}
	}, [] );

	// Auto-load the Google Forms list when we hit state D
	// (connected, not yet linked, picker visible).
	useEffect( () => {
		if ( status?.connected && ! linked && formsList.length === 0 && ! formsLoading && formsError === '' ) {
			reloadForms();
		}
	}, [ status?.connected, linked ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const handleLink = useCallback( () => {
		const picked = formsList.find( ( f ) => f.id === pickedFormId );
		if ( ! picked ) return;
		setGooglePatch( {
			enabled:           true,
			mode:              'linked_source',
			google_form_id:    picked.id,
			google_form_title: picked.name || '',
			google_form_url:   'https://docs.google.com/forms/d/' + picked.id + '/edit',
			imported_at:       '',
			sync_responses:    false,
		} );
	}, [ formsList, pickedFormId, setGooglePatch ] );

	const handleUnlink = useCallback( () => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( __( 'Unlink this form from Google Forms? The Neural Form itself is unchanged — only the link metadata is removed.', 'neural-forms-lite' ) ) ) {
			return;
		}
		setGooglePatch( {
			enabled:           false,
			mode:              '',
			google_form_id:    '',
			google_form_title: '',
			google_form_url:   '',
			imported_at:       '',
			sync_responses:    false,
		} );
	}, [ setGooglePatch ] );

	// ── State A: capability locked ─────────────────────────────────
	if ( ! capUnlocked ) {
		return (
			<div className="nf-form-settings__hs-locked">
				<h3 className="nf-form-settings__hs-locked-title">
					{ __( 'Google Forms integration is a Pro feature', 'neural-forms-lite' ) }
				</h3>
				<p className="nf-form-settings__hs-locked-desc">
					{ __( 'Google Forms integration is available in Neural Forms Pro. Upgrade to import Google Forms into Neural Forms and link forms for future response sync.', 'neural-forms-lite' ) }
				</p>
			</div>
		);
	}

	if ( statusLoading ) {
		return <p className="nf-form-settings__hs-loading">{ __( 'Loading Google Forms status…', 'neural-forms-lite' ) }</p>;
	}
	if ( statusError ) {
		return <div className="nf-form-settings__hs-error" role="alert">{ statusError }</div>;
	}

	// ── State B: Google not connected ──────────────────────────────
	if ( ! status?.connected ) {
		return (
			<div className="nf-form-settings__hs-disconnected">
				<h3 className="nf-form-settings__hs-disconnected-title">
					{ __( 'Google Forms is not connected', 'neural-forms-lite' ) }
				</h3>
				<p className="nf-form-settings__hs-disconnected-desc">
					{ __( 'Connect Google Forms from Neural Forms → Integrations first. Once connected, you can import Google Forms or link this Neural Form to an existing Google Form.', 'neural-forms-lite' ) }
				</p>
				<a
					className="nf-form-settings__hs-cta"
					href={ integrationsPageHref() }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Open Integrations', 'neural-forms-lite' ) }
				</a>
			</div>
		);
	}

	// ── State C: form is linked to a Google Form ───────────────────
	if ( linked ) {
		const isImported = google.mode === 'imported_source';
		return (
			<div className="nf-form-settings__hs-panel">
				<div className="nf-form-settings__hs-header">
					<span className="nf-form-settings__hs-status nf-form-settings__hs-status--ok">
						{ isImported
							? __( 'Linked — imported from Google Forms', 'neural-forms-lite' )
							: __( 'Linked to Google Form', 'neural-forms-lite' )
						}
					</span>
				</div>

				<dl className="nf-form-settings__gf-linked-grid">
					<div>
						<dt>{ __( 'Google Form title', 'neural-forms-lite' ) }</dt>
						<dd>{ google.google_form_title || '—' }</dd>
					</div>
					<div>
						<dt>{ __( 'Google Form ID', 'neural-forms-lite' ) }</dt>
						<dd><code>{ google.google_form_id }</code></dd>
					</div>
					{ google.imported_at && (
						<div>
							<dt>{ __( 'Imported at', 'neural-forms-lite' ) }</dt>
							<dd>{ google.imported_at }</dd>
						</div>
					) }
					<div>
						<dt>{ __( 'Source mode', 'neural-forms-lite' ) }</dt>
						<dd>{ __( 'One-time import', 'neural-forms-lite' ) }</dd>
					</div>
					<div>
						<dt>{ __( 'Response sync', 'neural-forms-lite' ) }</dt>
						<dd className="nf-form-settings__gf-coming-soon">{ __( 'Coming soon', 'neural-forms-lite' ) }</dd>
					</div>
				</dl>

				<div className="nf-form-settings__hs-form-actions">
					{ google.google_form_url && (
						<a
							className="nf-form-settings__hs-btn"
							href={ google.google_form_url }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'Open original Google Form', 'neural-forms-lite' ) }
						</a>
					) }
					<button type="button" className="nf-form-settings__hs-btn" disabled title={ __( 'Coming in the response-sync milestone', 'neural-forms-lite' ) }>
						{ __( 'Sync responses', 'neural-forms-lite' ) }
					</button>
					<button type="button" className="nf-form-settings__hs-btn" disabled title={ __( 'Coming in the response-sync milestone', 'neural-forms-lite' ) }>
						{ __( 'Refresh structure', 'neural-forms-lite' ) }
					</button>
					<button type="button" className="nf-form-settings__hs-btn nf-form-settings__hs-btn--danger-light" onClick={ handleUnlink }>
						{ __( 'Disconnect link', 'neural-forms-lite' ) }
					</button>
				</div>
			</div>
		);
	}

	// ── State D: connected, not yet linked ─────────────────────────
	return (
		<div className="nf-form-settings__hs-panel">
			<div className="nf-form-settings__hs-header">
				<span className="nf-form-settings__hs-status nf-form-settings__hs-status--ok">
					{ __( 'Connected', 'neural-forms-lite' ) }
					{ status.account_email ? ' — ' + status.account_email : '' }
				</span>
			</div>

			<p className="nf-form-settings__hs-empty">
				{ __( 'This form is not linked to a Google Form. Linking is metadata-only — your Neural Form schema is not modified.', 'neural-forms-lite' ) }
			</p>

			<div className="nf-form-settings__hs-form-row">
				<label htmlFor="nf-gf-link-form" className="nf-form-settings__hs-label">
					{ __( 'Select Google Form', 'neural-forms-lite' ) }
				</label>
				<select
					id="nf-gf-link-form"
					className="nf-form-settings__hs-select"
					value={ pickedFormId }
					onChange={ ( e ) => setPickedFormId( e.target.value ) }
					disabled={ formsLoading }
				>
					<option value="">{ formsLoading ? __( 'Loading forms…', 'neural-forms-lite' ) : __( '— Select a Google Form —', 'neural-forms-lite' ) }</option>
					{ formsList.map( ( f ) => (
						<option key={ f.id } value={ f.id }>{ f.name || sprintf( __( 'Form %s', 'neural-forms-lite' ), f.id ) }</option>
					) ) }
				</select>
				<button
					type="button"
					className="nf-form-settings__hs-btn"
					onClick={ reloadForms }
					disabled={ formsLoading }
				>
					{ formsLoading ? __( 'Refreshing…', 'neural-forms-lite' ) : __( 'Refresh', 'neural-forms-lite' ) }
				</button>
				<button
					type="button"
					className="nf-form-settings__hs-btn nf-form-settings__hs-btn--primary"
					onClick={ handleLink }
					disabled={ pickedFormId === '' }
				>
					{ __( 'Link form', 'neural-forms-lite' ) }
				</button>
			</div>

			{ formsError && <div className="nf-form-settings__hs-error" role="alert">{ formsError }</div> }
		</div>
	);
}
