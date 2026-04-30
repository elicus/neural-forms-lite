/**
 * Per-form Settings tab.
 *
 * Reads and writes to the same `form.settings` keys the v1.0.0 admin
 * UI has always used. The active 1.0.0 build keeps rendering its
 * existing settings UI; this scaffold only kicks in once the bundle is
 * rebuilt from source. The keys are deliberately a subset of what the
 * engine already supports - no new state surface is introduced.
 *
 * Keys touched (all pre-existing in Config.php / FormRenderer.php):
 *   - captcha_enabled
 *   - enable_admin_notification
 *   - admin_notification_email
 *   - on_success.{ mode, message, url }
 *   - on_error.{ mode, message, url }
 *
 * The legacy `success_message` fallback is intentionally NOT edited
 * here; FormRenderer continues to honour it as a last-resort fallback
 * when on_success.message is empty, and rewriting it would silently
 * change pre-existing form behaviour.
 *
 * Layout:
 *
 *   [Form Settings + intro paragraph]
 *   ┌────────────┬────────────────────────────────────┐
 *   │ Security   │  active section's controls         │
 *   │ Notifs.    │                                    │
 *   │ On success │                                    │
 *   │ On error   │                                    │
 *   └────────────┴────────────────────────────────────┘
 *
 * Section nav lives on the left so the form-settings tab feels as
 * polished as the builder's 3-panel canvas. On narrow viewports the
 * nav collapses into a horizontal row of pills above the panel.
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const SECTIONS = [
	{ id: 'security',     label: 'Security',     hint: 'Protect this form from automated submissions.' },
	{ id: 'notifications', label: 'Notifications', hint: 'Choose who hears about new submissions.' },
	{ id: 'on_success',   label: 'On success',   hint: 'What submitters see after a successful submit.' },
	{ id: 'on_error',     label: 'On error',     hint: 'What submitters see when a submit fails.' },
];

export default function FormSettings( { settings, onChange } ) {
	const [ section, setSection ] = useState( SECTIONS[ 0 ].id );

	const s = settings || {};
	const onSuccess = { mode: 'message', message: '', url: '', ...( s.on_success || {} ) };
	const onError   = { mode: 'message', message: '', url: '', ...( s.on_error   || {} ) };

	const set = ( patch ) => onChange( { ...s, ...patch } );
	const setOnSuccess = ( patch ) => set( { on_success: { ...onSuccess, ...patch } } );
	const setOnError   = ( patch ) => set( { on_error:   { ...onError,   ...patch } } );

	const activeSection = SECTIONS.find( ( x ) => x.id === section ) || SECTIONS[ 0 ];

	return (
		<div className="nf-form-settings nf-form-settings--split">
			<header className="nf-form-settings__intro">
				<h2 className="nf-form-settings__intro-title">{ __( 'Form Settings', 'neural-forms-lite' ) }</h2>
				<p className="nf-form-settings__intro-hint">
					{ __( 'Control security, notifications, and what happens after a submission.', 'neural-forms-lite' ) }
				</p>
			</header>

			<div className="nf-form-settings__layout">
				<nav className="nf-form-settings__nav" aria-label={ __( 'Settings sections', 'neural-forms-lite' ) }>
					{ SECTIONS.map( ( sec ) => (
						<button
							key={ sec.id }
							type="button"
							className={ 'nf-form-settings__nav-item' + ( sec.id === section ? ' is-active' : '' ) }
							onClick={ () => setSection( sec.id ) }
							aria-current={ sec.id === section ? 'page' : undefined }
						>
							<span className="nf-form-settings__nav-label">{ __( sec.label, 'neural-forms-lite' ) }</span>
							<span className="nf-form-settings__nav-hint">{ __( sec.hint, 'neural-forms-lite' ) }</span>
						</button>
					) ) }
				</nav>

				<section className="nf-form-settings__panel" aria-labelledby={ `nf-section-${ activeSection.id }` }>
					<header className="nf-form-settings__panel-header">
						<h3 id={ `nf-section-${ activeSection.id }` } className="nf-form-settings__title">
							{ __( activeSection.label, 'neural-forms-lite' ) }
						</h3>
						<p className="nf-form-settings__hint">{ __( activeSection.hint, 'neural-forms-lite' ) }</p>
					</header>

					{ section === 'security' && (
						<div className="nf-form-settings__body">
							<label className="nf-form-settings__field nf-form-settings__field--inline">
								<input
									type="checkbox"
									checked={ !! s.captcha_enabled }
									onChange={ ( e ) => set( { captcha_enabled: e.target.checked } ) }
								/>
								<span>{ __( 'Enable CAPTCHA on this form', 'neural-forms-lite' ) }</span>
							</label>
							<p className="nf-form-settings__note">
								{ __( 'Provider and keys are configured globally under plugin Settings.', 'neural-forms-lite' ) }
							</p>
						</div>
					) }

					{ section === 'notifications' && (
						<div className="nf-form-settings__body">
							<label className="nf-form-settings__field nf-form-settings__field--inline">
								<input
									type="checkbox"
									checked={ !! s.enable_admin_notification }
									onChange={ ( e ) => set( { enable_admin_notification: e.target.checked } ) }
								/>
								<span>{ __( 'Send admin notification on submission', 'neural-forms-lite' ) }</span>
							</label>
							<label className="nf-form-settings__field">
								<span className="nf-form-settings__label">{ __( 'Notification email', 'neural-forms-lite' ) }</span>
								<input
									type="email"
									value={ s.admin_notification_email || '' }
									onChange={ ( e ) => set( { admin_notification_email: e.target.value } ) }
									placeholder="you@example.com"
								/>
								<span className="nf-form-settings__field-hint">
									{ __( 'Falls back to the WordPress admin email when blank.', 'neural-forms-lite' ) }
								</span>
							</label>
						</div>
					) }

					{ section === 'on_success' && (
						<div className="nf-form-settings__body">
							<label className="nf-form-settings__field">
								<span className="nf-form-settings__label">{ __( 'Action', 'neural-forms-lite' ) }</span>
								<select
									value={ onSuccess.mode === 'redirect' ? 'redirect' : 'message' }
									onChange={ ( e ) => setOnSuccess( { mode: e.target.value } ) }
								>
									<option value="message">{ __( 'Show a message', 'neural-forms-lite' ) }</option>
									<option value="redirect">{ __( 'Redirect to a URL', 'neural-forms-lite' ) }</option>
								</select>
							</label>
							{ onSuccess.mode !== 'redirect' && (
								<label className="nf-form-settings__field">
									<span className="nf-form-settings__label">{ __( 'Message', 'neural-forms-lite' ) }</span>
									<textarea
										rows={ 4 }
										value={ onSuccess.message || '' }
										onChange={ ( e ) => setOnSuccess( { message: e.target.value } ) }
									/>
								</label>
							) }
							{ onSuccess.mode === 'redirect' && (
								<label className="nf-form-settings__field">
									<span className="nf-form-settings__label">{ __( 'Redirect URL', 'neural-forms-lite' ) }</span>
									<input
										type="url"
										value={ onSuccess.url || '' }
										onChange={ ( e ) => setOnSuccess( { url: e.target.value } ) }
										placeholder="https://"
									/>
								</label>
							) }
						</div>
					) }

					{ section === 'on_error' && (
						<div className="nf-form-settings__body">
							<label className="nf-form-settings__field">
								<span className="nf-form-settings__label">{ __( 'Action', 'neural-forms-lite' ) }</span>
								<select
									value={ onError.mode === 'redirect' ? 'redirect' : 'message' }
									onChange={ ( e ) => setOnError( { mode: e.target.value } ) }
								>
									<option value="message">{ __( 'Show a message', 'neural-forms-lite' ) }</option>
									<option value="redirect">{ __( 'Redirect to a URL', 'neural-forms-lite' ) }</option>
								</select>
							</label>
							{ onError.mode !== 'redirect' && (
								<label className="nf-form-settings__field">
									<span className="nf-form-settings__label">{ __( 'Message', 'neural-forms-lite' ) }</span>
									<textarea
										rows={ 4 }
										value={ onError.message || '' }
										onChange={ ( e ) => setOnError( { message: e.target.value } ) }
									/>
								</label>
							) }
							{ onError.mode === 'redirect' && (
								<label className="nf-form-settings__field">
									<span className="nf-form-settings__label">{ __( 'Redirect URL', 'neural-forms-lite' ) }</span>
									<input
										type="url"
										value={ onError.url || '' }
										onChange={ ( e ) => setOnError( { url: e.target.value } ) }
										placeholder="https://"
									/>
								</label>
							) }
						</div>
					) }
				</section>
			</div>
		</div>
	);
}
