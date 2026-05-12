/**
 * Integrations screen.
 *
 * Pro-locked admin page. Lite owns the React UI; Pro provides the
 * backend (capability filter + REST endpoints under
 * /neural-forms-pro/v1/integrations/...). When the INTEGRATIONS
 * capability is locked (no Pro / Pro deactivated), the entire page
 * renders a single Pro-upsell card.
 *
 * Render modes:
 *   - capabilities.integrations === false → LockedView (upsell).
 *   - capabilities.integrations === true  → five-tab WAI-ARIA tablist
 *     - Zapier tab renders the full configure UI (ZapierPanel,
 *       backed by /neural-forms-pro/v1/integrations/zapier/webhooks).
 *     - HubSpot / WhatsApp / Mailchimp / Google Forms tabs render
 *       "Coming soon" cards — those providers aren't built yet.
 *
 * REST surface used by ZapierPanel (admin-gated, capability-gated):
 *   GET    /neural-forms-pro/v1/integrations/zapier/webhooks
 *   POST   /neural-forms-pro/v1/integrations/zapier/webhooks
 *   PUT    /neural-forms-pro/v1/integrations/zapier/webhooks/{id}
 *   DELETE /neural-forms-pro/v1/integrations/zapier/webhooks/{id}
 *   POST   /neural-forms-pro/v1/integrations/zapier/webhooks/{id}/test
 *
 * The Lite REST helper (../api.js) already handles the nonce middleware
 * + cross-namespace path routing, so this screen just calls
 * api( '/neural-forms-pro/v1/...' ) directly.
 *
 * Class naming: `.neural-forms-integrations__*` (BEM). All authored
 * CSS in src/integrations.css ships with the per-screen
 * build/integrations.* bundle.
 */

import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../api';

const UPGRADE_URL = 'https://neuralforms.ai/pricing';

const ZAPIER_HOOK_HOST = 'hooks.zapier.com';
const ZAPIER_EVENT     = 'form_submission_created';

// The server-side host allowlist is filterable via
// `neural_forms_pro_zapier_allowed_hosts` (default: just hooks.zapier.com).
// Developers running dev-only QA may extend it to webhook.site / ngrok /
// pipedream so they can verify the wire payload without paying for
// Zapier Premium. Because the React layer cannot read that PHP filter
// at runtime, the client guard intentionally only enforces "valid
// https URL"; the REST endpoint returns a specific `not_zapier_host`
// 422 with a user-readable message when the host is not allowed, which
// the form surfaces in its inline error banner.

const PROVIDERS = [
	{
		id:    'hubspot',
		name:  'HubSpot',
		blurb: __( 'Sync new entries directly into your HubSpot CRM as contacts and deals so sales never miss a lead.', 'neural-forms-lite' ),
		icon:  'H',
	},
	{
		id:    'whatsapp',
		name:  'WhatsApp',
		blurb: __( 'Send instant submission alerts to your team — or auto-reply to submitters — through WhatsApp Business.', 'neural-forms-lite' ),
		icon:  'W',
	},
	{
		id:    'mailchimp',
		name:  'Mailchimp',
		blurb: __( 'Add submitters to your Mailchimp audiences automatically, with field-to-merge-tag mapping.', 'neural-forms-lite' ),
		icon:  'M',
	},
	{
		id:    'google_forms',
		name:  __( 'Google Forms', 'neural-forms-lite' ),
		blurb: __( 'Mirror entries into a linked Google Sheet so existing spreadsheet workflows keep working.', 'neural-forms-lite' ),
		icon:  'G',
	},
	{
		id:    'zapier',
		name:  'Zapier',
		blurb: __( 'Send Neural Forms submissions to Zapier webhooks and connect with thousands of apps.', 'neural-forms-lite' ),
		icon:  'Z',
	},
];

// ─── Icons ──────────────────────────────────────────────────────

function LockIcon() {
	return (
		<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
			<rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
			<path d="M7 11V7a5 5 0 0 1 10 0v4" />
		</svg>
	);
}

function HourglassIcon() {
	return (
		<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
			<path d="M6 2h12" />
			<path d="M6 22h12" />
			<path d="M6 2v4a6 6 0 0 0 12 0V2" />
			<path d="M6 22v-4a6 6 0 0 1 12 0v4" />
		</svg>
	);
}

// ─── Locked (Lite-only) variant ─────────────────────────────────

function LockedView() {
	return (
		<div className="neural-forms-integrations__lock-card" role="region" aria-label={ __( 'Integrations are a Pro feature', 'neural-forms-lite' ) }>
			<div className="neural-forms-integrations__lock-icon" aria-hidden="true">
				<LockIcon />
			</div>
			<div className="neural-forms-integrations__lock-body">
				<div className="neural-forms-integrations__lock-eyebrow">
					<span className="neural-forms-integrations__pro-badge">{ __( 'Pro', 'neural-forms-lite' ) }</span>
					<span className="neural-forms-integrations__lock-eyebrow-text">{ __( 'Premium feature', 'neural-forms-lite' ) }</span>
				</div>
				<h2 className="neural-forms-integrations__lock-title">
					{ __( 'Integrations are available in Neural Forms Pro', 'neural-forms-lite' ) }
				</h2>
				<p className="neural-forms-integrations__lock-desc">
					{ __( 'Send Neural Forms submissions to the tools you already run on — Zapier, HubSpot, WhatsApp, Mailchimp, Google Forms, and more. Upgrade to Neural Forms Pro to unlock this page and configure providers.', 'neural-forms-lite' ) }
				</p>
				<ul className="neural-forms-integrations__lock-list">
					{ PROVIDERS.map( ( p ) => (
						<li key={ p.id } className="neural-forms-integrations__lock-list-item">
							<span className="neural-forms-integrations__provider-mark" aria-hidden="true">{ p.icon }</span>
							<span>{ p.name }</span>
						</li>
					) ) }
				</ul>
				<a
					className="neural-forms-integrations__cta"
					href={ UPGRADE_URL }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Upgrade to Pro', 'neural-forms-lite' ) }
				</a>
			</div>
		</div>
	);
}

// ─── Unlocked: Coming-soon card for the non-Zapier providers ─────

