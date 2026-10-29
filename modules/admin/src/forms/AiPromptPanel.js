/**
 * "Generate with AI" panel — sidebar entry point in the form builder.
 *
 * Lite-owned since 2026-10-30 (was a Pro feature). The working state only
 * needs plugin.aiFormGenerate (localized by AdminPanel from
 * FormGeneratorService::status()): cloud connected + Cloud AI enabled +
 * credits available. Everything else (plan, quota, safety) is decided by
 * Neural Forms Cloud and surfaced through the endpoint's error codes.
 *
 * Backend (Lite):
 *   POST /wp-json/neural-forms/v1/ai/generate-form
 *   GET  /wp-json/neural-forms/v1/ai/generate-form/status
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

// Canonical Pro upgrade URL — matches Integrations / BuilderInspector
// / EntryDetail / Settings / PagePerformance so a future change has one
// place to update. Cloud responses may carry their own `upgrade_url`;
// this is the static fallback used when no cloud-supplied URL exists.

/**
 * Polished assistant-card chrome used by every state of the panel.
 * Renders the icon + title + subtitle + optional PRO badge header,
 * then the description, then whatever state-specific body the caller
 * passes as `children`. Soft mint→white gradient surface, mint-tinted
 * border — see `.nf-inspector-ai-card` in builder.css.
 */
function AssistantCard( { locked = false, subtitle, description, children } ) {
	const className = 'nf-inspector-ai-card'
		+ ( locked ? ' nf-inspector-ai-card--locked' : '' );
	return (
		<section className={ className }>
			<header className="nf-inspector-ai-card__head">
				<span className="nf-inspector-ai-card__icon" aria-hidden="true">AI</span>
				<div className="nf-inspector-ai-card__heading">
					<h4 className="nf-inspector-ai-card__title">
						{ __( 'Generate with Neural AI', 'neural-forms-lite' ) }
					</h4>
					{ subtitle && (
						<p className="nf-inspector-ai-card__sub">{ subtitle }</p>
					) }
				</div>
				{ locked && (
					<span className="nf-inspector-ai-card__badge">
						{ __( 'PRO', 'neural-forms-lite' ) }
					</span>
				) }
			</header>
			{ description && (
				<p className="nf-inspector-ai-card__desc">{ description }</p>
			) }
			{ children }
		</section>
	);
}

const PROMPT_EXAMPLES = [
	'Contact form for a service business with name, email, phone, service type, and message.',
	'Appointment booking form with preferred date, time, contact details, and notes.',
	'Customer feedback form with rating, comments, and permission to contact.',
];

