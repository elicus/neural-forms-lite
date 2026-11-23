/**
 * Plugin Settings screen.
 *
 * Section order (matches the live rich UI — keep stable):
 *   1. Neural Forms Cloud AI
 *   2. Email Settings
 *   3. Notifications
 *   4. CAPTCHA
 *   5. General & Privacy
 *
 * REST endpoints used:
 *   GET  /settings                    initial load (returns cloud_plan)
 *   PATCH /settings                   per-key partial update
 *   POST /settings/test-connection    verify cloud_api_key
 *   POST /settings/revoke-connection  drop cloud connection
 *   POST /settings/test-email         send a one-off test email
 *   POST /ai/process-queue            trigger AI queue manually
 *
 * Capabilities (Pro features rendered as upgrade lock cards in Lite):
 *   business-aware analysis  → window.neuralFormsAdmin.capabilities.ai_reanalyze
 *   spam detection           → window.neuralFormsAdmin.capabilities.ai_spam_detect
 *
 * Save flow:
 *   - State diff (only changed keys + write-only API key inputs).
 *   - Save bar lives at the BOTTOM of the page (right-aligned), with a
 *     "✓ Saved" success state on the button itself.
 *
 * Class naming: `.neural-forms-settings__*` (BEM). All authored CSS in
 * src/settings.css ships with the per-screen build/settings.* bundle.
 */

import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../api';
import { ProSlot } from '../slot-registry';

const CAPTCHA_PROVIDERS = [
	{ value: 'none',         label: __( 'None — CAPTCHA disabled', 'neural-forms-lite' ) },
	{ value: 'turnstile',    label: __( 'Cloudflare Turnstile',     'neural-forms-lite' ) },
	{ value: 'recaptcha_v2', label: __( 'reCAPTCHA v2',             'neural-forms-lite' ) },
];

// ─── Reusable building blocks ────────────────────────────────────

function Section( { title, description, children } ) {
	return (
		<section className="neural-forms-settings__section">
			<header className="neural-forms-settings__section-header">
				<h2 className="neural-forms-settings__section-title">{ title }</h2>
				{ description && (
					<p className="neural-forms-settings__section-desc">{ description }</p>
				) }
			</header>
			<div className="neural-forms-settings__section-body">
				{ children }
			</div>
		</section>
	);
}

function Field( { label, hint, children } ) {
	return (
		<div className="neural-forms-settings__field">
			<label className="neural-forms-settings__label">{ label }</label>
			<div className="neural-forms-settings__control">
				{ children }
				{ hint && <p className="neural-forms-settings__hint">{ hint }</p> }
			</div>
		</div>
	);
}

function ToggleField( { label, hint, checked, onChange, disabled } ) {
	return (
		<div className="neural-forms-settings__field">
			<span className="neural-forms-settings__label">{ label }</span>
			<div className="neural-forms-settings__control">
				<label className={
					'neural-forms-settings__toggle'
					+ ( checked ? ' neural-forms-settings__toggle--on' : '' )
					+ ( disabled ? ' neural-forms-settings__toggle--disabled' : '' )
				}>
					<input
						type="checkbox"
						className="neural-forms-settings__toggle-input"
						checked={ !! checked }
						disabled={ !! disabled }
						onChange={ ( e ) => onChange( e.target.checked ) }
					/>
					<span className="neural-forms-settings__toggle-track" aria-hidden="true">
						<span className="neural-forms-settings__toggle-thumb" />
					</span>
				</label>
				{ hint && <p className="neural-forms-settings__hint">{ hint }</p> }
			</div>
		</div>
	);
}

function TextInput( props ) {
	return <input className="neural-forms-settings__input" { ...props } />;
}

function Textarea( props ) {
	return <textarea className="neural-forms-settings__textarea" { ...props } />;
}

function Select( props ) {
	return <select className="neural-forms-settings__select" { ...props } />;
}

function ApiKeyInput( { isSet, value, onChange, placeholder } ) {
	return (
		<div className="neural-forms-settings__api-key-wrap">
			<input
				type="password"
				className="neural-forms-settings__input"
				value={ value }
				onChange={ ( e ) => onChange( e.target.value ) }
				placeholder={ isSet ? '••••••••••••••••' : ( placeholder || '' ) }
			/>
			{ isSet && (
				<span className="neural-forms-settings__api-key-badge">
					{ __( 'SET', 'neural-forms-lite' ) }
				</span>
			) }
		</div>
	);
}

function ProLockCard( { title, description, upgradeUrl } ) {
	return (
		<div className="neural-forms-settings__pro-card">
			<span className="neural-forms-settings__pro-icon" aria-hidden="true">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
					<rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
					<path d="M7 11V7a5 5 0 0 1 10 0v4" />
				</svg>
			</span>
			<div className="neural-forms-settings__pro-body">
				<h3 className="neural-forms-settings__pro-title">{ title }</h3>
				<p className="neural-forms-settings__pro-desc">{ description }</p>
				<a
					className="neural-forms-settings__pro-cta"
					href={ upgradeUrl || 'https://neuralforms.ai/pricing' }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Upgrade to unlock', 'neural-forms-lite' ) }
				</a>
			</div>
		</div>
	);
}

