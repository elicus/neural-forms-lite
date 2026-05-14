/**
 * Per-form WhatsApp notification panel.
 *
 * Lives inside the builder Settings → Integrations → WhatsApp
 * subtab. Reads / writes a single slice of form.settings:
 *
 *   form.settings.integrations.whatsapp = {
 *     enabled:           bool,
 *     recipients:        string[],   // digits-only E.164
 *     trigger:           'entry_created',
 *     template_name:     string,
 *     template_language: 'en_US' | ...,
 *     send_when:         'all_entries' | 'high_priority' | ...,
 *     include_summary:   bool
 *   }
 *
 * No tokens, no phone_number_id, no WABA id, no graph_version in
 * form settings — those live globally in the
 * neural_forms_pro_whatsapp_settings option managed by C1's admin
 * REST surface.
 *
 * REST surface consumed (Pro / C1):
 *   GET /neural-forms-pro/v1/integrations/whatsapp/status
 *   GET /neural-forms-pro/v1/integrations/whatsapp/templates   (optional)
 *
 * Save behavior:
 *   This panel never POSTs. Mapping mutations call `onChange`
 *   which threads back up to the builder's normal Save flow —
 *   the C4 (HubSpot-era) FormSettingsSanitizer runs on every save
 *   and now whitelists the WhatsApp shape.
 *
 * Four render states:
 *
 *   A. capability locked  → Pro upsell card
 *   B. WhatsApp not connected globally → "Connect first" card
 *      with link to /wp-admin/admin.php?page=neural-forms-
 *      integrations
 *   C. WhatsApp configured globally   → full settings UI
 *      (enable toggle + recipients textarea + trigger / send_when
 *       / template / language dropdowns + include_summary toggle
 *       + message preview card)
 *   D. status fetch failed → error banner only (no settings form
 *      while we don't know the global state)
 *
 * Templates list:
 *   When configured, we attempt to load approved templates via
 *   GET /templates. APPROVED templates appear in the dropdown.
 *   On failure (WABA scope missing, etc.) we fall back to a free
 *   text input so admins can type the template name manually —
 *   matches the spec's "allow manual text input fallback".
 */

import { useEffect, useMemo, useState, useCallback } from '@wordpress/element';
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

/**
 * Convert the recipients array we store (digits-only) into a
 * textarea-friendly string (one number per line, with a leading
 * `+` so admins recognise the country prefix shape).
 */
function recipientsToText( recipients ) {
	if ( ! Array.isArray( recipients ) ) return '';
	return recipients.map( ( r ) => '+' + String( r ) ).join( '\n' );
}

/**
 * Parse textarea input into a normalised digits-only array.
 * Mirrors the PHP sanitizer's logic exactly so the React preview
 * matches what the server will actually store.
 */
function parseRecipientsText( text ) {
	if ( typeof text !== 'string' ) return [];
	const out  = [];
	const seen = new Set();
	const lines = text.split( /[\r\n,]+/ );
	for ( const line of lines ) {
		const digits = String( line ).replace( /[^0-9]/g, '' );
		if ( digits === '' ) continue;
		if ( digits.length < 7 || digits.length > 15 ) continue;
		if ( seen.has( digits ) ) continue;
		seen.add( digits );
		out.push( digits );
		if ( out.length >= 10 ) break;
	}
	return out;
}

const SEND_WHEN_OPTIONS = [
	{ value: 'all_entries',   label: 'All entries',   needsAi: false },
	{ value: 'high_priority', label: 'High priority', needsAi: true  },
	{ value: 'needs_reply',   label: 'Needs reply',   needsAi: true  },
	{ value: 'spam_detected', label: 'Spam detected', needsAi: true  },
	{ value: 'not_spam',      label: 'Not spam',      needsAi: false },
];