function ComingSoonCard( { provider } ) {
	return (
		<div className="neural-forms-integrations__provider-card">
			<div className="neural-forms-integrations__provider-header">
				<span className="neural-forms-integrations__provider-mark neural-forms-integrations__provider-mark--lg" aria-hidden="true">
					{ provider.icon }
				</span>
				<div className="neural-forms-integrations__provider-heading">
					<h2 className="neural-forms-integrations__provider-title">{ provider.name }</h2>
					<p className="neural-forms-integrations__provider-blurb">{ provider.blurb }</p>
				</div>
			</div>

			<div className="neural-forms-integrations__soon-row">
				<span className="neural-forms-integrations__soon-icon" aria-hidden="true">
					<HourglassIcon />
				</span>
				<div className="neural-forms-integrations__soon-body">
					<h3 className="neural-forms-integrations__soon-title">{ __( 'Coming soon', 'neural-forms-lite' ) }</h3>
					<p className="neural-forms-integrations__soon-desc">
						{ __( 'This integration is on the Neural Forms Pro roadmap. Your capability is already unlocked — once the provider ships, configuration will appear right here, no plugin update required.', 'neural-forms-lite' ) }
					</p>
				</div>
			</div>
		</div>
	);
}

// ─── Zapier configure panel ─────────────────────────────────────

/**
 * Mask a stored webhook URL for display. The full URL is the bearer
 * token for an unauthenticated Catch Hook target — we never render
 * the whole path in the admin UI (or in any future log surface).
 *
 * Output shape mirrors the PHP WebhookRepository::mask_url helper:
 *   https://hooks.zapier.com/...cdef
 */