function ConnectionStatusPill( { connected, name } ) {
	if ( connected ) {
		return (
			<span className="neural-forms-settings__status-pill neural-forms-settings__status-pill--ok">
				✓ { name
					? __( 'Connected — ', 'neural-forms-lite' ) + name
					: __( 'Connected', 'neural-forms-lite' ) }
			</span>
		);
	}
	return (
		<span className="neural-forms-settings__status-pill neural-forms-settings__status-pill--idle">
			{ __( 'Not connected', 'neural-forms-lite' ) }
		</span>
	);
}

function PlanBalanceCard( { plan, credits } ) {
	return (
		<div className="neural-forms-settings__plan-card">
			<div className="neural-forms-settings__plan-row">
				<span className="neural-forms-settings__plan-label">
					{ __( 'Plan', 'neural-forms-lite' ) }
				</span>
				<span className="neural-forms-settings__plan-value">
					{ plan || __( '—', 'neural-forms-lite' ) }
				</span>
			</div>
			<div className="neural-forms-settings__plan-row">
				<span className="neural-forms-settings__plan-label">
					{ __( 'Available credits', 'neural-forms-lite' ) }
				</span>
				<span className="neural-forms-settings__plan-value neural-forms-settings__plan-value--credits">
					{ Number.isFinite( credits ) ? credits.toLocaleString() : '—' }
				</span>
			</div>
		</div>
	);
}

// Pro fills expect compatible UI primitives in their fillProps.
// `Toggle` is the bare iOS-style switch (not the labeled row);
// `ProField` is the same row layout as <Field> but accepts the
// `htmlFor` prop the rich bundle's primitive exposes.

function Toggle( { id, checked, onChange, disabled, ...rest } ) {
	return (
		<label className={
			'neural-forms-settings__toggle'
			+ ( checked ? ' neural-forms-settings__toggle--on' : '' )
			+ ( disabled ? ' neural-forms-settings__toggle--disabled' : '' )
		}>
			<input
				type="checkbox"
				id={ id }
				className="neural-forms-settings__toggle-input"
				checked={ !! checked }
				disabled={ !! disabled }
				onChange={ ( e ) => onChange && onChange( e.target.checked ) }
				{ ...rest }
			/>
			<span className="neural-forms-settings__toggle-track" aria-hidden="true">
				<span className="neural-forms-settings__toggle-thumb" />
			</span>
		</label>
	);
}

function ProField( { label, htmlFor, children } ) {
	return (
		<div className="neural-forms-settings__field">
			<label className="neural-forms-settings__label" htmlFor={ htmlFor }>{ label }</label>
			<div className="neural-forms-settings__control">{ children }</div>
		</div>
	);
}

// ─── Section: Neural Forms Cloud AI ──────────────────────────────