export default function WhatsAppFormPanel( { whatsapp, onChange, capUnlocked, formSettings } ) {
	const [ statusLoading, setStatusLoading ] = useState( true );
	const [ status, setStatus ]               = useState( null );
	const [ statusError, setStatusError ]     = useState( '' );

	const [ templates,        setTemplates ]        = useState( [] );
	const [ templatesLoading, setTemplatesLoading ] = useState( false );
	const [ templatesError,   setTemplatesError ]   = useState( '' );
	const [ templatesLoaded,  setTemplatesLoaded ]  = useState( false );

	// Local mirror of the textarea so we don't re-derive it on
	// every keystroke. Initialised from the saved array; pushed
	// back to settings on blur (or when other fields change).
	const [ recipientsText, setRecipientsText ] = useState( () => recipientsToText( whatsapp?.recipients ) );

	const setWhatsappPatch = useCallback( ( patch ) => {
		onChange( { ...whatsapp, ...patch } );
	}, [ whatsapp, onChange ] );

	// ── Status fetch (gated on capability) ────────────────────────────
	useEffect( () => {
		if ( ! capUnlocked ) { setStatusLoading( false ); return; }
		let cancelled = false;
		( async () => {
			try {
				const s = await api( '/neural-forms-pro/v1/integrations/whatsapp/status' );
				if ( cancelled ) return;
				setStatus( s );
			} catch ( e ) {
				if ( cancelled ) return;
				setStatusError( errorMessage( e, __( 'Could not load WhatsApp status.', 'neural-forms-lite' ) ) );
			} finally {
				if ( ! cancelled ) setStatusLoading( false );
			}
		} )();
		return () => { cancelled = true; };
	}, [ capUnlocked ] );

	// ── Templates fetch (only when configured) ────────────────────────
	const reloadTemplates = useCallback( async () => {
		setTemplatesLoading( true );
		setTemplatesError( '' );
		try {
			const res = await api( '/neural-forms-pro/v1/integrations/whatsapp/templates' );
			setTemplates( Array.isArray( res?.templates ) ? res.templates : [] );
		} catch ( e ) {
			// Fallback to text input — the global WABA scope may not
			// permit template listing, but the admin can still type
			// a known-approved template name manually.
			setTemplates( [] );
			setTemplatesError( errorMessage( e, __( 'Could not load templates — enter a template name manually below.', 'neural-forms-lite' ) ) );
		} finally {
			setTemplatesLoading( false );
			setTemplatesLoaded( true );
		}
	}, [] );

	useEffect( () => {
		if ( status?.has_token && ! templatesLoaded && ! templatesLoading ) {
			reloadTemplates();
		}
	}, [ status?.has_token ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// ── Recipients persistence ────────────────────────────────────────
	const commitRecipients = useCallback( () => {
		const next = parseRecipientsText( recipientsText );
		// Only push when the parsed array differs from the stored
		// array (avoid setting form-dirty on every blur).
		const current = whatsapp?.recipients || [];
		const sameLength = current.length === next.length;
		const same = sameLength && current.every( ( v, i ) => v === next[ i ] );
		if ( same ) return;
		setWhatsappPatch( { recipients: next } );
	}, [ recipientsText, whatsapp, setWhatsappPatch ] );

	// ── Derived: approved templates first; current value preserved ────
	const approvedTemplates = useMemo(
		() => ( Array.isArray( templates ) ? templates.filter( ( t ) => ( t.status || '' ).toUpperCase() === 'APPROVED' ) : [] ),
		[ templates ]
	);
	const currentTemplate = String( whatsapp?.template_name || '' );
	const useTemplateDropdown = approvedTemplates.length > 0;

	// ── State A: capability locked ────────────────────────────────────
	if ( ! capUnlocked ) {
		return (
			<div className="nf-form-settings__hs-locked">
				<h3 className="nf-form-settings__hs-locked-title">
					{ __( 'WhatsApp notifications are a Pro feature', 'neural-forms-lite' ) }
				</h3>
				<p className="nf-form-settings__hs-locked-desc">
					{ __( 'WhatsApp notifications are available in Neural Forms Pro. Upgrade to send approved WhatsApp template messages to your team when entries are submitted.', 'neural-forms-lite' ) }
				</p>
			</div>
		);
	}

	if ( statusLoading ) {
		return <p className="nf-form-settings__hs-loading">{ __( 'Loading WhatsApp status…', 'neural-forms-lite' ) }</p>;
	}
	if ( statusError ) {
		return <div className="nf-form-settings__hs-error" role="alert">{ statusError }</div>;
	}

	// ── State B: WhatsApp not configured globally ─────────────────────
	if ( ! status?.has_token ) {
		return (
			<div className="nf-form-settings__hs-disconnected">
				<h3 className="nf-form-settings__hs-disconnected-title">
					{ __( 'WhatsApp is not connected', 'neural-forms-lite' ) }
				</h3>
				<p className="nf-form-settings__hs-disconnected-desc">
					{ __( 'Connect WhatsApp from Neural Forms → Integrations before enabling notifications for this form. The global connection holds the WhatsApp access token, phone number ID, and WABA ID — this form just chooses recipients and a template.', 'neural-forms-lite' ) }
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

	// ── State C: WhatsApp configured globally → full settings UI ──────
	const enabled = !! whatsapp?.enabled;

	// `send_when` derived state used by the dropdown + inline warning.
	const currentSendWhen    = String( whatsapp?.send_when || 'all_entries' );
	const currentSendOption  = SEND_WHEN_OPTIONS.find( ( o ) => o.value === currentSendWhen );
	const aiDependentSelected = !! currentSendOption?.needsAi;
	const autoAiEnabled      = String( formSettings?.process_with_ai?.mode || '' ) === 'every_entry';

	return (
		<div className="nf-form-settings__hs-panel">
			<div className="nf-form-settings__hs-header">
				<span className="nf-form-settings__hs-status nf-form-settings__hs-status--ok">
					{ status?.connected
						? __( 'Connected', 'neural-forms-lite' )
						: __( 'Configured', 'neural-forms-lite' )
					}
				</span>
			</div>

			<label className="nf-form-settings__hs-toggle-row">
				<input
					type="checkbox"
					checked={ enabled }
					onChange={ ( e ) => setWhatsappPatch( { enabled: !! e.target.checked } ) }
				/>
				<span>{ __( 'Send WhatsApp notifications when this form is submitted', 'neural-forms-lite' ) }</span>
			</label>

			<div className={ 'nf-form-settings__hs-config' + ( enabled ? '' : ' is-disabled' ) }>
				<div className="nf-form-settings__hs-field">
					<label htmlFor="nf-wa-recipients" className="nf-form-settings__hs-label">
						{ __( 'Recipients', 'neural-forms-lite' ) }
					</label>
					<textarea
						id="nf-wa-recipients"
						className="nf-form-settings__hs-textarea"
						rows={ 4 }
						value={ recipientsText }
						onChange={ ( e ) => setRecipientsText( e.target.value ) }
						onBlur={ commitRecipients }
						placeholder={ '+919999999999\n+918888888888' }
						disabled={ ! enabled }
					/>
					<p className="nf-form-settings__hs-cell-sub">
						{ __( 'Enter one WhatsApp number per line, including country code. Up to 10 recipients per form.', 'neural-forms-lite' ) }
					</p>
				</div>

				<div className="nf-form-settings__hs-field">
					<label htmlFor="nf-wa-trigger" className="nf-form-settings__hs-label">
						{ __( 'Trigger', 'neural-forms-lite' ) }
					</label>
					<select
						id="nf-wa-trigger"
						className="nf-form-settings__hs-select"
						value={ String( whatsapp?.trigger || 'entry_created' ) }
						onChange={ ( e ) => setWhatsappPatch( { trigger: e.target.value } ) }
						disabled={ ! enabled }
					>
						<option value="entry_created">{ __( 'New form submission', 'neural-forms-lite' ) }</option>
					</select>
				</div>

				<div className="nf-form-settings__hs-field">
					<label htmlFor="nf-wa-send-when" className="nf-form-settings__hs-label">
						{ __( 'Send when', 'neural-forms-lite' ) }
					</label>
					<select
						id="nf-wa-send-when"
						className="nf-form-settings__hs-select"
						value={ currentSendWhen }
						onChange={ ( e ) => setWhatsappPatch( { send_when: e.target.value } ) }
						disabled={ ! enabled }
					>
						{ SEND_WHEN_OPTIONS.map( ( opt ) => (
							<option key={ opt.value } value={ opt.value }>{ opt.label }</option>
						) ) }
					</select>
					<p className="nf-form-settings__hs-cell-sub">
						{ __( 'Choose which entries should trigger WhatsApp notifications. AI-based conditions (high priority, needs reply, spam detected) require entry analysis — if analysis is not available for this form, those conditions will not fire.', 'neural-forms-lite' ) }
					</p>
					{ aiDependentSelected && ! autoAiEnabled && (
						<p className="nf-form-settings__hs-cell-sub nf-form-settings__hs-required">
							{ __( 'Enable "Process every entry" under Process with AI for this form so this condition can be evaluated.', 'neural-forms-lite' ) }
						</p>
					) }
				</div>

				<div className="nf-form-settings__hs-field">
					<label htmlFor="nf-wa-template" className="nf-form-settings__hs-label">
						{ __( 'Template', 'neural-forms-lite' ) }
					</label>
					{ useTemplateDropdown ? (
						<select
							id="nf-wa-template"
							className="nf-form-settings__hs-select"
							value={ currentTemplate }
							onChange={ ( e ) => {
								const picked = approvedTemplates.find( ( t ) => t.name === e.target.value );
								setWhatsappPatch( {
									template_name:     e.target.value,
									template_language: picked?.language || ( whatsapp?.template_language || 'en_US' ),
								} );
							} }
							disabled={ ! enabled }
						>
							<option value="">{ __( '— Select an approved template —', 'neural-forms-lite' ) }</option>
							{ approvedTemplates.map( ( t ) => (
								<option key={ ( t.name || '' ) + ':' + ( t.language || '' ) } value={ t.name }>
									{ t.name }{ t.language ? ' (' + t.language + ')' : '' }
								</option>
							) ) }
						</select>
					) : (
						<input
							id="nf-wa-template"
							type="text"
							className="nf-form-settings__hs-input"
							value={ currentTemplate }
							onChange={ ( e ) => setWhatsappPatch( { template_name: e.target.value } ) }
							placeholder="neural_forms_entry_alert"
							disabled={ ! enabled }
						/>
					) }
					{ templatesError && (
						<p className="nf-form-settings__hs-cell-sub">{ templatesError }</p>
					) }
					<button
						type="button"
						className="nf-form-settings__hs-btn"
						onClick={ reloadTemplates }
						disabled={ ! enabled || templatesLoading }
						style={ { marginTop: '6px' } }
					>
						{ templatesLoading ? __( 'Refreshing…', 'neural-forms-lite' ) : __( 'Refresh templates', 'neural-forms-lite' ) }
					</button>
				</div>

				<div className="nf-form-settings__hs-field">
					<label htmlFor="nf-wa-lang" className="nf-form-settings__hs-label">
						{ __( 'Template Language', 'neural-forms-lite' ) }
					</label>
					<input
						id="nf-wa-lang"
						type="text"
						className="nf-form-settings__hs-input"
						value={ String( whatsapp?.template_language || 'en_US' ) }
						onChange={ ( e ) => setWhatsappPatch( { template_language: e.target.value } ) }
						placeholder="en_US"
						disabled={ ! enabled }
					/>
				</div>

				<label className="nf-form-settings__hs-toggle-row">
					<input
						type="checkbox"
						checked={ whatsapp?.include_summary !== false }
						onChange={ ( e ) => setWhatsappPatch( { include_summary: !! e.target.checked } ) }
						disabled={ ! enabled }
					/>
					<span>{ __( 'Include submitter / priority / entry-link variables in the template', 'neural-forms-lite' ) }</span>
				</label>

				<div className="nf-form-settings__wa-preview">
					<h4 className="nf-form-settings__hs-mapping-title">{ __( 'Message preview (recommended template)', 'neural-forms-lite' ) }</h4>
					<pre className="nf-form-settings__wa-preview-body">
{ `New Neural Forms entry received.

Form: {{1}}
Submitter: {{2}}
Priority: {{3}}
View entry: {{4}}` }
					</pre>
					<p className="nf-form-settings__hs-cell-sub">
						{ __( 'Variable order maps to: form name, submitter, priority, entry detail link. Approve a template with this body in Meta Business Manager (name: neural_forms_entry_alert, category: UTILITY) so deliveries work in v1.', 'neural-forms-lite' ) }
					</p>
				</div>
			</div>
		</div>
	);
}
