/**
 * Plugin Settings screen - simplified for 1.1.0.
 *
 * Reads GET /settings, lets the admin edit the high-traffic keys, then
 * PATCHes only the changed keys back. The endpoint is field-by-field
 * partial: keys that aren't sent are left untouched - so any legacy /
 * unknown / Pro-side settings that happen to exist on the option row
 * survive every save without us having to enumerate them here.
 *
 * Write-only fields:
 *   - cloud_api_key   -> never returned by GET. UI shows "set" badge
 *                        plus an input that writes a new value when
 *                        non-empty (empty submit leaves the existing
 *                        key in place).
 *   - captcha_secret_key -> same write-only contract.
 */

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../api';

const CAPTCHA_PROVIDERS = [
	{ value: 'none',         label: 'None'             },
	{ value: 'turnstile',    label: 'Cloudflare Turnstile' },
	{ value: 'recaptcha_v2', label: 'reCAPTCHA v2'     },
];

const SPAM_MODES = [
	{ value: 'suggest', label: 'Suggest only'  },
	{ value: 'auto',    label: 'Auto-mark spam' },
];

export default function Settings() {
	const [ initial, setInitial ] = useState( null );
	const [ form,    setForm    ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error,   setError   ] = useState( '' );
	const [ saving,  setSaving  ] = useState( false );
	const [ saved,   setSaved   ] = useState( false );

	// Write-only key inputs - tracked separately so an empty value means
	// "leave existing key in place" rather than "blank out the key".
	const [ cloudKey,        setCloudKey        ] = useState( '' );
	const [ captchaSecretKey, setCaptchaSecretKey ] = useState( '' );

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

	if ( loading ) {
		return <div className="neural-forms-screen"><p>{ __( 'Loading settings...', 'neural-forms-lite' ) }</p></div>;
	}
	if ( error ) {
		return <div className="neural-forms-screen neural-forms-screen--error">{ error }</div>;
	}
	if ( ! form ) return null;

	const set = ( patch ) => {
		setForm( { ...form, ...patch } );
		setSaved( false );
	};

	const save = () => {
		setSaving( true );
		setError( '' );

		// Build a diff: only send keys whose value actually changed. The
		// endpoint already does a per-key partial update, but sending only
		// changed keys keeps log noise low and makes intent explicit.
		const diff = {};
		for ( const k of Object.keys( form ) ) {
			// Never echo write-only flags (the *_set / cloud_signup_complete
			// fields the GET response derives) or read-only nested objects.
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
			setTimeout( () => setSaved( false ), 1500 );
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
				setTimeout( () => setSaved( false ), 1500 );
			} )
			.catch( ( err ) => {
				setSaving( false );
				setError( err?.message || __( 'Save failed.', 'neural-forms-lite' ) );
			} );
	};

	return (
		<div className="neural-forms-screen nf-settings">
			<header className="neural-forms-screen__header">
				<h1>{ __( 'Settings', 'neural-forms-lite' ) }</h1>
				<div className="nf-settings__actions">
					{ saved && <span className="nf-settings__saved">{ __( 'Saved', 'neural-forms-lite' ) }</span> }
					<button
						type="button"
						className="neural-forms-btn neural-forms-btn--primary"
						onClick={ save }
						disabled={ saving }
					>
						{ saving ? __( 'Saving...', 'neural-forms-lite' ) : __( 'Save changes', 'neural-forms-lite' ) }
					</button>
				</div>
			</header>

			<section className="nf-settings__section">
				<h2>{ __( 'Notifications', 'neural-forms-lite' ) }</h2>
				<label className="nf-settings__field nf-settings__field--inline">
					<input type="checkbox" checked={ !! form.enable_admin_notification }
						onChange={ ( e ) => set( { enable_admin_notification: e.target.checked } ) } />
					<span>{ __( 'Send admin notification on submission', 'neural-forms-lite' ) }</span>
				</label>
				<label className="nf-settings__field">
					<span>{ __( 'Notification email', 'neural-forms-lite' ) }</span>
					<input type="email" value={ form.admin_notification_email || '' }
						onChange={ ( e ) => set( { admin_notification_email: e.target.value } ) }
						placeholder={ __( 'Falls back to WP admin email when blank.', 'neural-forms-lite' ) } />
				</label>
				<label className="nf-settings__field">
					<span>{ __( 'Sender name', 'neural-forms-lite' ) }</span>
					<input type="text" value={ form.sender_name || '' }
						onChange={ ( e ) => set( { sender_name: e.target.value } ) } />
				</label>
				<label className="nf-settings__field">
					<span>{ __( 'Sender email', 'neural-forms-lite' ) }</span>
					<input type="email" value={ form.sender_email || '' }
						onChange={ ( e ) => set( { sender_email: e.target.value } ) } />
				</label>
				<label className="nf-settings__field">
					<span>{ __( 'Reply subject pattern', 'neural-forms-lite' ) }</span>
					<input type="text" value={ form.reply_subject_pattern || '' }
						onChange={ ( e ) => set( { reply_subject_pattern: e.target.value } ) } />
				</label>
			</section>

			<section className="nf-settings__section">
				<h2>{ __( 'Autoresponder', 'neural-forms-lite' ) }</h2>
				<label className="nf-settings__field nf-settings__field--inline">
					<input type="checkbox" checked={ !! form.enable_autoresponder }
						onChange={ ( e ) => set( { enable_autoresponder: e.target.checked } ) } />
					<span>{ __( 'Send a thank-you email to the submitter', 'neural-forms-lite' ) }</span>
				</label>
				<label className="nf-settings__field">
					<span>{ __( 'Subject', 'neural-forms-lite' ) }</span>
					<input type="text" value={ form.autoresponder_subject || '' }
						onChange={ ( e ) => set( { autoresponder_subject: e.target.value } ) } />
				</label>
				<label className="nf-settings__field">
					<span>{ __( 'Body', 'neural-forms-lite' ) }</span>
					<textarea rows={ 4 } value={ form.autoresponder_body || '' }
						onChange={ ( e ) => set( { autoresponder_body: e.target.value } ) } />
				</label>
			</section>

			<section className="nf-settings__section">
				<h2>{ __( 'CAPTCHA', 'neural-forms-lite' ) }</h2>
				<label className="nf-settings__field">
					<span>{ __( 'Provider', 'neural-forms-lite' ) }</span>
					<select value={ form.captcha_provider || 'none' }
						onChange={ ( e ) => set( { captcha_provider: e.target.value } ) }>
						{ CAPTCHA_PROVIDERS.map( ( p ) => (
							<option key={ p.value } value={ p.value }>{ p.label }</option>
						) ) }
					</select>
				</label>
				{ form.captcha_provider !== 'none' && (
					<>
						<label className="nf-settings__field">
							<span>{ __( 'Site key', 'neural-forms-lite' ) }</span>
							<input type="text" value={ form.captcha_site_key || '' }
								onChange={ ( e ) => set( { captcha_site_key: e.target.value } ) } />
						</label>
						<label className="nf-settings__field">
							<span>
								{ __( 'Secret key', 'neural-forms-lite' ) }
								{ form.captcha_secret_key_set && (
									<em className="nf-settings__hint">{ __( ' (currently set - leave blank to keep)', 'neural-forms-lite' ) }</em>
								) }
							</span>
							<input type="password" value={ captchaSecretKey }
								onChange={ ( e ) => setCaptchaSecretKey( e.target.value ) }
								placeholder={ form.captcha_secret_key_set ? '••••••••' : '' } />
						</label>
					</>
				) }
			</section>

			<section className="nf-settings__section">
				<h2>{ __( 'Neural Forms Cloud (AI)', 'neural-forms-lite' ) }</h2>
				<label className="nf-settings__field nf-settings__field--inline">
					<input type="checkbox" checked={ !! form.cloud_ai_enabled }
						onChange={ ( e ) => set( { cloud_ai_enabled: e.target.checked } ) } />
					<span>{ __( 'Enable Cloud AI', 'neural-forms-lite' ) }</span>
				</label>
				<label className="nf-settings__field">
					<span>
						{ __( 'API key', 'neural-forms-lite' ) }
						{ form.cloud_api_key_set && (
							<em className="nf-settings__hint">{ __( ' (currently set - leave blank to keep)', 'neural-forms-lite' ) }</em>
						) }
					</span>
					<input type="password" value={ cloudKey }
						onChange={ ( e ) => setCloudKey( e.target.value ) }
						placeholder={ form.cloud_api_key_set ? '••••••••' : '' } />
				</label>
				<label className="nf-settings__field nf-settings__field--inline">
					<input type="checkbox" checked={ !! form.ai_auto_process }
						onChange={ ( e ) => set( { ai_auto_process: e.target.checked } ) } />
					<span>{ __( 'Auto-process AI analysis on submission', 'neural-forms-lite' ) }</span>
				</label>
			</section>

			<section className="nf-settings__section">
				<h2>{ __( 'Spam detection', 'neural-forms-lite' ) }</h2>
				<label className="nf-settings__field nf-settings__field--inline">
					<input type="checkbox" checked={ !! form.ai_spam_enabled }
						onChange={ ( e ) => set( { ai_spam_enabled: e.target.checked } ) } />
					<span>{ __( 'Use AI to detect spam submissions', 'neural-forms-lite' ) }</span>
				</label>
				<label className="nf-settings__field">
					<span>{ __( 'Mode', 'neural-forms-lite' ) }</span>
					<select value={ form.ai_spam_mode || 'suggest' }
						onChange={ ( e ) => set( { ai_spam_mode: e.target.value } ) }>
						{ SPAM_MODES.map( ( m ) => (
							<option key={ m.value } value={ m.value }>{ m.label }</option>
						) ) }
					</select>
				</label>
				<label className="nf-settings__field">
					<span>{ __( 'Confidence threshold', 'neural-forms-lite' ) } ({ form.ai_spam_threshold ?? 85 })</span>
					<input type="range" min="60" max="100" step="1"
						value={ form.ai_spam_threshold ?? 85 }
						onChange={ ( e ) => set( { ai_spam_threshold: parseInt( e.target.value, 10 ) } ) } />
				</label>
				<label className="nf-settings__field nf-settings__field--inline">
					<input type="checkbox" checked={ !! form.email_skip_spam_notifications }
						onChange={ ( e ) => set( { email_skip_spam_notifications: e.target.checked } ) } />
					<span>{ __( 'Skip admin emails for spam-flagged entries', 'neural-forms-lite' ) }</span>
				</label>
			</section>

			<section className="nf-settings__section">
				<h2>{ __( 'Privacy', 'neural-forms-lite' ) }</h2>
				<label className="nf-settings__field nf-settings__field--inline">
					<input type="checkbox" checked={ !! form.privacy_mode }
						onChange={ ( e ) => set( { privacy_mode: e.target.checked } ) } />
					<span>{ __( 'Privacy mode: skip storing IP and User-Agent on submissions', 'neural-forms-lite' ) }</span>
				</label>
			</section>
		</div>
	);
}