function CloudAiSection( {
	form, set,
	cloudKey, setCloudKey,
	caps,
	onTestConnection, onRevokeConnection, connectionState,
	onProcessQueue, queueState,
} ) {
	const cloudPlan = form?.cloud_plan || {};
	const planLabel  = cloudPlan?.plan?.label || cloudPlan?.plan?.name || '';
	const credits    = Number( cloudPlan?.balance?.credits ?? cloudPlan?.balance?.available ?? NaN );
	const subStatus  = String( cloudPlan?.subscription?.status || '' );
	const connected  = !! ( form.cloud_api_key_set && subStatus === 'active' );
	const workspace  = String( cloudPlan?.workspace?.name || cloudPlan?.user?.name || '' );

	const hasBusinessAnalysis = !! caps.ai_reanalyze;
	const hasSpamDetect       = !! caps.ai_spam_detect;

	// First-run signup gate. Shown when no API key is set yet AND the
	// user hasn't dismissed it this page-load. Dismissal is intentionally
	// in-memory only (not persisted) so the gate reappears on reload if
	// the user bounced to /pricing without coming back with a key.
	const [ gateDismissed, setGateDismissed ] = useState( false );
	const showSignupGate = ! form.cloud_api_key_set && ! gateDismissed;

	// Pro presence detection (set by Pro's extend_script_data filter
	// at window.neuralFormsAdmin.pro.version). Lite-alone vs. Pro
	// changes both the gate copy and where the CTA points: Lite users
	// don't yet have an account so they go to the pricing page; Pro
	// users already have an account but haven't pasted their API key
	// yet so they go straight to my-account to copy it.
	const proInstalled = !! (
		typeof window !== 'undefined'
		&& window.neuralFormsAdmin
		&& window.neuralFormsAdmin.pro
		&& window.neuralFormsAdmin.pro.version
	);
	const gateUrl = proInstalled
		? 'https://neuralforms.ai/my-account/'
		: 'https://neuralforms.ai/pricing/';

	function handleSignupGateClick() {
		// Open in a new tab so the user can come back to Settings with
		// the page state intact (the field they may have started typing
		// in, the form snapshot, etc.). Local dismissal so they can
		// inspect / interact with the section without the overlay until
		// the next reload.
		window.open( gateUrl, '_blank', 'noopener,noreferrer' );
		setGateDismissed( true );
	}

	return (
		<div className="neural-forms-settings__cloud-wrap">
		<Section
			title={ __( 'Neural AI Cloud', 'neural-forms-lite' ) }
			description={ __( 'Connect to Neural AI Cloud to enable Neural AI-powered entry analysis, suggested replies, and spam detection.', 'neural-forms-lite' ) }
		>
			<Field
				label={ __( 'API Key', 'neural-forms-lite' ) }
				hint={ form.cloud_api_key_set
					? __( 'Your key is stored securely and never displayed. Leave blank to keep the current key.', 'neural-forms-lite' )
					: __( 'Paste your Neural Forms Cloud API key.', 'neural-forms-lite' ) }
			>
				<ApiKeyInput
					isSet={ !! form.cloud_api_key_set }
					value={ cloudKey }
					onChange={ setCloudKey }
				/>
			</Field>

			<Field label={ __( 'Connection', 'neural-forms-lite' ) }>
				<div className="neural-forms-settings__connection-row">
					<button
						type="button"
						className="neural-forms-settings__btn"
						onClick={ onTestConnection }
						disabled={ connectionState.testing }
					>
						{ connectionState.testing
							? __( 'Testing…', 'neural-forms-lite' )
							: __( 'Test Connection', 'neural-forms-lite' ) }
					</button>
					{ form.cloud_api_key_set && (
						<button
							type="button"
							className="neural-forms-settings__btn neural-forms-settings__btn--danger"
							onClick={ onRevokeConnection }
							disabled={ connectionState.revoking }
						>
							{ connectionState.revoking
								? __( 'Revoking…', 'neural-forms-lite' )
								: __( 'Revoke Connection', 'neural-forms-lite' ) }
						</button>
					) }
				</div>
				<ConnectionStatusPill connected={ connected } name={ workspace } />
				{ connectionState.message && (
					<p className={
						'neural-forms-settings__hint'
						+ ( connectionState.error
							? ' neural-forms-settings__hint--error'
							: ' neural-forms-settings__hint--ok' )
					}>
						{ connectionState.message }
					</p>
				) }
			</Field>

			{ form.cloud_api_key_set && (
				<Field label={ __( 'Plan & Balance', 'neural-forms-lite' ) }>
					<PlanBalanceCard plan={ planLabel } credits={ credits } />
				</Field>
			) }

			{ hasBusinessAnalysis ? (
				<ProSlot
					name="SettingsSiteProfile"
					fillProps={ {
						Field: ProField,
						cloudAiEnabled: !! form.cloud_ai_enabled,
					} }
				/>
			) : (
				<div className="neural-forms-settings__pro-row">
					<ProLockCard
						title={ __( 'Business-aware analysis is a Pro feature', 'neural-forms-lite' ) }
						description={ __( 'Neural Forms Pro classifies your site once a month so off-topic submissions are automatically scored lower and sorted out of your main inbox.', 'neural-forms-lite' ) }
						upgradeUrl={ cloudPlan?.upgrade_url }
					/>
				</div>
			) }

			<ToggleField
				label={ __( 'Enable Neural AI', 'neural-forms-lite' ) }
				checked={ form.cloud_ai_enabled }
				onChange={ ( v ) => set( { cloud_ai_enabled: v } ) }
			/>

			<ToggleField
				label={ __( 'Auto-process entries', 'neural-forms-lite' ) }
				hint={
					// Without the ai_process_entries capability (Lite) the queue
					// never auto-runs — say so instead of promising it.
					window.neuralFormsAdmin?.plugin?.capabilities?.ai_process_entries
						? __( 'Automatically analyse new submissions when they arrive.', 'neural-forms-lite' )
						: __( 'Automatic analysis of new submissions requires Neural Forms Pro. In the free plugin, open an entry in the inbox and click "Analyze with Neural AI" to analyze it once.', 'neural-forms-lite' )
				}
				checked={ form.ai_auto_process }
				onChange={ ( v ) => set( { ai_auto_process: v } ) }
			/>

			<Field
				label={ __( 'Process queue', 'neural-forms-lite' ) }
				hint={ __( 'Trigger an immediate Neural AI queue run. Useful on local/dev where WP-Cron may not fire automatically.', 'neural-forms-lite' ) }
			>
				<div className="neural-forms-settings__queue-row">
					<button
						type="button"
						className="neural-forms-settings__btn"
						onClick={ onProcessQueue }
						disabled={ queueState.running }
					>
						{ queueState.running
							? __( 'Processing…', 'neural-forms-lite' )
							: __( 'Process AI Queue Now', 'neural-forms-lite' ) }
					</button>
					{ queueState.message && (
						<span className={
							'neural-forms-settings__queue-msg'
							+ ( queueState.error
								? ' neural-forms-settings__queue-msg--error'
								: ' neural-forms-settings__queue-msg--ok' )
						}>
							{ queueState.message }
						</span>
					) }
				</div>
			</Field>

			{ hasSpamDetect ? (
				<ProSlot
					name="SettingsSpamPanel"
					fillProps={ {
						form,
						set,
						aiEnabled: !! form.cloud_ai_enabled,
						setSettingsNotice: () => {},
						Toggle,
						Field: ProField,
						requestUpgrade: () => {
							const url = cloudPlan?.upgrade_url || 'https://neuralforms.ai/pricing';
							window.open( url, '_blank', 'noopener' );
						},
						balance: cloudPlan?.balance || { credits: 0 },
					} }
				/>
			) : (
				<div className="neural-forms-settings__pro-row">
					<ProLockCard
						title={ __( 'Spam detection is a Pro feature', 'neural-forms-lite' ) }
						description={ __( 'Automatically classify submissions as spam and move high-confidence matches to the Spam folder.', 'neural-forms-lite' ) }
						upgradeUrl={ cloudPlan?.upgrade_url }
					/>
				</div>
			) }
		</Section>

		{ showSignupGate && (
			<div
				className="neural-forms-settings__cloud-gate"
				role="dialog"
				aria-labelledby="neural-forms-cloud-gate-title"
			>
				<div className="neural-forms-settings__cloud-gate-card">
					<span className="neural-forms-settings__cloud-gate-icon" aria-hidden="true">
						<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
							<rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
							<path d="M7 11V7a5 5 0 0 1 10 0v4" />
						</svg>
					</span>
					<h3
						id="neural-forms-cloud-gate-title"
						className="neural-forms-settings__cloud-gate-title"
					>
						{ proInstalled
							? __( 'Enter your API key to activate Neural AI', 'neural-forms-lite' )
							: __( 'Sign up for free to activate Lite features of Neural AI', 'neural-forms-lite' ) }
					</h3>
					<p className="neural-forms-settings__cloud-gate-desc">
						{ proInstalled
							? __( 'Copy your API key from your Neural Forms account and paste it into the API Key field above to unlock AI analysis, suggested replies, and spam detection.', 'neural-forms-lite' )
							: __( 'Connect your free Neural Forms Cloud account to unlock AI-powered entry analysis, suggested replies, and built-in spam detection.', 'neural-forms-lite' ) }
					</p>
					<button
						type="button"
						className="neural-forms-settings__cloud-gate-btn"
						onClick={ handleSignupGateClick }
					>
						{ proInstalled
							? __( 'Get API Key', 'neural-forms-lite' )
							: __( 'Sign up free', 'neural-forms-lite' ) }
					</button>
				</div>
			</div>
		) }
		</div>
	);
}