function maskWebhookUrl( url ) {
	if ( ! url || typeof url !== 'string' ) return '';
	try {
		const u = new URL( url );
		const path = ( u.pathname || '' ).replace( /^\//, '' );
		const tail = path.length > 4 ? path.slice( -4 ) : path;
		return `${ u.protocol }//${ u.host }/...${ tail }`;
	} catch ( e ) {
		return '';
	}
}

/**
 * Client-side URL validation. Intentionally permissive on host —
 * the server's host allowlist is filterable for development QA
 * (see the ZAPIER_HOOK_HOST comment above), and the React layer
 * can't read that filter at runtime. We enforce the two hard rules
 * the user can definitely fix client-side (parses as a URL, uses
 * https) and let the server return a specific 422 when the host
 * is not on the production / extended allowlist.
 */
function isValidZapierUrl( url ) {
	try {
		const u = new URL( url );
		return u.protocol === 'https:';
	} catch ( e ) {
		return false;
	}
}

/**
 * Render an inline error message from a REST WP_Error response.
 * Tries common shapes first (data.code / message), falls back to
 * the literal exception message.
 */
function errorMessage( err, fallback ) {
	if ( ! err ) return fallback;
	if ( typeof err === 'string' ) return err;
	if ( err.message ) return err.message;
	if ( err.data && err.data.message ) return err.data.message;
	return fallback;
}

function ZapierEmpty( { onAdd } ) {
	return (
		<div className="neural-forms-integrations__zap-empty">
			<h3 className="neural-forms-integrations__zap-empty-title">
				{ __( 'No Zapier connections yet', 'neural-forms-lite' ) }
			</h3>
			<p className="neural-forms-integrations__zap-empty-desc">
				{ __( 'Create a Zap in Zapier using "Webhooks by Zapier → Catch Hook", then paste the webhook URL here. Each new submission will trigger the Zap.', 'neural-forms-lite' ) }
			</p>
			<button type="button" className="neural-forms-integrations__btn neural-forms-integrations__btn--primary" onClick={ onAdd }>
				{ __( 'Add Zapier connection', 'neural-forms-lite' ) }
			</button>
		</div>
	);
}

function ZapierList( { rows, formsById, onAdd, onEdit, onToggle, onTest, onDelete, busy } ) {
	return (
		<div className="neural-forms-integrations__zap-list-wrap">
			<div className="neural-forms-integrations__zap-list-header">
				<h3 className="neural-forms-integrations__zap-list-title">
					{ __( 'Zapier connections', 'neural-forms-lite' ) }
				</h3>
				<button type="button" className="neural-forms-integrations__btn neural-forms-integrations__btn--primary" onClick={ onAdd }>
					{ __( '+ Add connection', 'neural-forms-lite' ) }
				</button>
			</div>

			<ul className="neural-forms-integrations__zap-list">
				{ rows.map( ( row ) => {
					const masked  = maskWebhookUrl( row.target_url );
					const isAll   = ( row.form_id || 0 ) === 0;
					const formName = isAll
						? __( 'All forms', 'neural-forms-lite' )
						: ( formsById[ row.form_id ] || sprintf( __( 'Form #%d', 'neural-forms-lite' ), row.form_id ) );
					const isActive = row.status === 'active';
					const rowBusy  = busy === row.id;

					return (
						<li key={ row.id } className="neural-forms-integrations__zap-row">
							<div className="neural-forms-integrations__zap-row-main">
								<div className="neural-forms-integrations__zap-row-line1">
									<code className="neural-forms-integrations__zap-url">{ masked }</code>
									<span className={ 'neural-forms-integrations__zap-status' + ( isActive ? ' is-active' : ' is-inactive' ) }>
										{ isActive ? __( 'Active', 'neural-forms-lite' ) : __( 'Inactive', 'neural-forms-lite' ) }
									</span>
								</div>
								<div className="neural-forms-integrations__zap-row-meta">
									<span>{ __( 'Form: ', 'neural-forms-lite' ) }{ formName }</span>
									<span>{ __( 'Event: ', 'neural-forms-lite' ) }{ __( 'New form submission', 'neural-forms-lite' ) }</span>
									{ row.last_sent_at && (
										<span>{ __( 'Last sent: ', 'neural-forms-lite' ) }{ row.last_sent_at }</span>
									) }
								</div>
								{ row.last_error && (
									<p className="neural-forms-integrations__zap-error">
										{ __( 'Last error: ', 'neural-forms-lite' ) }{ row.last_error }
									</p>
								) }
							</div>

							<div className="neural-forms-integrations__zap-row-actions">
								<button type="button" className="neural-forms-integrations__btn" onClick={ () => onTest( row ) } disabled={ rowBusy }>
									{ rowBusy ? __( 'Sending…', 'neural-forms-lite' ) : __( 'Test', 'neural-forms-lite' ) }
								</button>
								<button type="button" className="neural-forms-integrations__btn" onClick={ () => onEdit( row ) }>
									{ __( 'Edit', 'neural-forms-lite' ) }
								</button>
								<button type="button" className="neural-forms-integrations__btn" onClick={ () => onToggle( row ) }>
									{ isActive ? __( 'Disable', 'neural-forms-lite' ) : __( 'Enable', 'neural-forms-lite' ) }
								</button>
								<button type="button" className="neural-forms-integrations__btn neural-forms-integrations__btn--danger" onClick={ () => onDelete( row ) }>
									{ __( 'Delete', 'neural-forms-lite' ) }
								</button>
							</div>
						</li>
					);
				} ) }
			</ul>
		</div>
	);
}

function ZapierForm( { initial, forms, onCancel, onSubmit, saving, error } ) {
	const isEdit = !! initial;
	const [ url, setUrl ]       = useState( initial?.target_url || '' );
	const [ formId, setFormId ] = useState( initial?.form_id || 0 );
	const [ status, setStatus ] = useState( initial?.status || 'active' );

	const urlValid    = url === '' || isValidZapierUrl( url );
	const canSubmit   = isValidZapierUrl( url ) && ! saving;

	const handleSubmit = ( ev ) => {
		ev.preventDefault();
		if ( ! canSubmit ) return;
		onSubmit( {
			event:      ZAPIER_EVENT,
			target_url: url.trim(),
			form_id:    formId,
			status,
		} );
	};

	return (
		<form className="neural-forms-integrations__zap-form" onSubmit={ handleSubmit }>
			<header className="neural-forms-integrations__zap-form-header">
				<h3 className="neural-forms-integrations__zap-form-title">
					{ isEdit
						? __( 'Edit Zapier connection', 'neural-forms-lite' )
						: __( 'Add Zapier connection', 'neural-forms-lite' )
					}
				</h3>
				<p className="neural-forms-integrations__zap-form-help">
					{ __( 'Create a Zap in Zapier using "Webhooks by Zapier → Catch Hook", then paste the webhook URL here.', 'neural-forms-lite' ) }
				</p>
			</header>

			<div className="neural-forms-integrations__zap-field">
				<label className="neural-forms-integrations__zap-label" htmlFor="nf-zap-url">
					{ __( 'Webhook URL', 'neural-forms-lite' ) }
				</label>
				<input
					id="nf-zap-url"
					type="url"
					className={ 'neural-forms-integrations__zap-input' + ( ! urlValid ? ' is-invalid' : '' ) }
					placeholder="https://hooks.zapier.com/hooks/catch/..."
					value={ url }
					onChange={ ( e ) => setUrl( e.target.value ) }
					autoComplete="off"
					required
				/>
				{ ! urlValid && (
					<p className="neural-forms-integrations__zap-field-error">
						{ __( 'Webhook URL must be a valid https:// URL.', 'neural-forms-lite' ) }
					</p>
				) }
			</div>

			<div className="neural-forms-integrations__zap-field">
				<label className="neural-forms-integrations__zap-label" htmlFor="nf-zap-form">
					{ __( 'Form', 'neural-forms-lite' ) }
				</label>
				<select
					id="nf-zap-form"
					className="neural-forms-integrations__zap-select"
					value={ formId }
					onChange={ ( e ) => setFormId( parseInt( e.target.value, 10 ) || 0 ) }
				>
					<option value={ 0 }>{ __( 'All forms', 'neural-forms-lite' ) }</option>
					{ forms.map( ( f ) => (
						<option key={ f.id } value={ f.id }>{ f.name }</option>
					) ) }
				</select>
			</div>

			<div className="neural-forms-integrations__zap-field">
				<label className="neural-forms-integrations__zap-label" htmlFor="nf-zap-event">
					{ __( 'Event', 'neural-forms-lite' ) }
				</label>
				<select id="nf-zap-event" className="neural-forms-integrations__zap-select" value={ ZAPIER_EVENT } onChange={ () => {} } disabled>
					<option value={ ZAPIER_EVENT }>{ __( 'New form submission', 'neural-forms-lite' ) }</option>
				</select>
				<p className="neural-forms-integrations__zap-hint">
					{ __( 'More triggers (spam detected, AI analysed, high priority) will be available in a future release.', 'neural-forms-lite' ) }
				</p>
			</div>

			<div className="neural-forms-integrations__zap-field">
				<label className="neural-forms-integrations__zap-label" htmlFor="nf-zap-status">
					{ __( 'Status', 'neural-forms-lite' ) }
				</label>
				<select id="nf-zap-status" className="neural-forms-integrations__zap-select" value={ status } onChange={ ( e ) => setStatus( e.target.value ) }>
					<option value="active">{ __( 'Active', 'neural-forms-lite' ) }</option>
					<option value="inactive">{ __( 'Inactive', 'neural-forms-lite' ) }</option>
				</select>
			</div>

			{ error && (
				<div className="neural-forms-integrations__zap-error-banner" role="alert">{ error }</div>
			) }

			<div className="neural-forms-integrations__zap-form-actions">
				<button type="submit" className="neural-forms-integrations__btn neural-forms-integrations__btn--primary" disabled={ ! canSubmit }>
					{ saving ? __( 'Saving…', 'neural-forms-lite' ) : __( 'Save connection', 'neural-forms-lite' ) }
				</button>
				<button type="button" className="neural-forms-integrations__btn" onClick={ onCancel } disabled={ saving }>
					{ __( 'Cancel', 'neural-forms-lite' ) }
				</button>
			</div>
		</form>
	);
}

function ZapierPanel( { provider } ) {
	const [ loading, setLoading ]   = useState( true );
	const [ webhooks, setWebhooks ] = useState( [] );
	const [ forms, setForms ]       = useState( [] );
	const [ mode, setMode ]         = useState( { name: 'list' } );
	const [ saving, setSaving ]     = useState( false );
	const [ formError, setFormError ] = useState( '' );
	const [ flash, setFlash ]       = useState( null );
	const [ busy, setBusy ]         = useState( 0 );

	const reloadWebhooks = useCallback( async () => {
		try {
			const res = await api( '/neural-forms-pro/v1/integrations/zapier/webhooks' );
			setWebhooks( Array.isArray( res?.webhooks ) ? res.webhooks : [] );
		} catch ( e ) {
			setFlash( { kind: 'error', message: errorMessage( e, __( 'Could not load Zapier connections.', 'neural-forms-lite' ) ) } );
		}
	}, [] );

	useEffect( () => {
		let cancelled = false;
		( async () => {
			try {
				const [ wh, fs ] = await Promise.all( [
					api( '/neural-forms-pro/v1/integrations/zapier/webhooks' ),
					api( '/forms?per_page=200' ),
				] );
				if ( cancelled ) return;
				setWebhooks( Array.isArray( wh?.webhooks ) ? wh.webhooks : [] );
				const list = Array.isArray( fs )
					? fs
					: ( Array.isArray( fs?.items ) ? fs.items : ( Array.isArray( fs?.forms ) ? fs.forms : [] ) );
				setForms( list.map( ( f ) => ( { id: parseInt( f.id, 10 ), name: f.name || `Form #${ f.id }` } ) ) );
			} catch ( e ) {
				if ( cancelled ) return;
				setFlash( { kind: 'error', message: errorMessage( e, __( 'Could not load Zapier connections.', 'neural-forms-lite' ) ) } );
			} finally {
				if ( ! cancelled ) setLoading( false );
			}
		} )();
		return () => { cancelled = true; };
	}, [] );

	const formsById = useMemo( () => {
		const map = {};
		forms.forEach( ( f ) => { map[ f.id ] = f.name; } );
		return map;
	}, [ forms ] );

	const handleSubmit = useCallback( async ( payload ) => {
		setSaving( true );
		setFormError( '' );
		try {
			if ( mode.name === 'edit' ) {
				await api( `/neural-forms-pro/v1/integrations/zapier/webhooks/${ mode.row.id }`, {
					method: 'PUT',
					data:   payload,
				} );
			} else {
				await api( '/neural-forms-pro/v1/integrations/zapier/webhooks', {
					method: 'POST',
					data:   payload,
				} );
			}
			await reloadWebhooks();
			setMode( { name: 'list' } );
			setFlash( { kind: 'success', message: __( 'Zapier connection saved.', 'neural-forms-lite' ) } );
		} catch ( e ) {
			setFormError( errorMessage( e, __( 'Could not save the webhook.', 'neural-forms-lite' ) ) );
		} finally {
			setSaving( false );
		}
	}, [ mode, reloadWebhooks ] );

	const handleToggle = useCallback( async ( row ) => {
		const next = row.status === 'active' ? 'inactive' : 'active';
		try {
			await api( `/neural-forms-pro/v1/integrations/zapier/webhooks/${ row.id }`, {
				method: 'PUT',
				data:   { status: next },
			} );
			await reloadWebhooks();
		} catch ( e ) {
			setFlash( { kind: 'error', message: errorMessage( e, __( 'Could not update the webhook.', 'neural-forms-lite' ) ) } );
		}
	}, [ reloadWebhooks ] );

	const handleDelete = useCallback( async ( row ) => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( __( 'Delete this Zapier connection? This cannot be undone.', 'neural-forms-lite' ) ) ) {
			return;
		}
		try {
			await api( `/neural-forms-pro/v1/integrations/zapier/webhooks/${ row.id }`, {
				method: 'DELETE',
			} );
			await reloadWebhooks();
			setFlash( { kind: 'success', message: __( 'Zapier connection deleted.', 'neural-forms-lite' ) } );
		} catch ( e ) {
			setFlash( { kind: 'error', message: errorMessage( e, __( 'Could not delete the webhook.', 'neural-forms-lite' ) ) } );
		}
	}, [ reloadWebhooks ] );

	const handleTest = useCallback( async ( row ) => {
		setBusy( row.id );
		setFlash( null );
		try {
			const res = await api( `/neural-forms-pro/v1/integrations/zapier/webhooks/${ row.id }/test`, {
				method: 'POST',
			} );
			if ( res?.success ) {
				setFlash( { kind: 'success', message: __( 'Test payload sent to Zapier.', 'neural-forms-lite' ) } );
			} else {
				setFlash( {
					kind:    'error',
					message: sprintf(
						/* translators: %s: error message */
						__( 'Zapier returned an error: %s', 'neural-forms-lite' ),
						res?.error || __( 'unknown error', 'neural-forms-lite' )
					),
				} );
			}
			await reloadWebhooks();
		} catch ( e ) {
			setFlash( { kind: 'error', message: errorMessage( e, __( 'Could not send the test payload.', 'neural-forms-lite' ) ) } );
		} finally {
			setBusy( 0 );
		}
	}, [ reloadWebhooks ] );

	return (
		<div className="neural-forms-integrations__provider-card">
			<div className="neural-forms-integrations__provider-header">
				<span className="neural-forms-integrations__provider-mark neural-forms-integrations__provider-mark--lg" aria-hidden="true">
					{ provider.icon }
				</span>
				<div className="neural-forms-integrations__provider-heading">
					<h2 className="neural-forms-integrations__provider-title">{ provider.name }</h2>
					<p className="neural-forms-integrations__provider-blurb">{ provider.blurb }</p>
				</div>
			</div>

			{ flash && (
				<div className={ 'neural-forms-integrations__zap-flash neural-forms-integrations__zap-flash--' + flash.kind } role="status">
					{ flash.message }
					<button type="button" className="neural-forms-integrations__zap-flash-close" onClick={ () => setFlash( null ) } aria-label={ __( 'Dismiss', 'neural-forms-lite' ) }>×</button>
				</div>
			) }

			{ loading ? (
				<p className="neural-forms-integrations__zap-loading">{ __( 'Loading…', 'neural-forms-lite' ) }</p>
			) : mode.name === 'new' ? (
				<ZapierForm
					initial={ null }
					forms={ forms }
					onCancel={ () => { setFormError( '' ); setMode( { name: 'list' } ); } }
					onSubmit={ handleSubmit }
					saving={ saving }
					error={ formError }
				/>
			) : mode.name === 'edit' ? (
				<ZapierForm
					initial={ mode.row }
					forms={ forms }
					onCancel={ () => { setFormError( '' ); setMode( { name: 'list' } ); } }
					onSubmit={ handleSubmit }
					saving={ saving }
					error={ formError }
				/>
			) : webhooks.length === 0 ? (
				<ZapierEmpty onAdd={ () => setMode( { name: 'new' } ) } />
			) : (
				<ZapierList
					rows={ webhooks }
					formsById={ formsById }
					onAdd={ () => setMode( { name: 'new' } ) }
					onEdit={ ( row ) => setMode( { name: 'edit', row } ) }
					onToggle={ handleToggle }
					onTest={ handleTest }
					onDelete={ handleDelete }
					busy={ busy }
				/>
			) }
		</div>
	);
}

