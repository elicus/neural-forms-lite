/**
 * Per-form Zapier panel.
 *
 * Lives inside the builder Settings → Integrations → Zapier
 * subtab. Reads / writes a single slice of form.settings:
 *
 *   form.settings.integrations.zapier = {
 *     enabled:                 bool,
 *     webhook_ids:             number[],  // ids from the global hooks list
 *     send_when:               'all_entries' | …,
 *     include_fields_readable: bool,
 *     include_raw_fields:      bool,
 *     include_source:          bool
 *   }
 *
 * Per-form settings are a SELECTION on top of the global Zapier
 * webhooks (which live in Pro's `neural_forms_pro_integrations_webhooks`
 * option). No URLs / secrets are stored on the form — the sanitizer
 * rejects them.
 *
 * Three render states:
 *   A. capability locked → Pro upsell card
 *   B. capability unlocked but no Zapier webhooks configured globally
 *      → "Create one in Integrations" card
 *   C. capability unlocked + webhooks present → checkbox-list selector
 *      + send_when dropdown + payload toggles
 *
 * REST surface consumed (Pro):
 *   GET /neural-forms-pro/v1/integrations/zapier/webhooks
 *     Returns every webhook row; we filter to provider=zapier active
 *     rows client-side and surface a masked URL only — admins should
 *     edit URLs from the global Integrations page, not from here.
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
 * Mask a stored webhook URL for display. Mirrors the helper in
 * screens/Integrations.js — duplicated here to keep this panel
 * self-contained (the bundle already inlines its dependencies, so
 * a shared import would mean a cross-bundle change with no real
 * payoff).
 *
 *   https://hooks.zapier.com/.../cdef
 */
