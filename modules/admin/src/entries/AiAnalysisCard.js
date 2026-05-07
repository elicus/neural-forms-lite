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

import { __ } from '@wordpress/i18n';
import { ProSlot } from '../slot-registry';

const TIER_LABEL = {
	fast:     'Fast',
	balanced: 'Balanced',
	smart:    'Smart',
	premium:  'Premium',
};

function tierLabel( t ) {
	const k = String( t || 'fast' ).toLowerCase();
	return TIER_LABEL[ k ] || k;
}

export default function AiAnalysisCard( {
	entry,
	capabilities,
	onReanalyze,
	onRegenerate,
	reanalyzing,
	error,
} ) {
	if ( ! entry ) return null;

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

	return (
		<section className="neural-forms-entry-detail__ai-card">
			<header className="neural-forms-entry-detail__ai-card-header">
				<h4 className="neural-forms-entry-detail__ai-card-title">{ __( 'AI Analysis', 'neural-forms-lite' ) }</h4>
				<div className="neural-forms-entry-detail__ai-badges">
					{ tier && (
						<span className="neural-forms-entries-pill neural-forms-entries-pill--tier" title={ __( 'AI tier used', 'neural-forms-lite' ) }>
							{ tierLabel( tier ) }
						</span>
					) }
					{ status === 'queued'     && <span className="neural-forms-entries-pill neural-forms-entries-pill--neutral">{ __( 'Queued', 'neural-forms-lite' ) }</span> }
					{ status === 'processing' && <span className="neural-forms-entries-pill neural-forms-entries-pill--neutral">{ __( 'Processing...', 'neural-forms-lite' ) }</span> }
					{ status === 'failed'     && <span className="neural-forms-entries-pill neural-forms-entries-pill--spam">{ __( 'AI failed', 'neural-forms-lite' ) }</span> }
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
					<a
						className="neural-forms-entries-upsell-link"
						href="https://neuralforms.ai/pro/"
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'Reanalyze (Pro)', 'neural-forms-lite' ) }
					</a>
				) }
			</footer>
		</section>
	);
}