// ─── Google Forms configure panel ───────────────────────────────

/**
 * State machine:
 *   - loading       → initial status fetch
 *   - no_creds      → site-level OAuth client_id / client_secret form
 *   - disconnected  → "Connect Google Account" button
 *   - connected     → forms list + import flow
 *
 * The OAuth handshake leaves the browser on
 * /wp-admin/admin.php?page=neural-forms-integrations&nf_google=...
 * On mount we read that flag, show the flash, and replaceState the
 * URL clean so a refresh doesn't replay the banner.
 *
 * REST surface (admin-gated, capability-gated):
 *   GET    /neural-forms-pro/v1/integrations/google-forms/status
 *   POST   /neural-forms-pro/v1/integrations/google-forms/settings
 *   GET    /neural-forms-pro/v1/integrations/google-forms/auth-url
 *   POST   /neural-forms-pro/v1/integrations/google-forms/disconnect
 *   GET    /neural-forms-pro/v1/integrations/google-forms/forms
 *   GET    /neural-forms-pro/v1/integrations/google-forms/forms/{id}/preview
 *   POST   /neural-forms-pro/v1/integrations/google-forms/forms/{id}/import
 */

function CopyableInput( { value, label } ) {
	const [ copied, setCopied ] = useState( false );
	const onCopy = useCallback( () => {
		if ( ! navigator?.clipboard ) return;
		navigator.clipboard.writeText( value ).then( () => {
			setCopied( true );
			setTimeout( () => setCopied( false ), 1500 );
		} );
	}, [ value ] );
	return (
		<div className="neural-forms-integrations__zap-field">
			<label className="neural-forms-integrations__zap-label">{ label }</label>
			<div className="neural-forms-integrations__gf-copy-row">
				<input className="neural-forms-integrations__zap-input" type="text" value={ value } readOnly onFocus={ ( e ) => e.target.select() } />
				<button type="button" className="neural-forms-integrations__btn" onClick={ onCopy } disabled={ ! value }>
					{ copied ? __( 'Copied!', 'neural-forms-lite' ) : __( 'Copy', 'neural-forms-lite' ) }
				</button>
			</div>
		</div>
	);
}

