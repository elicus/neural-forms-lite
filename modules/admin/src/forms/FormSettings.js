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

import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import IntegrationsSection from './IntegrationsSection';
import api from '../api';
import ConditionEditor, { describeConditional } from './ConditionEditor';
import WorkflowsSection from './WorkflowsSection';
import { flattenFields, toEditableConditional } from './schemaHelper';

const BASE_SECTIONS = [
	{ id: 'process_with_ai', label: 'Process with AI', hint: 'Decide how Neural Forms uses AI for this form.' },
	{ id: 'security',     label: 'Security',     hint: 'Protect this form from automated submissions.' },
	{ id: 'notifications', label: 'Notifications', hint: 'Choose who hears about new submissions.' },
	{ id: 'on_success',   label: 'On success',   hint: 'What submitters see after a successful submit.' },
	{ id: 'on_error',     label: 'On error',     hint: 'What submitters see when a submit fails.' },
	{ id: 'integrations', label: 'Integrations', hint: 'Send this form\'s submissions to connected third-party services.' },
	{ id: 'workflows',    label: 'Workflows',    hint: 'When → If → Then: act on submissions, AI results, stage changes and overdue follow-ups.' },
	{ id: 'ai_behaviour', label: 'AI behaviour', hint: 'Tone, language and which business knowledge AI drafts for this form may use.' },
	{ id: 'pricing',      label: 'Pricing',      hint: 'Currency, discount rules and deposit for Priced Item fields on this form.' },
	{ id: 'payments',     label: 'Payments',     hint: 'Send visitors to a hosted checkout for the amount due right after they submit.' },
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
 * Pricing (1.2.0, part B, Pro): currency, discount rules (first match
 * wins, each with optional conditions via the shared editor) and deposit.
 * Mirrors PriceEngine::normalize_settings(). Product field prices are
 * typed in this currency.
 */
const CURRENCIES = [ 'USD', 'EUR', 'GBP', 'INR', 'AUD', 'CAD', 'NZD', 'SGD', 'AED', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'JPY', 'ZAR', 'BRL', 'MXN' ];

function PricingSection( { value, onChange, fields } ) {
	const caps     = readCaps();
	const unlocked = !! caps.calculations;
	const v = { currency: 'USD', discounts: [], deposit: { type: 'none', value: '' }, ...( value || {} ) };
	v.deposit = { type: 'none', value: '', ...( v.deposit || {} ) };
	const set = ( patch ) => onChange( { ...v, ...patch } );
	const sources = flattenFields( Array.isArray( fields ) ? fields : [] ).filter( ( n ) => n && n.id && n.type !== 'hidden' );
	const hasProducts = sources.some( ( n ) => n.type === 'product' );
	const custom = ! CURRENCIES.includes( v.currency );

	if ( ! unlocked ) {
		return (
			<div className="nf-form-settings__pro-note">
				{ __( 'Price your services with Priced Item fields, show a live total with discounts and a deposit, and calculate values from answers. Prices are set in the form itself; no WooCommerce or shop plugin is needed. Available in Neural Forms Pro.', 'neural-forms-lite' ) }
			</div>
		);
	}

	const updateDiscount = ( i, patch ) => set( { discounts: v.discounts.map( ( d, k ) => ( k === i ? { ...d, ...patch } : d ) ) } );
	const removeDiscount = ( i ) => set( { discounts: v.discounts.filter( ( _, k ) => k !== i ) } );
	const moveDiscount = ( i, dir ) => {
		const j = i + dir;
		if ( j < 0 || j >= v.discounts.length ) return;
		const next = v.discounts.slice(); const t = next[ i ]; next[ i ] = next[ j ]; next[ j ] = t;
		set( { discounts: next } );
	};
	const addDiscount = () => {
		if ( v.discounts.length >= 20 ) return;
		set( { discounts: [ ...v.discounts, { id: 'discount_' + Math.random().toString( 36 ).slice( 2, 8 ), label: '', type: 'percent', value: '10', max: '', conditions: null } ] } );
	};

	return (
		<div className="nf-pricing">
			{ ! hasProducts && (
				<p className="nf-form-settings__hint">{ __( 'Add a Priced Item field to the form to start pricing. These settings apply once it has one.', 'neural-forms-lite' ) }</p>
			) }
			<label className="nf-form-settings__field">
				<span className="nf-form-settings__label">{ __( 'Currency', 'neural-forms-lite' ) }</span>
				<select value={ custom ? 'other' : v.currency } onChange={ ( e ) => set( { currency: e.target.value === 'other' ? '' : e.target.value } ) }>
					{ CURRENCIES.map( ( c ) => <option key={ c } value={ c }>{ c }</option> ) }
					<option value="other">{ __( 'Other ISO code…', 'neural-forms-lite' ) }</option>
				</select>
			</label>
			{ custom && (
				<label className="nf-form-settings__field">
					<span className="nf-form-settings__label">{ __( 'ISO 4217 code', 'neural-forms-lite' ) }</span>
					<input type="text" maxLength={ 3 } value={ v.currency } placeholder="e.g. CZK" onChange={ ( e ) => set( { currency: e.target.value.toUpperCase() } ) } />
				</label>
			) }

			<h4 className="nf-form-settings__subtitle">{ __( 'Discount rules', 'neural-forms-lite' ) }</h4>
			<p className="nf-form-settings__rules-note">{ __( 'Checked in order; the first rule whose conditions match is applied. A rule without conditions always matches. Discounts never take the total below zero.', 'neural-forms-lite' ) }</p>
			<div className="nf-form-settings__rules">
				{ v.discounts.map( ( d, i ) => (
					<div className="nf-form-settings__rule" key={ d.id || i }>
						<div className="nf-form-settings__rule-head">
							<strong>{ sprintf( __( 'Discount %d', 'neural-forms-lite' ), i + 1 ) }</strong>
							<button type="button" onClick={ () => moveDiscount( i, -1 ) } disabled={ i === 0 } aria-label={ __( 'Move up', 'neural-forms-lite' ) }>↑</button>
							<button type="button" onClick={ () => moveDiscount( i, 1 ) } disabled={ i === v.discounts.length - 1 } aria-label={ __( 'Move down', 'neural-forms-lite' ) }>↓</button>
							<button type="button" onClick={ () => removeDiscount( i ) } aria-label={ __( 'Remove discount', 'neural-forms-lite' ) }>{ __( 'Remove', 'neural-forms-lite' ) }</button>
						</div>
						<label className="nf-inspector__field">
							<span className="nf-inspector__label">{ __( 'Label (shown to the visitor)', 'neural-forms-lite' ) }</span>
							<input type="text" value={ d.label || '' } placeholder={ __( 'e.g. Launch offer', 'neural-forms-lite' ) } onChange={ ( e ) => updateDiscount( i, { label: e.target.value } ) } />
						</label>
						<div className="nf-inspector__row">
							<label className="nf-inspector__field">
								<span className="nf-inspector__label">{ __( 'Type', 'neural-forms-lite' ) }</span>
								<select value={ d.type === 'fixed' ? 'fixed' : 'percent' } onChange={ ( e ) => updateDiscount( i, { type: e.target.value } ) }>
									<option value="percent">{ __( 'Percent off', 'neural-forms-lite' ) }</option>
									<option value="fixed">{ __( 'Fixed amount off', 'neural-forms-lite' ) }</option>
								</select>
							</label>
							<label className="nf-inspector__field">
								<span className="nf-inspector__label">{ d.type === 'fixed' ? __( 'Amount', 'neural-forms-lite' ) : __( 'Percent', 'neural-forms-lite' ) }</span>
								<input type="text" inputMode="decimal" value={ d.value ?? '' } onChange={ ( e ) => updateDiscount( i, { value: e.target.value } ) } />
							</label>
							<label className="nf-inspector__field">
								<span className="nf-inspector__label">{ __( 'Max amount (optional)', 'neural-forms-lite' ) }</span>
								<input type="text" inputMode="decimal" value={ d.max ?? '' } onChange={ ( e ) => updateDiscount( i, { max: e.target.value } ) } />
							</label>
						</div>
						<span className="nf-inspector__label">{ __( 'Apply when', 'neural-forms-lite' ) }</span>
						<ConditionEditor
							value={ { ...toEditableConditional( d.conditions ), enabled: true } }
							onChange={ ( next ) => updateDiscount( i, { conditions: ( next.rules.length || next.groups.length ) ? { ...next, enabled: true } : null } ) }
							sources={ sources }
							allowGroups
							actionLabels={ null }
							showEnable={ false }
							emptySourcesHint={ __( 'Add a field to the form first.', 'neural-forms-lite' ) }
						/>
						{ d.conditions && ( toEditableConditional( d.conditions ).rules.length + toEditableConditional( d.conditions ).groups.length ) > 0
							? <p className="nf-inspector__cl-summary">{ sprintf( __( 'Applies when %s.', 'neural-forms-lite' ), describeConditional( d.conditions, sources ) ) }</p>
							: <p className="nf-inspector__cl-hint">{ __( 'No conditions: always applies (if no earlier rule matched).', 'neural-forms-lite' ) }</p> }
					</div>
				) ) }
			</div>
			<button type="button" className="nf-form-settings__rules-add" onClick={ addDiscount } disabled={ v.discounts.length >= 20 }>
				{ __( '+ Add discount rule', 'neural-forms-lite' ) }
			</button>

			<h4 className="nf-form-settings__subtitle">{ __( 'Deposit', 'neural-forms-lite' ) }</h4>
			<div className="nf-inspector__row">
				<label className="nf-form-settings__field">
					<span className="nf-form-settings__label">{ __( 'Collect', 'neural-forms-lite' ) }</span>
					<select value={ v.deposit.type } onChange={ ( e ) => set( { deposit: { ...v.deposit, type: e.target.value } } ) }>
						<option value="none">{ __( 'The full amount', 'neural-forms-lite' ) }</option>
						<option value="percent">{ __( 'A percentage as deposit', 'neural-forms-lite' ) }</option>
						<option value="fixed">{ __( 'A fixed deposit', 'neural-forms-lite' ) }</option>
					</select>
				</label>
				{ v.deposit.type !== 'none' && (
					<label className="nf-form-settings__field">
						<span className="nf-form-settings__label">{ v.deposit.type === 'fixed' ? __( 'Deposit amount', 'neural-forms-lite' ) : __( 'Deposit percent', 'neural-forms-lite' ) }</span>
						<input type="text" inputMode="decimal" value={ v.deposit.value ?? '' } placeholder={ v.deposit.type === 'fixed' ? '250' : '30' } onChange={ ( e ) => set( { deposit: { ...v.deposit, value: e.target.value } } ) } />
					</label>
				) }
			</div>
			<p className="nf-form-settings__hint">{ __( 'The visitor sees the total, the amount due now and the remaining balance. Payment collection is configured under Payments once connected.', 'neural-forms-lite' ) }</p>
		</div>
	);
}

/**
 * Per-form AI behaviour (1.2.0, Pro): tone, language policy and a
 * knowledge picker. Saved as settings.ai_behaviour and sent with every
 * AI call for this form (the cloud applies the site-level business
 * context otherwise). Knowledge items come from Pro's business-context
 * endpoint; without Pro the section is an upsell.
 */
/**
 * Payments (1.2.0 part B): per-form switch + provider choice. Credentials
 * live on Neural Forms › Integrations (Stripe / PayPal tabs); this only
 * decides whether THIS form sends the visitor to checkout for the amount
 * due (the deposit when one is set, otherwise the full total).
 */
function PaymentsSection( { value, onChange, fields } ) {
	const caps     = readCaps();
	const unlocked = !! caps.payments;
	const v = { enabled: false, provider: 'auto', submit_label: '', description: '', success_url: '', cancel_url: '', send_invoice: true, notify_admin: true, notify_email: '', ...( value || {} ) };
	const set = ( patch ) => onChange( { ...v, ...patch } );
	const [ providers, setProviders ] = useState( null );
	useEffect( () => {
		if ( ! unlocked ) return;
		let cancelled = false;
		api( '/payments/providers' ).then( ( res ) => { if ( ! cancelled ) setProviders( Array.isArray( res?.providers ) ? res.providers : [] ); } ).catch( () => { if ( ! cancelled ) setProviders( [] ); } );
		return () => { cancelled = true; };
	}, [ unlocked ] );
	const hasProducts = flattenFields( Array.isArray( fields ) ? fields : [] ).some( ( n ) => n && n.type === 'product' );
	const configured  = ( providers || [] ).filter( ( p ) => p.configured );
	const integrationsUrl = ( window.neuralFormsAdmin?.admin?.adminUrl || '' ) ? window.neuralFormsAdmin.admin.adminUrl + 'admin.php?page=neural-forms-integrations' : 'admin.php?page=neural-forms-integrations';

	if ( ! unlocked ) {
		return (
			<div className="nf-form-settings__pro-note">
				{ __( 'Take a deposit or the full amount through Stripe or PayPal right after a priced form is submitted. Payments are confirmed by the gateway, never by the browser. Available in Neural Forms Pro.', 'neural-forms-lite' ) }
			</div>
		);
	}
	return (
		<div className="nf-payments-section">
			{ ! hasProducts && <p className="nf-form-settings__hint">{ __( 'Add a Priced Item field first — payments need an amount to charge.', 'neural-forms-lite' ) }</p> }
			{ providers && configured.length === 0 && (
				<p className="nf-form-settings__hint">
					{ __( 'No payment provider is connected yet.', 'neural-forms-lite' ) } <a href={ integrationsUrl } target="_blank" rel="noopener noreferrer">{ __( 'Connect Stripe or PayPal under Integrations.', 'neural-forms-lite' ) }</a>
				</p>
			) }
			<label className="nf-form-settings__field nf-payments-section__toggle">
				<input type="checkbox" checked={ !! v.enabled } disabled={ ! hasProducts } onChange={ ( e ) => set( { enabled: e.target.checked } ) } />
				<span>
					<strong>{ __( 'Take payment after submit', 'neural-forms-lite' ) }</strong>
					<small>{ __( 'The entry is saved first, then the visitor is sent to the hosted checkout for the amount due (the deposit when one is set, otherwise the total). Zero totals skip the checkout.', 'neural-forms-lite' ) }</small>
				</span>
			</label>
			{ v.enabled && (
				<>
					<label className="nf-form-settings__field">
						<span className="nf-form-settings__label">{ __( 'Payment method', 'neural-forms-lite' ) }</span>
						<select value={ v.provider } onChange={ ( e ) => set( { provider: e.target.value } ) }>
							<option value="auto">{ __( 'First connected provider', 'neural-forms-lite' ) }</option>
							{ ( providers || [] ).map( ( p ) => <option key={ p.id } value={ p.id }>{ p.label }{ p.configured ? '' : ' — ' + __( 'not connected', 'neural-forms-lite' ) }{ p.configured && ! p.live ? ' (' + __( 'test mode', 'neural-forms-lite' ) + ')' : '' }</option> ) }
							<option value="choice">{ __( 'Let the visitor choose', 'neural-forms-lite' ) }</option>
						</select>
					</label>
					<label className="nf-form-settings__field">
						<span className="nf-form-settings__label">{ __( 'Submit button text', 'neural-forms-lite' ) }</span>
						<input type="text" value={ v.submit_label } placeholder={ __( 'Continue to payment', 'neural-forms-lite' ) } maxLength={ 80 } onChange={ ( e ) => set( { submit_label: e.target.value } ) } />
					</label>
					<label className="nf-form-settings__field">
						<span className="nf-form-settings__label">{ __( 'Note above the button', 'neural-forms-lite' ) }</span>
						<input type="text" value={ v.description } placeholder={ __( 'You will be taken to a secure checkout page.', 'neural-forms-lite' ) } maxLength={ 200 } onChange={ ( e ) => set( { description: e.target.value } ) } />
					</label>
					<label className="nf-form-settings__field">
						<span className="nf-form-settings__label">{ __( 'After payment, send to (optional)', 'neural-forms-lite' ) }</span>
						<input type="url" value={ v.success_url } placeholder="https://" onChange={ ( e ) => set( { success_url: e.target.value } ) } />
						<span className="nf-form-settings__hint">{ __( 'Leave empty to show the built-in confirmation page. The page only ever shows the status the gateway confirmed.', 'neural-forms-lite' ) }</span>
					</label>
					<label className="nf-form-settings__field">
						<span className="nf-form-settings__label">{ __( 'If the visitor cancels, send to (optional)', 'neural-forms-lite' ) }</span>
						<input type="url" value={ v.cancel_url } placeholder="https://" onChange={ ( e ) => set( { cancel_url: e.target.value } ) } />
					</label>
					<label className="nf-form-settings__field nf-payments-section__toggle">
						<input type="checkbox" checked={ v.send_invoice !== false } onChange={ ( e ) => set( { send_invoice: e.target.checked } ) } />
						<span>
							<strong>{ __( 'Email an invoice automatically when a payment is confirmed', 'neural-forms-lite' ) }</strong>
							<small>{ __( 'Uses the branding from Neural Forms › Quotes. You can also send or resend invoices by hand from the entry or the Payments page.', 'neural-forms-lite' ) }</small>
						</span>
					</label>
					<label className="nf-form-settings__field nf-payments-section__toggle">
						<input type="checkbox" checked={ v.notify_admin !== false } onChange={ ( e ) => set( { notify_admin: e.target.checked } ) } />
						<span>
							<strong>{ __( 'Email the site owner when a payment is confirmed', 'neural-forms-lite' ) }</strong>
							<small>{ __( 'Amount, customer, remaining balance and a link to the entry. Goes to the addresses below, otherwise the admin notification address from Settings.', 'neural-forms-lite' ) }</small>
						</span>
					</label>
					{ v.notify_admin !== false && (
						<label className="nf-form-settings__field">
							<span className="nf-form-settings__label">{ __( 'Payment notification recipients (optional)', 'neural-forms-lite' ) }</span>
							<input type="text" value={ v.notify_email } placeholder={ __( 'sales@example.com, accounts@example.com', 'neural-forms-lite' ) } onChange={ ( e ) => set( { notify_email: e.target.value } ) } />
						</label>
					) }
					<p className="nf-form-settings__hint">{ __( 'Payments are confirmed by signed webhooks from the gateway, so make sure the webhook URL shown on the Integrations page is set up there. A "Submission received" workflow never carries a paid status; use the "Payment confirmed" trigger for that.', 'neural-forms-lite' ) }</p>
				</>
			) }
		</div>
	);
}

function AiBehaviourSection( { value, onChange } ) {
	const caps     = readCaps();
	const unlocked = !! caps.ai_business_context;
	const [ knowledge, setKnowledge ] = useState( null );
	const [ loadError, setLoadError ] = useState( '' );
	const v = value || {};
	const set = ( patch ) => onChange( { ...v, ...patch } );

	useEffect( () => {
		if ( ! unlocked ) return;
		api( '/neural-forms-pro/v1/business-context' )
			.then( ( res ) => setKnowledge( Array.isArray( res?.knowledge ) ? res.knowledge : [] ) )
			.catch( ( e ) => { setKnowledge( [] ); setLoadError( e?.message || '' ); } );
	}, [ unlocked ] );

	if ( ! unlocked ) {
		return (
			<div className="nf-form-settings__pro-note">
				{ __( 'Give AI drafts for this form their own tone, reply language and a curated set of business facts (opening hours, services, prices, policies). Drafts then quote your approved knowledge and flag what they cannot answer instead of inventing it. Available in Neural Forms Pro.', 'neural-forms-lite' ) }
			</div>
		);
	}

	const selected = Array.isArray( v.knowledge_ids ) ? v.knowledge_ids : [];
	const toggleId = ( id ) => set( { knowledge_ids: selected.includes( id ) ? selected.filter( ( x ) => x !== id ) : [ ...selected, id ] } );

	return (
		<div className="nf-ai-behaviour">
			<label className="nf-form-settings__field">
				<span className="nf-form-settings__label">{ __( 'Tone for this form', 'neural-forms-lite' ) }</span>
				<input type="text" maxLength={ 64 } value={ v.tone || '' } placeholder={ __( 'Leave empty to use the site-wide voice', 'neural-forms-lite' ) } onChange={ ( e ) => set( { tone: e.target.value } ) } />
			</label>
			<label className="nf-form-settings__field">
				<span className="nf-form-settings__label">{ __( 'Reply language', 'neural-forms-lite' ) }</span>
				<select value={ v.language_mode || '' } onChange={ ( e ) => set( { language_mode: e.target.value || undefined, language: e.target.value ? ( v.language || '' ) : undefined } ) }>
					<option value="">{ __( 'Use the site-wide policy', 'neural-forms-lite' ) }</option>
					<option value="fixed">{ __( 'Always reply in a fixed language', 'neural-forms-lite' ) }</option>
					<option value="fallback">{ __( "Visitor's language, fall back to a fixed one", 'neural-forms-lite' ) }</option>
				</select>
			</label>
			{ v.language_mode && (
				<label className="nf-form-settings__field">
					<span className="nf-form-settings__label">{ __( 'Language code', 'neural-forms-lite' ) }</span>
					<input type="text" maxLength={ 10 } value={ v.language || '' } placeholder="en, de, fr-CA" onChange={ ( e ) => set( { language: e.target.value } ) } />
				</label>
			) }
			<div className="nf-form-settings__field">
				<span className="nf-form-settings__label">{ __( 'Knowledge this form may use', 'neural-forms-lite' ) }</span>
				{ knowledge === null && <span className="nf-form-settings__hint">{ __( 'Loading business knowledge…', 'neural-forms-lite' ) }</span> }
				{ knowledge && knowledge.length === 0 && (
					<span className="nf-form-settings__hint">
						{ loadError || __( 'No business knowledge yet. Add FAQs, services and policies under Settings → Business context; all enabled items are used until you pick some here.', 'neural-forms-lite' ) }
					</span>
				) }
				{ knowledge && knowledge.length > 0 && (
					<>
						<span className="nf-form-settings__hint">{ __( 'Nothing ticked = every enabled item. Tick items to restrict this form to them.', 'neural-forms-lite' ) }</span>
						<ul className="nf-ai-behaviour__knowledge">
							{ knowledge.map( ( k ) => (
								<li key={ k.id }>
									<label>
										<input type="checkbox" checked={ selected.includes( k.id ) } onChange={ () => toggleId( k.id ) } disabled={ ! k.enabled } />
										<span>{ k.title }{ ! k.enabled ? ' ' + __( '(disabled)', 'neural-forms-lite' ) : '' }</span>
										<em>{ k.type }</em>
									</label>
								</li>
							) ) }
						</ul>
					</>
				) }
			</div>
		</div>
	);
}

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
	formId,
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

					{ section === 'workflows' && (
						<div className="nf-form-settings__body">
							<WorkflowsSection formId={ formId } fields={ fields } />
						</div>
					) }

					{ section === 'payments' && (
						<div className="nf-form-settings__body">
							<PaymentsSection
								value={ s.payment && typeof s.payment === 'object' ? s.payment : {} }
								onChange={ ( next ) => {
									const copy = { ...s };
									const isDefault = ! next.enabled && ( ! next.provider || next.provider === 'auto' ) && ! next.submit_label && ! next.description && ! next.success_url && ! next.cancel_url && next.send_invoice !== false && next.notify_admin !== false && ! next.notify_email;
									if ( isDefault ) delete copy.payment; else copy.payment = next;
									onChange( copy );
								} }
								fields={ fields }
							/>
						</div>
					) }

					{ section === 'pricing' && (
						<div className="nf-form-settings__body">
							<PricingSection
								value={ s.pricing && typeof s.pricing === 'object' ? s.pricing : {} }
								onChange={ ( next ) => {
									const copy = { ...s };
									const isDefault = ( ! next.currency || next.currency === 'USD' ) && ! ( next.discounts && next.discounts.length ) && ( ! next.deposit || ! next.deposit.type || next.deposit.type === 'none' );
									if ( isDefault ) delete copy.pricing; else copy.pricing = next;
									onChange( copy );
								} }
								fields={ fields }
							/>
						</div>
					) }

					{ section === 'ai_behaviour' && (
						<div className="nf-form-settings__body">
							<AiBehaviourSection
								value={ s.ai_behaviour && typeof s.ai_behaviour === 'object' ? s.ai_behaviour : {} }
								onChange={ ( next ) => {
									const copy = { ...s };
									if ( next && ( next.tone || next.language_mode || ( next.knowledge_ids && next.knowledge_ids.length ) ) ) copy.ai_behaviour = next; else delete copy.ai_behaviour;
									onChange( copy );
								} }
							/>
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