// ─── Section: Email Settings ─────────────────────────────────────

function EmailSettingsSection( {
	form, set,
	onSendTestEmail, testEmail, setTestEmail, testEmailState,
} ) {
	return (
		<Section
			title={ __( 'Email Settings', 'neural-forms-lite' ) }
			description={ __( 'Control how outgoing emails appear to recipients.', 'neural-forms-lite' ) }
		>
			<Field
				label={ __( 'Sender name', 'neural-forms-lite' ) }
				hint={ __( "Displayed as the 'From' name. Leave blank to use your site name.", 'neural-forms-lite' ) }
			>
				<TextInput
					type="text"
					value={ form.sender_name || '' }
					onChange={ ( e ) => set( { sender_name: e.target.value } ) }
				/>
			</Field>

			<Field
				label={ __( 'Sender email', 'neural-forms-lite' ) }
				hint={ __( "Displayed as the 'From' address. Leave blank to use the WordPress admin email.", 'neural-forms-lite' ) }
			>
				<TextInput
					type="email"
					value={ form.sender_email || '' }
					onChange={ ( e ) => set( { sender_email: e.target.value } ) }
				/>
			</Field>

			<Field label={ __( 'Default reply subject', 'neural-forms-lite' ) }>
				<TextInput
					type="text"
					value={ form.reply_subject_pattern || '' }
					onChange={ ( e ) => set( { reply_subject_pattern: e.target.value } ) }
				/>
			</Field>

			<div className="neural-forms-settings__notice neural-forms-settings__notice--info">
				{ __( "Neural Forms sends email via WordPress's built-in", 'neural-forms-lite' ) }
				{ ' ' }
				<code>wp_mail()</code>
				{ '. ' }
				{ __( 'To use a custom SMTP server or transactional email service, install a mail plugin such as WP Mail SMTP.', 'neural-forms-lite' ) }
			</div>

			<Field label={ __( 'Send test email', 'neural-forms-lite' ) }>
				<div className="neural-forms-settings__test-email-wrap">
					<TextInput
						type="email"
						value={ testEmail }
						onChange={ ( e ) => setTestEmail( e.target.value ) }
						placeholder={ form.admin_notification_email || form.sender_email || '' }
					/>
				</div>
				<button
					type="button"
					className="neural-forms-settings__btn neural-forms-settings__btn--secondary"
					onClick={ onSendTestEmail }
					disabled={ testEmailState.sending }
				>
					{ testEmailState.sending
						? __( 'Sending…', 'neural-forms-lite' )
						: __( 'Send test email', 'neural-forms-lite' ) }
				</button>
				{ testEmailState.message && (
					<p className={
						'neural-forms-settings__hint'
						+ ( testEmailState.error
							? ' neural-forms-settings__hint--error'
							: ' neural-forms-settings__hint--ok' )
					}>
						{ testEmailState.message }
					</p>
				) }
				<p className="neural-forms-settings__hint">
					{ __( 'Sends a test message using the sender settings above. Useful for verifying your mail configuration.', 'neural-forms-lite' ) }
				</p>
			</Field>
		</Section>
	);
}

// ─── Section: Notifications ──────────────────────────────────────

