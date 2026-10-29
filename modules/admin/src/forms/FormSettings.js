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
import { __, sprintf } from '@wordpress/i18n';
import IntegrationsSection from './IntegrationsSection';
import ConditionEditor, { describeConditional } from './ConditionEditor';
import { flattenFields, toEditableConditional } from './schemaHelper';

const BASE_SECTIONS = [
	{ id: 'process_with_ai', label: 'Process with AI', hint: 'Decide how Neural Forms uses AI for this form.' },
	{ id: 'security',     label: 'Security',     hint: 'Protect this form from automated submissions.' },
	{ id: 'notifications', label: 'Notifications', hint: 'Choose who hears about new submissions.' },
	{ id: 'on_success',   label: 'On success',   hint: 'What submitters see after a successful submit.' },
	{ id: 'on_error',     label: 'On error',     hint: 'What submitters see when a submit fails.' },
	{ id: 'integrations', label: 'Integrations', hint: 'Send this form\'s submissions to connected third-party services.' },
];

/**
 * 5 AI-processing modes stored on form_schema.settings.ai_processing.mode.
 * Mirror the PHP whitelist in SchemaHelper::AI_PROCESSING_MODES — both
 * sides must agree, the resolver clamps unknown values to "inherit".
 */
const AI_PROCESSING_MODES = [
	{
		value: 'inherit',
		label: 'Use global setting',
		description: 'Follows your global Neural Forms AI configuration.',
	},
	{
		value: 'disabled',
		label: 'Do not process this form',
		description: 'No automatic AI processing for entries or results.',
	},
	{
		value: 'every_entry',
		label: 'Process every entry',
		description: 'Analyze each submission automatically. Best for lead/contact forms.',
	},
	{
		value: 'manual',
		label: 'Manual only',
		description: 'No automatic processing. Run AI on demand from Entries or Analytics.',
	},
	{
		value: 'final_results',
		label: 'Process final results',
		description: 'Analyze aggregate results instead of individual submissions. Best for polls, quizzes, and surveys.',
	},
];

/** Per-form-type recommendation copy. Display-only — never mutates settings. */
function recommendationFor( type ) {
	switch ( type ) {
		case 'poll':
			return 'This form contains poll fields. Per-entry AI processing is usually unnecessary. Use Final results or Manual only to save credits.';
		case 'quiz':
			return 'This form contains quiz questions. Scoring is handled automatically without AI. Use Final results or Manual only if you want AI insights across attempts.';
		case 'mixed':
			return 'This form contains quiz/poll fields. Use Final results or Manual only unless each submission needs individual AI review.';
		case 'multistep':
			return 'This multi-step form can use entry processing if submissions need human follow-up.';
		case 'survey':
			return 'Surveys benefit from aggregate analysis. Final results is usually the best choice.';
		case 'simple':
		default:
			return 'This looks like a simple form. Entry analysis can help with summaries, categories, priority, and suggested replies.';
	}
}

/**
 * Read the per-install AI capability + cloud-connection state from the
 * server-localized window.neuralFormsAdmin payload. The values are a
 * snapshot — they don't update live, but Settings is a non-real-time
 * surface so a reload after enabling AI is acceptable UX.
 *
 * Shape comes from AdminPanel::script_data() — the values live under
 * window.neuralFormsAdmin.plugin (NOT under a top-level `cloud` key,
 * which is what an earlier pass of this code mistakenly read).
 */
function readAiAvailability() {
	if ( typeof window === 'undefined' ) return { capable: false, cloudConnected: false, aiEnabled: false };
	const root = window.neuralFormsAdmin || {};
	const plugin = root.plugin || {};
	const capabilities = plugin.capabilities || {};
	return {
		capable:        !! capabilities.ai_process_entries,
		// `cloudConnected` is true when both the cloud master toggle is on
		// AND an API key is saved. The QueueListener gate uses the same
		// pair (ai_enabled + cloud_ai_enabled + cloud_api_key) — keeping
		// the UI in agreement avoids "settings say connected but listener
		// still skips" mismatches.
		cloudConnected: !! plugin.cloudAiEnabled && !! plugin.cloudApiKeySet,
		aiEnabled:      !! plugin.cloudAiEnabled,
	};
}