function GFCredsForm( { status, onSaved, onConnect, busy } ) {
	const [ clientId,     setClientId ]     = useState( status?.client_id || '' );
	const [ clientSecret, setClientSecret ] = useState( '' );
	const [ saving,       setSaving ]       = useState( false );
	const [ err,          setErr ]          = useState( '' );
	const hasCreds = !! status?.has_client_creds;

	const onSave = async ( ev ) => {
		ev.preventDefault();
		setSaving( true );
		setErr( '' );
		try {
			const data = { client_id: clientId };
			if ( clientSecret !== '' ) data.client_secret = clientSecret;
			const next = await api( '/neural-forms-pro/v1/integrations/google-forms/settings', { method: 'POST', data } );
			setClientSecret( '' );
			onSaved( next );
		} catch ( e ) {
			setErr( errorMessage( e, __( 'Could not save credentials.', 'neural-forms-lite' ) ) );
		} finally {
			setSaving( false );
		}
	};

	return (
		<form className="neural-forms-integrations__zap-form" onSubmit={ onSave }>
			<header className="neural-forms-integrations__zap-form-header">
				<h3 className="neural-forms-integrations__zap-form-title">
					{ __( 'Google Cloud OAuth credentials', 'neural-forms-lite' ) }
				</h3>
				<p className="neural-forms-integrations__zap-form-help">
					{ __( 'Create an OAuth client in Google Cloud Console → APIs & Services → Credentials. Use the redirect URI below verbatim, then paste the client ID and secret here.', 'neural-forms-lite' ) }
				</p>
			</header>

			<CopyableInput value={ status?.redirect_uri || '' } label={ __( 'Authorised redirect URI (paste into Google Cloud)', 'neural-forms-lite' ) } />

			<div className="neural-forms-integrations__zap-field">
				<label className="neural-forms-integrations__zap-label" htmlFor="nf-gf-client-id">
					{ __( 'Client ID', 'neural-forms-lite' ) }
				</label>
				<input
					id="nf-gf-client-id"
					type="text"
					className="neural-forms-integrations__zap-input"
					value={ clientId }
					onChange={ ( e ) => setClientId( e.target.value ) }
					placeholder="123-abc.apps.googleusercontent.com"
					required
				/>
			</div>

			<div className="neural-forms-integrations__zap-field">
				<label className="neural-forms-integrations__zap-label" htmlFor="nf-gf-client-secret">
					{ __( 'Client Secret', 'neural-forms-lite' ) }
				</label>
				<input
					id="nf-gf-client-secret"
					type="password"
					className="neural-forms-integrations__zap-input"
					value={ clientSecret }
					onChange={ ( e ) => setClientSecret( e.target.value ) }
					placeholder={ status?.client_secret_set ? '•••••••• (' + __( 'leave blank to keep existing', 'neural-forms-lite' ) + ')' : '' }
				/>
				<p className="neural-forms-integrations__zap-hint">
					{ __( 'The secret is encrypted at rest and never displayed again.', 'neural-forms-lite' ) }
				</p>
			</div>

			{ err && <div className="neural-forms-integrations__zap-error-banner" role="alert">{ err }</div> }

			<div className="neural-forms-integrations__zap-form-actions">
				<button type="submit" className="neural-forms-integrations__btn neural-forms-integrations__btn--primary" disabled={ saving || ! clientId || ( ! hasCreds && ! clientSecret ) }>
					{ saving ? __( 'Saving…', 'neural-forms-lite' ) : __( 'Save credentials', 'neural-forms-lite' ) }
				</button>
				{ hasCreds && (
					<button type="button" className="neural-forms-integrations__btn neural-forms-integrations__btn--primary" onClick={ onConnect } disabled={ busy }>
						{ busy ? __( 'Connecting…', 'neural-forms-lite' ) : __( 'Connect Google Account', 'neural-forms-lite' ) }
					</button>
				) }
			</div>
		</form>
	);
}