function NotificationsSection( { form, set } ) {
	return (
		<Section
			title={ __( 'Notifications', 'neural-forms-lite' ) }
			description={ __( 'Control which emails are sent automatically when a form is submitted.', 'neural-forms-lite' ) }
		>
			<ToggleField
				label={ __( 'Admin notification', 'neural-forms-lite' ) }
				hint={ __( 'Send an email to the admin whenever a new submission arrives.', 'neural-forms-lite' ) }
				checked={ form.enable_admin_notification }
				onChange={ ( v ) => set( { enable_admin_notification: v } ) }
			/>

			<Field
				label={ __( 'Notify address', 'neural-forms-lite' ) }
				hint={ __( 'Separate multiple email addresses with commas. Leave blank to use the WordPress admin email.', 'neural-forms-lite' ) }
			>
				{ /* type="text" rather than type="email" so the browser
				     doesn't reject commas. The server splits the list and
				     re-joins valid addresses on save. */ }
				<TextInput
					type="text"
					value={ form.admin_notification_email || '' }
					onChange={ ( e ) => set( { admin_notification_email: e.target.value } ) }
					placeholder={ __( 'you@example.com, manager@example.com', 'neural-forms-lite' ) }
				/>
			</Field>

			<ToggleField
				label={ __( 'Autoresponder', 'neural-forms-lite' ) }
				hint={ __( 'Send a confirmation email to the submitter when their entry contains an email address.', 'neural-forms-lite' ) }
				checked={ form.enable_autoresponder }
				onChange={ ( v ) => set( { enable_autoresponder: v } ) }
			/>

			{ form.enable_autoresponder && (
				<>
					<Field
						label={ __( 'Subject', 'neural-forms-lite' ) }
						hint={ __( 'Tokens work here too: {site_name}, {form_name}, or a field ID in {curly braces}.', 'neural-forms-lite' ) }
					>
						<TextInput
							type="text"
							value={ form.autoresponder_subject || '' }
							onChange={ ( e ) => set( { autoresponder_subject: e.target.value } ) }
						/>
					</Field>
					<Field
						label={ __( 'Message body', 'neural-forms-lite' ) }
						hint={ __( 'Available tokens: {site_name}, {form_name}, and any field ID wrapped in {curly braces}. Find a field\'s ID in the form builder: select the field and look under Field settings > Field ID (there is a "Copy token" button). Choice fields insert the option label.', 'neural-forms-lite' ) }
					>
						<Textarea
							rows={ 5 }
							value={ form.autoresponder_body || '' }
							onChange={ ( e ) => set( { autoresponder_body: e.target.value } ) }
						/>
					</Field>
				</>
			) }
		</Section>
	);
}

// ─── Section: CAPTCHA ────────────────────────────────────────────

function CaptchaSection( { form, set, captchaSecretKey, setCaptchaSecretKey } ) {
	const provider = form.captcha_provider || 'none';
	return (
		<Section
			title={ __( 'CAPTCHA', 'neural-forms-lite' ) }
			description={ __( 'Add a CAPTCHA challenge to individual forms to reduce spam submissions.', 'neural-forms-lite' ) }
		>
			<Field label={ __( 'Provider', 'neural-forms-lite' ) }>
				<Select
					value={ provider }
					onChange={ ( e ) => set( { captcha_provider: e.target.value } ) }
				>
					{ CAPTCHA_PROVIDERS.map( ( p ) => (
						<option key={ p.value } value={ p.value }>{ p.label }</option>
					) ) }
				</Select>
			</Field>

			{ provider !== 'none' && (
				<>
					<Field label={ __( 'Site key', 'neural-forms-lite' ) }>
						<TextInput
							type="text"
							value={ form.captcha_site_key || '' }
							onChange={ ( e ) => set( { captcha_site_key: e.target.value } ) }
						/>
					</Field>
					<Field
						label={ __( 'Secret key', 'neural-forms-lite' ) }
						hint={ form.captcha_secret_key_set
							? __( 'Currently set. Leave blank to keep.', 'neural-forms-lite' )
							: undefined }
					>
						<ApiKeyInput
							isSet={ !! form.captcha_secret_key_set }
							value={ captchaSecretKey }
							onChange={ setCaptchaSecretKey }
						/>
					</Field>
				</>
			) }
		</Section>
	);
}

// ─── Section: Processing health (1.2.0) ──────────────────────────

