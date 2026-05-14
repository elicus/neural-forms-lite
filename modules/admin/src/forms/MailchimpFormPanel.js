/**
 * Per-form Mailchimp panel.
 *
 * Lives inside the builder Settings → Integrations → Mailchimp
 * subtab. Reads / writes a single slice of form.settings:
 *
 *   form.settings.integrations.mailchimp = {
 *     enabled:         bool,
 *     audience_id:     string,
 *     audience_name:   string,
 *     email_field:     string (NF field id),
 *     status:          'subscribed' | 'pending',
 *     update_existing: bool,
 *     tags:            string[],
 *     merge_map:       { <MERGE_TAG>: <nf_field_id> }
 *   }
 *
 * No api_key, no server_prefix in form settings — those live globally
 * in the neural_forms_pro_mailchimp_settings option managed by C1.
 * The FormSettingsSanitizer (Lite) whitelists this exact shape and
 * strips any tampered global / secret / telemetry keys.
 *
 * REST surface consumed (Pro / C1):
 *   GET /neural-forms-pro/v1/integrations/mailchimp/status
 *   GET /neural-forms-pro/v1/integrations/mailchimp/audiences
 *   GET /neural-forms-pro/v1/integrations/mailchimp/audiences/{id}/merge-fields
 *
 * Save behavior:
 *   This panel never POSTs. Mapping mutations call `onChange` which
 *   threads back up to the builder's normal Save flow.
 *
 * Three render states (per C3 spec):
 *   1. capability locked → Pro upsell card
 *   2. Mailchimp not connected globally → "Connect first" card
 *      with link to /wp-admin/admin.php?page=neural-forms-integrations
 *   3. Mailchimp configured globally → full settings UI
 */