function GFFormsList( { forms, loading, error, onRefresh, onPreview, busyId } ) {
	if ( loading ) return <p className="neural-forms-integrations__zap-loading">{ __( 'Loading Google Forms…', 'neural-forms-lite' ) }</p>;
	if ( error )   return <div className="neural-forms-integrations__zap-error-banner" role="alert">{ error }</div>;
	if ( ! forms || forms.length === 0 ) {
		return (
			<div className="neural-forms-integrations__zap-empty">
				<h3 className="neural-forms-integrations__zap-empty-title">{ __( 'No Google Forms found', 'neural-forms-lite' ) }</h3>
				<p className="neural-forms-integrations__zap-empty-desc">
					{ __( 'Create a form at forms.google.com, then click Refresh.', 'neural-forms-lite' ) }
				</p>
				<button type="button" className="neural-forms-integrations__btn neural-forms-integrations__btn--primary" onClick={ onRefresh }>
					{ __( 'Refresh forms', 'neural-forms-lite' ) }
				</button>
			</div>
		);
	}
	return (
		<div className="neural-forms-integrations__zap-list-wrap">
			<div className="neural-forms-integrations__zap-list-header">
				<h3 className="neural-forms-integrations__zap-list-title">{ __( 'Your Google Forms', 'neural-forms-lite' ) }</h3>
				<button type="button" className="neural-forms-integrations__btn" onClick={ onRefresh }>{ __( 'Refresh', 'neural-forms-lite' ) }</button>
			</div>
			<ul className="neural-forms-integrations__zap-list">
				{ forms.map( ( f ) => (
					<li key={ f.id } className="neural-forms-integrations__zap-row">
						<div className="neural-forms-integrations__zap-row-main">
							<div className="neural-forms-integrations__zap-row-line1">
								<strong>{ f.name || __( '(untitled)', 'neural-forms-lite' ) }</strong>
								<code className="neural-forms-integrations__zap-url">{ f.id.slice( 0, 8 ) }…</code>
							</div>
							{ f.modified_time && (
								<div className="neural-forms-integrations__zap-row-meta">
									<span>{ __( 'Modified: ', 'neural-forms-lite' ) }{ f.modified_time }</span>
								</div>
							) }
						</div>
						<div className="neural-forms-integrations__zap-row-actions">
							<button type="button" className="neural-forms-integrations__btn neural-forms-integrations__btn--primary" onClick={ () => onPreview( f ) } disabled={ busyId === f.id }>
								{ busyId === f.id ? __( 'Loading…', 'neural-forms-lite' ) : __( 'Preview & Import', 'neural-forms-lite' ) }
							</button>
						</div>
					</li>
				) ) }
			</ul>
		</div>
	);
}

function GFPreviewModal( { preview, onCancel, onImport, importing } ) {
	if ( ! preview ) return null;
	const { name, description, schema, warnings, summary } = preview;
	const fields = schema?.fields || [];
	return (
		<div className="neural-forms-integrations__gf-modal" role="dialog" aria-modal="true" aria-label={ __( 'Preview Google Form import', 'neural-forms-lite' ) }>
			<div className="neural-forms-integrations__gf-modal-body">
				<header className="neural-forms-integrations__gf-modal-header">
					<h3 className="neural-forms-integrations__zap-form-title">{ name || __( 'Imported form', 'neural-forms-lite' ) }</h3>
					{ description && <p className="neural-forms-integrations__zap-form-help">{ description }</p> }
				</header>

				<div className="neural-forms-integrations__gf-modal-summary">
					<span>{ sprintf( __( '%d fields mapped', 'neural-forms-lite' ), summary?.mapped_items || 0 ) }</span>
					{ ( summary?.fallback_items || 0 ) > 0 && (
						<span>{ sprintf( __( '%d fallback', 'neural-forms-lite' ), summary.fallback_items ) }</span>
					) }
					{ ( summary?.skipped_items || 0 ) > 0 && (
						<span>{ sprintf( __( '%d skipped', 'neural-forms-lite' ), summary.skipped_items ) }</span>
					) }
				</div>

				<div className="neural-forms-integrations__gf-modal-fields">
					<h4 className="neural-forms-integrations__zap-list-title">{ __( 'Fields', 'neural-forms-lite' ) }</h4>
					<ul className="neural-forms-integrations__gf-modal-field-list">
						{ fields.map( ( f, i ) => (
							<li key={ i }>
								<strong>{ f.label || f.id }</strong>
								<span className="neural-forms-integrations__gf-modal-type">{ f.type }</span>
								{ f.required && <span className="neural-forms-integrations__gf-modal-req">{ __( 'required', 'neural-forms-lite' ) }</span> }
							</li>
						) ) }
					</ul>
				</div>

				{ warnings && warnings.length > 0 && (
					<div className="neural-forms-integrations__gf-modal-warnings">
						<h4 className="neural-forms-integrations__zap-list-title">{ __( 'Warnings', 'neural-forms-lite' ) }</h4>
						<ul>
							{ warnings.map( ( w, i ) => (
								<li key={ i } className={ 'neural-forms-integrations__gf-warning-' + ( w.kind || 'note' ) }>
									<strong>{ w.field_title }:</strong> { w.message }
								</li>
							) ) }
						</ul>
					</div>
				) }

				<div className="neural-forms-integrations__zap-form-actions">
					<button type="button" className="neural-forms-integrations__btn neural-forms-integrations__btn--primary" onClick={ onImport } disabled={ importing || fields.length === 0 }>
						{ importing ? __( 'Importing…', 'neural-forms-lite' ) : __( 'Import as Neural Form', 'neural-forms-lite' ) }
					</button>
					<button type="button" className="neural-forms-integrations__btn" onClick={ onCancel } disabled={ importing }>
						{ __( 'Cancel', 'neural-forms-lite' ) }
					</button>
				</div>
			</div>
		</div>
	);
}