function ProcessingHealthSection() {
	const [ health, setHealth ] = useState( null );
	const [ error, setError ]   = useState( '' );
	useEffect( () => {
		api( '/workflows/health' ).then( setHealth ).catch( ( e ) => setError( e?.message || __( 'Could not load processing health.', 'neural-forms-lite' ) ) );
	}, [] );

	const fmt = ( s ) => {
		if ( ! s ) return __( 'never', 'neural-forms-lite' );
		const d = new Date( String( s ).replace( ' ', 'T' ) + 'Z' );
		return isNaN( d.getTime() ) ? String( s ) : d.toLocaleString();
	};
	const total = ( type ) => {
		const q = health?.queue?.[ type ] || {};
		return Object.values( q ).reduce( ( a, b ) => a + b, 0 );
	};

	return (
		<Section
			title={ __( 'Processing health', 'neural-forms-lite' ) }
			description={ __( 'Background jobs (AI analysis, workflows) run on WordPress cron. On a low-traffic site cron only fires when someone visits, so a real system cron keeps things moving.', 'neural-forms-lite' ) }
		>
			{ error && <div className="neural-forms-settings__notice neural-forms-settings__notice--warn">{ error }</div> }
			{ ! health && ! error && (
				<Field label={ __( 'Queue', 'neural-forms-lite' ) }>
					<p className="neural-forms-settings__hint">{ __( 'Loading…', 'neural-forms-lite' ) }</p>
				</Field>
			) }
			{ health && (
				<>
					<Field label={ __( 'Queue', 'neural-forms-lite' ) }>
						<dl className="neural-forms-settings__health">
							<dt>{ __( 'AI jobs waiting', 'neural-forms-lite' ) }</dt><dd>{ total( 'analyze_entry' ) + total( 'regenerate_reply' ) }</dd>
							<dt>{ __( 'Workflow jobs waiting', 'neural-forms-lite' ) }</dt><dd>{ total( 'workflow' ) }</dd>
							<dt>{ __( 'Oldest waiting job', 'neural-forms-lite' ) }</dt><dd>{ fmt( health.oldest_pending ) }</dd>
						</dl>
					</Field>
					<Field label={ __( 'Cron', 'neural-forms-lite' ) }>
						<dl className="neural-forms-settings__health">
							<dt>{ __( 'Last queue run', 'neural-forms-lite' ) }</dt><dd>{ fmt( health.last_run_at ) }</dd>
							<dt>{ __( 'Next scheduled run', 'neural-forms-lite' ) }</dt><dd>{ fmt( health.next_run_at ) }</dd>
							<dt>{ __( 'Follow-up check', 'neural-forms-lite' ) }</dt><dd>{ fmt( health.sweep_next_at ) }</dd>
							<dt>{ __( 'WP-Cron', 'neural-forms-lite' ) }</dt><dd>{ health.wp_cron_disabled ? __( 'disabled (system cron expected)', 'neural-forms-lite' ) : __( 'enabled', 'neural-forms-lite' ) }</dd>
						</dl>
					</Field>
					<Field
						label={ __( 'System cron', 'neural-forms-lite' ) }
						hint={ health.wp_cron_disabled
							? __( 'WP-Cron is disabled on this site. Make sure a system cron calls wp-cron.php every few minutes, for example with the line above.', 'neural-forms-lite' )
							: __( 'Optional. For reliable timing on quiet sites, add define( \'DISABLE_WP_CRON\', true ); to wp-config.php and run the line above from a system cron every 5 minutes.', 'neural-forms-lite' ) }
					>
						<pre className="neural-forms-settings__code">{ health.system_cron_hint }</pre>
					</Field>
				</>
			) }
		</Section>
	);
}

// ─── Section: General & Privacy ──────────────────────────────────


function GeneralPrivacySection( { form, set } ) {
	return (
		<Section title={ __( 'General & Privacy', 'neural-forms-lite' ) }>
			<ToggleField
				label={ __( 'Privacy mode', 'neural-forms-lite' ) }
				hint={ __( 'When enabled, Neural Forms will not store IP addresses or user-agent strings with form submissions.', 'neural-forms-lite' ) }
				checked={ form.privacy_mode }
				onChange={ ( v ) => set( { privacy_mode: v } ) }
			/>

			{ !! form.cloud_ai_enabled && (
				<div className="neural-forms-settings__notice neural-forms-settings__notice--warn">
					<strong>{ __( 'Neural AI & Privacy:', 'neural-forms-lite' ) }</strong>
					{ ' ' }
					{ __( 'When Neural AI is on, submission content is sent to Neural Forms Cloud for processing. Ensure this aligns with your privacy policy and any applicable data regulations (e.g. GDPR).', 'neural-forms-lite' ) }
				</div>
			) }
		</Section>
	);
}

// ─── Screen ──────────────────────────────────────────────────────