function readBootData() {
	const root = ( typeof window !== 'undefined' && window.neuralFormsAdmin ) || {};
	const ai = ( root.plugin && root.plugin.aiFormGenerate ) || {};
	const settingsUrl = ( root.captcha && root.captcha.settingsUrl ) || ''; // Lite-localized — Settings page anchor.
	return {
		cloudConnected:   !! ai.cloudConnected,
		aiEnabled:        ai.aiEnabled !== false,
		plan:             String( ai.plan || '' ),
		eligible:         !! ai.eligible,
		creditsAvailable: ai.creditsAvailable !== false,
		maxPromptChars:   Number( ai.maxPromptChars ) || 1500,
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

	// "Need ideas?" inline reveal — replaces the previous <details>
	// element so the example list looks like a real interactive block
	// in the inspector (and so we can style the toggle as a ghost link
	// instead of relying on the native disclosure widget).
	const [ showIdeas, setShowIdeas ] = useState( false );

	// Map a server error code to an actionable CTA so the user has one
	// click to fix it. Mirrors the brief's spec — no OpenAI mention,
	// uses the existing Settings page URL.
	const ctaForCode = ( code ) => {
		const settings = boot.settingsUrl || 'admin.php?page=neural-forms-settings';
		switch ( code ) {
			case 'cloud_not_connected':
				return { label: __( 'Open Settings',     'neural-forms-lite' ), href: settings };
			case 'cloud_unauthorized':
				return { label: __( 'Check API key',     'neural-forms-lite' ), href: settings };
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
				return __( 'Neural AI could not generate a valid form. Please try a more specific prompt.', 'neural-forms-lite' );
			case 'ai_provider_timeout':
				return __( 'The Neural AI request timed out. Please try again.', 'neural-forms-lite' );
			case 'ai_provider_error':
				return __( 'The Neural AI service is temporarily unavailable. Please try again in a moment.', 'neural-forms-lite' );
			case 'blocked_by_safety':
				return __( 'Neural AI skipped this prompt because it appears unsafe. No credits were deducted.', 'neural-forms-lite' );
			case 'ai_safety_unavailable':
				return __( 'Neural AI safety check is temporarily unavailable. Please try again in a few minutes.', 'neural-forms-lite' );
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
	const ready = boot.eligible;

	// Compute the rendered state name for diagnostics + button data attrs.
	// MUST stay in sync with the early-return branches below.
	const computedState = ! boot.cloudConnected ? 'connect-cloud'
		: ! boot.aiEnabled                      ? 'enable-ai'
		: ! boot.creditsAvailable               ? 'out-of-credits'
		: ! boot.eligible                       ? 'not-eligible'
		: 'working';

	// Diagnostic — one render log per mount, plus state-change logs on
	// any boot-flag flip (boot is memoised so this fires once unless
	// something causes a remount). Captures the localized data shape so
	// "no logs at all" vs "logs but wrong state" is instantly visible.
	useEffect( () => {
		nfDebug( 'AiPromptPanel render', {
			hasAdmin: !! ( typeof window !== 'undefined' && window.neuralFormsAdmin ),
			aiFormGenerate: ( typeof window !== 'undefined' && window.neuralFormsAdmin && window.neuralFormsAdmin.plugin )
				? window.neuralFormsAdmin.plugin.aiFormGenerate : null,
			state: computedState,
		} );
		nfDebug( 'computed state', {
			cloudConnected:   boot.cloudConnected,
			aiEnabled:        boot.aiEnabled,
			creditsAvailable: boot.creditsAvailable,
			eligible:         boot.eligible,
			plan:             boot.plan,
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
			const reason = __( 'Generate with Neural AI is not enabled for this site yet. Reload the builder to refresh entitlement.', 'neural-forms-lite' );
			nfDebug( 'Generate aborted', { reason: 'not_eligible', eligible: boot.eligible } );
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
			path: '/neural-forms/v1/ai/generate-form',
			promptLength: prompt.trim().length,
		} );

		api( '/neural-forms/v1/ai/generate-form', {
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
					throw new Error( __( 'Neural AI returned an empty response. Please try again.', 'neural-forms-lite' ) );
				}
				if ( res.code && ! res.form ) {
					// Some apiFetch error paths surface as a 200 with an
					// error envelope instead of a thrown rejection.
					throw new Error( extractErrorMessage( res, __( 'Neural AI returned an error.', 'neural-forms-lite' ) ) );
				}
				if ( ! res.form ) {
					throw new Error( __( 'Neural AI returned an invalid form. Please try again.', 'neural-forms-lite' ) );
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

	// Setup notices. Each one renders the same assistant card chrome with
	// a state-specific notice + Open Settings CTA — no silent failures.
	const setupCardFor = ( notice, ctaLabel, ctaHref ) => (
		<AssistantCard
			subtitle={ __( 'Powered by Neural Forms Neural AI', 'neural-forms-lite' ) }
			description={ notice }
		>
			<a
				className="nf-inspector-ai-card__cta neural-forms-btn neural-forms-btn--primary"
				href={ ctaHref || '#' }
			>
				{ ctaLabel }
			</a>
		</AssistantCard>
	);

	if ( ! boot.cloudConnected ) {
		return setupCardFor(
			__( 'Neural AI generation requires your Neural Forms Neural AI connection. Configure it in Settings.', 'neural-forms-lite' ),
			__( 'Open Settings', 'neural-forms-lite' ),
			boot.settingsUrl
		);
	}
	if ( ! boot.aiEnabled ) {
		return setupCardFor(
			__( 'Enable Neural AI Cloud in Neural Forms settings to use Neural AI generation.', 'neural-forms-lite' ),
			__( 'Open Settings', 'neural-forms-lite' ),
			boot.settingsUrl
		);
	}
	if ( ! boot.creditsAvailable ) {
		return setupCardFor(
			__( "You're out of Neural AI credits.", 'neural-forms-lite' ),
			__( 'Manage plan', 'neural-forms-lite' ),
			boot.settingsUrl
		);
	}
	// Server-truth flag — see boot.eligible note above.
	if ( ! boot.eligible ) {
		return setupCardFor(
			__( 'Neural AI generation is not available for this site right now. Check your Neural Forms Cloud connection in Settings.', 'neural-forms-lite' ),
			__( 'Open Settings', 'neural-forms-lite' ),
			boot.settingsUrl
		);
	}

	// Working state: cloud connected + Cloud AI on + credits available —
	// full working assistant card. Textarea is inline
	// (no card→expanded indirection); the existing handleGenerate +
	// PreviewModal flow is unchanged.
	return (
		<>
			<AssistantCard
				subtitle={ __( 'Powered by Neural Forms Neural AI', 'neural-forms-lite' ) }
				description={ __( 'Describe the form you need and Neural Forms Neural AI will draft fields, labels, and layout for you.', 'neural-forms-lite' ) }
			>
				<textarea
					className="nf-inspector-ai-card__input"
					rows={ 4 }
					placeholder={ __( 'Example: Create a dental appointment form with name, email, phone, preferred date, and message.', 'neural-forms-lite' ) }
					value={ prompt }
					maxLength={ boot.maxPromptChars }
					onChange={ ( e ) => setPrompt( e.target.value ) }
					disabled={ loading }
				/>
				<div className="nf-inspector-ai-card__meta">
					<button
						type="button"
						className="nf-inspector-ai-card__ideas-toggle"
						onClick={ () => setShowIdeas( ( v ) => ! v ) }
						aria-expanded={ showIdeas }
					>
						{ __( 'Need ideas?', 'neural-forms-lite' ) }
					</button>
					<span className="nf-inspector-ai-card__count">
						{ prompt.length + ' / ' + boot.maxPromptChars }
					</span>
				</div>
				{ showIdeas && (
					<ul className="nf-inspector-ai-card__ideas">
						{ PROMPT_EXAMPLES.map( ( ex, i ) => (
							<li key={ i }>
								<button
									type="button"
									className="nf-inspector-ai-card__idea-btn"
									onClick={ () => {
										setPrompt( ex );
										setShowIdeas( false );
									} }
								>
									{ ex }
								</button>
							</li>
						) ) }
					</ul>
				) }
				<button
					type="button"
					className="nf-inspector-ai-card__cta neural-forms-btn neural-forms-btn--primary"
					onMouseDown={ () => nfDebug( 'Generate button mouse down' ) }
					onClick={ handleGenerate }
					disabled={ ! promptOk || loading }
					data-debug-ai-state={ computedState }
					data-debug-ai-disabled={ ( ! promptOk || loading ) ? '1' : '0' }
				>
					{ loading
						? __( 'Generating…', 'neural-forms-lite' )
						: __( 'Generate Form', 'neural-forms-lite' ) }
				</button>
				{ error && (
					<p className="nf-inspector-ai-card__error" role="alert">
						{ error }
						{ errorCta && errorCta.href && (
							<>
								{ ' ' }
								<a
									className="nf-inspector-ai-card__error-cta"
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
						className="nf-inspector-ai-card__usage"
						style={ { marginTop: 8 } }
					/>
				) }
				{ warnings.length > 0 && (
					<details className="nf-inspector-ai-card__warnings">
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
			</AssistantCard>

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
