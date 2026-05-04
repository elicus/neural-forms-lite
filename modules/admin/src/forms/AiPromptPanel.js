/**
 * "Generate with AI" panel — sidebar entry point in the form builder.
 *
 * Three-state UX gated on two server-supplied flags:
 *
 *   1. capabilities.ai_form_generate (Lite cap, flipped by Pro)
 *      → false: render upsell-style placeholder; CTA disabled.
 *      → true:  Pro is installed; render the working panel below.
 *
 *   2. plugin.pro.aiFormGenerate.configured (Pro localizes this)
 *      → false: Pro is installed but no provider key. Show a
 *               "configure in Pro settings" hint and disable Generate.
 *      → true:  full working flow (textarea → REST → preview modal →
 *               insert/append/replace).
 *
 * Backend lives in Pro:
 *   POST /wp-json/neural-forms-pro/v1/ai/generate-form
 *   GET  /wp-json/neural-forms-pro/v1/ai/generate-form/status
 *
 * Builder integration:
 *   - currentFieldCount  : number  (drives Insert vs Append/Replace)
 *   - onApplyForm( generated, mode )  : ('append' | 'replace') → builder writes new schema/name
 */

import { useState, useEffect, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../api';
import AiUsageNotice from '../ai/AiUsageNotice';
import extractUsage  from '../ai/extractUsage';

// Diagnostic logger gated on localStorage flag. Off by default — every
// log path also carries an `nf_debug_ai` toggle hint in the message.
// Enable with: localStorage.setItem('nf_debug_ai', '1') and reload.
function nfDebug( ...args ) {
	try {
		if ( typeof window !== 'undefined' && window.localStorage
			&& window.localStorage.getItem( 'nf_debug_ai' ) === '1'
		) {
			// eslint-disable-next-line no-console
			console.log( '[NF AI]', ...args );
		}
	} catch ( e ) { /* noop — Safari private mode etc. */ }
}

/**
 * Pull a useful error message out of whatever apiFetch rejected with.
 * apiFetch's contract: REST errors come back as `{ code, message, data }`
 * plain objects, NOT `Error` instances — so reading `err.message` works,
 * but `err instanceof Error` is false. Network/timeout errors come back
 * as actual Error instances.
 */
function extractErrorMessage( err, fallback ) {
	if ( ! err ) return fallback;
	if ( typeof err === 'string' ) return err;
	if ( err.message && typeof err.message === 'string' ) return err.message;
	if ( err.code && typeof err.code === 'string' ) return err.code;
	return fallback;
}

const PROMPT_EXAMPLES = [
	'Contact form for a law firm',
	'Dental appointment form with name, email, phone, and preferred date',
	'Multi-step quote request form',
	'Customer feedback survey with rating',
	'Poll about preferred service',
	'Quiz with 5 questions about WordPress basics',
];

function readBootData() {
	const root = ( typeof window !== 'undefined' && window.neuralFormsAdmin ) || {};
	const caps = ( root.plugin && root.plugin.capabilities ) || {};
	const proAi = ( root.pro && root.pro.aiFormGenerate ) || {};
	const settingsUrl = ( root.captcha && root.captcha.settingsUrl ) || ''; // Lite-localized — Settings page anchor.
	return {
		canGenerate:      !! caps.ai_form_generate,
		cloudConnected:   !! proAi.cloudConnected,
		aiEnabled:        proAi.aiEnabled !== false,
		licenseActive:    !! proAi.licenseActive,
		plan:             String( proAi.plan || '' ),
		normalizedPlan:   String( proAi.normalizedPlan || '' ),
		eligible:         !! proAi.eligible,
		creditsAvailable: proAi.creditsAvailable !== false,
		maxPromptChars:   Number( proAi.maxPromptChars ) || 1500,
		settingsUrl,
	};
}

function PreviewList( { fields, depth = 0 } ) {
	if ( ! Array.isArray( fields ) || fields.length === 0 ) return null;
	return (
		<ul className={ 'nf-ai-preview__list' + ( depth > 0 ? ' nf-ai-preview__list--nested' : '' ) }>
			{ fields.map( ( f, i ) => {
				const t = String( f && f.type || '' );
				if ( t === 'step' ) {
					return (
						<li key={ i } className="nf-ai-preview__row nf-ai-preview__row--step">
							<span className="nf-ai-preview__type">{ __( 'Step', 'neural-forms-lite' ) }</span>
							<span className="nf-ai-preview__label">{ f.title || __( 'Step', 'neural-forms-lite' ) }</span>
							<PreviewList fields={ f.fields || [] } depth={ depth + 1 } />
						</li>
					);
				}
				if ( t === 'row' ) {
					const cols = Array.isArray( f.columns ) ? f.columns : [];
					const inner = cols.flatMap( ( c ) => Array.isArray( c.fields ) ? c.fields : [] );
					return (
						<li key={ i } className="nf-ai-preview__row nf-ai-preview__row--row">
							<span className="nf-ai-preview__type">
								{ sprintf(
									/* translators: %d: column count */
									__( 'Row · %d cols', 'neural-forms-lite' ),
									cols.length
								) }
							</span>
							<PreviewList fields={ inner } depth={ depth + 1 } />
						</li>
					);
				}
				return (
					<li key={ i } className="nf-ai-preview__row">
						<span className="nf-ai-preview__type">{ t || '?' }</span>
						<span className="nf-ai-preview__label">{ f.label || f.id || '' }</span>
						{ f.required && (
							<span className="nf-ai-preview__req">{ __( 'required', 'neural-forms-lite' ) }</span>
						) }
					</li>
				);
			} ) }
		</ul>
	);
}

function PreviewModal( { generated, usage, currentFieldCount, onApply, onClose } ) {
	if ( ! generated ) return null;
	const fields = ( generated.form_schema && generated.form_schema.fields ) || [];
	const fieldCount = fields.length;
	const isEmpty = currentFieldCount === 0;

	return (
		<div className="nf-ai-modal" role="dialog" aria-modal="true" aria-label={ __( 'Review generated form', 'neural-forms-lite' ) }>
			<div className="nf-ai-modal__backdrop" onClick={ onClose } />
			<div className="nf-ai-modal__panel">
				<header className="nf-ai-modal__head">
					<h2 className="nf-ai-modal__title">{ generated.name || __( 'Generated form', 'neural-forms-lite' ) }</h2>
					<p className="nf-ai-modal__subtitle">
						{ sprintf(
							/* translators: %d: number of fields */
							__( '%d fields generated', 'neural-forms-lite' ),
							fieldCount
						) }
					</p>
				</header>

				{ generated.description && (
					<p className="nf-ai-modal__desc">{ generated.description }</p>
				) }

				<div className="nf-ai-modal__body">
					<PreviewList fields={ fields } />
				</div>

				<footer className="nf-ai-modal__foot">
					{ /* Credit-usage line at the LEFT of the footer so the
					    user can see what was billed before approving an
					    insert/replace. Renders nothing when usage is null. */ }
					<div className="nf-ai-modal__foot-meta">
						<AiUsageNotice usage={ usage } />
					</div>
					<button type="button" className="neural-forms-btn" onClick={ onClose }>
						{ __( 'Cancel', 'neural-forms-lite' ) }
					</button>
					{ ! isEmpty && (
						<button
							type="button"
							className="neural-forms-btn"
							onClick={ () => onApply( 'append' ) }
						>
							{ __( 'Append to current form', 'neural-forms-lite' ) }
						</button>
					) }
					<button
						type="button"
						className="neural-forms-btn neural-forms-btn--primary"
						onClick={ () => {
							if ( ! isEmpty ) {
								// Final confirmation before destructive replace.
								// eslint-disable-next-line no-alert
								if ( ! window.confirm( __( 'Replace the current form? Existing fields will be removed.', 'neural-forms-lite' ) ) ) {
									return;
								}
							}
							onApply( 'replace' );
						} }
					>
						{ isEmpty
							? __( 'Insert into form', 'neural-forms-lite' )
							: __( 'Replace current form', 'neural-forms-lite' ) }
					</button>
				</footer>
			</div>
		</div>
	);
}

export default function AiPromptPanel( { currentFieldCount = 0, onApplyForm } ) {
	const boot = useMemo( readBootData, [] );

	const [ prompt,    setPrompt    ] = useState( '' );
	const [ loading,   setLoading   ] = useState( false );
	const [ error,     setError     ] = useState( '' );
	const [ errorCta,  setErrorCta  ] = useState( null );   // { label, href } | null
	// Canonical user-facing usage block from the cloud — see
	// modules/admin/src/ai/extractUsage.js + AiUsageNotice.js. Cleared
	// on every Generate click so a previous success doesn't bleed into
	// a new (in-flight or failed) request. Set to either:
	//   - the cloud's success usage block (credits_deducted=true), or
	//   - the failure-with-no-charge block (credits_deducted=false), or
	//   - null when the failure didn't return any usage info.
	const [ lastUsage, setLastUsage ] = useState( null );
	const [ warnings,  setWarnings  ] = useState( [] );
	const [ generated, setGenerated ] = useState( null );

	// Map a server error code to an actionable CTA so the user has one
	// click to fix it. Mirrors the brief's spec — no OpenAI mention,
	// uses the existing Settings page URL.
	const ctaForCode = ( code ) => {
		const settings = boot.settingsUrl || 'admin.php?page=neural-forms-settings';
		switch ( code ) {
			case 'cloud_not_connected':
				return { label: __( 'Open Settings',     'neural-forms-lite' ), href: settings };
			case 'license_inactive':
				return { label: __( 'Activate license',  'neural-forms-lite' ), href: settings };
			case 'plan_not_allowed':
				return { label: __( 'Upgrade plan',      'neural-forms-lite' ), href: 'https://neuralforms.ai/pricing' };
			case 'insufficient_credits':
				return { label: __( 'Manage plan',       'neural-forms-lite' ), href: settings };
			case 'feature_not_allowed':
				return { label: __( 'Upgrade plan',      'neural-forms-lite' ), href: 'https://neuralforms.ai/pricing' };
			case 'ai_disabled':
				return { label: __( 'Open Settings',     'neural-forms-lite' ), href: settings };
			// Provider/AI failures — no CTA needed; user just retries.
			case 'ai_invalid_response':
			case 'ai_provider_timeout':
			case 'ai_provider_error':
				return null;
		}
		return null;
	};

	// Friendlier copy for AI-side failures. The cloud's message is
	// already actionable (e.g. "Try a more specific prompt") but for
	// the most common cases we surface a fixed copy line so the UI
	// stays consistent regardless of model output.
	const friendlyMessageForCode = ( code, fallback ) => {
		switch ( code ) {
			case 'ai_invalid_response':
				return __( 'AI could not generate a valid form. Please try a more specific prompt.', 'neural-forms-lite' );
			case 'ai_provider_timeout':
				return __( 'The AI request timed out. Please try again.', 'neural-forms-lite' );
			case 'ai_provider_error':
				return __( 'The AI service is temporarily unavailable. Please try again in a moment.', 'neural-forms-lite' );
		}
		return fallback;
	};

	// Keep error/warnings out of the way after a fresh prompt edit.
	useEffect( () => {
		if ( prompt && error ) {
			setError( '' );
			setErrorCta( null );
		}
	}, [ prompt ] );

	const promptOk = prompt.trim().length > 0 && prompt.length <= boot.maxPromptChars;
	const ready = boot.canGenerate && boot.eligible;

	// Compute the rendered state name for diagnostics + button data attrs.
	// MUST stay in sync with the early-return branches below.
	const computedState = ! boot.canGenerate     ? 'upsell'
		: ! boot.cloudConnected                 ? 'connect-pro'
		: ! boot.aiEnabled                      ? 'enable-ai'
		: ! boot.licenseActive                  ? 'activate-license'
		: ( ! boot.eligible && boot.creditsAvailable ) ? 'plan-not-allowed'
		: ! boot.creditsAvailable               ? 'out-of-credits'
		: 'working';

	// Diagnostic — one render log per mount, plus state-change logs on
	// any boot-flag flip (boot is memoised so this fires once unless
	// something causes a remount). Captures the localized data shape so
	// "no logs at all" vs "logs but wrong state" is instantly visible.
	useEffect( () => {
		nfDebug( 'AiPromptPanel render', {
			hasAdmin: !! ( typeof window !== 'undefined' && window.neuralFormsAdmin ),
			capabilities: ( typeof window !== 'undefined' && window.neuralFormsAdmin && window.neuralFormsAdmin.plugin )
				? window.neuralFormsAdmin.plugin.capabilities : null,
			proAi: ( typeof window !== 'undefined' && window.neuralFormsAdmin && window.neuralFormsAdmin.pro )
				? window.neuralFormsAdmin.pro.aiFormGenerate : null,
			state: computedState,
		} );
		nfDebug( 'computed state', {
			capability:       boot.canGenerate,
			cloudConnected:   boot.cloudConnected,
			licenseActive:    boot.licenseActive,
			aiEnabled:        boot.aiEnabled,
			creditsAvailable: boot.creditsAvailable,
			eligible:         boot.eligible,
			plan:             boot.plan,
			normalizedPlan:   boot.normalizedPlan,
		} );
	}, [ computedState ] );

	// Per-render button-state log. Wrapped in an effect so it fires once
	// per render-cycle rather than during render (avoids duplicating in
	// React 18 strict mode).
	useEffect( () => {
		if ( computedState !== 'working' ) return;
		nfDebug( 'button render', {
			disabled: ! promptOk || loading,
			loading,
			promptLength: prompt.trim().length,
		} );
	}, [ computedState, promptOk, loading ] );

	// Temporary admin-debug global. Only attached when nf_debug_ai=1 so
	// production browsers never see it. Lets the admin re-trigger the
	// flow from the console without re-entering a prompt.
	useEffect( () => {
		try {
			if ( typeof window === 'undefined' || ! window.localStorage ) return;
			if ( window.localStorage.getItem( 'nf_debug_ai' ) !== '1' ) return;
			window.neuralFormsAiDebug = {
				lastState:    computedState,
				lastPayload:  generated,                    // generated form (after success)
				lastError:    error,
				promptLength: prompt.trim().length,
				rerun:        () => handleGenerate(),       // hoisted below
			};
		} catch ( e ) { /* noop */ }
	}, [ computedState, generated, error, prompt ] );

	const handleGenerate = () => {
		nfDebug( 'Generate button clicked', {
			disabled: ! promptOk || loading,
			loading,
			promptLength: prompt.trim().length,
		} );

		// Surface WHY the click was dropped instead of failing silently.
		if ( loading ) {
			nfDebug( 'Generate aborted', { reason: 'loading' } );
			return;
		}
		if ( ! ready ) {
			const reason = ! boot.canGenerate
				? __( 'Generate with AI requires Neural Forms Pro.', 'neural-forms-lite' )
				: __( 'Generate with AI is not enabled for this site yet. Reload the builder to refresh entitlement.', 'neural-forms-lite' );
			nfDebug( 'Generate aborted', {
				reason: ! boot.canGenerate ? 'no_capability' : 'not_eligible',
				canGenerate: boot.canGenerate, eligible: boot.eligible,
			} );
			setError( reason );
			return;
		}
		if ( ! promptOk ) {
			nfDebug( 'Generate aborted', { reason: 'empty_prompt', promptLength: prompt.trim().length } );
			setError( __( 'Please enter a prompt before generating.', 'neural-forms-lite' ) );
			return;
		}

		setLoading( true );
		setError( '' );
		setErrorCta( null );
		setWarnings( [] );
		setGenerated( null );
		// Brief rule: clear stale usage at request start so the user
		// never sees credits attributed to a prior call while waiting
		// for the new one.
		setLastUsage( null );

		nfDebug( 'calling WP generate endpoint', {
			path: '/neural-forms-pro/v1/ai/generate-form',
			promptLength: prompt.trim().length,
		} );

		api( '/neural-forms-pro/v1/ai/generate-form', {
			method: 'POST',
			data: { prompt },
		} )
			.then( ( raw ) => {
				// apiFetch returns the parsed body by default. Some setups
				// (e.g. parse:false middleware) yield { body: ... }; the
				// brief calls this out as a known footgun. Read either.
				const res = raw && raw.body && typeof raw.body === 'object' ? raw.body : raw;
				nfDebug( 'WP generate response raw', {
					hasResponse: !! raw,
					keys:        raw ? Object.keys( raw ) : [],
					hasBody:     !! ( raw && raw.body ),
					success:     res && ( res.success === true ),
					hasForm:     !! ( res && res.form ),
					requestId:   ( res && res.request_id ) || null,
					fieldCount:  ( res && res.form && res.form.form_schema && Array.isArray( res.form.form_schema.fields ) )
						? res.form.form_schema.fields.length : null,
				} );

				if ( ! res || typeof res !== 'object' ) {
					throw new Error( __( 'AI returned an empty response. Please try again.', 'neural-forms-lite' ) );
				}
				if ( res.code && ! res.form ) {
					// Some apiFetch error paths surface as a 200 with an
					// error envelope instead of a thrown rejection.
					throw new Error( extractErrorMessage( res, __( 'AI returned an error.', 'neural-forms-lite' ) ) );
				}
				if ( ! res.form ) {
					throw new Error( __( 'AI returned an invalid form. Please try again.', 'neural-forms-lite' ) );
				}

				nfDebug( 'opening generated form preview', {
					formName:      res.form.name,
					fieldCount:    ( res.form.form_schema && Array.isArray( res.form.form_schema.fields ) )
						? res.form.form_schema.fields.length : 0,
					warningsCount: Array.isArray( res.warnings ) ? res.warnings.length : 0,
				} );

				setGenerated( res.form );
				setWarnings( Array.isArray( res.warnings ) ? res.warnings : [] );
				// Cloud's canonical usage block — accept either top-level
				// or wrapped-in-body shape (extractUsage handles both).
				setLastUsage( extractUsage( raw ) );
			} )
			.catch( ( err ) => {
				nfDebug( 'WP generate error', {
					name:    err && err.name,
					code:    err && err.code,
					message: err && err.message,
					status:  err && err.status,
					data:    err && err.data,
				} );
				const rawMsg = extractErrorMessage(
					err,
					__( 'Could not generate the form. Please try again.', 'neural-forms-lite' )
				);
				const code = err && typeof err === 'object' ? String( err.code || '' ) : '';
				setError( friendlyMessageForCode( code, rawMsg ) );
				// Attach a contextual CTA if the server's structured
				// `code` matches one of our known recoverable cases.
				setErrorCta( ctaForCode( code ) );
				// If the failure response carried an explicit "no
				// credits deducted" block, surface it so the user sees
				// "No credits were deducted." reassurance. Failures
				// with no usage block leave lastUsage null and the
				// notice stays hidden.
				const failureUsage = extractUsage( err );
				if ( failureUsage && failureUsage.credits_deducted === false ) {
					setLastUsage( failureUsage );
				}
			} )
			.finally( () => {
				nfDebug( 'finally — loading=false' );
				setLoading( false );
			} );
	};

	const handleApply = ( mode ) => {
		if ( ! generated || typeof onApplyForm !== 'function' ) return;
		onApplyForm( generated, mode );
		setGenerated( null );
		setPrompt( '' );
	};

	// State 1: Pro not installed → upsell placeholder.
	if ( ! boot.canGenerate ) {
		return (
			<section className="nf-palette__section nf-ai-prompt">
				<h3 className="nf-palette__title">
					{ __( 'Generate with AI', 'neural-forms-lite' ) }
					<span className="nf-palette__title-badge">{ __( 'Pro', 'neural-forms-lite' ) }</span>
				</h3>
				<p className="nf-ai-prompt__hint">
					{ __( 'Describe the form you want and we will draft the fields.', 'neural-forms-lite' ) }
				</p>
				<textarea
					className="nf-ai-prompt__input"
					rows={ 3 }
					placeholder={ __( 'e.g. Create a dental appointment form with name, email, phone, and preferred date.', 'neural-forms-lite' ) }
					value={ prompt }
					onChange={ ( e ) => setPrompt( e.target.value ) }
				/>
				<button
					type="button"
					className="neural-forms-btn neural-forms-btn--primary nf-ai-prompt__cta"
					disabled
					title={ __( 'Available with Neural Forms Pro', 'neural-forms-lite' ) }
				>
					{ __( 'Generate form', 'neural-forms-lite' ) }
				</button>
			</section>
		);
	}

	// State 2: Pro installed, cloud / license not connected.
	if ( ! boot.cloudConnected ) {
		return (
			<section className="nf-palette__section nf-ai-prompt">
				<h3 className="nf-palette__title">{ __( 'Generate with AI', 'neural-forms-lite' ) }</h3>
				<p className="nf-ai-prompt__hint">
					{ __( 'Connect Neural Forms Pro to use AI generation.', 'neural-forms-lite' ) }
				</p>
				{ boot.settingsUrl ? (
					<a
						className="neural-forms-btn neural-forms-btn--primary nf-ai-prompt__cta"
						href={ boot.settingsUrl }
					>
						{ __( 'Connect Pro', 'neural-forms-lite' ) }
					</a>
				) : (
					<button
						type="button"
						className="neural-forms-btn neural-forms-btn--primary nf-ai-prompt__cta"
						disabled
					>
						{ __( 'Connect Pro', 'neural-forms-lite' ) }
					</button>
				) }
				<p className="nf-ai-prompt__hint nf-ai-prompt__hint--muted">
					{ __( 'AI generation is powered by Neural Forms Cloud.', 'neural-forms-lite' ) }
				</p>
			</section>
		);
	}

	// State 2b: Cloud AI master toggle is OFF in Settings.
	if ( ! boot.aiEnabled ) {
		return (
			<section className="nf-palette__section nf-ai-prompt">
				<h3 className="nf-palette__title">{ __( 'Generate with AI', 'neural-forms-lite' ) }</h3>
				<p className="nf-ai-prompt__hint">
					{ __( 'Enable Cloud AI in Neural Forms settings to use AI generation.', 'neural-forms-lite' ) }
				</p>
				<a
					className="neural-forms-btn neural-forms-btn--primary nf-ai-prompt__cta"
					href={ boot.settingsUrl || '#' }
				>
					{ __( 'Enable AI in settings', 'neural-forms-lite' ) }
				</a>
				<p className="nf-ai-prompt__hint nf-ai-prompt__hint--muted">
					{ __( 'AI generation is powered by Neural Forms Cloud.', 'neural-forms-lite' ) }
				</p>
			</section>
		);
	}

	// State 3a: License inactive — distinct from plan-not-allowed.
	if ( ! boot.licenseActive ) {
		return (
			<section className="nf-palette__section nf-ai-prompt">
				<h3 className="nf-palette__title">{ __( 'Generate with AI', 'neural-forms-lite' ) }</h3>
				<p className="nf-ai-prompt__hint">
					{ __( 'Activate your Pro license to enable AI generation.', 'neural-forms-lite' ) }
				</p>
				<a
					className="neural-forms-btn neural-forms-btn--primary nf-ai-prompt__cta"
					href={ boot.settingsUrl || '#' }
				>
					{ __( 'Activate license', 'neural-forms-lite' ) }
				</a>
				<p className="nf-ai-prompt__hint nf-ai-prompt__hint--muted">
					{ __( 'AI generation is powered by Neural Forms Cloud.', 'neural-forms-lite' ) }
				</p>
			</section>
		);
	}

	// State 3b: Cloud connected + license active, but the plan tier
	// doesn't include AI form generation (Lite/free/basic).
	//
	// Eligibility is the server's call — we trust `boot.eligible`
	// over any local plan-string comparison so a future plan rename
	// (e.g. cloud starts emitting "pro-business" or a new variation
	// id) can't desync this panel from the entitlement check on the
	// REST endpoint. Empty `plan` here means PlanNormalizer didn't
	// recognise the cloud value — also surfaces as not-eligible.
	if ( ! boot.eligible && boot.creditsAvailable ) {
		return (
			<section className="nf-palette__section nf-ai-prompt">
				<h3 className="nf-palette__title">{ __( 'Generate with AI', 'neural-forms-lite' ) }</h3>
				<p className="nf-ai-prompt__hint">
					{ __( 'Generate with AI requires an active Pro, Yearly, or Enterprise plan.', 'neural-forms-lite' ) }
				</p>
				<a
					className="neural-forms-btn neural-forms-btn--primary nf-ai-prompt__cta"
					href={ boot.settingsUrl || '#' }
				>
					{ __( 'Upgrade plan', 'neural-forms-lite' ) }
				</a>
				<p className="nf-ai-prompt__hint nf-ai-prompt__hint--muted">
					{ __( 'AI generation is powered by Neural Forms Cloud.', 'neural-forms-lite' ) }
				</p>
			</section>
		);
	}

	// State 4: Plan is eligible but no credits left.
	if ( ! boot.creditsAvailable ) {
		return (
			<section className="nf-palette__section nf-ai-prompt">
				<h3 className="nf-palette__title">{ __( 'Generate with AI', 'neural-forms-lite' ) }</h3>
				<p className="nf-ai-prompt__hint">
					{ __( "You're out of AI credits.", 'neural-forms-lite' ) }
				</p>
				<a
					className="neural-forms-btn neural-forms-btn--primary nf-ai-prompt__cta"
					href={ boot.settingsUrl || '#' }
				>
					{ __( 'Manage plan', 'neural-forms-lite' ) }
				</a>
				<p className="nf-ai-prompt__hint nf-ai-prompt__hint--muted">
					{ __( 'AI generation is powered by Neural Forms Cloud.', 'neural-forms-lite' ) }
				</p>
			</section>
		);
	}

	// State 5: Eligible + credits available — full working flow.
	return (
		<>
			<section className="nf-palette__section nf-ai-prompt">
				<h3 className="nf-palette__title">{ __( 'Generate with AI', 'neural-forms-lite' ) }</h3>
				<p className="nf-ai-prompt__hint">
					{ __( 'Describe the form you want and we will draft the fields.', 'neural-forms-lite' ) }
				</p>
				<p className="nf-ai-prompt__hint nf-ai-prompt__hint--muted">
					{ __( 'AI generation is powered by Neural Forms Cloud.', 'neural-forms-lite' ) }
				</p>
				<textarea
					className="nf-ai-prompt__input"
					rows={ 3 }
					placeholder={ __( 'e.g. Create a dental appointment form with name, email, phone, preferred date, and message.', 'neural-forms-lite' ) }
					value={ prompt }
					maxLength={ boot.maxPromptChars }
					onChange={ ( e ) => setPrompt( e.target.value ) }
					disabled={ loading }
				/>
				<div className="nf-ai-prompt__meta">
					<span className="nf-ai-prompt__count">
						{ prompt.length + ' / ' + boot.maxPromptChars }
					</span>
				</div>
				<button
					type="button"
					className="neural-forms-btn neural-forms-btn--primary nf-ai-prompt__cta"
					onMouseDown={ () => nfDebug( 'Generate button mouse down' ) }
					onClick={ handleGenerate }
					disabled={ ! promptOk || loading }
					data-debug-ai-state={ computedState }
					data-debug-ai-disabled={ ( ! promptOk || loading ) ? '1' : '0' }
				>
					{ loading
						? __( 'Generating…', 'neural-forms-lite' )
						: __( 'Generate form', 'neural-forms-lite' ) }
				</button>
				{ error && (
					<p className="nf-ai-prompt__error" role="alert">
						{ error }
						{ errorCta && errorCta.href && (
							<>
								{ ' ' }
								<a
									className="nf-ai-prompt__error-cta"
									href={ errorCta.href }
									target={ errorCta.href.startsWith( 'http' ) ? '_blank' : undefined }
									rel={ errorCta.href.startsWith( 'http' ) ? 'noopener noreferrer' : undefined }
								>
									{ errorCta.label }
								</a>
							</>
						) }
					</p>
				) }
				{ /* Credit-usage line — appears under the Generate button
				    after a request settles. Stays visible after the
				    preview modal closes so the user can see what was
				    just charged when they look back at the panel.
				    Cleared when a new Generate click starts. */ }
				{ ! loading && lastUsage && (
					<AiUsageNotice
						usage={ lastUsage }
						className="nf-ai-prompt__usage"
						style={ { marginTop: 8 } }
					/>
				) }
				{ ! prompt && (
					<details className="nf-ai-prompt__examples">
						<summary>{ __( 'Need ideas?', 'neural-forms-lite' ) }</summary>
						<ul>
							{ PROMPT_EXAMPLES.map( ( ex, i ) => (
								<li key={ i }>
									<button
										type="button"
										className="nf-ai-prompt__example-btn"
										onClick={ () => setPrompt( ex ) }
									>{ ex }</button>
								</li>
							) ) }
						</ul>
					</details>
				) }
				{ warnings.length > 0 && (
					<details className="nf-ai-prompt__warnings">
						<summary>
							{ sprintf(
								/* translators: %d: warning count */
								__( '%d notes from generation', 'neural-forms-lite' ),
								warnings.length
							) }
						</summary>
						<ul>{ warnings.map( ( w, i ) => <li key={ i }>{ w }</li> ) }</ul>
					</details>
				) }
			</section>

			<PreviewModal
				generated={ generated }
				usage={ lastUsage }
				currentFieldCount={ currentFieldCount }
				onApply={ handleApply }
				onClose={ () => setGenerated( null ) }
			/>
		</>
	);
}