const QUIZ_SECTION = { id: 'quiz', label: 'Quiz', hint: 'Pass/fail and result messages for score quizzes.' };

const STARTER_RESULT_MESSAGES = [
	{ min: 0,  max: 49, title: 'Keep practicing', message: 'You can try again and improve your score.' },
	{ min: 50, max: 79, title: 'Good attempt',    message: 'Nice work. Review the explanations and try again.' },
	{ min: 80, max: 100, title: 'Excellent',      message: 'Great job! You passed with a strong score.' },
];

/**
 * Conditional confirmations / redirects (1.2.0, Pro).
 *
 * settings.on_success.rules = [ { id, conditions, mode, message, url } ]
 * Ordered; the server picks the FIRST matching rule after validation and
 * falls back to the default outcome above when none matches. Flat rules
 * and condition groups both use the shared ConditionEditor.
 */
const MAX_CONFIRMATION_RULES = 10;

function readCaps() {
	if ( typeof window === 'undefined' ) return {};
	return ( window.neuralFormsAdmin?.plugin?.capabilities ) || {};
}

function newRuleId() {
	return 'rule_' + Math.random().toString( 36 ).slice( 2, 10 );
}

function ConfirmationRules( { rules, onChange, fields } ) {
	const caps     = readCaps();
	const unlocked = !! caps.conditional_logic_advanced;
	const sources  = flattenFields( Array.isArray( fields ) ? fields : [] ).filter( ( n ) => n && n.id && n.type !== 'hidden' );

	if ( ! unlocked ) {
		return (
			<div className="nf-form-settings__pro-note">
				{ __( 'Show a different message or redirect based on the answers — for example send “Website” enquiries to a booking page. Conditional confirmations are available in Neural Forms Pro.', 'neural-forms-lite' ) }
				{ rules.length > 0 && (
					<>
						{ ' ' }
						{ sprintf(
							/* translators: %d: number of saved rules */
							__( 'This form has %d saved rule(s) that keep working.', 'neural-forms-lite' ),
							rules.length
						) }
					</>
				) }
			</div>
		);
	}

	const update = ( i, patch ) => onChange( rules.map( ( r, k ) => ( k === i ? { ...r, ...patch } : r ) ) );
	const remove = ( i ) => onChange( rules.filter( ( _, k ) => k !== i ) );
	const move   = ( i, dir ) => {
		const j = i + dir;
		if ( j < 0 || j >= rules.length ) return;
		const next = rules.slice();
		const t = next[ i ]; next[ i ] = next[ j ]; next[ j ] = t;
		onChange( next );
	};
	const add = () => {
		if ( rules.length >= MAX_CONFIRMATION_RULES ) return;
		onChange( [ ...rules, {
			id:         newRuleId(),
			conditions: { enabled: true, version: 2, action: 'show', match: 'all', rules: [ { field: ( sources[ 0 ] && sources[ 0 ].id ) || '', operator: 'equals', value: '' } ], groups: [] },
			mode:       'message',
			message:    '',
			url:        '',
		} ] );
	};

	// Two rules with identical conditions can never both be useful: the
	// second is unreachable. Flag it so ordering surprises are visible.
	const signatures = rules.map( ( r ) => JSON.stringify( toEditableConditional( r.conditions ) ) );

	return (
		<div className="nf-form-settings__rules-wrap">
			<h4 className="nf-form-settings__subtitle">{ __( 'Conditional confirmations', 'neural-forms-lite' ) }</h4>
			<p className="nf-form-settings__rules-note">
				{ __( 'Rules are checked in order after a successful submission. The first matching rule decides what the visitor sees; when none matches, the default above is used.', 'neural-forms-lite' ) }
			</p>

			<div className="nf-form-settings__rules">
				{ rules.map( ( rule, i ) => {
					const duplicateOf = signatures.indexOf( signatures[ i ] );
					const summary = describeConditional( rule.conditions, sources );
					return (
						<div className="nf-form-settings__rule" key={ rule.id || i }>
							<div className="nf-form-settings__rule-head">
								<strong>{ sprintf( __( 'Rule %d', 'neural-forms-lite' ), i + 1 ) }</strong>
								<button type="button" onClick={ () => move( i, -1 ) } disabled={ i === 0 } aria-label={ __( 'Move up', 'neural-forms-lite' ) }>↑</button>
								<button type="button" onClick={ () => move( i, 1 ) } disabled={ i === rules.length - 1 } aria-label={ __( 'Move down', 'neural-forms-lite' ) }>↓</button>
								<button type="button" onClick={ () => remove( i ) } aria-label={ __( 'Remove rule', 'neural-forms-lite' ) }>{ __( 'Remove', 'neural-forms-lite' ) }</button>
							</div>

							<ConditionEditor
								value={ { ...toEditableConditional( rule.conditions ), enabled: true } }
								onChange={ ( next ) => update( i, { conditions: { ...next, enabled: true } } ) }
								sources={ sources }
								allowGroups
								actionLabels={ null }
								showEnable={ false }
								emptySourcesHint={ __( 'Add a field to this form first.', 'neural-forms-lite' ) }
							/>

							{ duplicateOf !== i && (
								<p className="nf-inspector__cl-hint nf-inspector__cl-hint--pro">
									{ sprintf(
										/* translators: %d: rule number */
										__( 'Same conditions as rule %d, which runs first — this rule can never match.', 'neural-forms-lite' ),
										duplicateOf + 1
									) }
								</p>
							) }

							<label className="nf-inspector__field">
								<span className="nf-inspector__label">{ __( 'Then', 'neural-forms-lite' ) }</span>
								<select
									value={ rule.mode === 'redirect' ? 'redirect' : 'message' }
									onChange={ ( e ) => update( i, { mode: e.target.value } ) }
								>
									<option value="message">{ __( 'Show a message', 'neural-forms-lite' ) }</option>
									<option value="redirect">{ __( 'Redirect to a URL', 'neural-forms-lite' ) }</option>
								</select>
							</label>

							{ rule.mode !== 'redirect' && (
								<label className="nf-inspector__field">
									<span className="nf-inspector__label">{ __( 'Message', 'neural-forms-lite' ) }</span>
									<textarea
										rows={ 3 }
										value={ rule.message || '' }
										onChange={ ( e ) => update( i, { message: e.target.value } ) }
									/>
								</label>
							) }
							{ rule.mode === 'redirect' && (
								<label className="nf-inspector__field">
									<span className="nf-inspector__label">{ __( 'Redirect URL', 'neural-forms-lite' ) }</span>
									<input
										type="url"
										value={ rule.url || '' }
										onChange={ ( e ) => update( i, { url: e.target.value } ) }
										placeholder="https://"
									/>
								</label>
							) }

							{ summary && (
								<p className="nf-inspector__cl-summary">
									{ sprintf( __( 'When %s.', 'neural-forms-lite' ), summary ) }
								</p>
							) }
						</div>
					);
				} ) }
			</div>

			<button
				type="button"
				className="nf-form-settings__rules-add"
				onClick={ add }
				disabled={ rules.length >= MAX_CONFIRMATION_RULES || sources.length === 0 }
			>
				{ __( '+ Add rule', 'neural-forms-lite' ) }
			</button>
		</div>
	);
}