function GoogleFormsPanel( { provider } ) {
	const [ loading,  setLoading ]  = useState( true );
	const [ status,   setStatus ]   = useState( null );
	const [ flash,    setFlash ]    = useState( null );
	const [ forms,    setForms ]    = useState( [] );
	const [ formsLoading, setFormsLoading ] = useState( false );
	const [ formsError, setFormsError ] = useState( '' );
	const [ connecting, setConnecting ] = useState( false );
	const [ preview, setPreview ]   = useState( null );
	const [ previewLoading, setPreviewLoading ] = useState( false );
	const [ previewError, setPreviewError ] = useState( '' );
	const [ busyFormId, setBusyFormId ] = useState( '' );
	const [ importing, setImporting ] = useState( false );

	const reloadStatus = useCallback( async () => {
		try {
			const s = await api( '/neural-forms-pro/v1/integrations/google-forms/status' );
			setStatus( s );
		} catch ( e ) {
			setFlash( { kind: 'error', message: errorMessage( e, __( 'Could not load Google Forms status.', 'neural-forms-lite' ) ) } );
		}
	}, [] );

	const reloadForms = useCallback( async () => {
		setFormsLoading( true );
		setFormsError( '' );
		try {
			const res = await api( '/neural-forms-pro/v1/integrations/google-forms/forms' );
			setForms( Array.isArray( res?.forms ) ? res.forms : [] );
		} catch ( e ) {
			setFormsError( errorMessage( e, __( 'Could not load Google Forms.', 'neural-forms-lite' ) ) );
		} finally {
			setFormsLoading( false );
		}
	}, [] );

	// First mount: read URL flag, fetch status.
	useEffect( () => {
		const url    = new URL( window.location.href );
		const gFlag  = url.searchParams.get( 'nf_google' );
		const reason = url.searchParams.get( 'reason' );
		if ( gFlag === 'connected' ) {
			setFlash( { kind: 'success', message: __( 'Google account connected.', 'neural-forms-lite' ) } );
		} else if ( gFlag === 'error' ) {
			setFlash( { kind: 'error', message: sprintf(
				/* translators: %s: Google error code */
				__( 'Google connection failed: %s', 'neural-forms-lite' ),
				reason || __( 'unknown error', 'neural-forms-lite' )
			) } );
		}
		if ( gFlag ) {
			url.searchParams.delete( 'nf_google' );
			url.searchParams.delete( 'reason' );
			window.history.replaceState( null, '', url.toString() );
		}
		( async () => {
			await reloadStatus();
			setLoading( false );
		} )();
	}, [ reloadStatus ] );

	// Auto-load forms when we transition to connected.
	useEffect( () => {
		if ( status?.connected && forms.length === 0 && ! formsLoading && formsError === '' ) {
			reloadForms();
		}
	}, [ status?.connected ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const handleConnect = useCallback( async () => {
		setConnecting( true );
		try {
			const res = await api( '/neural-forms-pro/v1/integrations/google-forms/auth-url' );
			if ( res?.url ) {
				window.location.href = res.url;
			} else {
				throw new Error( __( 'Auth URL was not returned by the server.', 'neural-forms-lite' ) );
			}
		} catch ( e ) {
			setFlash( { kind: 'error', message: errorMessage( e, __( 'Could not start the Google connection flow.', 'neural-forms-lite' ) ) } );
			setConnecting( false );
		}
	}, [] );

	const handleDisconnect = useCallback( async () => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( __( 'Disconnect Google? Your imported forms will keep working — only the live Google connection is removed.', 'neural-forms-lite' ) ) ) {
			return;
		}
		try {
			const s = await api( '/neural-forms-pro/v1/integrations/google-forms/disconnect', { method: 'POST' } );
			setStatus( s );
			setForms( [] );
			setFlash( { kind: 'success', message: __( 'Google account disconnected.', 'neural-forms-lite' ) } );
		} catch ( e ) {
			setFlash( { kind: 'error', message: errorMessage( e, __( 'Could not disconnect.', 'neural-forms-lite' ) ) } );
		}
	}, [] );

	const handlePreview = useCallback( async ( form ) => {
		setBusyFormId( form.id );
		setPreviewError( '' );
		try {
			const res = await api( '/neural-forms-pro/v1/integrations/google-forms/forms/' + encodeURIComponent( form.id ) + '/preview' );
			setPreview( res );
		} catch ( e ) {
			setFlash( { kind: 'error', message: errorMessage( e, __( 'Preview failed.', 'neural-forms-lite' ) ) } );
		} finally {
			setBusyFormId( '' );
		}
	}, [] );

	const handleImport = useCallback( async () => {
		if ( ! preview?.source?.google_form_id ) return;
		setImporting( true );
		try {
			const res = await api( '/neural-forms-pro/v1/integrations/google-forms/forms/' + encodeURIComponent( preview.source.google_form_id ) + '/import', { method: 'POST' } );
			setPreview( null );
			setFlash( { kind: 'success', message: sprintf(
				/* translators: %s: imported form name */
				__( 'Imported "%s" as a new Neural Form.', 'neural-forms-lite' ),
				res?.name || ''
			) } );
		} catch ( e ) {
			setPreviewError( errorMessage( e, __( 'Import failed.', 'neural-forms-lite' ) ) );
		} finally {
			setImporting( false );
		}
	}, [ preview ] );

	return (
		<div className="neural-forms-integrations__provider-card">
			<div className="neural-forms-integrations__provider-header">
				<span className="neural-forms-integrations__provider-mark neural-forms-integrations__provider-mark--lg" aria-hidden="true">
					{ provider.icon }
				</span>
				<div className="neural-forms-integrations__provider-heading">
					<h2 className="neural-forms-integrations__provider-title">{ provider.name }</h2>
					<p className="neural-forms-integrations__provider-blurb">{ provider.blurb }</p>
				</div>
			</div>

			{ flash && (
				<div className={ 'neural-forms-integrations__zap-flash neural-forms-integrations__zap-flash--' + flash.kind } role="status">
					{ flash.message }
					<button type="button" className="neural-forms-integrations__zap-flash-close" onClick={ () => setFlash( null ) } aria-label={ __( 'Dismiss', 'neural-forms-lite' ) }>×</button>
				</div>
			) }

			{ loading ? (
				<p className="neural-forms-integrations__zap-loading">{ __( 'Loading…', 'neural-forms-lite' ) }</p>
			) : status?.connected ? (
				<>
					<div className="neural-forms-integrations__gf-connected">
						<div>
							<strong>{ __( 'Connected as: ', 'neural-forms-lite' ) }</strong>
							<span>{ status.account_email || __( 'your Google account', 'neural-forms-lite' ) }</span>
						</div>
						<div className="neural-forms-integrations__zap-row-actions">
							<button type="button" className="neural-forms-integrations__btn" onClick={ reloadForms }>{ __( 'Refresh forms', 'neural-forms-lite' ) }</button>
							<button type="button" className="neural-forms-integrations__btn neural-forms-integrations__btn--danger" onClick={ handleDisconnect }>{ __( 'Disconnect', 'neural-forms-lite' ) }</button>
						</div>
					</div>
					<GFFormsList forms={ forms } loading={ formsLoading } error={ formsError } onRefresh={ reloadForms } onPreview={ handlePreview } busyId={ busyFormId } />
				</>
			) : (
				<GFCredsForm
					status={ status }
					onSaved={ ( s ) => { setStatus( s ); setFlash( { kind: 'success', message: __( 'Credentials saved.', 'neural-forms-lite' ) } ); } }
					onConnect={ handleConnect }
					busy={ connecting }
				/>
			) }

			{ preview && (
				<GFPreviewModal
					preview={ preview }
					onCancel={ () => setPreview( null ) }
					onImport={ handleImport }
					importing={ importing }
				/>
			) }
			{ previewError && (
				<div className="neural-forms-integrations__zap-error-banner" role="alert">{ previewError }</div>
			) }
		</div>
	);
}

