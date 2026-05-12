/**
 * Per-form HubSpot mapping panel.
 *
 * Lives inside the builder Settings → Integrations → HubSpot subtab.
 * Reads / writes a single slice of form.settings:
 *
 *   form.settings.integrations.hubspot = {
 *     enabled:           bool,
 *     hubspot_form_id:   string,  // HubSpot form guid
 *     hubspot_form_name: string,
 *     field_map:         { "<hubspot_field_name>": "<nf_field_id>", ... }
 *   }
 *
 * No tokens, secrets, or portal IDs land in form.settings — the
 * connection state lives entirely in the global Pro option managed
 * by C1's HubSpotSettingsRepository.
 *
 * REST surface consumed (all from C1 / C2):
 *   GET /neural-forms-pro/v1/integrations/hubspot/status
 *   GET /neural-forms-pro/v1/integrations/hubspot/forms
 *   GET /neural-forms-pro/v1/integrations/hubspot/forms/{id}/fields
 *
 * Save behavior:
 *   This panel never POSTs. Mapping mutations call the `onChange`
 *   prop which threads back up to the builder's normal save flow —
 *   the existing form-level Save button picks them up as part of the
 *   `form.settings` payload.
 */

import { useEffect, useMemo, useState, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../api';
import {
	flattenNfFields,
	compatibleNfFieldsForHubSpot,
	autoMapHubSpotFields,
	findRequiredUnmapped,
} from './hubspotAutoMap';

const ADMIN_PAGE_URL = ( typeof window !== 'undefined' && window.neuralFormsAdmin?.admin?.adminUrl )
	? ''  /* unused — we build below */
	: '';

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

export default function HubSpotMappingPanel( { fields, hubspot, onChange, capUnlocked } ) {
	const [ statusLoading, setStatusLoading ] = useState( true );
	const [ status, setStatus ]               = useState( null );
	const [ statusError, setStatusError ]     = useState( '' );

	const [ formsLoading, setFormsLoading ]   = useState( false );
	const [ formsList,    setFormsList ]      = useState( [] );
	const [ formsError,   setFormsError ]     = useState( '' );

	const [ fieldsLoading, setFieldsLoading ] = useState( false );
	const [ hubspotFields, setHubspotFields ] = useState( [] );
	const [ fieldsError,   setFieldsError ]   = useState( '' );

	const nfFields = useMemo( () => flattenNfFields( fields || [] ), [ fields ] );

	const setHubspotPatch = useCallback( ( patch ) => {
		onChange( { ...hubspot, ...patch } );
	}, [ hubspot, onChange ] );

	// ── Status ───────────────────────────────────────────────────────
	useEffect( () => {
		if ( ! capUnlocked ) { setStatusLoading( false ); return; }
		let cancelled = false;
		( async () => {
			try {
				const s = await api( '/neural-forms-pro/v1/integrations/hubspot/status' );
				if ( cancelled ) return;
				setStatus( s );
			} catch ( e ) {
				if ( cancelled ) return;
				setStatusError( errorMessage( e, __( 'Could not load HubSpot status.', 'neural-forms-lite' ) ) );
			} finally {
				if ( ! cancelled ) setStatusLoading( false );
			}
		} )();
		return () => { cancelled = true; };
	}, [ capUnlocked ] );

	// ── Forms list (auto-fetched once connected) ──────────────────────
	const reloadForms = useCallback( async () => {
		setFormsLoading( true );
		setFormsError( '' );
		try {
			const r = await api( '/neural-forms-pro/v1/integrations/hubspot/forms' );
			setFormsList( Array.isArray( r?.forms ) ? r.forms : [] );
		} catch ( e ) {
			setFormsError( errorMessage( e, __( 'Could not load HubSpot forms.', 'neural-forms-lite' ) ) );
		} finally {
			setFormsLoading( false );
		}
	}, [] );

	useEffect( () => {
		if ( status?.connected && formsList.length === 0 && ! formsLoading && formsError === '' ) {
			reloadForms();
		}
	}, [ status?.connected ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// ── Fields for selected form ──────────────────────────────────────
	const loadFieldsFor = useCallback( async ( formId ) => {
		if ( ! formId ) { setHubspotFields( [] ); return; }
		setFieldsLoading( true );
		setFieldsError( '' );
		try {
			const r = await api( '/neural-forms-pro/v1/integrations/hubspot/forms/' + encodeURIComponent( formId ) + '/fields' );
			setHubspotFields( Array.isArray( r?.fields ) ? r.fields : [] );
		} catch ( e ) {
			setHubspotFields( [] );
			setFieldsError( errorMessage( e, __( 'Could not load HubSpot fields.', 'neural-forms-lite' ) ) );
		} finally {
			setFieldsLoading( false );
		}
	}, [] );

	// Re-fetch whenever the selected HubSpot form changes (and on
	// initial mount of an existing config).
	useEffect( () => {
		if ( status?.connected && hubspot?.hubspot_form_id ) {
			loadFieldsFor( hubspot.hubspot_form_id );
		} else {
			setHubspotFields( [] );
		}
	}, [ status?.connected, hubspot?.hubspot_form_id, loadFieldsFor ] );

	// ── Handlers ──────────────────────────────────────────────────────
	const handleEnabledToggle = useCallback( ( e ) => {
		setHubspotPatch( { enabled: !! e.target.checked } );
	}, [ setHubspotPatch ] );

	const handleFormSelect = useCallback( ( e ) => {
		const guid = e.target.value;
		const next = formsList.find( ( f ) => f.guid === guid || f.id === guid );
		setHubspotPatch( {
			hubspot_form_id:   guid,
			hubspot_form_name: next ? ( next.name || '' ) : '',
			// Selecting a different HubSpot form invalidates the
			// prior mapping (different field set). Reset cleanly so
			// the table doesn't show stale rows.
			field_map: {},
		} );
	}, [ formsList, setHubspotPatch ] );

	const handleMappingChange = useCallback( ( hubspotName, nfFieldId ) => {
		const next = { ...( hubspot?.field_map || {} ) };
		if ( nfFieldId === '' ) {
			delete next[ hubspotName ];
		} else {
			next[ hubspotName ] = nfFieldId;
		}
		setHubspotPatch( { field_map: next } );
	}, [ hubspot, setHubspotPatch ] );

	const handleAutoMap = useCallback( () => {
		const proposed = autoMapHubSpotFields( hubspotFields, nfFields, hubspot?.field_map || {} );
		setHubspotPatch( { field_map: proposed } );
	}, [ hubspotFields, nfFields, hubspot, setHubspotPatch ] );

	// ── Render ────────────────────────────────────────────────────────
	if ( ! capUnlocked ) {
		return (
			<div className="nf-form-settings__hs-locked">
				<h3 className="nf-form-settings__hs-locked-title">
					{ __( 'HubSpot integration is a Pro feature', 'neural-forms-lite' ) }
				</h3>
				<p className="nf-form-settings__hs-locked-desc">
					{ __( 'HubSpot integration is available in Neural Forms Pro. Upgrade to enable this form to push submissions to HubSpot.', 'neural-forms-lite' ) }
				</p>
			</div>
		);
	}

	if ( statusLoading ) {
		return <p className="nf-form-settings__hs-loading">{ __( 'Loading HubSpot status…', 'neural-forms-lite' ) }</p>;
	}
	if ( statusError ) {
		return <div className="nf-form-settings__hs-error" role="alert">{ statusError }</div>;
	}
	if ( ! status?.connected ) {
		return (
			<div className="nf-form-settings__hs-disconnected">
				<h3 className="nf-form-settings__hs-disconnected-title">
					{ __( 'HubSpot is not connected', 'neural-forms-lite' ) }
				</h3>
				<p className="nf-form-settings__hs-disconnected-desc">
					{ __( 'Connect HubSpot from Neural Forms → Integrations before enabling this form integration.', 'neural-forms-lite' ) }
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

	// ── Connected: full mapping UI ────────────────────────────────────
	const enabled       = !! hubspot?.enabled;
	const selectedGuid  = String( hubspot?.hubspot_form_id || '' );
	const field_map     = hubspot?.field_map || {};
	const requiredMiss  = findRequiredUnmapped( hubspotFields, field_map );

	return (
		<div className="nf-form-settings__hs-panel">
			<div className="nf-form-settings__hs-header">
				<span className="nf-form-settings__hs-status nf-form-settings__hs-status--ok">
					{ __( 'Connected', 'neural-forms-lite' ) }
					{ status.account_email ? ' — ' + status.account_email : ( status.portal_id ? ' — portal ' + status.portal_id : '' ) }
				</span>
			</div>

			<label className="nf-form-settings__hs-toggle-row">
				<input
					type="checkbox"
					checked={ enabled }
					onChange={ handleEnabledToggle }
				/>
				<span>{ __( 'Send this form\'s submissions to HubSpot', 'neural-forms-lite' ) }</span>
			</label>

			<div className={ 'nf-form-settings__hs-config' + ( enabled ? '' : ' is-disabled' ) }>
				<div className="nf-form-settings__hs-form-row">
					<label htmlFor="nf-hs-form" className="nf-form-settings__hs-label">
						{ __( 'HubSpot Form', 'neural-forms-lite' ) }
					</label>
					<select
						id="nf-hs-form"
						className="nf-form-settings__hs-select"
						value={ selectedGuid }
						onChange={ handleFormSelect }
						disabled={ ! enabled || formsLoading }
					>
						<option value="">{ formsLoading ? __( 'Loading forms…', 'neural-forms-lite' ) : __( '— Select a HubSpot form —', 'neural-forms-lite' ) }</option>
						{ formsList.map( ( f ) => (
							<option key={ f.guid || f.id } value={ f.guid || f.id }>{ f.name }</option>
						) ) }
					</select>
					<button
						type="button"
						className="nf-form-settings__hs-btn"
						onClick={ reloadForms }
						disabled={ ! enabled || formsLoading }
					>
						{ formsLoading ? __( 'Refreshing…', 'neural-forms-lite' ) : __( 'Refresh forms', 'neural-forms-lite' ) }
					</button>
				</div>

				{ formsError && <div className="nf-form-settings__hs-error" role="alert">{ formsError }</div> }
			</div>

			{ enabled && selectedGuid !== '' && (
				<MappingTable
					hubspotFields={ hubspotFields }
					hubspotFieldsLoading={ fieldsLoading }
					hubspotFieldsError={ fieldsError }
					nfFields={ nfFields }
					fieldMap={ field_map }
					onMappingChange={ handleMappingChange }
					onAutoMap={ handleAutoMap }
					requiredMiss={ requiredMiss }
				/>
			) }
		</div>
	);
}

function MappingTable( { hubspotFields, hubspotFieldsLoading, hubspotFieldsError, nfFields, fieldMap, onMappingChange, onAutoMap, requiredMiss } ) {
	if ( hubspotFieldsLoading ) {
		return <p className="nf-form-settings__hs-loading">{ __( 'Loading HubSpot fields…', 'neural-forms-lite' ) }</p>;
	}
	if ( hubspotFieldsError ) {
		return <div className="nf-form-settings__hs-error" role="alert">{ hubspotFieldsError }</div>;
	}
	if ( ! hubspotFields || hubspotFields.length === 0 ) {
		return <p className="nf-form-settings__hs-empty">{ __( 'This HubSpot form has no fields.', 'neural-forms-lite' ) }</p>;
	}

	return (
		<div className="nf-form-settings__hs-mapping">
			<div className="nf-form-settings__hs-mapping-header">
				<h4 className="nf-form-settings__hs-mapping-title">{ __( 'Field mapping', 'neural-forms-lite' ) }</h4>
				<button type="button" className="nf-form-settings__hs-btn nf-form-settings__hs-btn--primary" onClick={ onAutoMap }>
					{ __( 'Auto-map fields', 'neural-forms-lite' ) }
				</button>
			</div>

			{ requiredMiss.length > 0 && (
				<div className="nf-form-settings__hs-warning" role="alert">
					<strong>{ __( 'Required HubSpot fields are not mapped:', 'neural-forms-lite' ) }</strong>
					<ul>
						{ requiredMiss.map( ( f ) => (
							<li key={ f.name }>{ f.label || f.name }</li>
						) ) }
					</ul>
				</div>
			) }

			<table className="nf-form-settings__hs-table">
				<thead>
					<tr>
						<th>{ __( 'HubSpot Field', 'neural-forms-lite' ) }</th>
						<th>{ __( 'Type', 'neural-forms-lite' ) }</th>
						<th>{ __( 'Required', 'neural-forms-lite' ) }</th>
						<th>{ __( 'Neural Forms Field', 'neural-forms-lite' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ hubspotFields.map( ( hs ) => {
						const compatible = compatibleNfFieldsForHubSpot( hs, nfFields );
						const current    = String( fieldMap[ hs.name ] || '' );
						return (
							<tr key={ hs.name }>
								<td>
									<div className="nf-form-settings__hs-cell-main">{ hs.label || hs.name }</div>
									<div className="nf-form-settings__hs-cell-sub"><code>{ hs.name }</code></div>
								</td>
								<td>
									<div className="nf-form-settings__hs-cell-main">{ hs.field_type || hs.type }</div>
									{ hs.type && hs.field_type && hs.type !== hs.field_type && (
										<div className="nf-form-settings__hs-cell-sub">{ hs.type }</div>
									) }
								</td>
								<td>
									{ hs.required
										? <span className="nf-form-settings__hs-required">{ __( 'Yes', 'neural-forms-lite' ) }</span>
										: <span className="nf-form-settings__hs-cell-sub">{ __( 'No', 'neural-forms-lite' ) }</span>
									}
								</td>
								<td>
									<select
										className="nf-form-settings__hs-select"
										value={ current }
										onChange={ ( e ) => onMappingChange( hs.name, e.target.value ) }
									>
										<option value="">{ __( '— Do not send —', 'neural-forms-lite' ) }</option>
										{ compatible.map( ( f ) => (
											<option key={ f.id } value={ f.id }>
												{ f.label || f.id } ({ f.type })
											</option>
										) ) }
									</select>
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>

			{ nfFields.length === 0 && (
				<p className="nf-form-settings__hs-empty">
					{ __( 'Add input fields (text, email, phone, etc.) to this form before mapping.', 'neural-forms-lite' ) }
				</p>
			) }
		</div>
	);
}