function maskWebhookUrl( url ) {
	if ( ! url || typeof url !== 'string' ) return '';
	try {
		const u    = new URL( url );
		const path = ( u.pathname || '' ).replace( /^\//, '' );
		const tail = path.length > 4 ? path.slice( -4 ) : path;
		return `${ u.protocol }//${ u.host }/...${ tail }`;
	} catch ( e ) {
		return '';
	}
}

const SEND_WHEN_OPTIONS = [
	{ value: 'all_entries',   label: 'All entries',   needsAi: false },
	{ value: 'high_priority', label: 'High priority — Coming soon', needsAi: true, disabled: true },
	{ value: 'needs_reply',   label: 'Needs reply — Coming soon',   needsAi: true, disabled: true },
	{ value: 'spam_detected', label: 'Spam detected — Coming soon', needsAi: true, disabled: true },
	{ value: 'not_spam',      label: 'Not spam — Coming soon',      needsAi: true, disabled: true },
];

export default function ZapierFormPanel( { zapier, onChange, capUnlocked } ) {
	const [ webhooksLoading, setWebhooksLoading ] = useState( true );
	const [ webhooks,        setWebhooks ]        = useState( [] );
	const [ webhooksError,   setWebhooksError ]   = useState( '' );

	const setZapierPatch = useCallback( ( patch ) => {
		onChange( { ...zapier, ...patch } );
	}, [ zapier, onChange ] );

	// ── Webhooks fetch (gated on capability) ───────────────────────
	useEffect( () => {
		if ( ! capUnlocked ) { setWebhooksLoading( false ); return; }
		let cancelled = false;
		( async () => {
			try {
				const res  = await api( '/neural-forms-pro/v1/integrations/zapier/webhooks' );
				const rows = Array.isArray( res?.webhooks ) ? res.webhooks : [];
				if ( cancelled ) return;
				setWebhooks( rows );
			} catch ( e ) {
				if ( cancelled ) return;
				setWebhooksError( errorMessage( e, __( 'Could not load Zapier webhooks.', 'neural-forms-lite' ) ) );
			} finally {
				if ( ! cancelled ) setWebhooksLoading( false );
			}
		} )();
		return () => { cancelled = true; };
	}, [ capUnlocked ] );

	// ── State A: capability locked ─────────────────────────────────
	if ( ! capUnlocked ) {
		return (
			<div className="nf-form-settings__hs-locked">
				<h3 className="nf-form-settings__hs-locked-title">
					{ __( 'Zapier integration is a Pro feature', 'neural-forms-lite' ) }
				</h3>
				<p className="nf-form-settings__hs-locked-desc">
					{ __( 'Zapier delivery is available in Neural Forms Pro. Upgrade to send form submissions to Zapier and connect with thousands of apps.', 'neural-forms-lite' ) }
				</p>
			</div>
		);
	}

	if ( webhooksLoading ) {
		return <p className="nf-form-settings__hs-loading">{ __( 'Loading Zapier webhooks…', 'neural-forms-lite' ) }</p>;
	}
	if ( webhooksError ) {
		return <div className="nf-form-settings__hs-error" role="alert">{ webhooksError }</div>;
	}

	// ── State B: no webhooks configured globally ───────────────────
	if ( ! webhooks || webhooks.length === 0 ) {
		return (
			<div className="nf-form-settings__hs-disconnected">
				<h3 className="nf-form-settings__hs-disconnected-title">
					{ __( 'Zapier is not configured', 'neural-forms-lite' ) }
				</h3>
				<p className="nf-form-settings__hs-disconnected-desc">
					{ __( 'Create a Zapier Catch Hook from Neural Forms → Integrations before enabling Zapier for this form. The global page is where Zap URLs live; this form just picks which Zaps fire.', 'neural-forms-lite' ) }
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

	// ── State C: webhooks configured → full settings UI ────────────
	const enabled         = !! zapier?.enabled;
	const selectedIds     = Array.isArray( zapier?.webhook_ids ) ? zapier.webhook_ids.map( ( v ) => Number( v ) ).filter( ( n ) => n > 0 ) : [];
	const currentSendWhen = String( zapier?.send_when || 'all_entries' );
	const inclReadable    = zapier?.include_fields_readable !== false;
	const inclRaw         = zapier?.include_raw_fields      !== false;
	const inclSource      = zapier?.include_source          !== false;

	const handleToggleWebhook = ( id, checked ) => {
		const next = new Set( selectedIds );
		if ( checked ) next.add( id ); else next.delete( id );
		setZapierPatch( { webhook_ids: Array.from( next ).slice( 0, 10 ) } );
	};

	return (
		<div className="nf-form-settings__hs-panel">
			<div className="nf-form-settings__hs-header">
				<span className="nf-form-settings__hs-status nf-form-settings__hs-status--ok">
					{ sprintf(
						/* translators: %d: number of webhooks available */
						__( '%d Zapier webhook(s) available', 'neural-forms-lite' ),
						webhooks.length
					) }
				</span>
			</div>

			<label className="nf-form-settings__hs-toggle-row">
				<input
					type="checkbox"
					checked={ enabled }
					onChange={ ( e ) => setZapierPatch( { enabled: !! e.target.checked } ) }
				/>
				<span>{ __( 'Send submissions to Zapier when this form is submitted', 'neural-forms-lite' ) }</span>
			</label>

			<div className={ 'nf-form-settings__hs-config' + ( enabled ? '' : ' is-disabled' ) }>

				{/* Webhook selector */}
				<div className="nf-form-settings__hs-field">
					<label className="nf-form-settings__hs-label">
						{ __( 'Send to these Zaps', 'neural-forms-lite' ) }
					</label>
					<ul className="nf-form-settings__zap-hook-list" role="list">
						{ webhooks.map( ( w ) => {
							const id       = Number( w.id );
							const checked  = selectedIds.includes( id );
							const masked   = maskWebhookUrl( w.target_url || '' );
							const isActive = ( w.status || '' ) === 'active';
							const scopeNote = Number( w.form_id ) === 0
								? __( 'All forms', 'neural-forms-lite' )
								: sprintf( /* translators: %d: form id */ __( 'Form #%d', 'neural-forms-lite' ), Number( w.form_id ) );
							return (
								<li key={ id } className={ 'nf-form-settings__zap-hook-item' + ( isActive ? '' : ' is-inactive' ) }>
									<label className="nf-form-settings__zap-hook-row">
										<input
											type="checkbox"
											checked={ checked }
											onChange={ ( e ) => handleToggleWebhook( id, !! e.target.checked ) }
											disabled={ ! enabled || ! isActive }
										/>
										<span className="nf-form-settings__zap-hook-meta">
											<code>{ masked || '(missing URL)' }</code>
											<span className="nf-form-settings__hs-cell-sub">
												{ scopeNote }
												{ ' · ' }
												{ isActive ? __( 'active', 'neural-forms-lite' ) : __( 'inactive', 'neural-forms-lite' ) }
											</span>
										</span>
									</label>
								</li>
							);
						} ) }
					</ul>
					<p className="nf-form-settings__hs-cell-sub">
						{ __( 'Pick which Zaps should receive submissions from this form. Inactive Zaps are skipped. Edit URLs or create new Zaps from Neural Forms → Integrations → Zapier.', 'neural-forms-lite' ) }
					</p>
					{ enabled && selectedIds.length === 0 && (
						<p className="nf-form-settings__hs-cell-sub nf-form-settings__hs-required">
							{ __( 'Select at least one Zap — Zapier is enabled but no Zaps are selected, so no deliveries will fire.', 'neural-forms-lite' ) }
						</p>
					) }
				</div>

				{/* Send when */}
				<div className="nf-form-settings__hs-field">
					<label htmlFor="nf-zap-send-when" className="nf-form-settings__hs-label">
						{ __( 'Send when', 'neural-forms-lite' ) }
					</label>
					<select
						id="nf-zap-send-when"
						className="nf-form-settings__hs-select"
						value={ currentSendWhen }
						onChange={ ( e ) => setZapierPatch( { send_when: e.target.value } ) }
						disabled={ ! enabled }
					>
						{ SEND_WHEN_OPTIONS.map( ( opt ) => (
							<option key={ opt.value } value={ opt.value } disabled={ opt.disabled }>{ opt.label }</option>
						) ) }
					</select>
					<p className="nf-form-settings__hs-cell-sub">
						{ __( 'v1 fires on every submission. Neural AI-conditional triggers will activate once the analyzed-entry hook is wired into the Zapier dispatcher.', 'neural-forms-lite' ) }
					</p>
				</div>

				{/* Payload toggles */}
				<div className="nf-form-settings__hs-field">
					<label className="nf-form-settings__hs-label">
						{ __( 'Include in payload', 'neural-forms-lite' ) }
					</label>
					<label className="nf-form-settings__hs-toggle-row">
						<input
							type="checkbox"
							checked={ inclReadable }
							onChange={ ( e ) => setZapierPatch( { include_fields_readable: !! e.target.checked } ) }
							disabled={ ! enabled }
						/>
						<span>{ __( 'Readable field labels + values (fields_readable)', 'neural-forms-lite' ) }</span>
					</label>
					<label className="nf-form-settings__hs-toggle-row">
						<input
							type="checkbox"
							checked={ inclRaw }
							onChange={ ( e ) => setZapierPatch( { include_raw_fields: !! e.target.checked } ) }
							disabled={ ! enabled }
						/>
						<span>{ __( 'Raw field ids → values map (fields)', 'neural-forms-lite' ) }</span>
					</label>
					<label className="nf-form-settings__hs-toggle-row">
						<input
							type="checkbox"
							checked={ inclSource }
							onChange={ ( e ) => setZapierPatch( { include_source: !! e.target.checked } ) }
							disabled={ ! enabled }
						/>
						<span>{ __( 'Source / page URL (source)', 'neural-forms-lite' ) }</span>
					</label>
					<p className="nf-form-settings__hs-cell-sub">
						{ __( 'These toggles are stored on the form. The Zapier payload format is unchanged in v1 — toggles will start filtering the payload once the dispatcher honours them.', 'neural-forms-lite' ) }
					</p>
				</div>
			</div>
		</div>
	);
}