import { useEffect, useMemo, useState, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../api';
import {
	compatibleNfFieldsForMailchimp,
	autoMapMailchimpMergeFields,
} from './mailchimpAutoMap';

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

/** Tags as stored: array of strings. Editor surface: comma-separated input. */
function tagsToText( tags ) {
	if ( ! Array.isArray( tags ) ) return '';
	return tags.join( ', ' );
}
function parseTagsText( raw ) {
	if ( typeof raw !== 'string' ) return [];
	const out  = [];
	const seen = new Set();
	for ( const part of raw.split( ',' ) ) {
		const t = part.trim();
		if ( t === '' ) continue;
		const key = t.toLowerCase();
		if ( seen.has( key ) ) continue;
		seen.add( key );
		out.push( t );
		if ( out.length >= 20 ) break;
	}
	return out;
}

export default function MailchimpFormPanel( { mailchimp, onChange, fields, capUnlocked } ) {
	const [ statusLoading, setStatusLoading ] = useState( true );
	const [ status, setStatus ]               = useState( null );
	const [ statusError, setStatusError ]     = useState( '' );

	const [ audiences,        setAudiences ]        = useState( [] );
	const [ audiencesLoading, setAudiencesLoading ] = useState( false );
	const [ audiencesError,   setAudiencesError ]   = useState( '' );
	const [ audiencesLoaded,  setAudiencesLoaded ]  = useState( false );

	const [ mergeFields,        setMergeFields ]        = useState( [] );
	const [ mergeFieldsLoading, setMergeFieldsLoading ] = useState( false );
	const [ mergeFieldsError,   setMergeFieldsError ]   = useState( '' );

	// Local mirror of the tags textbox so we don't re-derive on every keystroke.
	const [ tagsText, setTagsText ] = useState( () => tagsToText( mailchimp?.tags ) );

	const setMailchimpPatch = useCallback( ( patch ) => {
		onChange( { ...mailchimp, ...patch } );
	}, [ mailchimp, onChange ] );

	// ── Status fetch (gated on capability) ────────────────────────────
	useEffect( () => {
		if ( ! capUnlocked ) { setStatusLoading( false ); return; }
		let cancelled = false;
		( async () => {
			try {
				const s = await api( '/neural-forms-pro/v1/integrations/mailchimp/status' );
				if ( cancelled ) return;
				setStatus( s );
			} catch ( e ) {
				if ( cancelled ) return;
				setStatusError( errorMessage( e, __( 'Could not load Mailchimp status.', 'neural-forms-lite' ) ) );
			} finally {
				if ( ! cancelled ) setStatusLoading( false );
			}
		} )();
		return () => { cancelled = true; };
	}, [ capUnlocked ] );

	// ── Audiences fetch (only when configured) ────────────────────────
	const reloadAudiences = useCallback( async () => {
		setAudiencesLoading( true );
		setAudiencesError( '' );
		try {
			const res = await api( '/neural-forms-pro/v1/integrations/mailchimp/audiences' );
			setAudiences( Array.isArray( res?.audiences ) ? res.audiences : [] );
		} catch ( e ) {
			setAudiences( [] );
			setAudiencesError( errorMessage( e, __( 'Could not load audiences.', 'neural-forms-lite' ) ) );
		} finally {
			setAudiencesLoading( false );
			setAudiencesLoaded( true );
		}
	}, [] );

	useEffect( () => {
		if ( status?.has_api_key && ! audiencesLoaded && ! audiencesLoading ) {
			reloadAudiences();
		}
	}, [ status?.has_api_key ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// ── Merge-fields fetch keyed on the chosen audience ───────────────
	const audienceId = String( mailchimp?.audience_id || '' );

	const reloadMergeFields = useCallback( async ( id ) => {
		if ( ! id ) {
			setMergeFields( [] );
			setMergeFieldsError( '' );
			return;
		}
		setMergeFieldsLoading( true );
		setMergeFieldsError( '' );
		try {
			const res = await api( '/neural-forms-pro/v1/integrations/mailchimp/audiences/' + encodeURIComponent( id ) + '/merge-fields' );
			setMergeFields( Array.isArray( res?.merge_fields ) ? res.merge_fields : [] );
		} catch ( e ) {
			setMergeFields( [] );
			setMergeFieldsError( errorMessage( e, __( 'Could not load merge fields.', 'neural-forms-lite' ) ) );
		} finally {
			setMergeFieldsLoading( false );
		}
	}, [] );

	useEffect( () => {
		if ( audienceId !== '' && status?.has_api_key ) {
			reloadMergeFields( audienceId );
		} else {
			setMergeFields( [] );
		}
	}, [ audienceId, status?.has_api_key, reloadMergeFields ] );

	const commitTags = useCallback( () => {
		const next = parseTagsText( tagsText );
		const current = mailchimp?.tags || [];
		const same = current.length === next.length && current.every( ( v, i ) => v === next[ i ] );
		if ( same ) return;
		setMailchimpPatch( { tags: next } );
	}, [ tagsText, mailchimp, setMailchimpPatch ] );

	// ── State A: capability locked ────────────────────────────────────
	if ( ! capUnlocked ) {
		return (
			<div className="nf-form-settings__hs-locked">
				<h3 className="nf-form-settings__hs-locked-title">
					{ __( 'Mailchimp sync is a Pro feature', 'neural-forms-lite' ) }
				</h3>
				<p className="nf-form-settings__hs-locked-desc">
					{ __( 'Mailchimp sync is available in Neural Forms Pro. Upgrade to subscribe submitters to your Mailchimp audiences automatically, with field-to-merge-tag mapping.', 'neural-forms-lite' ) }
				</p>
			</div>
		);
	}

	if ( statusLoading ) {
		return <p className="nf-form-settings__hs-loading">{ __( 'Loading Mailchimp status…', 'neural-forms-lite' ) }</p>;
	}
	if ( statusError ) {
		return <div className="nf-form-settings__hs-error" role="alert">{ statusError }</div>;
	}

	// ── State B: Mailchimp not configured globally ────────────────────
	if ( ! status?.has_api_key ) {
		return (
			<div className="nf-form-settings__hs-disconnected">
				<h3 className="nf-form-settings__hs-disconnected-title">
					{ __( 'Mailchimp is not connected', 'neural-forms-lite' ) }
				</h3>
				<p className="nf-form-settings__hs-disconnected-desc">
					{ __( 'Connect Mailchimp from Neural Forms → Integrations before enabling sync for this form. The global connection holds the Mailchimp API key + server prefix — this form just picks an audience and maps fields.', 'neural-forms-lite' ) }
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

	// ── State C: Mailchimp configured globally → full settings UI ─────
	const enabled         = !! mailchimp?.enabled;
	const currentStatus   = String( mailchimp?.status || 'subscribed' );
	const updateExisting  = mailchimp?.update_existing !== false;
	const emailFieldId    = String( mailchimp?.email_field || '' );
	const nfFields        = Array.isArray( fields ) ? fields : [];
	const emailNfFields   = nfFields.filter( ( f ) => f.type === 'email' );
	const noEmailField    = enabled && emailFieldId === '';
	const mergeMap        = ( mailchimp?.merge_map && typeof mailchimp.merge_map === 'object' ) ? mailchimp.merge_map : {};
	const requiredMissing = mergeFields
		.filter( ( m ) => m.required && String( m.tag || '' ).toUpperCase() !== 'EMAIL' )
		.filter( ( m ) => ! mergeMap[ String( m.tag || '' ).toUpperCase() ] );

	const handleAudienceChange = ( newId ) => {
		const picked = audiences.find( ( a ) => a.id === newId );
		setMailchimpPatch( {
			audience_id:   newId,
			audience_name: picked?.name || '',
			// Clear the mapping when the audience changes — merge tags
			// are audience-specific. Email_field is preserved since it
			// references the form's own schema, not the audience.
			merge_map:     {},
		} );
	};

	const handleMergeMappingChange = ( tag, nfFieldId ) => {
		const next = { ...mergeMap };
		if ( ! nfFieldId ) {
			delete next[ tag ];
		} else {
			next[ tag ] = nfFieldId;
		}
		setMailchimpPatch( { merge_map: next } );
	};

	const handleAutoMap = () => {
		const proposed = autoMapMailchimpMergeFields( mergeFields, nfFields, mergeMap );
		setMailchimpPatch( { merge_map: proposed } );
	};

	return (
		<div className="nf-form-settings__hs-panel">
			<div className="nf-form-settings__hs-header">
				<span className="nf-form-settings__hs-status nf-form-settings__hs-status--ok">
					{ status?.connected
						? __( 'Connected', 'neural-forms-lite' )
						: __( 'Configured', 'neural-forms-lite' )
					}
				</span>
				{ status?.account_name && (
					<span className="nf-form-settings__hs-cell-sub" style={ { marginLeft: '10px' } }>
						{ status.account_name }
					</span>
				) }
			</div>

			<label className="nf-form-settings__hs-toggle-row">
				<input
					type="checkbox"
					checked={ enabled }
					onChange={ ( e ) => setMailchimpPatch( { enabled: !! e.target.checked } ) }
				/>
				<span>{ __( 'Sync submissions to a Mailchimp audience', 'neural-forms-lite' ) }</span>
			</label>

			<div className={ 'nf-form-settings__hs-config' + ( enabled ? '' : ' is-disabled' ) }>

				{/* Audience picker + refresh */}
				<div className="nf-form-settings__hs-field">
					<label htmlFor="nf-mc-audience" className="nf-form-settings__hs-label">
						{ __( 'Audience', 'neural-forms-lite' ) }
					</label>
					{ audiencesError && (
						<div className="nf-form-settings__hs-error" role="alert">{ audiencesError }</div>
					) }
					<select
						id="nf-mc-audience"
						className="nf-form-settings__hs-select"
						value={ audienceId }
						onChange={ ( e ) => handleAudienceChange( e.target.value ) }
						disabled={ ! enabled || audiencesLoading }
					>
						<option value="">{ __( '— Select an audience —', 'neural-forms-lite' ) }</option>
						{ audiences.map( ( a ) => (
							<option key={ a.id } value={ a.id }>
								{ a.name }{ ' ' }
								{ Number.isFinite( a.member_count )
									? '(' + a.member_count.toLocaleString() + ' members)'
									: ''
								}
							</option>
						) ) }
					</select>
					<button
						type="button"
						className="nf-form-settings__hs-btn"
						onClick={ reloadAudiences }
						disabled={ ! enabled || audiencesLoading }
						style={ { marginTop: '6px' } }
					>
						{ audiencesLoading ? __( 'Refreshing…', 'neural-forms-lite' ) : __( 'Refresh audiences', 'neural-forms-lite' ) }
					</button>
				</div>

				{/* Email field picker */}
				<div className="nf-form-settings__hs-field">
					<label htmlFor="nf-mc-email-field" className="nf-form-settings__hs-label">
						{ __( 'Email field', 'neural-forms-lite' ) }
					</label>
					<select
						id="nf-mc-email-field"
						className="nf-form-settings__hs-select"
						value={ emailFieldId }
						onChange={ ( e ) => setMailchimpPatch( { email_field: e.target.value } ) }
						disabled={ ! enabled }
					>
						<option value="">{ __( '— Required: pick the email field —', 'neural-forms-lite' ) }</option>
						{ emailNfFields.map( ( f ) => (
							<option key={ f.id } value={ f.id }>
								{ f.label || f.id }
							</option>
						) ) }
					</select>
					{ emailNfFields.length === 0 && (
						<p className="nf-form-settings__hs-cell-sub">
							{ __( 'Add an email field to this form to enable Mailchimp sync.', 'neural-forms-lite' ) }
						</p>
					) }
					{ noEmailField && emailNfFields.length > 0 && (
						<p className="nf-form-settings__hs-cell-sub nf-form-settings__hs-required">
							{ __( 'Required — Mailchimp needs an email address for every subscriber.', 'neural-forms-lite' ) }
						</p>
					) }
				</div>

				{/* Subscriber status */}
				<div className="nf-form-settings__hs-field">
					<label htmlFor="nf-mc-status" className="nf-form-settings__hs-label">
						{ __( 'Subscriber status', 'neural-forms-lite' ) }
					</label>
					<select
						id="nf-mc-status"
						className="nf-form-settings__hs-select"
						value={ currentStatus }
						onChange={ ( e ) => setMailchimpPatch( { status: e.target.value } ) }
						disabled={ ! enabled }
					>
						<option value="subscribed">{ __( 'Subscribed', 'neural-forms-lite' ) }</option>
						<option value="pending">{ __( 'Pending — double opt-in', 'neural-forms-lite' ) }</option>
					</select>
					<p className="nf-form-settings__hs-cell-sub">
						{ __( 'Pending sends Mailchimp\'s confirmation email; Subscribed adds the contact immediately.', 'neural-forms-lite' ) }
					</p>
				</div>

				{/* Update existing toggle */}
				<label className="nf-form-settings__hs-toggle-row">
					<input
						type="checkbox"
						checked={ updateExisting }
						onChange={ ( e ) => setMailchimpPatch( { update_existing: !! e.target.checked } ) }
						disabled={ ! enabled }
					/>
					<span>{ __( 'Update existing subscribers (merge fields and tags) when their email matches', 'neural-forms-lite' ) }</span>
				</label>

				{/* Tags */}
				<div className="nf-form-settings__hs-field">
					<label htmlFor="nf-mc-tags" className="nf-form-settings__hs-label">
						{ __( 'Tags', 'neural-forms-lite' ) }
					</label>
					<input
						id="nf-mc-tags"
						type="text"
						className="nf-form-settings__hs-input"
						value={ tagsText }
						onChange={ ( e ) => setTagsText( e.target.value ) }
						onBlur={ commitTags }
						placeholder="Website Lead, Newsletter"
						disabled={ ! enabled }
					/>
					<p className="nf-form-settings__hs-cell-sub">
						{ __( 'Comma-separated. Up to 20 tags. Leave blank to use the global default tags from Integrations → Mailchimp.', 'neural-forms-lite' ) }
					</p>
				</div>

				{/* Mapping table */}
				<MailchimpMappingTable
					mergeFields={ mergeFields }
					mergeFieldsLoading={ mergeFieldsLoading }
					mergeFieldsError={ mergeFieldsError }
					audienceId={ audienceId }
					nfFields={ nfFields }
					mergeMap={ mergeMap }
					onMappingChange={ handleMergeMappingChange }
					onAutoMap={ handleAutoMap }
					requiredMissing={ requiredMissing }
				/>
			</div>
		</div>
	);
}

function MailchimpMappingTable( {
	mergeFields,
	mergeFieldsLoading,
	mergeFieldsError,
	audienceId,
	nfFields,
	mergeMap,
	onMappingChange,
	onAutoMap,
	requiredMissing,
} ) {
	if ( ! audienceId ) {
		return (
			<p className="nf-form-settings__hs-cell-sub" style={ { marginTop: '6px' } }>
				{ __( 'Select an audience above to load its merge fields.', 'neural-forms-lite' ) }
			</p>
		);
	}
	if ( mergeFieldsLoading ) {
		return <p className="nf-form-settings__hs-loading">{ __( 'Loading merge fields…', 'neural-forms-lite' ) }</p>;
	}
	if ( mergeFieldsError ) {
		return <div className="nf-form-settings__hs-error" role="alert">{ mergeFieldsError }</div>;
	}

	// The reserved EMAIL tag isn't part of merge_map — the email_field
	// dropdown handles it — so drop it from the mapping surface to
	// avoid confusing admins.
	const mappableFields = ( mergeFields || [] ).filter(
		( m ) => String( m.tag || '' ).toUpperCase() !== 'EMAIL'
	);

	if ( mappableFields.length === 0 ) {
		return (
			<p className="nf-form-settings__hs-cell-sub" style={ { marginTop: '6px' } }>
				{ __( 'This audience has no extra merge fields beyond EMAIL. Only the email address will be synced.', 'neural-forms-lite' ) }
			</p>
		);
	}

	return (
		<div className="nf-form-settings__hs-mapping">
			<div className="nf-form-settings__hs-mapping-header">
				<h4 className="nf-form-settings__hs-mapping-title">{ __( 'Merge field mapping', 'neural-forms-lite' ) }</h4>
				<button type="button" className="nf-form-settings__hs-btn nf-form-settings__hs-btn--primary" onClick={ onAutoMap }>
					{ __( 'Auto-map fields', 'neural-forms-lite' ) }
				</button>
			</div>

			{ requiredMissing.length > 0 && (
				<div className="nf-form-settings__hs-warning" role="alert">
					<strong>{ __( 'Required Mailchimp merge fields are not mapped:', 'neural-forms-lite' ) }</strong>
					<ul>
						{ requiredMissing.map( ( m ) => (
							<li key={ m.tag }>{ ( m.name || m.tag ) }{ ' ' }<code>{ m.tag }</code></li>
						) ) }
					</ul>
				</div>
			) }

			<table className="nf-form-settings__hs-table">
				<thead>
					<tr>
						<th>{ __( 'Mailchimp Merge Field', 'neural-forms-lite' ) }</th>
						<th>{ __( 'Type', 'neural-forms-lite' ) }</th>
						<th>{ __( 'Required', 'neural-forms-lite' ) }</th>
						<th>{ __( 'Neural Forms Field', 'neural-forms-lite' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ mappableFields.map( ( m ) => {
						const tag = String( m.tag || '' ).toUpperCase();
						const compatible = compatibleNfFieldsForMailchimp( m, nfFields );
						const current    = String( mergeMap[ tag ] || '' );
						return (
							<tr key={ tag }>
								<td>
									<div className="nf-form-settings__hs-cell-main">{ m.name || tag }</div>
									<div className="nf-form-settings__hs-cell-sub"><code>{ tag }</code></div>
								</td>
								<td>
									<div className="nf-form-settings__hs-cell-main">{ m.type || '—' }</div>
								</td>
								<td>
									{ m.required
										? <span className="nf-form-settings__hs-required">{ __( 'Yes', 'neural-forms-lite' ) }</span>
										: <span className="nf-form-settings__hs-cell-sub">{ __( 'No', 'neural-forms-lite' ) }</span>
									}
								</td>
								<td>
									<select
										className="nf-form-settings__hs-select"
										value={ current }
										onChange={ ( e ) => onMappingChange( tag, e.target.value ) }
									>
										<option value="">{ __( '— Do not send —', 'neural-forms-lite' ) }</option>
										{ compatible.map( ( f ) => (
											<option key={ f.id } value={ f.id }>
												{ ( f.label || f.id ) } ({ f.type })
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