export default function Settings() {
	const [ initial, setInitial ] = useState( null );
	const [ form,    setForm    ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error,   setError   ] = useState( '' );
	const [ saving,  setSaving  ] = useState( false );
	const [ saved,   setSaved   ] = useState( false );

	// Write-only key inputs.
	const [ cloudKey,         setCloudKey         ] = useState( '' );
	const [ captchaSecretKey, setCaptchaSecretKey ] = useState( '' );

	// Per-action state for the rich Cloud AI panel + test email.
	const [ connectionState, setConnectionState ] = useState( { testing: false, revoking: false, message: '', error: false } );
	const [ queueState,      setQueueState      ] = useState( { running: false, message: '', error: false } );
	const [ testEmail,       setTestEmail       ] = useState( '' );
	const [ testEmailState,  setTestEmailState  ] = useState( { sending: false, message: '', error: false } );

	// Plugin-level capability snapshot — see AdminPanel::script_data().
	// Pro flips these via the `neural_forms_can_*` filters, so when Pro
	// is installed every cap is `true` and the lock cards are hidden.
	const caps = ( typeof window !== 'undefined' && window.neuralFormsAdmin?.plugin?.capabilities )
		|| {};

	useEffect( () => {
		let cancelled = false;
		api( '/settings' )
			.then( ( res ) => {
				if ( cancelled ) return;
				setInitial( res );
				setForm( res );
				setLoading( false );
			} )
			.catch( ( err ) => {
				if ( cancelled ) return;
				setError( err?.message || __( 'Could not load settings.', 'neural-forms-lite' ) );
				setLoading( false );
			} );
		return () => { cancelled = true; };
	}, [] );

	// Drives the header save-bar's three states (saved / dirty / saving).
	// MUST live above the loading/error early returns so hooks fire in
	// the same order on every render — otherwise React errors with
	// "Rendered more hooks than during the previous render" the moment
	// `form` flips from null to populated.
	const isDirty = useMemo( () => {
		if ( ! initial || ! form ) return false;
		if ( cloudKey.trim() !== '' || captchaSecretKey.trim() !== '' ) return true;
		for ( const k of Object.keys( form ) ) {
			if ( k.endsWith( '_set' ) || k === 'cloud_plan' || k === 'cloud_signup_complete' ) continue;
			if ( JSON.stringify( form[ k ] ) !== JSON.stringify( initial[ k ] ) ) return true;
		}
		return false;
	}, [ form, initial, cloudKey, captchaSecretKey ] );

	if ( loading ) {
		return (
			<div className="neural-forms-settings">
				<div className="neural-forms-settings__loading">
					{ __( 'Loading settings…', 'neural-forms-lite' ) }
				</div>
			</div>
		);
	}
	if ( error && ! form ) {
		return (
			<div className="neural-forms-settings">
				<div className="neural-forms-settings__loading">{ error }</div>
			</div>
		);
	}
	if ( ! form ) return null;

	// Accepts BOTH calling conventions:
	//   set( { foo: 1, bar: 2 } )            — Lite's object-patch style
	//   set( 'foo', 1 )                      — Pro fills' key/value style
	// Pro's compiled bundle (e.g. SettingsSpamPanel) calls the second form,
	// so without this adapter every change inside a Pro fill silently
	// no-ops — that's why the Auto-move dropdown wouldn't stick and the
	// Confidence threshold field stayed hidden.
	const set = ( keyOrPatch, value ) => {
		if ( keyOrPatch && typeof keyOrPatch === 'object' ) {
			setForm( { ...form, ...keyOrPatch } );
		} else if ( typeof keyOrPatch === 'string' ) {
			setForm( { ...form, [ keyOrPatch ]: value } );
		}
		setSaved( false );
	};

	// ── Save flow (diff PATCH) ─────────────────────────────────
	const save = () => {
		setSaving( true );
		setError( '' );

		const diff = {};
		for ( const k of Object.keys( form ) ) {
			if ( k.endsWith( '_set' ) || k === 'cloud_plan' || k === 'cloud_signup_complete' ) continue;
			if ( JSON.stringify( form[ k ] ) !== JSON.stringify( initial[ k ] ) ) {
				diff[ k ] = form[ k ];
			}
		}
		if ( cloudKey.trim() !== '' )         diff.cloud_api_key      = cloudKey.trim();
		if ( captchaSecretKey.trim() !== '' ) diff.captcha_secret_key = captchaSecretKey.trim();

		if ( Object.keys( diff ).length === 0 ) {
			setSaving( false );
			setSaved( true );
			setTimeout( () => setSaved( false ), 1800 );
			return;
		}

		api( '/settings', { method: 'PATCH', data: diff } )
			.then( ( res ) => {
				setInitial( res );
				setForm( res );
				setCloudKey( '' );
				setCaptchaSecretKey( '' );
				setSaving( false );
				setSaved( true );
				setTimeout( () => setSaved( false ), 1800 );
			} )
			.catch( ( err ) => {
				setSaving( false );
				setError( err?.message || __( 'Save failed.', 'neural-forms-lite' ) );
			} );
	};

	// ── Cloud connection actions ────────────────────────────────
	const onTestConnection = () => {
		setConnectionState( { testing: true, revoking: false, message: '', error: false } );
		const payload = cloudKey.trim() !== '' ? { api_key: cloudKey.trim() } : {};
		api( '/settings/test-connection', { method: 'POST', data: payload } )
			.then( ( res ) => {
				// Refresh the local form so cloud_plan / cloud_api_key_set
				// reflect the test outcome.
				api( '/settings' ).then( ( settings ) => {
					setInitial( settings );
					setForm( settings );
					// Pro's gated fills subscribe to this store and re-render without a reload.
					if ( window.neuralForms?.plan?.set ) {
						window.neuralForms.plan.set( settings?.cloud_plan || {} );
					}
				} );
				setConnectionState( {
					testing: false, revoking: false, error: false,
					message: res?.workspace_name
						? __( 'Connected to ', 'neural-forms-lite' ) + res.workspace_name
						: __( 'Connection successful.', 'neural-forms-lite' ),
				} );
			} )
			.catch( ( err ) => {
				setConnectionState( {
					testing: false, revoking: false, error: true,
					message: err?.message || __( 'Connection failed.', 'neural-forms-lite' ),
				} );
			} );
	};

	const onRevokeConnection = () => {
		if ( ! window.confirm( __( 'Revoke the saved Cloud API key? Neural AI features will stop working until a new key is connected.', 'neural-forms-lite' ) ) ) {
			return;
		}
		setConnectionState( { testing: false, revoking: true, message: '', error: false } );
		api( '/settings/revoke-connection', { method: 'POST' } )
			.then( () => {
				api( '/settings' ).then( ( settings ) => {
					setInitial( settings );
					setForm( settings );
					setCloudKey( '' );
				} );
				setConnectionState( {
					testing: false, revoking: false, error: false,
					message: __( 'Connection revoked.', 'neural-forms-lite' ),
				} );
			} )
			.catch( ( err ) => {
				setConnectionState( {
					testing: false, revoking: false, error: true,
					message: err?.message || __( 'Revoke failed.', 'neural-forms-lite' ),
				} );
			} );
	};

	// ── Process AI queue ────────────────────────────────────────
	const onProcessQueue = () => {
		setQueueState( { running: true, message: '', error: false } );
		api( '/ai/process-queue', { method: 'POST' } )
			.then( ( res ) => {
				const summary = res && typeof res.summary === 'object' ? res.summary : null;
				const jobsCharged = Number( summary?.jobs_charged ?? 0 );
				let message;
				if ( jobsCharged > 0 ) {
					const used = Number( summary.credits_used ?? 0 ).toFixed( 2 );
					const left = summary.credits_remaining != null
						? Number( summary.credits_remaining ).toFixed( 2 )
						: null;
					message = jobsCharged + ' ' + __( 'job(s) processed.', 'neural-forms-lite' )
						+ ' ' + __( 'Credits used:', 'neural-forms-lite' ) + ' ' + used;
					if ( left !== null ) {
						message += ' · ' + __( 'Remaining:', 'neural-forms-lite' ) + ' ' + left;
					}
				} else {
					message = __( 'Queue is empty.', 'neural-forms-lite' );
				}
				setQueueState( { running: false, error: false, message } );
			} )
			.catch( ( err ) => {
				setQueueState( {
					running: false, error: true,
					message: err?.message || __( 'Queue run failed.', 'neural-forms-lite' ),
				} );
			} );
	};

	// ── Send test email ────────────────────────────────────────
	const onSendTestEmail = () => {
		setTestEmailState( { sending: true, message: '', error: false } );
		const payload = testEmail.trim() !== '' ? { to: testEmail.trim() } : {};
		api( '/settings/test-email', { method: 'POST', data: payload } )
			.then( ( res ) => {
				setTestEmailState( {
					sending: false, error: false,
					message: res?.sent
						? __( 'Test email sent.', 'neural-forms-lite' )
						: __( 'Test email queued.', 'neural-forms-lite' ),
				} );
			} )
			.catch( ( err ) => {
				setTestEmailState( {
					sending: false, error: true,
					message: err?.message || __( 'Test email failed.', 'neural-forms-lite' ),
				} );
			} );
	};

	const renderSaveBar = ( variant ) => {
		// Three reactive states, same on top + bottom:
		//   - just saved   → green "Saved! Your changes are live." + ✓ Saved pill
		//   - dirty        → orange "Unsaved changes" + navy Save button
		//   - saving       → "Saving…" disabled button
		//   - idle         → ✓ Saved pill alone
		// `variant` toggles `__save-bar--top` (compact, no flex-end pad)
		// vs the default bottom-aligned bar.
		const cls = 'neural-forms-settings__save-bar'
			+ ( variant === 'top' ? ' neural-forms-settings__save-bar--top' : '' );
		return (
			<div className={ cls }>
				{ saved && (
					<span className="neural-forms-settings__save-msg neural-forms-settings__save-msg--ok">
						{ __( 'Saved! Your changes are live.', 'neural-forms-lite' ) }
					</span>
				) }
				{ isDirty && ! saving && ! saved && (
					<span className="neural-forms-settings__save-msg neural-forms-settings__save-msg--dirty">
						{ __( 'Unsaved changes', 'neural-forms-lite' ) }
					</span>
				) }
				{ error && (
					<span className="neural-forms-settings__save-msg neural-forms-settings__save-msg--error">
						{ error }
					</span>
				) }

				{ ( isDirty || saving ) ? (
					<button
						type="button"
						className="neural-forms-settings__save-btn"
						onClick={ save }
						disabled={ saving }
					>
						{ saving
							? __( 'Saving…', 'neural-forms-lite' )
							: __( 'Save settings', 'neural-forms-lite' ) }
					</button>
				) : (
					<span
						className="neural-forms-settings__save-btn neural-forms-settings__save-btn--saved"
						aria-live="polite"
					>
						{ __( 'Saved', 'neural-forms-lite' ) }
					</span>
				) }
			</div>
		);
	};

	return (
		<div className="neural-forms-settings">
			<header className="neural-forms-settings__header">
				<div className="neural-forms-settings__header-text">
					<h1 className="neural-forms-settings__page-title">
						{ __( 'Settings', 'neural-forms-lite' ) }
					</h1>
					<p className="neural-forms-settings__page-desc">
						{ __( 'Configure Neural AI, notifications, email, and privacy options for Neural Forms.', 'neural-forms-lite' ) }
					</p>
				</div>
				{ renderSaveBar( 'top' ) }
			</header>

			<CloudAiSection
				form={ form }
				set={ set }
				cloudKey={ cloudKey }
				setCloudKey={ setCloudKey }
				caps={ caps }
				onTestConnection={ onTestConnection }
				onRevokeConnection={ onRevokeConnection }
				connectionState={ connectionState }
				onProcessQueue={ onProcessQueue }
				queueState={ queueState }
			/>

			<EmailSettingsSection
				form={ form }
				set={ set }
				onSendTestEmail={ onSendTestEmail }
				testEmail={ testEmail }
				setTestEmail={ setTestEmail }
				testEmailState={ testEmailState }
			/>

			<NotificationsSection form={ form } set={ set } />

			<CaptchaSection
				form={ form }
				set={ set }
				captchaSecretKey={ captchaSecretKey }
				setCaptchaSecretKey={ setCaptchaSecretKey }
			/>

			<GeneralPrivacySection form={ form } set={ set } />
			<ProcessingHealthSection />

			{ /* Bottom-right save bar — same three states as the header so
			     a long settings page never leaves the user scrolling back
			     up to save. */ }
			{ renderSaveBar( 'bottom' ) }
		</div>
	);
}
