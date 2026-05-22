/**
 * AI analysis card displayed in the entry detail pane.
 *
 * Shows the cloud-side analysis: summary, category, priority, score,
 * spam classification. Tier badge shows which model wrote this analysis
 * (fast/balanced/smart/premium) so users can see at a glance how their
 * plan ladder resolved per-call.
 *
 * Phase 5 separation: the Reanalyze + Regenerate buttons themselves
 * are Pro-rendered. Lite mounts the `EntryAiActions` slot with
 * fillProps `{onReanalyzeClick, onRegenerateClick, isAiRerunning,
 * aiData, hasPrev}` matching the contract Pro's
 * `pro:reanalyze-regenerate` fill expects. When `caps.ai_reanalyze`
 * is false, Lite shows an upsell link instead — Lite never renders
 * the actual Reanalyze/Regenerate button itself.
 *
 * Props:
 *   entry          full entry row from /entries/{id}
 *   capabilities   { ai_reanalyze, ai_refine_reply, ... } snapshot
 *   onReanalyze    () => Promise<void> — owner runs the request
 *   onRegenerate   () => Promise<void> — owner runs the request
 *   reanalyzing    boolean — disables Pro fill while a request is in flight
 *   error          string — shown inline below the card
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ProSlot } from '../slot-registry';

const TIER_LABEL = {
	fast:     'Fast',
	balanced: 'Balanced',
	smart:    'Smart',
	premium:  'Premium',
};

const PRO_UPGRADE_URL = 'https://neuralforms.ai/pricing';

function tierLabel( t ) {
	const k = String( t || 'fast' ).toLowerCase();
	return TIER_LABEL[ k ] || k;
}

function LockIcon() {
	return (
		<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
			strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
			<rect x="4" y="11" width="16" height="10" rx="2" />
			<path d="M8 11V8a4 4 0 0 1 8 0v3" />
		</svg>
	);
}

export default function AiAnalysisCard( {
	entry,
	capabilities,
	onReanalyze,
	onRegenerate,
	reanalyzing,
	regenerating,
	freshKey,
	error,
} ) {
	if ( ! entry ) return null;

	// Run a 2.5s "just analyzed" navy pulse whenever freshKey bumps.
	// Skips the very first render so we don't pulse on entry open.
	const [ isFresh, setIsFresh ] = useState( false );
	const firstRef = useRef( true );
	useEffect( () => {
		if ( firstRef.current ) { firstRef.current = false; return; }
		setIsFresh( true );
		const t = setTimeout( () => setIsFresh( false ), 2500 );
		return () => clearTimeout( t );
	}, [ freshKey ] );

	const summary    = entry.ai_summary  || '';
	const category   = entry.ai_category || '';
	const priority   = entry.ai_priority || '';
	const score      = entry.ai_score;
	const tier       = entry.ai_tier;
	const isSpam     = entry.ai_is_spam === 1 || entry.ai_is_spam === true;
	const spamConf   = entry.ai_spam_confidence || 0;
	const spamReason = entry.ai_spam_reason || '';
	const status     = entry.ai_status || '';

	const hasAnalysis = !! ( summary || category || priority || score != null );

	const canReanalyze = !! capabilities?.ai_reanalyze;
	const [ showUpsell, setShowUpsell ] = useState( false );

	const cardClass = 'neural-forms-entry-detail__ai-card'
		+ ( reanalyzing ? ' is-loading' : '' )
		+ ( isFresh && ! reanalyzing ? ' is-just-analyzed' : '' );

	return (
		<section className={ cardClass }>
			{ reanalyzing && (
				<div className="neural-forms-entry-detail__ai-loading-overlay" role="status" aria-live="polite">
					<span className="neural-forms-entry-detail__ai-loading-spinner" aria-hidden="true"></span>
					<span className="neural-forms-entry-detail__ai-loading-label">
						{ __( 'Reanalyzing entry…', 'neural-forms-lite' ) }
					</span>
				</div>
			) }
			<header className="neural-forms-entry-detail__ai-card-header">
				<h4 className="neural-forms-entry-detail__ai-card-title">{ __( 'Neural AI Analysis', 'neural-forms-lite' ) }</h4>
				<div className="neural-forms-entry-detail__ai-badges">
					{ tier && (
						<span className="neural-forms-entries-pill neural-forms-entries-pill--tier" title={ __( 'Neural AI tier used', 'neural-forms-lite' ) }>
							{ tierLabel( tier ) }
						</span>
					) }
					{ status === 'queued'     && <span className="neural-forms-entries-pill neural-forms-entries-pill--neutral">{ __( 'Queued', 'neural-forms-lite' ) }</span> }
					{ status === 'processing' && <span className="neural-forms-entries-pill neural-forms-entries-pill--neutral">{ __( 'Processing...', 'neural-forms-lite' ) }</span> }
					{ status === 'failed'     && <span className="neural-forms-entries-pill neural-forms-entries-pill--spam">{ __( 'Neural AI failed', 'neural-forms-lite' ) }</span> }
				</div>
			</header>

			{ ! hasAnalysis && status !== 'queued' && status !== 'processing' && (
				<p className="neural-forms-entry-detail__ai-empty">
					{ __( 'No analysis yet for this entry.', 'neural-forms-lite' ) }
				</p>
			) }

			{ hasAnalysis && (
				<dl className="neural-forms-entry-detail__ai-summary">
					{ summary && (
						<div className="neural-forms-entry-detail__field-row neural-forms-entry-detail__field-row--full">
							<dt>{ __( 'Summary', 'neural-forms-lite' ) }</dt>
							<dd>{ summary }</dd>
						</div>
					) }
					{ category && (
						<div className="neural-forms-entry-detail__field-row">
							<dt>{ __( 'Category', 'neural-forms-lite' ) }</dt>
							<dd>{ category }</dd>
						</div>
					) }
					{ priority && (
						<div className="neural-forms-entry-detail__field-row">
							<dt>{ __( 'Priority', 'neural-forms-lite' ) }</dt>
							<dd>{ priority }</dd>
						</div>
					) }
					{ score != null && (
						<div className="neural-forms-entry-detail__field-row">
							<dt>{ __( 'Score', 'neural-forms-lite' ) }</dt>
							<dd>{ score }/100</dd>
						</div>
					) }
				</dl>
			) }

			{ isSpam && (
				<div className="neural-forms-entry-detail__ai-spam">
					<span className="neural-forms-entries-pill neural-forms-entries-pill--spam">{ __( 'Likely spam', 'neural-forms-lite' ) }</span>
					{ spamConf > 0 && (
						<span className="neural-forms-entry-detail__ai-spam-confidence">{ spamConf }%</span>
					) }
					{ spamReason && (
						<p className="neural-forms-entry-detail__ai-spam-reason">{ spamReason }</p>
					) }
				</div>
			) }

			{ !! error && <p className="neural-forms-entries-error">{ error }</p> }

			<footer className="neural-forms-entry-detail__ai-rerun-buttons">
				{ canReanalyze ? (
					<ProSlot
						name="EntryAiActions"
						fillProps={ {
							onReanalyzeClick: onReanalyze,
							onRegenerateClick: onRegenerate,
							isAiRerunning: !! reanalyzing,
							aiData: entry,
							hasPrev: !! hasAnalysis,
						} }
					/>
				) : (
					<>
						<button
							type="button"
							className="neural-forms-entry-detail__ai-rerun-btn"
							onClick={ () => setShowUpsell( true ) }
							aria-label={ __( 'Re-analyze with Neural AI (Pro feature)', 'neural-forms-lite' ) }
						>
							<span className="neural-forms-entry-detail__ai-rerun-btn-lock" aria-hidden="true">
								<LockIcon />
							</span>
							{ hasAnalysis
								? __( 'Re-analyze with Neural AI', 'neural-forms-lite' )
								: __( 'Analyze with Neural AI', 'neural-forms-lite' ) }
						</button>
						<button
							type="button"
							className="neural-forms-entry-detail__ai-rerun-btn"
							onClick={ () => setShowUpsell( true ) }
							aria-label={ __( 'Generate reply with Neural AI (Pro feature)', 'neural-forms-lite' ) }
						>
							<span className="neural-forms-entry-detail__ai-rerun-btn-lock" aria-hidden="true">
								<LockIcon />
							</span>
							{ entry.ai_reply_draft
								? __( 'Regenerate with Neural AI', 'neural-forms-lite' )
								: __( 'Generate with Neural AI', 'neural-forms-lite' ) }
						</button>
					</>
				) }
			</footer>

			{ ! canReanalyze && showUpsell && (
				<aside className="neural-forms-entries-pro-notice" role="status">
					<span className="neural-forms-entries-pro-notice__icon" aria-hidden="true">
						<LockIcon />
					</span>
					<div className="neural-forms-entries-pro-notice__body">
						<p className="neural-forms-entries-pro-notice__title">
							{ __( 'Upgrade to Pro to use Neural AI', 'neural-forms-lite' ) }
						</p>
						<p className="neural-forms-entries-pro-notice__desc">
							{ __( 'Re-analyze and generate replies are part of Neural Forms Pro.', 'neural-forms-lite' ) }
						</p>
					</div>
					<a
						className="neural-forms-entries-pro-notice__cta"
						href={ PRO_UPGRADE_URL }
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'View pricing', 'neural-forms-lite' ) }
					</a>
					<button
						type="button"
						className="neural-forms-entries-pro-notice__close"
						onClick={ () => setShowUpsell( false ) }
						aria-label={ __( 'Dismiss', 'neural-forms-lite' ) }
					>
						<span aria-hidden="true">×</span>
					</button>
				</aside>
			) }
		</section>
	);
}