export default function FormSettings( {
	settings,
	onChange,
	formSchemaSettings,
	onChangeFormSchemaSettings,
	hasQuiz,
	detectedType,
	fields,
} ) {
	const SECTIONS = hasQuiz ? [ ...BASE_SECTIONS, QUIZ_SECTION ] : BASE_SECTIONS;

	const [ section, setSection ] = useState( SECTIONS[ 0 ].id );

	const s = settings || {};
	const onSuccess = { mode: 'message', message: '', url: '', ...( s.on_success || {} ) };
	const onError   = { mode: 'message', message: '', url: '', ...( s.on_error   || {} ) };

	const set = ( patch ) => onChange( { ...s, ...patch } );
	const setOnSuccess = ( patch ) => set( { on_success: { ...onSuccess, ...patch } } );
	const setOnError   = ( patch ) => set( { on_error:   { ...onError,   ...patch } } );

	// Quiz settings live on form_schema.settings.quiz, NOT form.settings.
	// They apply to the whole quiz so they belong with the schema, not
	// with submission/security/email config.
	const schemaSettings = ( formSchemaSettings && typeof formSchemaSettings === 'object' ) ? formSchemaSettings : {};
	const quizSettings = {
		show_pass_fail:  false,
		passing_score:   70,
		result_messages: [],
		...( ( schemaSettings.quiz && typeof schemaSettings.quiz === 'object' ) ? schemaSettings.quiz : {} ),
	};
	const setQuiz = ( patch ) => {
		if ( typeof onChangeFormSchemaSettings !== 'function' ) return;
		const next = { ...schemaSettings, quiz: { ...quizSettings, ...patch } };
		onChangeFormSchemaSettings( next );
	};

	// Per-form AI processing mode lives on form_schema.settings.ai_processing.
	// Same routing as the quiz block above — schema-level settings, not
	// form.settings (which is reserved for submission/security/email).
	const aiProcessing = {
		mode: 'inherit',
		...( ( schemaSettings.ai_processing && typeof schemaSettings.ai_processing === 'object' ) ? schemaSettings.ai_processing : {} ),
	};
	const setAiProcessingMode = ( mode ) => {
		if ( typeof onChangeFormSchemaSettings !== 'function' ) return;
		const validModes = AI_PROCESSING_MODES.map( ( m ) => m.value );
		const safe = validModes.includes( mode ) ? mode : 'inherit';
		const next = { ...schemaSettings, ai_processing: { mode: safe } };
		onChangeFormSchemaSettings( next );
	};
	const aiAvailability = readAiAvailability();
	const aiUiEnabled = aiAvailability.capable && aiAvailability.cloudConnected && aiAvailability.aiEnabled;

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

					{ section === 'process_with_ai' && (
						<div className="nf-form-settings__body nf-ai-processing">
							<p className="nf-form-settings__note nf-ai-processing__note">
								{ __( 'Neural AI credits are used only when Neural AI processing runs successfully.', 'neural-forms-lite' ) }
							</p>

							{ /* Capability + cloud-connection gate. Lite-only
							    installs see the upsell; Pro installs without
							    Cloud connected see the connect-CTA. */ }
							{ ! aiAvailability.capable && (
								<div className="nf-ai-processing__upsell" role="note">
									<strong>{ __( 'Automatic processing is a Neural Forms Pro feature', 'neural-forms-lite' ) }</strong>
									<p>{ __( 'In the free plugin you can analyze any entry once: open it in the inbox and click "Analyze with Neural AI" (needs a connected Neural Forms Cloud account). Neural Forms Pro adds automatic analysis of every new submission using the modes below, plus re-analysis and regenerated or polished replies. You can still choose a mode now — it activates once Pro is installed.', 'neural-forms-lite' ) }</p>
								</div>
							) }
							{ aiAvailability.capable && ( ! aiAvailability.cloudConnected || ! aiAvailability.aiEnabled ) && (
								<div className="nf-ai-processing__upsell" role="note">
									<strong>{ __( 'Connect Neural Forms Cloud', 'neural-forms-lite' ) }</strong>
									<p>{ __( 'Connect your Cloud account in Settings to enable Neural AI processing. The mode below will be honoured once the cloud is connected.', 'neural-forms-lite' ) }</p>
								</div>
							) }

							<fieldset
								className={ 'nf-ai-processing__modes' + ( aiUiEnabled ? '' : ' is-disabled' ) }
								disabled={ ! aiUiEnabled }
								aria-disabled={ ! aiUiEnabled }
							>
								<legend className="screen-reader-text">{ __( 'Neural AI processing mode', 'neural-forms-lite' ) }</legend>
								{ AI_PROCESSING_MODES.map( ( m ) => {
									const id = `nf-ai-processing-${ m.value }`;
									const checked = aiProcessing.mode === m.value;
									return (
										<label
											key={ m.value }
											htmlFor={ id }
											className={ 'nf-ai-processing__option' + ( checked ? ' is-selected' : '' ) }
										>
											<input
												id={ id }
												type="radio"
												name="nf-ai-processing-mode"
												value={ m.value }
												checked={ checked }
												onChange={ () => setAiProcessingMode( m.value ) }
											/>
											<span className="nf-ai-processing__option-body">
												<span className="nf-ai-processing__option-label">{ __( m.label, 'neural-forms-lite' ) }</span>
												<span className="nf-ai-processing__option-desc">{ __( m.description, 'neural-forms-lite' ) }</span>
											</span>
										</label>
									);
								} ) }
							</fieldset>

							{ /* Recommendation panel — display-only, never
							    mutates the saved mode. Driven by the form's
							    detected type which Builder.js passes as a
							    prop derived from the same FormTypeDetector
							    used by the Forms list badge. */ }
							<div className="nf-ai-processing__recommendation" role="note">
								<strong>{ __( 'Recommendation', 'neural-forms-lite' ) }</strong>
								<p>{ __( recommendationFor( detectedType ), 'neural-forms-lite' ) }</p>
							</div>
						</div>
					) }

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
								{ /* Unset = inherit the global Notifications toggle (on by
								     default), so show it checked unless explicitly off.
								     NotificationService::admin_notification_enabled()
								     applies the same rule server-side. */ }
								<input
									type="checkbox"
									checked={ s.enable_admin_notification !== false }
									onChange={ ( e ) => set( { enable_admin_notification: e.target.checked } ) }
								/>
								<span>{ __( 'Send admin notification on submission', 'neural-forms-lite' ) }</span>
							</label>
							<p className="nf-form-settings__note">
								{ __( 'Untick to silence this form even when global notifications are on. Global notifications are configured under plugin Settings.', 'neural-forms-lite' ) }
							</p>
							<label className="nf-form-settings__field">
								<span className="nf-form-settings__label">{ __( 'Notification email', 'neural-forms-lite' ) }</span>
								{ /* type="text" rather than type="email" so the browser
								     doesn't reject commas. Multiple addresses are
								     resolved server-side by
								     NotificationService::parse_recipient_list. */ }
								<input
									type="text"
									value={ s.admin_notification_email || '' }
									onChange={ ( e ) => set( { admin_notification_email: e.target.value } ) }
									placeholder="you@example.com, manager@example.com"
								/>
								<span className="nf-form-settings__field-hint">
									{ __( 'Separate multiple email addresses with commas. Falls back to the WordPress admin email when blank.', 'neural-forms-lite' ) }
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

							<ConfirmationRules
								rules={ Array.isArray( onSuccess.rules ) ? onSuccess.rules : [] }
								onChange={ ( rules ) => {
									const next = { ...onSuccess };
									if ( rules.length ) next.rules = rules; else delete next.rules;
									set( { on_success: next } );
								} }
								fields={ fields }
							/>
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

					{ section === 'integrations' && (
						<div className="nf-form-settings__body">
							<IntegrationsSection
								settings={ s }
								onChange={ onChange }
								fields={ fields || [] }
							/>
						</div>
					) }

					{ section === 'quiz' && hasQuiz && (
						<div className="nf-form-settings__body">
							<label className="nf-form-settings__field nf-form-settings__field--inline">
								<input
									type="checkbox"
									checked={ !! quizSettings.show_pass_fail }
									onChange={ ( e ) => setQuiz( { show_pass_fail: e.target.checked } ) }
								/>
								<span>{ __( 'Show pass/fail result', 'neural-forms-lite' ) }</span>
							</label>

							<label className="nf-form-settings__field">
								<span className="nf-form-settings__label">{ __( 'Passing score (%)', 'neural-forms-lite' ) }</span>
								<input
									type="number"
									min="0"
									max="100"
									step="1"
									value={ Number( quizSettings.passing_score ) || 0 }
									onChange={ ( e ) => {
										const v = Number( e.target.value );
										const clamped = Number.isFinite( v ) ? Math.max( 0, Math.min( 100, Math.round( v ) ) ) : 70;
										setQuiz( { passing_score: clamped } );
									} }
									disabled={ ! quizSettings.show_pass_fail }
								/>
								<span className="nf-form-settings__field-hint">
									{ __( 'Submissions at or above this percentage are marked passed.', 'neural-forms-lite' ) }
								</span>
							</label>

							<div className="nf-form-settings__field">
								<span className="nf-form-settings__label">{ __( 'Result messages', 'neural-forms-lite' ) }</span>
								<p className="nf-form-settings__field-hint">
									{ __( 'Show a custom title + message based on the submitter’s percentage. The first matching range wins.', 'neural-forms-lite' ) }
								</p>

								{ ( ! quizSettings.result_messages || quizSettings.result_messages.length === 0 ) && (
									<button
										type="button"
										className="neural-forms-btn"
										onClick={ () => setQuiz( { result_messages: STARTER_RESULT_MESSAGES.slice() } ) }
									>
										{ __( '+ Add starter messages', 'neural-forms-lite' ) }
									</button>
								) }

								{ ( quizSettings.result_messages || [] ).map( ( m, i ) => (
									<div className="nf-form-settings__result-row" key={ i }>
										<div className="nf-form-settings__result-range">
											<label>
												<span>{ __( 'Min %', 'neural-forms-lite' ) }</span>
												<input
													type="number"
													min="0"
													max="100"
													value={ Number( m.min ) || 0 }
													onChange={ ( e ) => {
														const v = Number( e.target.value );
														const clamped = Number.isFinite( v ) ? Math.max( 0, Math.min( 100, Math.round( v ) ) ) : 0;
														const next = quizSettings.result_messages.slice();
														next[ i ] = { ...next[ i ], min: clamped };
														setQuiz( { result_messages: next } );
													} }
												/>
											</label>
											<label>
												<span>{ __( 'Max %', 'neural-forms-lite' ) }</span>
												<input
													type="number"
													min="0"
													max="100"
													value={ Number( m.max ) || 0 }
													onChange={ ( e ) => {
														const v = Number( e.target.value );
														const clamped = Number.isFinite( v ) ? Math.max( 0, Math.min( 100, Math.round( v ) ) ) : 100;
														const next = quizSettings.result_messages.slice();
														next[ i ] = { ...next[ i ], max: clamped };
														setQuiz( { result_messages: next } );
													} }
												/>
											</label>
										</div>
										<input
											type="text"
											className="nf-form-settings__result-title"
											value={ m.title || '' }
											placeholder={ __( 'Title', 'neural-forms-lite' ) }
											onChange={ ( e ) => {
												const next = quizSettings.result_messages.slice();
												next[ i ] = { ...next[ i ], title: e.target.value };
												setQuiz( { result_messages: next } );
											} }
										/>
										<textarea
											className="nf-form-settings__result-message"
											rows={ 2 }
											value={ m.message || '' }
											placeholder={ __( 'Message shown to the submitter', 'neural-forms-lite' ) }
											onChange={ ( e ) => {
												const next = quizSettings.result_messages.slice();
												next[ i ] = { ...next[ i ], message: e.target.value };
												setQuiz( { result_messages: next } );
											} }
										/>
										<button
											type="button"
											className="nf-form-settings__result-remove"
											onClick={ () => {
												const next = quizSettings.result_messages.slice();
												next.splice( i, 1 );
												setQuiz( { result_messages: next } );
											} }
											aria-label={ __( 'Remove message', 'neural-forms-lite' ) }
											title={ __( 'Remove message', 'neural-forms-lite' ) }
										>×</button>
									</div>
								) ) }

								<button
									type="button"
									className="neural-forms-btn"
									onClick={ () => {
										const next = ( quizSettings.result_messages || [] ).slice();
										next.push( { min: 0, max: 100, title: '', message: '' } );
										setQuiz( { result_messages: next } );
									} }
								>
									{ __( '+ Add message', 'neural-forms-lite' ) }
								</button>
							</div>
						</div>
					) }
				</section>
			</div>
		</div>
	);
}