// ─── Tab body switcher ──────────────────────────────────────────

function TabPanel( { provider } ) {
	if ( provider.id === 'zapier' ) {
		return <ZapierPanel provider={ provider } />;
	}
	if ( provider.id === 'google_forms' ) {
		return <GoogleFormsPanel provider={ provider } />;
	}
	return <ComingSoonCard provider={ provider } />;
}

function UnlockedView() {
	const [ activeId, setActiveId ] = useState( 'zapier' );

	const onKeyDown = useCallback( ( ev ) => {
		const idx = PROVIDERS.findIndex( ( p ) => p.id === activeId );
		if ( idx === -1 ) return;
		let next = idx;
		if ( ev.key === 'ArrowRight' || ev.key === 'ArrowDown' ) {
			next = ( idx + 1 ) % PROVIDERS.length;
		} else if ( ev.key === 'ArrowLeft' || ev.key === 'ArrowUp' ) {
			next = ( idx - 1 + PROVIDERS.length ) % PROVIDERS.length;
		} else if ( ev.key === 'Home' ) {
			next = 0;
		} else if ( ev.key === 'End' ) {
			next = PROVIDERS.length - 1;
		} else {
			return;
		}
		ev.preventDefault();
		setActiveId( PROVIDERS[ next ].id );
		const tab = document.getElementById( `neural-forms-integrations-tab-${ PROVIDERS[ next ].id }` );
		if ( tab && typeof tab.focus === 'function' ) tab.focus();
	}, [ activeId ] );

	const active = PROVIDERS.find( ( p ) => p.id === activeId ) || PROVIDERS[ 0 ];

	return (
		<>
			<div
				className="neural-forms-integrations__tablist"
				role="tablist"
				aria-label={ __( 'Integration providers', 'neural-forms-lite' ) }
				onKeyDown={ onKeyDown }
			>
				{ PROVIDERS.map( ( p ) => {
					const selected = p.id === activeId;
					return (
						<button
							key={ p.id }
							id={ `neural-forms-integrations-tab-${ p.id }` }
							type="button"
							role="tab"
							aria-selected={ selected }
							aria-controls={ `neural-forms-integrations-panel-${ p.id }` }
							tabIndex={ selected ? 0 : -1 }
							className={
								'neural-forms-integrations__tab'
								+ ( selected ? ' neural-forms-integrations__tab--active' : '' )
							}
							onClick={ () => setActiveId( p.id ) }
						>
							<span className="neural-forms-integrations__provider-mark" aria-hidden="true">{ p.icon }</span>
							<span>{ p.name }</span>
						</button>
					);
				} ) }
			</div>

			<div
				id={ `neural-forms-integrations-panel-${ active.id }` }
				role="tabpanel"
				aria-labelledby={ `neural-forms-integrations-tab-${ active.id }` }
				className="neural-forms-integrations__panel"
				tabIndex={ 0 }
			>
				<TabPanel provider={ active } />
			</div>
		</>
	);
}

// ─── Top-level screen ───────────────────────────────────────────

export default function Integrations() {
	const admin = ( typeof window !== 'undefined' && window.neuralFormsAdmin ) || {};
	const caps  = ( admin.plugin && admin.plugin.capabilities ) || {};
	const unlocked = !! caps.integrations;

	return (
		<div className="neural-forms-integrations">
			<header className="neural-forms-integrations__header">
				<div className="neural-forms-integrations__header-text">
					<h1 className="neural-forms-integrations__page-title">
						{ __( 'Integrations', 'neural-forms-lite' ) }
					</h1>
					<p className="neural-forms-integrations__page-desc">
						{ __( 'Connect Neural Forms to the tools your team already uses. Configure providers below.', 'neural-forms-lite' ) }
					</p>
				</div>
			</header>

			{ unlocked ? <UnlockedView /> : <LockedView /> }
		</div>
	);
}
